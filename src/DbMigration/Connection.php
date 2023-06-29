<?php

namespace ryunosuke\DbMigration;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Generator;
use mysqli;
use PDO;

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

    public function beginTransaction(int $targetLevel = 0)
    {
        $throughLevel = ++$this->throughTransactionLevel;

        if ($throughLevel === $targetLevel) {
            parent::beginTransaction();
            return true;
        }

        return false;
    }

    public function commit(int $targetLevel = 0)
    {
        $throughLevel = $this->throughTransactionLevel--;

        if ($throughLevel === $targetLevel) {
            parent::commit();
            return true;
        }

        return false;
    }

    public function rollBack(int $targetLevel = 0)
    {
        $throughLevel = $this->throughTransactionLevel--;

        if ($throughLevel === $targetLevel) {
            parent::rollBack();
            return true;
        }

        return false;
    }

    /**
     * @param mixed $value
     */
    public function quote($value, $type = ParameterType::STRING)
    {
        if (is_array($value)) {
            foreach ($value as $n => $v) {
                $value[$n] = $this->quote($v);
            }
            return $value;
        }

        // for compatible
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

        return parent::quote($value);
    }

    /**
     * @param mixed $str
     */
    public function quoteIdentifier($str)
    {
        if (is_array($str)) {
            foreach ($str as $n => $v) {
                $str[$n] = $this->quoteIdentifier($v);
            }
            return $str;
        }

        return parent::quoteIdentifier($str);
    }
}
