<?php

declare(strict_types=1);

namespace Satrak\Domain\Repositories;

use PDO;
use Satrak\Application\Support\Listing;

/**
 * Base de repositorios con paginación/búsqueda/orden seguros.
 *
 * El orden se valida contra una whitelist (evita inyección por ORDER BY) y la
 * búsqueda usa placeholders distintos por columna (PDO con prepares nativos no
 * permite reusar un mismo named placeholder).
 */
abstract class BaseRepository
{
    public function __construct(protected PDO $db)
    {
    }

    /**
     * @param string[]              $select      columnas/expresiones a traer
     * @param string[]              $whereSql    fragmentos WHERE ya con placeholders
     * @param array<string,mixed>   $bind        binds de los fragmentos WHERE
     * @param string[]              $searchCols  columnas sobre las que aplica `q`
     * @param array<string,string>  $sortable    alias_publico => columna_sql
     *
     * @return array{rows:array<int,array<string,mixed>>,total:int,page:int,pages:int,per_page:int,sort:string,dir:string}
     */
    protected function paginate(
        string $table,
        array $select,
        array $whereSql,
        array $bind,
        array $searchCols,
        array $sortable,
        Listing $listing,
        string $defaultSort
    ): array {
        $clauses = $whereSql;

        // Búsqueda: (col0 LIKE :s0 OR col1 LIKE :s1 ...)
        if ($listing->search !== '' && $searchCols !== []) {
            $ors = [];
            foreach (array_values($searchCols) as $i => $col) {
                $ph = ":s{$i}";
                $ors[] = "{$col} LIKE {$ph}";
                $bind[$ph] = '%' . $listing->search . '%';
            }
            $clauses[] = '(' . implode(' OR ', $ors) . ')';
        }

        $where = $clauses !== [] ? 'WHERE ' . implode(' AND ', $clauses) : '';

        // Total.
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM {$table} {$where}");
        $countStmt->execute($bind);
        $total = (int) $countStmt->fetchColumn();

        // Orden seguro.
        $sortCol = $sortable[$listing->sort] ?? $sortable[$defaultSort] ?? array_values($sortable)[0];
        $dir = $listing->dir === 'desc' ? 'DESC' : 'ASC';

        // Página.
        $perPage = $listing->perPage;
        $offset = $listing->offset();
        $cols = implode(', ', $select);

        $sql = "SELECT {$cols} FROM {$table} {$where} ORDER BY {$sortCol} {$dir} LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bind);
        $rows = $stmt->fetchAll();

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $listing->page,
            'pages'    => max(1, (int) ceil($total / $perPage)),
            'per_page' => $perPage,
            'sort'     => $listing->sort,
            'dir'      => $listing->dir,
        ];
    }
}
