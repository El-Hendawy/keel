<?php

declare(strict_types=1);

namespace Keel\Http\Toon;

/** @internal see Toon::decode() */
final class ToonDecoder
{
    /** @var list<array{depth: int, text: string, number: int}> */
    private array $lines = [];

    private int $at = 0;

    public function __construct(private readonly int $indent = 2, private readonly int $maxDepth = 64)
    {
    }

    public function decode(string $toon): mixed
    {
        $this->lines = [];
        $this->at = 0;
        foreach (preg_split('/\r?\n/', $toon) ?: [] as $i => $raw) {
            if (trim($raw) === '') {
                continue;
            }
            $spaces = \strlen($raw) - \strlen(ltrim($raw, ' '));
            if ($spaces % $this->indent !== 0 || str_starts_with(ltrim($raw, ' '), "\t")) {
                throw ToonException::at($i + 1, "indentation must be a multiple of {$this->indent} spaces.");
            }
            $this->lines[] = ['depth' => intdiv($spaces, $this->indent), 'text' => rtrim(substr($raw, $spaces), ' '), 'number' => $i + 1];
        }
        if ($this->lines === []) {
            return [];
        }
        $first = $this->lines[0];
        if ($first['depth'] !== 0) {
            throw ToonException::at($first['number'], 'the document must start at column 0.');
        }
        $header = $this->header($first['text']);
        if ($header !== null && $header['key'] === null) {
            $this->at = 1;
            $value = $this->array($header, 0, $first['number'], 1);
        } elseif ($header === null && \count($this->lines) === 1) {
            $this->at = 1;
            $value = $this->primitive($first['text'], $first['number']);
        } else {
            $value = $this->object(0, 1);
        }
        if ($this->at < \count($this->lines)) {
            throw ToonException::at($this->lines[$this->at]['number'], 'unexpected content.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function object(int $depth, int $nesting): array
    {
        $this->guard($nesting);
        $object = [];
        while ($this->at < \count($this->lines) && $this->lines[$this->at]['depth'] >= $depth) {
            $line = $this->lines[$this->at];
            if ($line['depth'] > $depth) {
                throw ToonException::at($line['number'], 'unexpected indentation.');
            }
            $this->at++;
            [$key, $value] = $this->field($line['text'], $depth, $line['number'], $nesting);
            if (\array_key_exists($key, $object)) {
                throw ToonException::at($line['number'], "duplicate key \"$key\".");
            }
            $object[$key] = $value;
        }

        return $object;
    }

    /** @return array{0: string, 1: mixed} a field whose line is at $depth (already consumed) */
    private function field(string $text, int $depth, int $number, int $nesting): array
    {
        $header = $this->header($text) ?? throw ToonException::at($number, 'expected "key: value".');
        $key = $header['key'] ?? throw ToonException::at($number, 'an array header needs a key here.');
        if ($header['length'] !== null) {
            return [$key, $this->array($header, $depth, $number, $nesting + 1)];
        }
        if ($header['rest'] !== '') {
            return [$key, $this->primitive($header['rest'], $number)];
        }
        $next = $this->lines[$this->at] ?? null;

        return [$key, $next !== null && $next['depth'] > $depth ? $this->object($depth + 1, $nesting + 1) : []];
    }

    /**
     * @param array{key: ?string, length: ?int, delimiter: string, fields: ?list<string>, rest: string} $header
     * @return list<mixed>
     */
    private function array(array $header, int $depth, int $number, int $nesting): array
    {
        $this->guard($nesting);
        $length = (int) $header['length'];
        $delimiter = $header['delimiter'];
        if ($header['fields'] !== null) {
            $rows = [];
            for ($i = 0; $i < $length; $i++) {
                $line = $this->lines[$this->at] ?? null;
                if ($line === null || $line['depth'] !== $depth + 1) {
                    throw ToonException::at($number, "table declares $length rows, found $i.");
                }
                $this->at++;
                $cells = $this->split($line['text'], $delimiter);
                if (\count($cells) !== \count($header['fields'])) {
                    throw ToonException::at($line['number'], 'row has ' . \count($cells) . ' values for ' . \count($header['fields']) . ' columns.');
                }
                $rows[] = array_combine($header['fields'], array_map(fn (string $c): mixed => $this->primitive($c, $line['number']), $cells));
            }
            $this->noMoreAt($depth + 1, $number, $length);

            return $rows;
        }
        if ($header['rest'] !== '' || $length === 0) {
            $values = $header['rest'] === '' ? [] : array_map(fn (string $c): mixed => $this->primitive($c, $number), $this->split($header['rest'], $delimiter));
            if (\count($values) !== $length) {
                throw ToonException::at($number, "array declares $length values, found " . \count($values) . '.');
            }

            return $values;
        }
        $items = [];
        for ($i = 0; $i < $length; $i++) {
            $line = $this->lines[$this->at] ?? null;
            if ($line === null || $line['depth'] !== $depth + 1 || ($line['text'] !== '-' && !str_starts_with($line['text'], '- '))) {
                throw ToonException::at($number, "list declares $length items, found $i.");
            }
            $this->at++;
            $items[] = $this->listItem(substr($line['text'], 2), $depth + 1, $line['number'], $nesting);
        }
        $this->noMoreAt($depth + 1, $number, $length);

        return $items;
    }

    private function listItem(string $text, int $depth, int $number, int $nesting): mixed
    {
        if ($text === '') {
            return [];
        }
        $header = $this->header($text);
        if ($header === null) {
            return $this->primitive($text, $number);
        }
        if ($header['key'] === null) {
            return $this->array($header, $depth + 1, $number, $nesting + 1);
        }
        // An object: its first field is on the hyphen line, the rest one level deeper.
        [$key, $value] = $this->field($text, $depth + 1, $number, $nesting + 1);

        return [$key => $value] + $this->rest($key, $depth + 1, $nesting + 1, $number);
    }

    /** @return array<string, mixed> */
    private function rest(string $firstKey, int $depth, int $nesting, int $number): array
    {
        $rest = $this->object($depth, $nesting);
        if (\array_key_exists($firstKey, $rest)) {
            throw ToonException::at($number, "duplicate key \"$firstKey\".");
        }

        return $rest;
    }

    private function noMoreAt(int $depth, int $number, int $length): void
    {
        $line = $this->lines[$this->at] ?? null;
        if ($line !== null && $line['depth'] >= $depth) {
            throw ToonException::at($line['number'], "more items than the $length declared on line $number.");
        }
    }

    /**
     * Parses `key: value`, `key:`, `key[N]: a,b`, `key[N]{f,g}:` or `[N]...`; null when the
     * text is not a key or array header (a plain value).
     *
     * @return array{key: ?string, length: ?int, delimiter: string, fields: ?list<string>, rest: string}|null
     */
    private function header(string $text): ?array
    {
        $pos = 0;
        $key = null;
        if (($text[0] ?? '') === '"') {
            [$key, $pos] = $this->quoted($text, 0);
        } elseif (($text[0] ?? '') !== '[') {
            $pos = strcspn($text, ':[');
            if ($pos === \strlen($text) || $pos === 0) {
                return null;
            }
            $key = substr($text, 0, $pos);
            if ($key !== trim($key) || str_contains($key, '"')) {
                return null;
            }
        }
        $length = null;
        $delimiter = ',';
        $fields = null;
        if (($text[$pos] ?? '') === '[') {
            if (preg_match('/\G\[(\d+)([\t|]?)\]/', $text, $m, 0, $pos) !== 1) {
                return null;
            }
            $length = (int) $m[1];
            $delimiter = $m[2] === '' ? ',' : $m[2];
            $pos += \strlen($m[0]);
            if (($text[$pos] ?? '') === '{') {
                $close = strpos($text, '}:', $pos);
                if ($close === false) {
                    return null;
                }
                $fields = array_map(fn (string $f): string => str_starts_with($f, '"') ? $this->quoted($f, 0)[0] : $f, $this->split(substr($text, $pos + 1, $close - $pos - 1), $delimiter));
                $pos = $close + 1;
            }
        }
        if (($text[$pos] ?? '') !== ':') {
            return null;
        }
        $rest = substr($text, $pos + 1);
        if ($rest !== '' && !str_starts_with($rest, ' ')) {
            return null;
        }

        return ['key' => $key, 'length' => $length, 'delimiter' => $delimiter, 'fields' => $fields, 'rest' => ltrim($rest, ' ')];
    }

    private function primitive(string $text, int $number): mixed
    {
        $text = trim($text, ' ');
        if (str_starts_with($text, '"')) {
            [$value, $end] = $this->quoted($text, 0, $number);
            if ($end !== \strlen($text)) {
                throw ToonException::at($number, 'unexpected characters after a quoted string.');
            }

            return $value;
        }

        return match (true) {
            $text === 'null' => null,
            $text === 'true' => true,
            $text === 'false' => false,
            preg_match('/^-?(?:0|[1-9]\d*)$/', $text) === 1 => filter_var($text, \FILTER_VALIDATE_INT) !== false ? (int) $text : (float) $text,
            preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?$/', $text) === 1 => (float) $text,
            default => $text,
        };
    }

    /** @return array{0: string, 1: int} the unescaped string and the offset after its closing quote */
    private function quoted(string $text, int $start, int $number = 0): array
    {
        $out = '';
        $length = \strlen($text);
        for ($i = $start + 1; $i < $length; $i++) {
            $c = $text[$i];
            if ($c === '"') {
                return [$out, $i + 1];
            }
            if ($c === '\\') {
                $next = $text[++$i] ?? '';
                $out .= match ($next) {
                    '\\' => '\\', '"' => '"', 'n' => "\n", 'r' => "\r", 't' => "\t",
                    default => throw ToonException::at($number, "invalid escape \\$next."),
                };
                continue;
            }
            $out .= $c;
        }

        throw ToonException::at($number, 'unterminated string.');
    }

    /** @return list<string> cells, splitting on $delimiter outside quotes */
    private function split(string $text, string $delimiter): array
    {
        $cells = [];
        $cell = '';
        $quoted = false;
        $length = \strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $c = $text[$i];
            if ($c === '\\' && $quoted) {
                $cell .= $c . ($text[++$i] ?? '');
                continue;
            }
            if ($c === '"') {
                $quoted = !$quoted;
            } elseif ($c === $delimiter && !$quoted) {
                $cells[] = $cell;
                $cell = '';
                continue;
            }
            $cell .= $c;
        }
        $cells[] = $cell;

        return array_map(static fn (string $c): string => trim($c, ' '), $cells);
    }

    private function guard(int $nesting): void
    {
        if ($nesting > $this->maxDepth) {
            throw new ToonException("TOON nesting exceeds {$this->maxDepth} levels.");
        }
    }
}
