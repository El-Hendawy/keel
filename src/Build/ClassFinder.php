<?php

declare(strict_types=1);

namespace Keel\Build;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds classes in source directories by reading tokens, without executing the files.
 * Also records each file's `use` imports so docblock types can be resolved.
 */
final class ClassFinder
{
    /** @var array<string, SourceFile> class name => file */
    private array $files = [];

    /**
     * @param list<string> $directories
     * @return list<class-string>
     */
    public function find(array $directories): array
    {
        $classes = [];
        foreach ($directories as $directory) {
            foreach (self::phpFiles($directory) as $path) {
                $file = self::parse($path);
                foreach ($file->classes as $class) {
                    $this->files[$class] = $file;
                    $classes[] = $class;
                }
            }
        }
        sort($classes);

        /** @var list<class-string> */
        return array_values(array_unique($classes));
    }

    public function fileOf(string $class): ?SourceFile
    {
        return $this->files[$class] ?? null;
    }

    /** @return list<string> */
    public static function phpFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var \SplFileInfo $info */
        foreach ($iterator as $info) {
            $name = $info->getFilename();
            if ($info->isFile() && str_ends_with($name, '.php') && !str_starts_with($name, '._')) {
                $files[] = $info->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    public static function parse(string $path): SourceFile
    {
        $code = (string) file_get_contents($path);
        $tokens = array_values(\PhpToken::tokenize($code));
        $namespace = '';
        $uses = [];
        $classes = [];
        $referenced = [];
        $depth = 0;
        $count = \count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_NAMESPACE)) {
                $namespace = self::nameAfter($tokens, $i);
                continue;
            }

            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                continue;
            }
            if ($token->text === '}') {
                $depth--;
                continue;
            }

            // Top-level `use` statements are imports; `use` inside a class is a trait.
            if ($token->is(T_USE) && $depth === 0) {
                foreach (self::parseUse($tokens, $i) as $alias => $fqcn) {
                    $uses[$alias] = $fqcn;
                    $referenced[] = $fqcn;
                }
                continue;
            }

            if ($token->is(T_NAME_FULLY_QUALIFIED)) {
                $referenced[] = ltrim($token->text, '\\');
                continue;
            }

            if ($token->is([T_CLASS, T_INTERFACE, T_ENUM, T_TRAIT])) {
                $previous = self::previousMeaningful($tokens, $i);
                if ($previous !== null && $previous->is([T_DOUBLE_COLON, T_NEW])) {
                    continue; // Foo::class or `new class`
                }
                $next = self::nextMeaningful($tokens, $i);
                if ($next !== null && $next->is(T_STRING)) {
                    $classes[] = ltrim($namespace . '\\' . $next->text, '\\');
                }
            }
        }

        return new SourceFile($path, $namespace, $uses, $classes, array_values(array_unique($referenced)));
    }

    /** @param list<\PhpToken> $tokens */
    private static function nameAfter(array $tokens, int &$i): string
    {
        $name = '';
        for ($i++; isset($tokens[$i]); $i++) {
            $t = $tokens[$i];
            if ($t->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $name .= $t->text;
            } elseif ($t->text === ';' || $t->text === '{') {
                $i--;
                break;
            }
        }

        return ltrim($name, '\\');
    }

    /**
     * @param list<\PhpToken> $tokens
     * @return array<string, string> alias => fully qualified name
     */
    private static function parseUse(array $tokens, int &$i): array
    {
        $statement = '';
        for ($i++; isset($tokens[$i]) && $tokens[$i]->text !== ';'; $i++) {
            $statement .= $tokens[$i]->text;
        }
        $statement = trim((string) preg_replace('/\s+/', ' ', $statement));
        if (str_starts_with($statement, 'function ') || str_starts_with($statement, 'const ')) {
            return [];
        }

        $entries = [];
        if (preg_match('/^(.*?)\{(.*)\}$/', $statement, $m) === 1) {
            $prefix = trim($m[1]);
            foreach (explode(',', $m[2]) as $part) {
                if (trim($part) !== '') {
                    $entries[] = $prefix . trim($part);
                }
            }
        } else {
            $entries = array_map('trim', explode(',', $statement));
        }

        $uses = [];
        foreach ($entries as $entry) {
            $parts = preg_split('/\s+as\s+/i', $entry) ?: [$entry];
            $fqcn = ltrim(trim($parts[0]), '\\');
            $alias = isset($parts[1]) ? trim($parts[1]) : substr((string) strrchr('\\' . $fqcn, '\\'), 1);
            $uses[$alias] = $fqcn;
        }

        return $uses;
    }

    /** @param list<\PhpToken> $tokens */
    private static function nextMeaningful(array $tokens, int $i): ?\PhpToken
    {
        for ($i++; isset($tokens[$i]); $i++) {
            if (!$tokens[$i]->isIgnorable()) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private static function previousMeaningful(array $tokens, int $i): ?\PhpToken
    {
        for ($i--; $i >= 0; $i--) {
            if (!$tokens[$i]->isIgnorable()) {
                return $tokens[$i];
            }
        }

        return null;
    }
}
