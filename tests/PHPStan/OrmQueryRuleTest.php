<?php

declare(strict_types=1);

namespace Keel\Tests\PHPStan;

use PHPUnit\Framework\TestCase;

/** The PHPStan extension finds wrong ORM queries before they run. */
final class OrmQueryRuleTest extends TestCase
{
    public function testWrongQueriesAreReported(): void
    {
        $root = \dirname(__DIR__, 2);
        $vendor = getenv('COMPOSER_VENDOR_DIR') ?: $root . '/vendor';
        $config = sys_get_temp_dir() . '/keel-phpstan-rule-test.neon';
        file_put_contents($config, "includes:\n    - $root/extension.neon\nparameters:\n    level: 5\n    tmpDir: " . sys_get_temp_dir() . "/keel-phpstan-rule-cache\n");
        exec(
            escapeshellarg(\PHP_BINARY) . ' -d memory_limit=1G ' . escapeshellarg("$vendor/bin/phpstan") . ' analyse --no-progress --error-format=raw -c ' . escapeshellarg($config)
            . ' --autoload-file=' . escapeshellarg("$vendor/autoload.php") . ' ' . escapeshellarg(__DIR__ . '/data/queries.php') . ' 2>&1',
            $output,
        );
        $errors = [];
        foreach ($output as $line) {
            if (preg_match('/queries\.php:(\d+):(.*)$/', $line, $m) === 1) {
                $errors[(int) $m[1]] = trim($m[2]);
            }
        }
        ksort($errors);

        self::assertSame([21, 22, 23, 24, 25, 26, 27, 28], array_keys($errors), implode("\n", $output));
        self::assertStringContainsString('Post has no column property "titel"', $errors[21]);
        self::assertStringContainsString('Post::$status expects Keel\Tests\Fixtures\OrmBlog\PostStatus, \'published\' given', $errors[22]);
        self::assertStringContainsString('Unsupported operator "=>"', $errors[23]);
        self::assertStringContainsString('Post has no relation "comment"', $errors[24]);
        self::assertStringContainsString('Comment has no relation "writer"', $errors[25]);
        self::assertStringContainsString('no column property "created_at"', $errors[26]);
        self::assertStringContainsString('Post::$featured expects bool', $errors[27]);
        self::assertStringContainsString('Post::$title expects string, 42 given', $errors[28]);
    }
}
