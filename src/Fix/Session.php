<?php

declare(strict_types=1);

namespace Keel\Fix;

use Keel\Fix\Store\SessionStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The FIX session layer as a state machine, independent of sockets: feed it inbound
 * messages and the clock, and it writes outbound bytes through $send.
 *
 * Implements logon (with ResetSeqNumFlag and optional Username/Password), sequence number
 * checks, gap detection with ResendRequest, resending with PossDupFlag and gap fills for
 * administrative messages, SequenceReset in both modes, Heartbeat and TestRequest
 * supervision, session-level Reject and Logout.
 */
final class Session
{
    public const string DISCONNECTED = 'disconnected';
    public const string AWAITING_LOGON = 'awaiting_logon';
    public const string LOGON_SENT = 'logon_sent';
    public const string ACTIVE = 'active';
    public const string LOGOUT_SENT = 'logout_sent';

    public string $state = self::DISCONNECTED;

    /** True when the transport should be closed (after a logout or a fatal protocol error). */
    public bool $shouldDisconnect = false;

    private float $lastReceived = 0.0;
    private float $lastSent = 0.0;
    private ?string $pendingTestRequest = null;
    private int $heartbeat;

    /**
     * @param \Closure(string): void $send writes raw bytes to the counterparty
     * @param \Closure(Message, Session): void $onApplication receives application messages in order
     */
    public function __construct(
        public readonly SessionSettings $settings,
        private readonly SessionStore $store,
        private readonly \Closure $send,
        private readonly \Closure $onApplication,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->heartbeat = $settings->heartbeat;
    }

    /** The transport is up: an initiator logs on, an acceptor waits for the Logon. */
    public function connected(float $now): void
    {
        $this->shouldDisconnect = false;
        $this->lastReceived = $this->lastSent = $now;
        if ($this->settings->role === 'acceptor') {
            $this->state = self::AWAITING_LOGON;

            return;
        }
        if ($this->settings->resetOnLogon) {
            $this->store->reset();
        }
        $this->state = self::LOGON_SENT;
        $this->sendAdmin(Message::of(MsgType::LOGON, $this->logonFields($this->settings->resetOnLogon)), $now);
    }

    public function disconnected(): void
    {
        $this->state = self::DISCONNECTED;
        $this->pendingTestRequest = null;
    }

    public function isLoggedOn(): bool
    {
        return $this->state === self::ACTIVE;
    }

    /** Sends an application message; it is stored for resending. */
    public function send(Message $message, float $now): void
    {
        if ($this->state !== self::ACTIVE) {
            throw new FixError("Session {$this->settings->id} is not logged on.");
        }
        $this->write($message, $now);
    }

    public function logout(string $text, float $now): void
    {
        if ($this->state === self::ACTIVE || $this->state === self::LOGON_SENT) {
            $this->state = self::LOGOUT_SENT;
            $this->sendAdmin(Message::of(MsgType::LOGOUT, $text === '' ? [] : [Tag::TEXT => $text]), $now);
        } else {
            $this->shouldDisconnect = true;
        }
    }

    public function receive(string $raw, float $now): void
    {
        $this->lastReceived = $now;
        $this->pendingTestRequest = null;
        try {
            $message = Codec::decode($raw);
        } catch (FixError $e) {
            $this->logger->warning('FIX {session}: discarding garbled message: {error}', ['session' => $this->settings->id, 'error' => $e->getMessage()]);

            return; // garbled messages are ignored; the gap will be resent
        }

        try {
            $this->process($message, $now);
        } catch (FixError $e) {
            if ($e->rejectReason !== null && $this->state === self::ACTIVE) {
                $this->reject($message, $e, $now);
                $this->store->setNextTargetSeq(max($this->store->nextTargetSeq(), $message->seqNum() + 1));

                return;
            }
            $this->logger->error('FIX {session}: {error}', ['session' => $this->settings->id, 'error' => $e->getMessage()]);
            $this->logout($e->getMessage(), $now);
            $this->shouldDisconnect = true;
        }
    }

