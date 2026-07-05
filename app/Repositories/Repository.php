<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\Model;

abstract class Repository
{
    public function __construct(protected Database $db, protected string $modelClass)
    {
    }

    public function find(int $id): ?Model
    {
        $table = $this->modelClass::table();
        $pk = $this->modelClass::primaryKey();
        $row = $this->db->fetch("SELECT * FROM {$table} WHERE {$pk} = ?", [$id]);
        return $row ? new $this->modelClass($row) : null;
    }

    public function all(string $orderBy = 'id DESC', int $limit = 100): array
    {
        $table = $this->modelClass::table();
        $rows = $this->db->fetchAll("SELECT * FROM {$table} ORDER BY {$orderBy} LIMIT {$limit}");
        return array_map(fn(array $row) => new $this->modelClass($row), $rows);
    }
}
