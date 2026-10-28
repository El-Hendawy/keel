<?php

declare(strict_types=1);

namespace Shop\Catalog;

use Keel\Auth\Attribute\Authorize;
use Keel\Orm\Orm;
use Keel\Routing\Attribute\Patch;

/** Change a price. Send the version you read: a concurrent change answers 409 instead of being overwritten. */
#[Patch('/products/{id}/price', name: 'catalog.price')]
#[Authorize(scopes: ['catalog:write'])]
final class UpdateProductPrice
{
    public function __invoke(string $id, PriceChange $change, Orm $orm): ProductResource
    {
        $product = $orm->get(Product::class, $id);
        if ($product->version !== $change->version) {
            throw new \Keel\Orm\StaleEntity("Product $id is at version {$product->version}, not {$change->version}. Reload it.");
        }
        $product->priceCents = $change->priceCents;
        $orm->save($product);

        return ProductResource::from($product);
    }
}