    /** Heartbeats, test requests and timeouts. Call it at least once a second. */
    public function tick(float $now): void
    {
        if ($this->state !== self::ACTIVE) {
            if ($this->state !== self::DISCONNECTED && $now - $this->lastReceived > max(10, $this->heartbeat * 2)) {
                $this->logger->warning('FIX {session}: no logon or logout response, disconnecting.', ['session' => $this->settings->id]);
                $this->shouldDisconnect = true;
            }

            return;
        }
        if ($now - $this->lastSent >= $this->heartbeat) {
            $this->sendAdmin(Message::of(MsgType::HEARTBEAT), $now);
        }
        $silence = $now - $this->lastReceived;
        if ($this->pendingTestRequest === null && $silence > $this->heartbeat * 1.2) {
            $this->pendingTestRequest = 'TEST-' . (int) ($now * 1000);
            $this->sendAdmin(Message::of(MsgType::TEST_REQUEST, [Tag::TEST_REQ_ID => $this->pendingTestRequest]), $now);
        } elseif ($this->pendingTestRequest !== null && $silence > $this->heartbeat * 2.4) {
            $this->logger->error('FIX {session}: counterparty unresponsive, disconnecting.', ['session' => $this->settings->id]);
            $this->shouldDisconnect = true;
        }
    }

    private function process(Message $message, float $now): void
    {
        $this->checkHeader($message, $now);
        $type = $message->type;

        if ($this->state === self::AWAITING_LOGON || $this->state === self::LOGON_SENT) {
            if ($type !== MsgType::LOGON) {
                throw new FixError("Expected Logon, received MsgType $type.");
            }
            $this->logon($message, $now);

            return;
        }

        if ($type === MsgType::SEQUENCE_RESET && $message->get(Tag::GAP_FILL_FLAG) !== 'Y') {
            $this->sequenceReset($message); // reset mode ignores the sequence number

            return;
        }
        if (!$this->checkSequence($message, $now)) {
            return;
        }

        match ($type) {
            MsgType::HEARTBEAT => null,
            MsgType::TEST_REQUEST => $this->sendAdmin(Message::of(MsgType::HEARTBEAT, [Tag::TEST_REQ_ID => (string) $message->get(Tag::TEST_REQ_ID)]), $now),
            MsgType::RESEND_REQUEST => $this->resend((int) $message->get(Tag::BEGIN_SEQ_NO), (int) $message->get(Tag::END_SEQ_NO), $now),
            MsgType::SEQUENCE_RESET => $this->sequenceReset($message),
            MsgType::REJECT => $this->logger->warning('FIX {session}: counterparty rejected seq {ref}: {text}', ['session' => $this->settings->id, 'ref' => $message->get(Tag::REF_SEQ_NUM), 'text' => $message->get(Tag::TEXT)]),
            MsgType::LOGOUT => $this->onLogout($now),
            MsgType::LOGON => throw new FixError('Logon received on an active session.', 5),
            default => ($this->onApplication)($message, $this),
        };
    }

    private function logon(Message $message, float $now): void
    {
        $heartbeat = (int) $message->get(Tag::HEART_BT_INT);
        if ($heartbeat < 1) {
            throw new FixError('Logon without a valid HeartBtInt (108).');
        }
        $reset = $message->get(Tag::RESET_SEQ_NUM_FLAG) === 'Y';
        if ($this->settings->role === 'acceptor') {
            if ($this->settings->password !== '' && !hash_equals($this->settings->password, (string) $message->get(Tag::PASSWORD))) {
                throw new FixError('Logon rejected: invalid credentials.');
            }
            if ($this->settings->username !== '' && $message->get(Tag::USERNAME) !== $this->settings->username) {
                throw new FixError('Logon rejected: invalid credentials.');
            }
            $this->heartbeat = $heartbeat;
            if ($reset) {
                $this->store->reset();
            }
        }
        if ($reset && $message->seqNum() !== 1) {
            throw new FixError('Logon with ResetSeqNumFlag must have MsgSeqNum 1.');
        }

        $expected = $this->store->nextTargetSeq();
        $seq = $message->seqNum();
        if ($seq < $expected) {
            throw new FixError("MsgSeqNum too low, expecting $expected but received $seq.");
        }
        $this->state = self::ACTIVE;
        if ($this->settings->role === 'acceptor') {
            $this->sendAdmin(Message::of(MsgType::LOGON, $this->logonFields($reset)), $now);
        }
        $this->logger->info('FIX {session}: logged on.', ['session' => $this->settings->id]);
        if ($seq > $expected) {
            $this->requestResend($expected, $now);
        } else {
            $this->store->setNextTargetSeq($seq + 1);
        }
    }

