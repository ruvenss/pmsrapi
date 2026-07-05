<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Http;

/**
 * The HTTP verbs v2 routes on. Backed by their canonical string so we can
 * map to/from $_SERVER and match routes exhaustively.
 */
enum HttpMethod: string
{
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case PATCH = 'PATCH';
    case DELETE = 'DELETE';
    case OPTIONS = 'OPTIONS';

    public static function fromRequest(string $raw): self
    {
        return self::tryFrom(strtoupper(trim($raw))) ?? self::GET;
    }

    /**
     * CRUD intent, preserved from v1's method-as-intent convention.
     */
    public function isWrite(): bool
    {
        return match ($this) {
            self::POST, self::PUT, self::PATCH, self::DELETE => true,
            self::GET, self::OPTIONS => false,
        };
    }
}
