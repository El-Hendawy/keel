# Endpoints and types

An endpoint is a final class with a route attribute and a public `__invoke`.

```php
#[Get('/orders/{id}', name: 'orders.show')]   // also Post, Put, Patch, Delete, or Route('METHOD', ...)
#[Status(200)]                                // optional; void endpoints default to 204
final class GetOrder
{
    public function __invoke(string $id, Orders $orders): OrderResource { ... }
}
```

## How parameters are bound

| Parameter | Source |
| --- | --- |
| Name matches a `{placeholder}` | The URL. `int` only matches digits; backed enums 404 on unknown values; `{id:[a-z]+}` sets a pattern |
| Type is a registered service (or `ServerRequestInterface`) | The request scope |
| `Principal` / `?Principal` | The authenticated caller (with `->withAuth()`; see [auth](auth.md)) |
| Any other class | The input object: JSON body for POST/PUT/PATCH, query string for GET/HEAD/DELETE. Override with `#[Body]` or `#[Query]` |

At most one input object per endpoint. Anything else is a build error.

## Inputs

```php
final readonly class CreateOrderInput
{
    public function __construct(
        #[Uuid] public string $merchantId,
        /** @var list<LineItem> */
        #[Count(min: 1, max: 100)] public array $items,
        #[Length(max: 500)] public ?string $note = null,
    ) {}
}
```

- Supported field types: `string`, `int`, `float`, `bool`, backed enums, `DateTimeImmutable` (RFC 3339),
  other input classes, and lists of any of those (`@var list<T>` or `#[ListOf(T::class)]`).
- A parameter without a default is required. `?T` allows `null`; it does not make the field optional.
- Unknown fields are rejected with `unknown_field`.
- JSON is strict (`"5"` is not an int). Query strings are coerced (`?limit=5`, `?active=true`).
- Doc comments on properties become OpenAPI descriptions.

### Constraints

| Attribute | Applies to | Code |
| --- | --- | --- |
| `#[Length(min, max)]` | string (characters) | `length` |
| `#[NotBlank]` | string | `not_blank` |
| `#[Pattern('/regex/', 'message')]` | string | `pattern` |
| `#[Uuid]`, `#[Email]`, `#[Url]` | string | `uuid`, `email`, `url` |
| `#[Range(min, max)]` | int, float | `range` |
| `#[Count(min, max)]` | list | `count` |

Type errors use `type`, missing fields `required`, nulls `null`, bad enum values `enum`.

A custom constraint implements `Keel\Validation\Constraint`. Its `compile()` returns a PHP expression that is
inlined into the generated hydrator, so it costs nothing at runtime beyond the check itself:

```php
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class Currency implements Constraint
{
    public function supports(string $kind): bool { return $kind === 'string'; }
    public function compile(string $value): string { return "\\in_array($value, ['EUR', 'USD', 'GBP'], true)"; }
    public function code(): string { return 'currency'; }
    public function message(): string { return 'Must be EUR, USD or GBP.'; }
    public function schema(): array { return ['enum' => ['EUR', 'USD', 'GBP']]; }
}
```

## Outputs

Return an object; its public properties are the JSON. Arrays and nullable return types are rejected at build
time: return a list wrapper (`OrderList { /** @var list<OrderResource> */ public array $data }`) and throw
`NotFound` instead of returning null. Return a `ResponseInterface` when you need full control (files, streams,
custom content types); use `Keel\Http\Responses` to build one.

## Lists

Return a typed list output with a `PageInfo` member and paginate with signed cursors; see
[cache and pagination](cache-and-pagination.md#cursor-pagination).

## Errors

Throw `Keel\Http\Exception\*` (`BadRequest`, `Unauthorized`, `Forbidden`, `NotFound`, `Conflict`,
`UnprocessableEntity`, `TooManyRequests`, `ServiceUnavailable`) or `HttpException` with your own `type` URI
and extension members. Everything renders as RFC 9457 problem details with the request id. Unexpected
exceptions are logged with the trace id and return a generic 500; with `KEEL_DEBUG=1` the body includes the
exception.
