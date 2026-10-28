<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\OrmBlog;

use Keel\Orm\Attribute\Column;
use Keel\Orm\Attribute\Encrypted;
use Keel\Orm\Attribute\Entity;
use Keel\Orm\Attribute\Id;
use Keel\Orm\Attribute\Version;

#[Entity('wallets')]
final class Wallet
{
    /** @param array<mixed>|null $kyc */
    public function __construct(
        public string $owner,
        #[Encrypted] public string $iban,
        #[Encrypted] public ?array $kyc = null,
        #[Encrypted, Column(type: 'text')] public ?string $notes = null,
        #[Id] public string $id = '',
        #[Version] public int $version = 0,
    ) {
    }
}
