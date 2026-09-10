<?php
/**
 * Base Model
 * All models extend this class.
 */
abstract class Model
{
    protected PDO $db;
    protected string $table = '';
    protected string $primaryKey = 'id';

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Find a single record by primary key.
     */
    public function find(int $id): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM `{$this->table}` WHERE `{$this->primaryKey}` = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Find a single record matching given conditions.
     *
     * @param array $conditions  ['column' => 'value', ...]
     */
    public function findWhere(array $conditions): array|false
    {
        $clauses = implode(' AND ', array_map(fn($col) => "`{$col}` = ?", array_keys($conditions)));
        $stmt = $this->db->prepare("SELECT * FROM `{$this->table}` WHERE {$clauses} LIMIT 1");
        $stmt->execute(array_values($conditions));
        return $stmt->fetch();
    }

    /**
     * Return all records from the table, with optional ordering.
     */
    public function all(string $orderBy = ''): array
    {
        $sql = "SELECT * FROM `{$this->table}`";
        if ($orderBy) {
            $sql .= " ORDER BY {$orderBy}";
        }
        return $this->db->query($sql)->fetchAll();
    }

    /**
     * Insert a new record and return its inserted ID.
     *
     * @param array $data  ['column' => 'value', ...]
     */
    public function create(array $data): int|false
    {
        $columns = implode(', ', array_map(fn($col) => "`{$col}`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $stmt = $this->db->prepare(
            "INSERT INTO `{$this->table}` ({$columns}) VALUES ({$placeholders})"
        );

        if ($stmt->execute(array_values($data))) {
            return (int) $this->db->lastInsertId();
        }
        return false;
    }

    /**
     * Update a record by primary key.
     *
     * @param int   $id
     * @param array $data  ['column' => 'value', ...]
     */
    public function update(int $id, array $data): bool
    {
        $setClause = implode(', ', array_map(fn($col) => "`{$col}` = ?", array_keys($data)));
        $values    = array_values($data);
        $values[]  = $id;

        $stmt = $this->db->prepare(
            "UPDATE `{$this->table}` SET {$setClause} WHERE `{$this->primaryKey}` = ?"
        );
        return $stmt->execute($values);
    }

    /**
     * Delete a record by primary key.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = ?"
        );
        return $stmt->execute([$id]);
    }

    /**
     * Count records in the table.
     */
    public function count(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM `{$this->table}`")->fetchColumn();
    }
}
