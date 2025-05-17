<?php

namespace ryunosuke\DbMigration;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Generator;
use mysqli;
use PDO;
use ryunosuke\DbMigration\FileType\Tool\Binary;

class Connection extends \Doctrine\DBAL\Connection
{
    private bool $maintainType = false;

    private int $throughTransactionLevel = 0;

    public function lockGlobal(string $lockname, float $locktime): bool
    {
        if ($this->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            if (!$this->executeQuery('SELECT GET_LOCK(?, ?)', [$lockname, $locktime])->fetchOne()) {
                return false;
            }
        }

        return true;
    }

    public function disableConstraint(bool $disabled): bool
    {
        if ($this->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->executeStatement('SET UNIQUE_CHECKS = ' . intval(!$disabled));
            $this->executeStatement('SET FOREIGN_KEY_CHECKS = ' . intval(!$disabled));
        }

        return !$disabled;
    }

    public function maintainType(bool $maintain): bool
    {
        $connection = $this->getNativeConnection();

        if ($connection instanceof PDO) {
            $connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, !$maintain);
            $connection->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, !$maintain);
        }
        elseif ($connection instanceof mysqli) {
            $connection->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, $maintain);
        }

        $return             = $this->maintainType;
        $this->maintainType = $maintain;
        return $return;
    }

    public function queryUnbuffered(string $sql): Generator
    {
        $platform   = $this->getDatabasePlatform();
        $connection = $this->getNativeConnection();

        if ($platform instanceof AbstractMySQLPlatform) {
            if ($connection instanceof PDO) {
                $connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                try {
                    yield from $this->executeQuery($sql)->iterateAssociative();
                }
                finally {
                    $connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                }
            }
            elseif ($connection instanceof mysqli) {
                yield from $connection->query($sql, MYSQLI_USE_RESULT);
            }
        }
        else {
            yield from $this->executeQuery($sql)->iterateAssociative();
        }
    }

    public function beginIfNeeded(int $targetLevel = 0): bool
    {
        $throughLevel = ++$this->throughTransactionLevel;

        if ($throughLevel === $targetLevel) {
            parent::beginTransaction();
            return true;
        }

        return false;
    }

    public function commitIfNeeded(int $targetLevel = 0): bool
    {
        $throughLevel = $this->throughTransactionLevel--;

        if ($throughLevel === $targetLevel) {
            parent::commit();
            return true;
        }

        return false;
    }

    public function rollbackIfNeeded(int $targetLevel = 0): bool
    {
        $throughLevel = $this->throughTransactionLevel--;

        if ($throughLevel === $targetLevel) {
            parent::rollBack();
            return true;
        }

        return false;
    }

    public function insert(string $table, array $data, array $types = []): int|string
    {
        if (count($data) === 0) {
            return parent::insert($table, $data, $types);
        }

        $implode = fn($v) => implode(',', $v);
        return $this->executeStatement("INSERT INTO $table ({$implode(array_keys($data))}) VALUES ({$implode($this->quoteValues($data))})");
    }

    public function upsert($table, array $data, array $types = [])
    {
        if (count($data) === 0) {
            return parent::insert($table, $data, $types);
        }

        static $tableObjects = [];
        $tableObjects[$table] ??= $this->createSchemaManager()->introspectTable($table);

        $cols = array_keys($data);

        $implode = fn($v) => implode(',', $v);
        $sql     = "INSERT INTO $table ({$implode($cols)}) VALUES ({$implode($this->quoteValues($data))})";

        if ($this->getDatabasePlatform() instanceof MySQLPlatform) {
            $sql .= " ON DUPLICATE KEY UPDATE {$implode(array_map(fn($c) => "$c = VALUES($c)", $cols))}";
        }
        else {
            $pkcol = $tableObjects[$table]->getPrimaryKey()->getColumns();
            $sql   .= " ON CONFLICT({$implode($pkcol)}) DO UPDATE SET {$implode(array_map(fn($c) => "$c = EXCLUDED.$c", $cols))}";
        }

        return $this->executeStatement($sql);
    }

    public function quoteValues(mixed $value): int|float|string|array
    {
        if (is_array($value)) {
            foreach ($value as $n => $v) {
                $value[$n] = $this->quoteValues($v);
            }
            return $value;
        }

        if ($value === null) {
            return 'NULL';
        }

        if ($this->maintainType) {
            if ($value === false) {
                return 'FALSE';
            }
            if ($value === true) {
                return 'TRUE';
            }

            if (is_int($value) || is_float($value)) {
                return $value;
            }
        }

        if ($value instanceof Binary) {
            if ($this->getDatabasePlatform() instanceof MySQLPlatform) {
                $value = "0{$value->xstring()}";
            }
            else {
                $value = "E'\\\\{$value->xstring()}'";
            }
            return $value;
        }

        return parent::quote($value);
    }

    public function quoteIdentifiers(string|array $identifier): string|array
    {
        if (is_array($identifier)) {
            foreach ($identifier as $n => $v) {
                $identifier[$n] = $this->quoteIdentifiers($v);
            }
            return $identifier;
        }

        return parent::quoteIdentifier($identifier);
    }
}
