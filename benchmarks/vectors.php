<?php

declare(strict_types=1);

/**
 * Plain PHP arrays, the pure-PHP vectors and the keel_vector extension, side by side: time and memory
 * per operation at several sizes. Each backend and size runs in its own process, so memory numbers
 * do not mix.
 *
 *     php benchmarks/vectors.php --extension=ext/keel_vector/target/release/keel_vector.so
 *     php benchmarks/vectors.php --extension=... --sizes=1000,100000 --markdown
 *
 * Every operation's result is checked against the plain-array one, so a fast wrong answer fails.
 * Results from one machine are in benchmarks/RESULTS.md.
 */

$options = getopt('', ['extension:', 'sizes:', 'markdown', 'worker:', 'size:', 'jit']);

if (isset($options['worker'])) {
    $vendor = getenv('COMPOSER_VENDOR_DIR') ?: __DIR__ . '/../vendor';
    require $vendor . '/autoload.php';
    echo json_encode(worker((string) $options['worker'], (int) $options['size']), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

$extension = isset($options['extension']) ? realpath((string) $options['extension']) : false;
if ($extension === false) {
    fwrite(STDERR, "Pass --extension=path/to/keel_vector.so (build it with ext/keel_vector/build.sh).\n");
    exit(1);
}
$sizes = array_map('intval', explode(',', (string) ($options['sizes'] ?? '1000,100000,10000000')));
$backends = ['array' => [], 'php' => [], 'native' => ['-d', "extension=$extension"]];
$ini = ['-d', 'memory_limit=-1', '-d', 'opcache.enable_cli=1'];
if (isset($options['jit'])) {
    array_push($ini, '-d', 'opcache.jit=tracing', '-d', 'opcache.jit_buffer_size=64M');
}

$results = [];
foreach ($sizes as $size) {
    foreach ($backends as $backend => $args) {
        fwrite(STDERR, "$backend, $size elements...\n");
        $command = array_merge([PHP_BINARY], $ini, $args, [__FILE__, "--worker=$backend", "--size=$size"]);
        $process = proc_open($command, [1 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        if (proc_close($process) !== 0 || !is_string($output)) {
            fwrite(STDERR, "The $backend worker failed for $size elements.\n");
            exit(1);
        }
        $results[$size][$backend] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
    foreach (['php', 'native'] as $backend) {
        foreach ($results[$size]['array']['checks'] as $operation => $check) {
            if ($results[$size][$backend]['checks'][$operation] !== $check) {
                fwrite(STDERR, "$backend $operation at $size elements differs from the plain-array result.\n");
                exit(1);
            }
        }
    }
}

$native = $results[$sizes[0]]['native'];
printf(
    "PHP %s (%s), opcache on, JIT %s; keel_vector %s, SIMD %s; %s\n\n",
    PHP_VERSION,
    php_uname('m'),
    isset($options['jit']) ? 'tracing' : 'off',
    $native['version'],
    $native['simd'],
    cpu(),
);
$format = isset($options['markdown'])
    ? static fn (array $cells): string => '| ' . implode(' | ', $cells) . " |\n"
    : static fn (array $cells): string => vsprintf("%-22s %10s %14s %14s %14s %9s %9s\n", $cells);
echo $format(['Operation', 'Elements', 'PHP array', 'Pure-PHP vector', 'Extension', 'vs array', 'vs pure']);
if (isset($options['markdown'])) {
    echo "| --- | ---: | ---: | ---: | ---: | ---: | ---: |\n";
}
foreach (array_keys($results[$sizes[0]]['array']['times']) as $operation) {
    foreach ($sizes as $size) {
        $t = array_map(static fn (array $r): ?float => $r['times'][$operation], $results[$size]);
        echo $format([
            $operation,
            number_format($size),
            $t['array'] === null ? 'n/a' : duration($t['array']),
            duration($t['php']),
            duration($t['native']),
            $t['array'] === null ? '' : ratio($t['array'] / $t['native']),
            ratio($t['php'] / $t['native']),
        ]);
    }
}
echo "\n";
echo $format(['Memory', 'Elements', 'PHP array', 'Pure-PHP vector', 'Extension', 'vs array', 'vs pure']);
if (isset($options['markdown'])) {
    echo "| --- | ---: | ---: | ---: | ---: | ---: | ---: |\n";
}
foreach ($sizes as $size) {
    $m = array_map(static fn (array $r): int => $r['memory'], $results[$size]);
    echo $format(['one float container', number_format($size), bytes($m['array']), bytes($m['php']), bytes($m['native']), ratio($m['array'] / max(1, $m['native'])), ratio($m['php'] / max(1, $m['native']))]);
}

/**
 * Runs every operation for one backend and size.
 *
 * @return array{times: array<string, ?float>, checks: array<string, string>, memory: int, simd: string, version: string}
 */
function worker(string $backend, int $size): array
{
    $vectors = $backend !== 'array';
    if ($vectors && (Keel\Vector\VectorInfo::isNative() !== ($backend === 'native'))) {
        throw new RuntimeException("The $backend worker loaded the wrong implementation.");
    }

    // One container of $size floats. Arrays and pure-PHP vectors live in PHP's allocator; the
    // extension's buffer does not, so its size is the buffer's capacity.
    $before = memory_get_usage();
    $container = $vectors ? Keel\Vector\FloatVector::filled($size, 1.5) : array_fill(0, $size, 1.5);
    $container = $backend === 'native' ? $container->memoryBytes() : memory_get_usage() - $before;

    mt_srand(1);
    $a = $b = [];
    for ($i = 0; $i < $size; $i++) {
        $a[] = mt_rand() / mt_getrandmax();
        $b[] = mt_rand() / mt_getrandmax() - 0.5;
    }
    $ints = range(0, $size - 1);

    $times = [];
    $checks = [];
    $run = static function (string $name, callable $operation, callable $check) use (&$times, &$checks): void {
        $result = $operation(); // warm-up, and the result checked
        $checks[$name] = $check($result);
        unset($result);
        $samples = [];
        $started = hrtime(true);
        do {
            $t = hrtime(true);
            $operation();
            $samples[] = (hrtime(true) - $t) / 1e9;
        } while (count($samples) < 3 || (count($samples) < 50 && hrtime(true) - $started < 3e8));
        sort($samples);
        $times[$name] = $samples[intdiv(count($samples), 2)];
    };
    $digest = static fn (array|Keel\Vector\IntVector|Keel\Vector\FloatVector $values): string => md5(serialize(is_array($values) ? $values : $values->toArray()));

    if (!$vectors) {
        $times['fromArray'] = null; // the data is already an array
        $checks['fromArray'] = $digest($a);
        $times['toArray'] = null;
        $checks['toArray'] = $digest($a);
        $run('fill', static fn () => array_fill(0, $size, 0.25), static fn (array $r): string => $digest($r));
        $run('sum (int)', static fn () => array_sum($ints), static fn (int $r): string => (string) $r);
        // array_sum() adds left to right; the check uses the vectors' fixed order, so the bits compare.
        $run('sum (float)', static fn () => array_sum($a), static fn (): string => bin2hex(pack('E', lanes($a))));
        $run('dot', static function () use ($a, $b): float {
            $t = [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
            foreach ($a as $i => $x) {
                $t[$i & 7] += $x * $b[$i];
            }

            return (($t[0] + $t[1]) + ($t[2] + $t[3])) + (($t[4] + $t[5]) + ($t[6] + $t[7]));
        }, static fn (float $r): string => bin2hex(pack('E', $r)));
        $run('add', static function () use ($a, $b): array {
            $out = [];
            foreach ($a as $i => $x) {
                $out[] = $x + $b[$i];
            }

            return $out;
        }, static fn (array $r): string => $digest($r));
        $run('filter (x > 0.5)', static function () use ($a): array {
            $out = [];
            foreach ($a as $i => $x) {
                if ($x > 0.5) {
                    $out[] = $i;
                }
            }

            return $out;
        }, static fn (array $r): string => $digest($r));
        $run('sort', static function () use ($b): array {
            $copy = $b;
            sort($copy);

            return $copy;
        }, static fn (array $r): string => $digest($r));
    } else {
        $va = Keel\Vector\FloatVector::fromArray($a);
        $vb = Keel\Vector\FloatVector::fromArray($b);
        $vi = Keel\Vector\IntVector::fromArray($ints);
        $run('fromArray', static fn () => Keel\Vector\FloatVector::fromArray($a), static fn (Keel\Vector\FloatVector $r): string => $digest($r));
        $run('toArray', static fn () => $va->toArray(), static fn (array $r): string => $digest($r));
        $run('fill', static fn () => Keel\Vector\FloatVector::filled($size, 0.25), static fn (Keel\Vector\FloatVector $r): string => $digest($r));
        $run('sum (int)', static fn () => $vi->sum(), static fn (int $r): string => (string) $r);
        $run('sum (float)', static fn () => $va->sum(), static fn (float $r): string => bin2hex(pack('E', $r)));
        $run('dot', static fn () => $va->dot($vb), static fn (float $r): string => bin2hex(pack('E', $r)));
        $run('add', static fn () => $va->add($vb), static fn (Keel\Vector\FloatVector $r): string => $digest($r));
        $run('filter (x > 0.5)', static fn () => $va->whereGreaterThan(0.5), static fn (Keel\Vector\IntVector $r): string => $digest($r));
        $run('sort', static fn () => $vb->sort(), static fn (Keel\Vector\FloatVector $r): string => $digest($r));
    }

    return [
        'times' => $times,
        'checks' => $checks,
        'memory' => $container,
        'simd' => $vectors ? Keel\Vector\VectorInfo::simdLevel() : '',
        'version' => $vectors ? Keel\Vector\VectorInfo::version() : '',
    ];
}

/**
 * The float sum in the vectors' fixed order, so the checks compare equal bits; `array_sum()` adds
 * left to right and differs in the last bits.
 *
 * @param list<float> $values
 */
function lanes(array $values): float
{
    $t = [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
    foreach ($values as $i => $x) {
        $t[$i & 7] += $x;
    }

    return (($t[0] + $t[1]) + ($t[2] + $t[3])) + (($t[4] + $t[5]) + ($t[6] + $t[7]));
}

function cpu(): string
{
    $name = PHP_OS_FAMILY === 'Darwin'
        ? trim((string) shell_exec('sysctl -n machdep.cpu.brand_string'))
        : (preg_match('/^model name\s*:\s*(.+)$/m', (string) @file_get_contents('/proc/cpuinfo'), $m) === 1 ? $m[1] : php_uname('m'));

    return $name . ', ' . PHP_OS_FAMILY;
}

function duration(float $seconds): string
{
    return match (true) {
        $seconds < 5e-8 => '<0.1 µs',
        $seconds < 1e-3 => sprintf('%.1f µs', $seconds * 1e6),
        $seconds < 1 => sprintf('%.2f ms', $seconds * 1e3),
        default => sprintf('%.2f s', $seconds),
    };
}

function bytes(int $bytes): string
{
    return match (true) {
        $bytes < 1 << 20 => sprintf('%.1f KiB', $bytes / 1024),
        default => sprintf('%.1f MiB', $bytes / (1 << 20)),
    };
}

function ratio(float $ratio): string
{
    return $ratio < 0.1 ? '<0.1×' : sprintf($ratio >= 10 ? '%.0f×' : '%.1f×', $ratio);
}
