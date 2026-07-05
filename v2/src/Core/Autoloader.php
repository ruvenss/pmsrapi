<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Core;

/**
 * Minimal PSR-4 autoloader — no Composer required.
 *
 * v2 keeps v1's zero-build, drop-in philosophy: this class is required
 * directly by bootstrap.php and then maps namespace prefixes to directories.
 *
 * DO NOT MODIFY user code here — this is core. Extend via new namespaced
 * classes under v2/src/ and they will be discovered automatically.
 */
final class Autoloader
{
    /** @var array<string, string> namespace prefix (with trailing "\") => base dir (with trailing separator) */
    private array $prefixes = [];

    public function register(): void
    {
        spl_autoload_register($this->loadClass(...));
    }

    public function addNamespace(string $prefix, string $baseDir): void
    {
        $prefix = trim($prefix, '\\') . '\\';
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->prefixes[$prefix] = $baseDir;
    }

    public function loadClass(string $class): bool
    {
        foreach ($this->prefixes as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            if (is_file($file)) {
                require $file;
                return true;
            }
        }

        return false;
    }
}
