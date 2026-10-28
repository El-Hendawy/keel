<?php

declare(strict_types=1);

namespace Shop\Catalog;

final readonly class ProductResource
{
    public function __construct(
        public string $id,
        public string $sku,
        public string $name,
        public int $priceCents,
        public int $version,
    ) {
    }

    public static function from(Product $p): self
    {
        return new self($p->id, $p->sku, $p->name, $p->priceCents, $p->version);
    }
}
