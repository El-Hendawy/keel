<?php

declare(strict_types=1);

namespace Shop\Catalog;

use Keel\Auth\Attribute\Authorize;
use Keel\Orm\Orm;
use Keel\Routing\Attribute\Get;

/** List active products by name, one page at a time. */
#[Get('/products', name: 'catalog.list')]
#[Authorize(scopes: ['catalog:read'])]
final class ListProducts
{
    public function __invoke(ProductQuery $query, Orm $orm): ProductList
    {
        $products = $orm->query(Product::class)->where('active', true);
        if ($query->search !== null) {
            $products->where('name', 'like', '%' . addcslashes($query->search, '%_') . '%');
        }
        $page = $products->orderBy('name')->cursorPaginate($query->limit, $query->cursor);

        return new ProductList(array_map(ProductResource::from(...), $page->items), $page->page);
    }
}
