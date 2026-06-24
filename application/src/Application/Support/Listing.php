<?php

declare(strict_types=1);

namespace Satrak\Application\Support;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Parámetros de listado (búsqueda + orden + paginación) tomados del query string.
 * Inmutable y saneado: el orden se valida contra una whitelist en el repositorio.
 */
final class Listing
{
    public function __construct(
        public readonly string $search,
        public readonly string $sort,
        public readonly string $dir,
        public readonly int $page,
        public readonly int $perPage
    ) {
    }

    public static function fromRequest(Request $request, string $defaultSort = 'id', int $perPage = 15): self
    {
        $q = $request->getQueryParams();
        $search = trim((string) ($q['q'] ?? ''));
        $sort = (string) ($q['sort'] ?? $defaultSort);
        $dir = strtolower((string) ($q['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $page = max(1, (int) ($q['page'] ?? 1));

        return new self($search, $sort, $dir, $page, $perPage);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
