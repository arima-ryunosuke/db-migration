<?php

namespace ryunosuke\DbMigration;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

class Connection extends \Doctrine\DBAL\Connection
{
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

        if ($value === null) {
            return 'NULL';
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
