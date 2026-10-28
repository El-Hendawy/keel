<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Realtime;

final readonly class PublishQuery
{
    public function __construct(public string $channel, public string $message)
    {
    }
}
