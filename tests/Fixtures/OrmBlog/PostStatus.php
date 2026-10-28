<?php

declare(strict_types=1);

namespace Keel\Tests\Fixtures\OrmBlog;

enum PostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