    private function checkHeader(Message $message, float $now): void
    {
        if ($message->get(Tag::BEGIN_STRING) !== $this->settings->beginString) {
            throw new FixError('Unexpected BeginString ' . $message->get(Tag::BEGIN_STRING) . '.');
        }
        if ($message->get(Tag::SENDER_COMP_ID) !== $this->settings->target || $message->get(Tag::TARGET_COMP_ID) !== $this->settings->sender) {
            throw new FixError('CompID problem: this session is ' . $this->settings->sender . ' <-> ' . $this->settings->target . '.', 9, Tag::SENDER_COMP_ID);
        }
        if ($message->seqNum() < 1) {
            throw new FixError('Missing MsgSeqNum (34).', 1, Tag::MSG_SEQ_NUM);
        }
        $sent = $message->get(Tag::SENDING_TIME);
        if ($sent === null) {
            throw new FixError('Missing SendingTime (52).', 1, Tag::SENDING_TIME);
        }
        if (!$message->isPossDup() && abs(Time::parse($sent)->getTimestamp() - $now) > $this->settings->maxClockSkewSeconds) {
            throw new FixError('SendingTime accuracy problem.', 10, Tag::SENDING_TIME);
        }
    }

    /** @return bool whether to process the message */
    private function checkSequence(Message $message, float $now): bool
    {
        $expected = $this->store->nextTargetSeq();
        $seq = $message->seqNum();
        if ($seq === $expected) {
            $this->store->setNextTargetSeq($seq + 1);

            return true;
        }
        if ($seq > $expected) {
            if ($message->type === MsgType::RESEND_REQUEST) {
                $this->resend((int) $message->get(Tag::BEGIN_SEQ_NO), (int) $message->get(Tag::END_SEQ_NO), $now);
            }
            $this->requestResend($expected, $now);

            return false;
        }
        if ($message->isPossDup()) {
            return false; // a duplicate we already processed
        }

        throw new FixError("MsgSeqNum too low, expecting $expected but received $seq.");
    }

    private function sequenceReset(Message $message): void
    {
        $new = (int) $message->get(Tag::NEW_SEQ_NO);
        if ($new < $this->store->nextTargetSeq() && $message->get(Tag::GAP_FILL_FLAG) !== 'Y') {
            throw new FixError('SequenceReset cannot lower NewSeqNo.', 5, Tag::NEW_SEQ_NO);
        }
        $this->store->setNextTargetSeq(max($new, $this->store->nextTargetSeq()));
    }

    private function requestResend(int $from, float $now): void
    {
        $this->logger->warning('FIX {session}: sequence gap, requesting resend from {from}.', ['session' => $this->settings->id, 'from' => $from]);
        $this->sendAdmin(Message::of(MsgType::RESEND_REQUEST, [Tag::BEGIN_SEQ_NO => (string) $from, Tag::END_SEQ_NO => '0']), $now);
    }

    /** Resends stored application messages; administrative ones and gaps become gap fills. */
    private function resend(int $from, int $to, float $now): void
    {
        $last = $this->store->nextSenderSeq() - 1;
        $to = $to === 0 || $to > $last ? $last : $to;
        $stored = $this->store->sent($from, $to);
        $gapStart = null;
        for ($seq = $from; $seq <= $to; $seq++) {
            $original = isset($stored[$seq]) ? Codec::decode($stored[$seq]) : null;
            if ($original === null || MsgType::isAdmin($original->type)) {
                $gapStart ??= $seq;
                continue;
            }
            if ($gapStart !== null) {
                $this->gapFill($gapStart, $seq, $now);
                $gapStart = null;
            }
            $header = $original->header;
            $header[Tag::POSS_DUP_FLAG] = 'Y';
            $header[Tag::ORIG_SENDING_TIME] = $header[Tag::SENDING_TIME] ?? Time::timestamp(self::at($now));
            $header[Tag::SENDING_TIME] = Time::timestamp(self::at($now));
            unset($header[Tag::BEGIN_STRING]);
            ($this->send)(Codec::encode(new Message($original->type, $original->fields, $header), $this->settings->beginString));
        }
        if ($gapStart !== null) {
            $this->gapFill($gapStart, $to + 1, $now);
        }
        $this->lastSent = $now;
    }

