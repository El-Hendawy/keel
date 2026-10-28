<?php

declare(strict_types=1);

namespace Keel\Fix;

/** Session-level (administrative) message types. */
final class MsgType
{
    public const string HEARTBEAT = '0';
    public const string TEST_REQUEST = '1';
    public const string RESEND_REQUEST = '2';
    public const string REJECT = '3';
    public const string SEQUENCE_RESET = '4';
    public const string LOGOUT = '5';
    public const string LOGON = 'A';
    public const string BUSINESS_MESSAGE_REJECT = 'j';

    public static function isAdmin(string $type): bool
    {
        return \in_array($type, ['0', '1', '2', '3', '4', '5', 'A'], true);
    }
}
