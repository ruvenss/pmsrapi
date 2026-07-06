<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Core;

use Pmsrapi\V2\Exception\ConfigException;

/**
 * Immutable configuration holder.
 *
 * Merges the committed PUBLIC config (v2/config.php) with the SECRET JSON
 * (the same file v1 loads, kept outside the web root). Secret values are
 * read with dot-notation, e.g. $config->secret('db.host').
 */
final class Config
{
    /**
     * @param array<string, mixed> $public  committed public settings
     * @param array<string, mixed> $secrets decoded secret JSON
     */
    private function __construct(
        private readonly array $public,
        private readonly array $secrets,
    ) {}

    public static function load(string $publicConfigFile): self
    {
        if (!is_readable($publicConfigFile)) {
            throw new ConfigException("Public config not found: {$publicConfigFile}");
        }

        /** @var array<string, mixed> $public */
        $public = require $publicConfigFile;

        if (!is_array($public) || !isset($public['secrets_path'])) {
            throw new ConfigException('Public config must return an array containing "secrets_path".');
        }

        $secretsPath = (string) $public['secrets_path'];
        if (!is_readable($secretsPath)) {
            throw new ConfigException("Secret config not found or unreadable: {$secretsPath}");
        }

        $raw = file_get_contents($secretsPath);
        if ($raw === false || !json_validate($raw)) {
            throw new ConfigException("Secret config is not valid JSON: {$secretsPath}");
        }

        /** @var array<string, mixed> $secrets */
        $secrets = json_decode($raw, true);

        return new self($public, $secrets);
    }

    public function public(string $key, mixed $default = null): mixed
    {
        return $this->public[$key] ?? $default;
    }

    /**
     * Dot-notation lookup into the secret JSON, e.g. secret('redis.port', 6379).
     */
    public function secret(string $path, mixed $default = null): mixed
    {
        $value = $this->secrets;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function hasSecret(string $path): bool
    {
        return $this->secret($path, null) !== null;
    }

    public function environment(): string
    {
        return (string) $this->secret('env', 'prod');
    }

    public function isProduction(): bool
    {
        return $this->environment() === 'prod';
    }

    /**
     * Cluster role: "worker" (default) or "hive_mind". The Hive is a
     * development-only coordinator; workers run standalone in production.
     */
    public function role(): string
    {
        return (string) $this->secret('role', 'worker');
    }

    public function isHiveMind(): bool
    {
        return $this->role() === 'hive_mind';
    }

    public function serverToken(): string
    {
        $token = $this->secret('ms_server_token');
        if (!is_string($token) || $token === '') {
            throw new ConfigException('Missing ms_server_token in secret config.');
        }
        return $token;
    }

    public function name(): string
    {
        return (string) $this->public('ms_name', 'microservice');
    }

    public function version(): string
    {
        return (string) $this->public('ms_version', '2.0.0');
    }

    /**
     * Resource definitions that drive the generic CRUD controller.
     * Read from the secret JSON so operators can expose tables without code.
     *
     * @return array<string, array<string, mixed>>
     */
    public function resources(): array
    {
        $resources = $this->secret('resources', []);
        return is_array($resources) ? $resources : [];
    }
}
