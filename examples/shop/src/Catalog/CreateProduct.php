<?php

declare(strict_types=1);

namespace Shop\Catalog;

use Keel\Auth\Attribute\Authorize;
use Keel\Orm\Orm;
use Keel\Routing\Attribute\Post;
use Keel\Routing\Attribute\Status;

/** Add a product to the catalog. */
#[Post('/products', name: 'catalog.create')]
#[Status(201)]
#[Authorize(scopes: ['catalog:write'])]
final class CreateProduct
{
    public function __invoke(ProductInput $in, Orm $orm): ProductResource
    {
        $product = new Product($in->sku, $in->name, $in->priceCents);
        $orm->save($product); // id, tenant, timestamps and version are filled in

        return ProductResource::from($product);
    }
}
