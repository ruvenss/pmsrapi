<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Debug;

/**
 * Strips sensitive values before anything is captured to the debug stream.
 *
 * The dashboard shows production traffic, so tokens, passwords, cookies and the
 * like MUST never land in the (Redis-stored) capture. Redaction happens at
 * capture time — the raw values are never written, not merely hidden in the UI.
 */
final class Redactor
{
    private const MASK = '***REDACTED***';
    private const MAX_STRING = 2000;

    /** Header names that are always masked (compared lowercase). */
    private const SECRET_HEADERS = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key',
    ];

    /** @var non-empty-string regex of sensitive key fragments */
    private readonly string $keyPattern;

    /**
     * @param list<string> $extraKeys additional key fragments to mask (from config)
     */
    public function __construct(array $extraKeys = [])
    {
        $fragments = ['pass', 'passwd', 'password', 'secret', 'token', 'authorization',
            'api[-_ ]?key', 'apikey', 'cookie', 'card', 'cvv', 'cvc', 'ssn', 'private', 'credential'];
        foreach ($extraKeys as $extra) {
            $extra = trim((string) $extra);
            if ($extra !== '') {
                $fragments[] = preg_quote($extra, '/');
            }
        }
        $this->keyPattern = '/(' . implode('|', $fragments) . ')/i';
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public function headers(array $headers): array
    {
        $clean = [];
        foreach ($headers as $name => $value) {
            $clean[$name] = in_array(strtolower($name), self::SECRET_HEADERS, true)
                ? self::MASK
                : $this->truncate((string) $value);
        }
        return $clean;
    }

    /**
     * Recursively mask sensitive keys and truncate long strings.
     */
    public function data(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = (is_string($key) && preg_match($this->keyPattern, $key) === 1)
                    ? self::MASK
                    : $this->data($item);
            }
            return $out;
        }

        if (is_string($value)) {
            return $this->truncate($value);
        }

        return $value;
    }

    /**
     * Mask positional/bound SQL params by best effort — long values truncated,
     * everything kept as scalars (params have no key names to match on).
     *
     * @param list<scalar|null> $params
     * @return list<scalar|null>
     */
    public function params(array $params): array
    {
        return array_map(
            fn(mixed $p): mixed => is_string($p) ? $this->truncate($p) : $p,
            $params,
        );
    }

    private function truncate(string $value): string
    {
        if (strlen($value) <= self::MAX_STRING) {
            return $value;
        }
        return substr($value, 0, self::MAX_STRING) . '…[' . strlen($value) . ' bytes]';
    }
}
