<?php

declare(strict_types=1);

namespace Keel\Validation;

/** One failed rule, addressed by its path in the input (for example `items[0].sku`). */
final readonly class Violation implements \JsonSerializable
{
    public function __construct(
        public string $path,
        public string $code,
        public string $message,
    ) {
    }

    public static function at(string $base, string $field): string
    {
        return $base === '' ? $field : $base . '.' . $field;
    }

    /** @return array{path: string, code: string, message: string} */
    public function jsonSerialize(): array
    {
        return ['path' => $this->path, 'code' => $this->code, 'message' => $this->message];
    }
}
