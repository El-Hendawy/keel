<?php

declare(strict_types=1);

namespace Keel\Tests\Fix;

use FixGateway\Blotter;
use FixGateway\ExecType;
use FixGateway\NewOrderSingle;
use FixGateway\OrdType;
use FixGateway\Party;
use FixGateway\Side;
use Keel\Fix\Codec;
use Keel\Fix\Engine;
use Keel\Fix\FixError;
use Keel\Fix\FixGateway as Gateway;
use Keel\Fix\Message;
use Keel\Fix\MsgType;
use Keel\Fix\Parser;
use Keel\Fix\Session;
use Keel\Fix\SessionSettings;
use Keel\Fix\Store\InMemorySessionStore;
use Keel\Fix\Tag;
use Keel\Testing\TestApp;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FixTest extends TestCase
{
    private const string APP = __DIR__ . '/../../examples/fix-gateway/app.php';

    public function testItReadsAPublishedMessageAndWritesValidFraming(): void
    {
        // The ExecutionReport example from Wikipedia's FIX article, with its published length and checksum.
        $raw = str_replace('|', "\x01", '8=FIX.4.2|9=178|35=8|49=PHLX|56=PERS|52=20071123-05:30:00.000|11=ATOMNOCCC9990900|20=3|150=E|39=E|55=MSFT|167=CS|54=1|38=15|40=2|44=15|58=PHLX EQUITY TESTING|59=0|47=C|32=0|31=0|151=15|14=0|6=0|10=128|');
        $message = Codec::decode($raw);
        self::assertSame('8', $message->type);
        self::assertSame('PHLX', $message->get(Tag::SENDER_COMP_ID));
        self::assertSame('MSFT', $message->get(55));

        $again = Codec::encode(new Message('8', $message->fields, array_diff_key($message->header, [Tag::BEGIN_STRING => true])), 'FIX.4.2');
        self::assertSame($raw, $again, 'Re-encoding reproduces the published bytes, length and checksum.');

        $this->expectException(FixError::class);
        Codec::decode(substr($raw, 0, -4) . "999\x01");
    }

    public function testTheParserFramesAByteStream(): void
    {
        $one = Codec::encode(Message::of('0', [], [Tag::SENDER_COMP_ID => 'A', Tag::TARGET_COMP_ID => 'B', Tag::MSG_SEQ_NUM => '1', Tag::SENDING_TIME => '20260101-00:00:00.000']), 'FIX.4.4');
        $two = str_replace('34=1', '34=2', $one);
        $two = substr($two, 0, -7) . '10=' . Codec::checksum(substr($two, 0, -7)) . "\x01";
        $parser = new Parser();
        $stream = "garbage\x01" . $one . $two;
        $out = [];
        foreach (str_split($stream, 7) as $chunk) {
            array_push($out, ...$parser->feed($chunk));
        }
        self::assertSame([$one, $two], $out);
    }

    public function testSessionsLogOnHeartbeatAndRecoverFromAGap(): void
    {
        [$client, $venue, $wire, $received] = self::pair();
        $now = 1_800_000_000.0;
        $venue->connected($now);
        $client->connected($now);
        self::deliver($wire, $client, $venue, $now);
        self::assertTrue($client->isLoggedOn());
        self::assertTrue($venue->isLoggedOn());

        // Application message, in order.
        $client->send(Message::of('D', [11 => 'c-1', 55 => 'X']), $now);
        self::deliver($wire, $client, $venue, $now);
        self::assertSame(['c-1'], array_map(static fn (Message $m): ?string => $m->get(11), $received['venue']));

        // A message lost on the wire: the next one reveals the gap, the venue asks for a
        // resend, and the client resends it with PossDupFlag.
        $client->send(Message::of('D', [11 => 'c-2', 55 => 'X']), $now);
        array_pop($wire->toVenue);
        $client->send(Message::of('D', [11 => 'c-3', 55 => 'X']), $now);
        self::deliver($wire, $client, $venue, $now);
        self::deliver($wire, $client, $venue, $now);
        self::assertSame(['c-1', 'c-2', 'c-3'], array_map(static fn (Message $m): ?string => $m->get(11), $received['venue']));
        self::assertTrue($received['venue'][1]->isPossDup());

        // Silence: heartbeat after HeartBtInt, then a TestRequest, then a disconnect.
        $client->tick($now + 30);
        self::assertSame(MsgType::HEARTBEAT, Codec::decode((string) end($wire->toVenue))->type);
        $venue->tick($now + 37);
        self::assertSame(MsgType::TEST_REQUEST, Codec::decode((string) end($wire->toClient))->type);
        $venue->tick($now + 80);
        self::assertTrue($venue->shouldDisconnect);
    }

    public function testASequenceNumberThatIsTooLowEndsTheSession(): void
    {
        [$client, $venue, $wire] = self::pair();
        $now = 1_800_000_000.0;
        $venue->connected($now);
        $client->connected($now);
        self::deliver($wire, $client, $venue, $now);
        $replay = Codec::encode(Message::of('D', [11 => 'old'], [Tag::SENDER_COMP_ID => 'ACME', Tag::TARGET_COMP_ID => 'VENUE', Tag::MSG_SEQ_NUM => '1', Tag::SENDING_TIME => '20270115-08:00:00.000']), 'FIX.4.4');
        $venue->receive($replay, $now);
        self::assertTrue($venue->shouldDisconnect);
        self::assertSame(MsgType::LOGOUT, Codec::decode((string) end($wire->toClient))->type);
    }

    public function testAWrongPasswordIsRefused(): void
    {
        [$client, $venue, $wire] = self::pair(clientPassword: 'wrong');
        $now = 1_800_000_000.0;
        $venue->connected($now);
        $client->connected($now);
        self::deliver($wire, $client, $venue, $now);
        self::assertFalse($venue->isLoggedOn());
        self::assertTrue($venue->shouldDisconnect);
    }

    public function testOrdersAndExecutionReportsOverTcp(): void
    {
        $port = random_int(30000, 40000);
        $app = TestApp::boot(self::APP, ['log' => ['stream' => 'php://memory']]);
        $engine = new Engine($app->container(), [
            new SessionSettings('venue', 'acceptor', 'FIX.4.4', 'VENUE', 'ACME', heartbeat: 5, port: $port),
            new SessionSettings('acme', 'initiator', 'FIX.4.4', 'ACME', 'VENUE', heartbeat: 5, port: $port),
        ], null, $app->container()->get(LoggerInterface::class));
        $gateway = $app->container()->get(Gateway::class);

        for ($i = 0; $i < 40 && !$gateway->isLoggedOn('acme'); $i++) {
            $engine->run(0.05);
        }
        self::assertTrue($gateway->isLoggedOn('acme'), 'The initiator logged on to the acceptor.');

        $at = new \DateTimeImmutable('2026-03-02T14:30:00.123Z');
        $gateway->send('acme', new NewOrderSingle('ord-1', 'ACME', Side::Buy, 100, OrdType::Limit, $at, '12.50', [new Party('TRADER-7')]));
        $gateway->send('acme', new NewOrderSingle('ord-2', 'ACME', Side::Sell, 5, OrdType::Limit, $at));
        $blotter = $app->container()->get(Blotter::class);
        for ($i = 0; $i < 40 && \count($blotter->reports) < 2; $i++) {
            $engine->run(0.05);
        }

        self::assertCount(2, $blotter->reports);
        self::assertSame('ord-1', $blotter->reports[0]->clOrdId);
        self::assertSame(ExecType::New, $blotter->reports[0]->ordStatus);
        self::assertSame(100, $blotter->reports[0]->leavesQty);
        self::assertSame(ExecType::Rejected, $blotter->reports[1]->ordStatus);
        self::assertSame('Limit orders need a price (44).', $blotter->reports[1]->text);

        // Raw message with an unsupported type: the venue answers with a BusinessMessageReject.
        $gateway->send('acme', Message::of('AE', [571 => 'x']));
        $engine->run(0.2);
        $engine->stop();
        $engine->run();
        self::assertFalse($gateway->isLoggedOn('acme'));
    }

    public function testTypedCodecsRoundTrip(): void
    {
        $app = TestApp::boot(self::APP, ['log' => ['stream' => 'php://memory']]);
        $gateway = $app->container()->get(Gateway::class);
        $order = new NewOrderSingle('o', 'S', Side::Sell, 3, OrdType::Market, new \DateTimeImmutable('2026-03-02T14:30:00.123Z'), null, [new Party('A'), new Party('B', 'C', 1)]);
        $message = $gateway->toMessage($order);
        self::assertSame('D', $message->type);
        self::assertSame([[453, '2'], [448, 'A'], [447, 'D'], [452, '3'], [448, 'B'], [447, 'C'], [452, '1']], \array_slice($message->fields, 6));
        self::assertEquals($order, $gateway->toObject($message));

        $this->expectException(FixError::class);
        $gateway->toObject(new Message('D', [[11, 'o'], [55, 'S'], [54, '9']]));
    }

    /**
     * @return array{0: Session, 1: Session, 2: object{toVenue: list<string>, toClient: list<string>}, 3: array{venue: list<Message>}}
     */
    private static function pair(string $clientPassword = 's3cret'): array
    {
        $wire = new class () {
            /** @var list<string> */
            public array $toVenue = [];
            /** @var list<string> */
            public array $toClient = [];
        };
        $received = new \ArrayObject(['venue' => []]);
        $venue = new Session(
            new SessionSettings('venue', 'acceptor', 'FIX.4.4', 'VENUE', 'ACME', heartbeat: 30, password: 's3cret'),
            new InMemorySessionStore(),
            static function (string $bytes) use ($wire): void { $wire->toClient[] = $bytes; },
            static function (Message $m) use ($received): void { $received['venue'] = [...$received['venue'], $m]; },
        );
        $client = new Session(
            new SessionSettings('acme', 'initiator', 'FIX.4.4', 'ACME', 'VENUE', heartbeat: 30, password: $clientPassword),
            new InMemorySessionStore(),
            static function (string $bytes) use ($wire): void { $wire->toVenue[] = $bytes; },
            static function (Message $m): void {},
        );

        return [$client, $venue, $wire, $received];
    }

    /** @param object{toVenue: list<string>, toClient: list<string>} $wire */
    private static function deliver(object $wire, Session $client, Session $venue, float $now): void
    {
        for ($round = 0; $round < 5 && ($wire->toVenue !== [] || $wire->toClient !== []); $round++) {
            $toVenue = $wire->toVenue;
            $wire->toVenue = [];
            foreach ($toVenue as $raw) {
                $venue->receive($raw, $now);
            }
            $toClient = $wire->toClient;
            $wire->toClient = [];
            foreach ($toClient as $raw) {
                $client->receive($raw, $now);
            }
        }
    }
}
