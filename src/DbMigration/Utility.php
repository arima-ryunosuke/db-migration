<?php

namespace ryunosuke\DbMigration;

use Doctrine\DBAL\Connection;

class Utility
{
    public static function quote(Connection $connection, $value)
    {
        if (is_array($value)) {
            foreach ($value as $n => $v) {
                $value[$n] = self::quote($connection, $v);
            }
            return $value;
        }

        if ($value === null) {
            return 'NULL';
        }

        return $connection->quote($value);
    }

    public static function quoteIdentifier(Connection $connection, $value)
    {
        if (is_array($value)) {
            foreach ($value as $n => $v) {
                $value[$n] = self::quoteIdentifier($connection, $v);
            }
            return $value;
        }

        return $connection->quoteIdentifier($value);
    }

    public static function filterTable(string $tablename, array $includes, array $excludes): int
    {
        // filter from includes
        $flag = count($includes) > 0;
        foreach ($includes as $include) {
            if (preg_match("@$include@i", $tablename)) {
                $flag = false;
                break;
            }
        }
        if ($flag) {
            return 1;
        }

        // filter from excludes
        foreach ($excludes as $exclude) {
            if (preg_match("@$exclude@i", $tablename)) {
                return 2;
            }
        }

        return 0;
    }

    public static function array_diff_exists(array $array1, array $array2): array
    {
        foreach ($array1 as $key => $val) {
            if (array_key_exists($key, $array2) && $val === $array2[$key]) {
                unset($array1[$key]);
            }
        }

        return $array1;
    }
}
