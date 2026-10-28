<?php

declare(strict_types=1);

namespace Keel\Crypto;

/** A ciphertext could not be decrypted: altered, truncated, under an unknown key, or in another context. */
final class DecryptionFailed extends \RuntimeException
{
}
