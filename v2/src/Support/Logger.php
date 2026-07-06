<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Support;

use Pmsrapi\V2\Core\Config;

/**
 * Structured logger. Writes newline-delimited JSON to the local_log path from
 * the secret config (the same file v1 writes to), gated by local_log.level.
 *
 * This is the v2 home for v1's log_event()/sqlLog() behaviour. It never uses
 * the @ operator and never throws for logging failures — logging must not take
 * down a request.
 */
final class Logger
{
    private readonly string $path;
    private readonly string $level;

    public function __construct(Config $config)
    {
        $this->path = (string) $config->secret('local_log.path', '');
        // info = everything, errors = warning+, none = off.
        $this->level = (string) $config->secret('local_log.level', 'errors');
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->write('critical', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $severity, string $message, array $context): void
    {
        if ($this->path === '' || !$this->shouldLog($severity)) {
            return;
        }

        $line = json_encode([
            'ts' => date('c'),
            'severity' => $severity,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($line === false) {
            return;
        }

        // Explicit failure handling instead of @ — a broken log path degrades
        // to error_log() rather than crashing the request.
        $written = file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            error_log("pmsrapi-v2: unable to write log to {$this->path}: {$message}");
        }
    }

    private function shouldLog(string $severity): bool
    {
        return match ($this->level) {
            'none' => false,
            'errors' => in_array($severity, ['warning', 'error', 'critical'], true),
            default => true, // 'info' and anything else -> log everything
        };
    }
}
