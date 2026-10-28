<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\Realtime;

use Keel\Realtime\Hub;
use Keel\Routing\Attribute\Post;
use Keel\Routing\Attribute\Query;

#[Post('/publish')]
final class Publish
{
    public function __invoke(#[Query] PublishQuery $q, Hub $hub): void
    {
        $hub->publish($q->channel, $q->message);
    }
}
