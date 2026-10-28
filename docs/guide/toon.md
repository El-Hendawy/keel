# TOON: token-efficient responses

[TOON](https://github.com/toon-format/spec) (Token-Oriented Object Notation) encodes the JSON data model in a
compact, indentation-based form. Uniform arrays of objects become tables, so language models and agents read
the same data with far fewer tokens than JSON.

```php
// app.php
->withToon()                  // JSON stays the default; clients opt in with Accept: text/toon
->withToon(default: true)     // the API answers in TOON unless the client asks for JSON
```

The default can also be set at runtime: `'http' => ['default_format' => 'toon']` in `config.php`.

## On the wire

```http
GET /orders?limit=2
Accept: text/toon
```

```toon
data[2]:
  - id: 01a1...
    status: placed
    items[2]{sku,quantity,unitPriceCents}:
      TEA,2,300
      "CAKE, LEMON",1,450
    totalCents: 1050
  - id: 01a0...
    status: paid
    items[1]{sku,quantity,unitPriceCents}:
      SCONE,4,250
    totalCents: 1000
page:
  nextCursor: eyJjIjoi...
  hasMore: true
```

- **Responses** follow `Accept`: `text/toon` gives TOON, `application/json` gives JSON; equal preference or no
  `Accept` uses the default. Problem documents (errors, validation) are negotiated too, with
  `Content-Type: text/toon; charset=utf-8; profile=problem`. Responses vary on `Accept`.
- **Request bodies** sent with `Content-Type: text/toon` are decoded into the same data a JSON body would
  produce, then validated by the same generated hydrators: unknown fields, types and constraints behave
  identically.
- **OpenAPI** lists `text/toon` next to `application/json` for every body and response.
- `toon.delimiter` (`,` default, `|` or a tab) sets the table delimiter for responses.

## The codec

`Keel\Http\Toon\Toon::encode($data)` and `Toon::decode($text)` can be used on their own, e.g. to put TOON in
prompts.

- The encoder writes canonical TOON: tables for uniform arrays of objects with primitive values, inline arrays
  for primitives, `-` lists otherwise; numbers without exponents; strings quoted only when needed (empty,
  padded, looking like a number/boolean/null, or containing `:`, quotes, brackets, braces, the delimiter or
  control characters).
- The decoder is strict: declared array lengths, table widths, indentation and escapes are checked, and
  duplicate keys are rejected. Malformed bodies are a 400.
- PHP cannot tell an empty object from an empty array; both encode as an empty array (`key[0]:`).
