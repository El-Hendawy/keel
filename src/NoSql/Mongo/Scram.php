<?php

declare(strict_types=1);

namespace Keel\NoSql\Mongo;

/**
 * SCRAM-SHA-256 (RFC 5802, RFC 7677), the client side, as MongoDB uses it.
 *
 * The password is used as given (no SASLprep normalisation): use ASCII passwords, or passwords
 * already in NFKC form.
 */
final class Scram
{
    private string $clientFirstBare;

    private string $authMessage = '';

    private string $saltedPassword = '';

    public function __construct(private readonly string $user, private readonly string $password, private readonly ?string $nonce = null)
    {
        $this->clientFirstBare = 'n=' . str_replace(['=', ','], ['=3D', '=2C'], $this->user) . ',r=' . ($this->nonce ?? base64_encode(random_bytes(24)));
    }

    public function first(): string
    {
        return 'n,,' . $this->clientFirstBare;
    }

    public function final(string $serverFirst): string
    {
        $fields = self::fields($serverFirst);
        $nonce = $fields['r'] ?? '';
        $salt = base64_decode($fields['s'] ?? '', true);
        $iterations = (int) ($fields['i'] ?? 0);
        $clientNonce = substr($this->clientFirstBare, (int) strpos($this->clientFirstBare, ',r=') + 3);
        if (!str_starts_with($nonce, $clientNonce) || $salt === false || $iterations < 4096) {
            throw new MongoException('Invalid SCRAM server challenge.');
        }
        $this->saltedPassword = hash_pbkdf2('sha256', $this->password, $salt, $iterations, 32, true);
        $clientKey = hash_hmac('sha256', 'Client Key', $this->saltedPassword, true);
        $withoutProof = 'c=biws,r=' . $nonce;
        $this->authMessage = $this->clientFirstBare . ',' . $serverFirst . ',' . $withoutProof;
        $signature = hash_hmac('sha256', $this->authMessage, hash('sha256', $clientKey, true), true);

        return $withoutProof . ',p=' . base64_encode($clientKey ^ $signature);
    }

    /** @throws MongoException when the server cannot prove it knows the password */
    public function verify(string $serverFinal): void
    {
        $fields = self::fields($serverFinal);
        $expected = base64_encode(hash_hmac('sha256', $this->authMessage, hash_hmac('sha256', 'Server Key', $this->saltedPassword, true), true));
        if (!hash_equals($expected, $fields['v'] ?? '')) {
            throw new MongoException(isset($fields['e']) ? 'Authentication failed: ' . $fields['e'] : 'The server signature did not verify.');
        }
    }

    /** @return array<string, string> */
    private static function fields(string $message): array
    {
        $fields = [];
        foreach (explode(',', $message) as $part) {
            if (\strlen($part) > 2 && $part[1] === '=') {
                $fields[$part[0]] = substr($part, 2);
            }
        }

        return $fields;
    }
}
