# Read-Only Code, Externalized Writable State

Deploy the application tree as **read-only**. All writable and cache data —
caches, logs, sessions, uploads, temp files, webhook queues — must live in a
**separate directory outside the code**, supplied as an absolute path via
config/env (ideally a mounted volume, or a backing service like Redis / object
storage). Nothing may write into the deployment directory or the web root.

## Bad Example

```php
<?php

declare(strict_types=1);

// Writing state INTO the code tree — this breaks read-only deploys and lets a
// single file-write bug persist a webshell right next to executable code.
file_put_contents(__DIR__ . '/cache/weather.json', $payload);

// Logs, uploads and queues living inside the project / web root
file_put_contents(__DIR__ . '/logs/app.log', $line, FILE_APPEND);
move_uploaded_file($tmp, getcwd() . '/public/uploads/' . $name);

// Worst case: a writable queue dir that the web server also serves
file_put_contents('webhooks/queue/' . uniqid() . '.json', $job);

// Session files default into the code dir on some setups
session_save_path(__DIR__ . '/tmp');

// Relative paths silently resolve against the deployment dir
$cacheDir = 'cache';
mkdir($cacheDir, 0777, true);
```

## Good Example

```php
<?php

declare(strict_types=1);

// Prefer a backing service over local files — a stateless process keeps NO
// writable state in the code tree at all (12-factor).
$cache->remember($key, $ttl, $producer);   // Redis
$storage->put($objectKey, $bytes);          // S3 / object store
$queue->push('webhooks', $job);             // Redis list

// When you DO need local files, resolve every writable path from config as an
// ABSOLUTE path outside the code, and hard-refuse to write inside the tree.
final class StoragePaths
{
    public function __construct(
        private readonly string $stateDir, // e.g. /var/lib/weather  (separate mount)
        private readonly string $logDir,   // e.g. /var/log/weather
        private readonly string $codeRoot, // the read-only deployment dir
    ) {}

    public function cacheFile(string $key): string
    {
        return $this->ensureOutsideCode($this->stateDir . '/cache/' . basename($key));
    }

    public function logFile(string $name): string
    {
        return $this->ensureOutsideCode($this->logDir . '/' . basename($name));
    }

    private function ensureOutsideCode(string $path): string
    {
        $dir = realpath(dirname($path)) ?: dirname($path);
        $code = realpath($this->codeRoot) ?: $this->codeRoot;

        if ($dir === $code || str_starts_with($dir . DIRECTORY_SEPARATOR, $code . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Refusing to write inside read-only code tree: {$path}");
        }

        return $path;
    }
}
```

```bash
# Deployment: code is immutable and owned by a DIFFERENT user than the runtime
# process, so the app can never rewrite itself.
chown -R root:root /srv/weather && chmod -R a-w /srv/weather
#   ...or mount it read-only:  mount -o remount,ro /srv/weather

# Writable state lives on a separate path/volume owned by the service user:
install -d -o app -g app /var/lib/weather/cache /var/log/weather
# Point the service at it via config, never a path relative to the code:
#   local_log.path = /var/log/weather/app.log
#   redis { ... }   # cache/queue/session state offloaded entirely
```

## Why

- **Tamper resistance**: with a read-only tree, a file-write vulnerability
  cannot drop a webshell, poison an autoloaded file, or modify code — the class
  of "write then execute" RCE is designed out.
- **Immutable, atomic deploys**: the release directory never mutates, so
  rollback is just repointing a symlink; no stale writable cruft accumulates.
- **Stateless processes**: writable state in backing services (Redis, object
  storage) or a shared mount is visible to every instance and survives redeploys
  and horizontal scaling.
- **Least privilege**: the runtime user needs write access only to the state
  mount, not to the code it executes.
- **Consistent with this project**: pairs with [sec-file-uploads](sec-file-uploads.md)
  (store uploads outside the web root) and the existing convention of keeping the
  secret config JSON outside the served tree. v2 already offloads cache, queue,
  and tokens to Redis and logs to an absolute `local_log.path`; new code must not
  reintroduce in-tree writable dirs the way v1's file-based `webhooks/` does.
