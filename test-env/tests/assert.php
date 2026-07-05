<?php

declare(strict_types=1);

/**
 * Tiny assertion + reporting helpers for the v2 test runner (no PHPUnit — the
 * project is Composer-free). DEV/TEST ONLY.
 */

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;

function section(string $title): void
{
    echo "\n\033[1;36m══ {$title}\033[0m\n";
}

function note(string $msg): void
{
    echo "  · {$msg}\n";
}

function pass(string $msg): void
{
    $GLOBALS['__pass']++;
    echo "  \033[32m✓\033[0m {$msg}\n";
}

function fail(string $msg, string $extra = ''): void
{
    $GLOBALS['__fail']++;
    echo "  \033[31m✗ {$msg}\033[0m" . ($extra !== '' ? "  ({$extra})" : '') . "\n";
}

function ok(bool $cond, string $msg): void
{
    $cond ? pass($msg) : fail($msg);
}

function eq(mixed $expected, mixed $actual, string $msg): void
{
    $expected === $actual
        ? pass($msg)
        : fail($msg, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}

function summary(): int
{
    $p = $GLOBALS['__pass'];
    $f = $GLOBALS['__fail'];
    $color = $f === 0 ? "\033[1;32m" : "\033[1;31m";
    echo "\n{$color}══════════════════════════════\n";
    echo "  {$p} passed, {$f} failed\n";
    echo "══════════════════════════════\033[0m\n";
    return $f === 0 ? 0 : 1;
}
