# Modules

A module is a namespace with a declared public API:

```php
namespace App\Billing;

#[Module(
    name: 'billing',
    exports: [InvoiceService::class, InvoicePaid::class],
    dependsOn: ['tenancy', 'orders'],
)]
final class BillingModule {}
```

Every class in `App\Billing\…` belongs to `billing`. The build fails when a class in another module:

- references a billing class that is not in `exports` (imports, fully qualified names and constructor types
  are all checked), or
- references billing at all without listing `'billing'` in its own `dependsOn`.

Dependency cycles between modules are rejected too. When two modules need each other, extract the shared part,
or invert one direction with an event: the example's `orders` module publishes `OrderPlaced` and `billing`
listens to it, so orders never depends on billing.

```php
#[Listener]
final readonly class CreateInvoiceWhenOrderPlaced
{
    public function __construct(private InvoiceService $invoices) {}

    public function __invoke(OrderPlaced $event): void
    {
        $this->invoices->createFor($event->orderId, $event->totalCents);
    }
}
```

Listeners run synchronously in the current scope (and transaction). Asynchronous delivery through the
transactional outbox is on the roadmap.

Modules show up in `keel inspect`, and their names become OpenAPI tags.
