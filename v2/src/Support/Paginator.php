<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Support;

use Pmsrapi\V2\Http\Request;

/**
 * Offset pagination value object. Reads ?page & ?per_page from the request,
 * clamps them to safe bounds, and exposes limit/offset for SQL plus a meta
 * block for the response envelope.
 */
readonly class Paginator
{
    private const MAX_PER_PAGE = 200;

    public function __construct(
        public int $page,
        public int $perPage,
    ) {}

    public static function fromRequest(Request $request, int $defaultPerPage = 20): self
    {
        $page = max(1, $request->queryInt('page', 1));
        $perPage = $request->queryInt('per_page', $defaultPerPage);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        return new self($page, $perPage);
    }

    public function limit(): int
    {
        return $this->perPage;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * @return array{page: int, per_page: int, total: int, total_pages: int}
     */
    public function meta(int $total): array
    {
        return [
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $total,
            'total_pages' => $total === 0 ? 0 : (int) ceil($total / $this->perPage),
        ];
    }
}
