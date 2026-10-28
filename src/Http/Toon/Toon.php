<?php

declare(strict_types=1);

namespace Keel\Http\Toon;

/**
 * TOON, Token-Oriented Object Notation (https://github.com/toon-format/spec): the JSON data
 * model in a compact, indentation-based form that costs language models far fewer tokens
 * than JSON, mostly by writing uniform arrays of objects as tables:
 *
 *     orders[2]{id,status,totalCents}:
 *       o-1,placed,900
 *       o-2,paid,1350
 *     page:
 *       nextCursor: null
 *       hasMore: false
 *
 * encode() produces canonical TOON; decode() is strict: array lengths, table widths and
 * indentation are checked, and anything ambiguous is an error, never a guess.
 */
final class Toon
{
    public const string MEDIA_TYPE = 'text/toon';

    /** @param ','|"\t"|'|' $delimiter */
    public static function encode(mixed $value, string $delimiter = ',', int $indent = 2): string
    {
        return (new ToonEncoder($delimiter, $indent))->encode($value);
    }

    /** @throws ToonException on malformed input */
    public static function decode(string $toon, int $indent = 2, int $maxDepth = 64): mixed
    {
        return (new ToonDecoder($indent, $maxDepth))->decode($toon);
    }
}
