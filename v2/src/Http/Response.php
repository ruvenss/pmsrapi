<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Http;

use Pmsrapi\V2\Exception\ApiException;
use Throwable;

/**
 * The response envelope. Preserves v1's {"success": bool, "data": ...} shape
 * so existing clients keep working, and adds a structured "error" object on
 * failure and optional "meta" (pagination, etc.).
 *
 * Unlike v1's http_response(), this does NOT call die(): the Kernel returns it
 * and index.php sends it exactly once, which keeps control flow explicit.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly int $status,
        public readonly bool $success,
        public readonly mixed $data,
        public readonly ?array $error = null,
        public readonly ?array $meta = null,
        public array $headers = [],
    ) {}

    public static function ok(mixed $data, ?array $meta = null): self
    {
        return new self(200, true, $data, null, $meta);
    }

    public static function created(mixed $data): self
    {
        return new self(201, true, $data);
    }

    public static function noContent(): self
    {
        return new self(204, true, null);
    }

    /**
     * @param array<string, mixed> $error
     */
    public static function error(int $status, array $error, ?array $meta = null): self
    {
        return new self($status, false, null, $error, $meta);
    }

    public static function fromApiException(ApiException $e): self
    {
        $error = [
            'code' => $e->errorCode(),
            'message' => $e->getMessage(),
        ];
        if ($e->details() !== []) {
            $error['details'] = $e->details();
        }

        return new self($e->statusCode(), false, null, $error);
    }

    public static function fromThrowable(Throwable $e, bool $production): self
    {
        if ($e instanceof ApiException) {
            return self::fromApiException($e);
        }

        $error = [
            'code' => 'internal_error',
            'message' => $production ? 'Internal Server Error' : $e->getMessage(),
        ];

        return new self(500, false, null, $error);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Powered-By: PMSRAPI-v2');
            foreach ($this->headers as $name => $value) {
                header("{$name}: {$value}");
            }
        }

        if ($this->status === 204) {
            return;
        }

        $payload = ['success' => $this->success];
        if ($this->success) {
            $payload['data'] = $this->data;
        } else {
            $payload['error'] = $this->error;
        }
        if ($this->meta !== null) {
            $payload['meta'] = $this->meta;
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
