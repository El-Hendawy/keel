<?php

declare(strict_types=1);

namespace Keel\Fix;

/** Standard header, trailer and session-level tag numbers (FIX 4.2 to FIXT 1.1). */
final class Tag
{
    public const int BEGIN_STRING = 8;
    public const int BODY_LENGTH = 9;
    public const int CHECKSUM = 10;
    public const int MSG_SEQ_NUM = 34;
    public const int MSG_TYPE = 35;
    public const int SENDER_COMP_ID = 49;
    public const int TARGET_COMP_ID = 56;
    public const int SENDING_TIME = 52;
    public const int POSS_DUP_FLAG = 43;
    public const int ORIG_SENDING_TIME = 122;
    public const int TEXT = 58;
    public const int BEGIN_SEQ_NO = 7;
    public const int END_SEQ_NO = 16;
    public const int NEW_SEQ_NO = 36;
    public const int REF_SEQ_NUM = 45;
    public const int ENCRYPT_METHOD = 98;
    public const int HEART_BT_INT = 108;
    public const int TEST_REQ_ID = 112;
    public const int GAP_FILL_FLAG = 123;
    public const int RESET_SEQ_NUM_FLAG = 141;
    public const int REF_TAG_ID = 371;
    public const int REF_MSG_TYPE = 372;
    public const int SESSION_REJECT_REASON = 373;
    public const int BUSINESS_REJECT_REASON = 380;
    public const int USERNAME = 553;
    public const int PASSWORD = 554;
    public const int DEFAULT_APPL_VER_ID = 1137;

    /** Tags that belong to the standard header, in the order they are written after 8, 9 and 35. */
    public const array HEADER = [49, 56, 34, 52, 43, 122];
}
