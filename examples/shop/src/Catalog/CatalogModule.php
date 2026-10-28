<?php

declare(strict_types=1);

namespace Shop\Catalog;

use Keel\Module\Module;

/** Products, kept with the Keel ORM (the orders module uses plain SQL; both coexist). */
#[Module(name: 'catalog')]
final class CatalogModule
{
}
