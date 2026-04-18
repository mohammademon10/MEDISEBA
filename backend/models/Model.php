<?php
/**
 * MediSeba - Base Model Class
 * 
 * Abstract base class for all database models
 * Provides common CRUD operations and query building
 */

declare(strict_types=1);

namespace MediSeba\Models;

use MediSeba\Config\Database;
use PDO;
use PDOStatement;

abstract class Model
{
    protected string $table;
    protected string $primaryKey = 'id';
    protected array $fillable = [];
    protected array $hidden = [];
    protected array $casts = [];
    protected ?PDO $db = null;
    
    /**
     * Validate a column/field name to prevent SQL injection
     */
    protected function validateColumnName(string $name): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $name)) {
            throw new \InvalidArgumentException("Invalid column name: {$name}");
        }
        return $name;
    }
    
    /**
     * Validate sort direction
     */
    protected function validateDirection(string $direction): string
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            return 'ASC';
        }
        return $direction;
    }
    
    public function __construct()
    {
        $this->db = Database::getConnection();
    }
    
    /**
     * Find record by ID
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ? LIMIT 1");
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        
        return $result ? $this->processResult($result) : null;
    }
    
    /**
     * Find record by column value
     */
    public function findBy(string $column, mixed $value): ?array
    {
        $column = $this->validateColumnName($column);
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE {$column} = ? LIMIT 1");
        $stmt->execute([$value]);
        $result = $stmt->fetch();
        
        return $result ? $this->processResult($result) : null;
    }
    
    /**
     * Find multiple records by column value
     */
    public function findAllBy(string $column, mixed $value, string $orderBy = null, string $direction = 'ASC'): array
    {
        $column = $this->validateColumnName($column);
        $sql = "SELECT * FROM {$this->table} WHERE {$column} = ?";
        
        if ($orderBy) {
            $orderBy = $this->validateColumnName($orderBy);
            $direction = $this->validateDirection($direction);
            $sql .= " ORDER BY {$orderBy} {$direction}";
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$value]);
        $results = $stmt->fetchAll();
        
        return array_map([$this, 'processResult'], $results);
    }
    
    /**
     * Get all records
     */
    public function all(string $orderBy = null, string $direction = 'ASC'): array
    {
        $sql = "SELECT * FROM {$this->table}";
        
        if ($orderBy) {
            $orderBy = $this->validateColumnName($orderBy);
            $direction = $this->validateDirection($direction);
            $sql .= " ORDER BY {$orderBy} {$direction}";
        }
        
        $stmt = $this->db->query($sql);
        $results = $stmt->fetchAll();
        
        return array_map([$this, 'processResult'], $results);
    }
    
    /**
     * Create new record
     */
    public function create(array $data): int
    {
        $data = $this->filterFillable($data);
        $data = $this->castForDatabase($data);
        
        $columns = array_keys($data);
        $values = array_values($data);
        $placeholders = array_fill(0, count($columns), '?');
        
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $this->table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);
        
        return (int) $this->db->lastInsertId();
    }
    
    /**
     * Update record
     */
    public function update(int $id, array $data): bool
    {
        $data = $this->filterFillable($data);
        $data = $this->castForDatabase($data);
        
        if (empty($data)) {
            return false;
        }
        
        $setParts = [];
        $values = [];
        
        foreach ($data as $column => $value) {
            $setParts[] = "{$column} = ?";
            $values[] = $value;
        }
        
        $values[] = $id;
        
        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s = ?",
            $this->table,
            implode(', ', $setParts),
            $this->primaryKey
        );
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($values);
    }
    
    /**
     * Delete record
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?");
        return $stmt->execute([$id]);
    }
    
    /**
     * Soft delete (if supported by table)
     */
    public function softDelete(int $id, int $deletedBy = null): bool
    {
        $data = [
            'status' => 'deleted',
            'deleted_at' => date('Y-m-d H:i:s')
        ];
        if ($deletedBy !== null) {
            $data['deleted_by'] = $deletedBy;
        }
        return $this->update($id, $data);
    }
    
    /**
     * Check if record exists
     */
    public function exists(int $id): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM {$this->table} WHERE {$this->primaryKey} = ? LIMIT 1");
        $stmt->execute([$id]);
        return $stmt->fetch() !== false;
    }
    
    /**
     * Count records
     */
    public function count(string $where = null, array $params = []): int
    {
        $sql = "SELECT COUNT(*) FROM {$this->table}";
        
        if ($where) {
            $sql .= " WHERE {$where}";
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * Paginate results
     */
    public function paginate(int $page = 1, int $perPage = 20, string $orderBy = null, string $direction = 'ASC'): array
    {
        $offset = ($page - 1) * $perPage;
        
        $sql = "SELECT * FROM {$this->table}";
        $countSql = "SELECT COUNT(*) FROM {$this->table}";
        
        if ($orderBy) {
            $orderBy = $this->validateColumnName($orderBy);
            $direction = $this->validateDirection($direction);
            $sql .= " ORDER BY {$orderBy} {$direction}";
        }
        
        $sql .= " LIMIT ? OFFSET ?";
        
        // Get total count
        $total = (int) $this->db->query($countSql)->fetchColumn();
        
        // Get paginated results
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll();
        
        return [
            'items' => array_map([$this, 'processResult'], $results),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ];
    }
    
    /**
     * Execute custom query
     */
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Execute raw SQL
     */
    public function raw(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction(): bool
    {
        return Database::beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit(): bool
    {
        return Database::commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollback(): bool
    {
        return Database::rollback();
    }
    
    /**
     * Filter data to only include fillable fields
     */
    protected function filterFillable(array $data): array
    {
        if (empty($this->fillable)) {
            return $data;
        }
        
        return array_intersect_key($data, array_flip($this->fillable));
    }
    
    /**
     * Cast data types for database storage
     */
    protected function castForDatabase(array $data): array
    {
        foreach ($data as $key => $value) {
            if (isset($this->casts[$key])) {
                $data[$key] = $this->castValue($value, $this->casts[$key]);
            }
        }
        
        return $data;
    }
    
    /**
     * Cast a single value
     */
    protected function castValue(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }
        
        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => (bool) $value ? 1 : 0,
            'string' => (string) $value,
            'array' => json_encode($value),
            'json' => json_encode($value),
            'datetime' => $value instanceof \DateTime ? $value->format('Y-m-d H:i:s') : $value,
            default => $value
        };
    }
    
    /**
     * Process result from database
     */
    protected function processResult(array $result): array
    {
        // Cast values from database
        foreach ($result as $key => $value) {
            if (isset($this->casts[$key])) {
                $result[$key] = $this->castFromDatabase($value, $this->casts[$key]);
            }
        }
        
        // Remove hidden fields
        foreach ($this->hidden as $field) {
            unset($result[$field]);
        }
        
        return $result;
    }
    
    /**
     * Cast value from database
     */
    protected function castFromDatabase(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }
        
        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'string' => (string) $value,
            'array' => json_decode($value, true),
            'json' => json_decode($value, true),
            default => $value
        };
    }
    
    /**
     * Get table name
     */
    public function getTable(): string
    {
        return $this->table;
    }
    
    /**
     * Get primary key name
     */
    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }
}