    private function gapFill(int $seq, int $newSeq, float $now): void
    {
        $header = $this->header($seq, $now) + [Tag::POSS_DUP_FLAG => 'Y'];
        ($this->send)(Codec::encode(new Message(MsgType::SEQUENCE_RESET, [[Tag::GAP_FILL_FLAG, 'Y'], [Tag::NEW_SEQ_NO, (string) $newSeq]], $header), $this->settings->beginString));
    }

    private function onLogout(float $now): void
    {
        if ($this->state !== self::LOGOUT_SENT) {
            $this->sendAdmin(Message::of(MsgType::LOGOUT), $now);
        }
        $this->logger->info('FIX {session}: logged out.', ['session' => $this->settings->id]);
        $this->state = self::DISCONNECTED;
        $this->shouldDisconnect = true;
    }

    private function reject(Message $message, FixError $error, float $now): void
    {
        $fields = [Tag::REF_SEQ_NUM => (string) $message->seqNum(), Tag::REF_MSG_TYPE => $message->type, Tag::SESSION_REJECT_REASON => (string) $error->rejectReason, Tag::TEXT => $error->getMessage()];
        if ($error->refTag !== null) {
            $fields[Tag::REF_TAG_ID] = (string) $error->refTag;
        }
        $this->sendAdmin(Message::of(MsgType::REJECT, $fields), $now);
    }

    /** Rejects an application message at the business level (MsgType j), e.g. an unsupported type. */
    public function businessReject(Message $message, int $reason, string $text, float $now): void
    {
        $this->write(Message::of(MsgType::BUSINESS_MESSAGE_REJECT, [
            Tag::REF_SEQ_NUM => (string) $message->seqNum(),
            Tag::REF_MSG_TYPE => $message->type,
            Tag::BUSINESS_REJECT_REASON => (string) $reason,
            Tag::TEXT => $text,
        ]), $now);
    }

    /** @return array<int, string> */
    private function logonFields(bool $reset): array
    {
        $fields = [Tag::ENCRYPT_METHOD => '0', Tag::HEART_BT_INT => (string) $this->heartbeat];
        if ($reset) {
            $fields[Tag::RESET_SEQ_NUM_FLAG] = 'Y';
        }
        if ($this->settings->role === 'initiator' && $this->settings->username !== '') {
            $fields[Tag::USERNAME] = $this->settings->username;
        }
        if ($this->settings->role === 'initiator' && $this->settings->password !== '') {
            $fields[Tag::PASSWORD] = $this->settings->password;
        }
        if ($this->settings->isFixt()) {
            $fields[Tag::DEFAULT_APPL_VER_ID] = $this->settings->defaultApplVerId;
        }

        return $fields;
    }

    private function sendAdmin(Message $message, float $now): void
    {
        $this->write($message, $now);
    }

    private function write(Message $message, float $now): void
    {
        $seq = $this->store->nextSenderSeq();
        $raw = Codec::encode(new Message($message->type, $message->fields, $this->header($seq, $now) + $message->header), $this->settings->beginString);
        $this->store->setNextSenderSeq($seq + 1);
        $this->store->saveSent($seq, $raw);
        $this->lastSent = $now;
        ($this->send)($raw);
    }

    /** @return array<int, string> */
    private function header(int $seq, float $now): array
    {
        return [
            Tag::SENDER_COMP_ID => $this->settings->sender,
            Tag::TARGET_COMP_ID => $this->settings->target,
            Tag::MSG_SEQ_NUM => (string) $seq,
            Tag::SENDING_TIME => Time::timestamp(self::at($now)),
        ];
    }

    private static function at(float $now): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . \sprintf('%.3F', $now)))->setTimezone(new \DateTimeZone('UTC'));
    }
}
