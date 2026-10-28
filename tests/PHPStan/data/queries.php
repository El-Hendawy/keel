<?php

declare(strict_types=1);

namespace Keel\Tests\PHPStan\Data;

use Keel\Orm\Orm;
use Keel\Tests\Fixtures\OrmBlog\Post;
use Keel\Tests\Fixtures\OrmBlog\PostStatus;

function queries(Orm $orm): void
{
    $posts = $orm->query(Post::class);

    // Fine.
    $posts->where('status', PostStatus::Published)->where('rating', '>', 3.5)->whereIn('authorId', ['a', 'b'])
        ->where('title', 'like', 'Keel%')->with('author', 'comments.author', 'tags')->whereHas('comments')
        ->orderBy('createdAt', 'desc')->update(['featured' => true]);

    // Wrong.
    $posts->where('titel', 'x');                       // line 21: unknown column
    $posts->where('status', 'published');              // line 22: string for an enum
    $posts->where('rating', '=>', 3);                  // line 23: operator
    $posts->with('comment');                           // line 24: unknown relation
    $posts->with('comments.writer');                   // line 25: unknown nested relation
    $posts->orderBy('created_at');                     // line 26: column name, not property
    $posts->whereIn('featured', ['yes']);              // line 27: string for a bool
    $posts->update(['title' => 42]);                   // line 28: int for a string
}
