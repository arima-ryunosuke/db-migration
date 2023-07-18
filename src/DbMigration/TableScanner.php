<?php

namespace ryunosuke\DbMigration;

use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Generator;
use ryunosuke\DbMigration\Exception\MigrationException;

class TableScanner
{
    private Connection $conn;

    private Table $table;

    private string $quotedName;

    private string $filterCondition;

    private array $primaryKeys;

    private array $flippedPrimaryKeys;

    /** * @var Column[] */
    private array $columns;

    /** @var string[] */
    private array $ignoreColumns;

    /** @var string */
    private string $primaryKeyString;

    public function __construct(Connection $conn, Table $table, $filterCondition, array $ignoreColumn = [])
    {
        if (!$table->getPrimaryKey()) {
            throw new MigrationException("has no primary key.");
        }

        // set property from argument
        $this->conn  = $conn;
        $this->table = $table;

        // set property from property
        $this->quotedName         = $conn->quoteIdentifier($this->table->getName());
        $this->primaryKeys        = $this->table->getPrimaryKey()->getColumns();
        $this->flippedPrimaryKeys = array_flip($this->primaryKeys);
        $this->primaryKeyString   = implode(', ', $this->conn->quoteIdentifier($this->primaryKeys));

        // column to array(ColumnNanme => Column)
        $this->columns = $table->getColumns();

        // parse condition
        $this->filterCondition = $this->parseCondition((array) $filterCondition);

        // ignore columns
        $this->ignoreColumns = [];
        foreach ($ignoreColumn as $icol) {
            $modifier = $table->getName();
            if (strpos($icol, '.') !== false) {
                [$modifier, $icol] = explode('.', $icol);
            }
            if (str_replace(['`', '"', '[', ']'], '', $modifier) === $table->getName()) {
                $this->ignoreColumns[str_replace(['`', '"', '[', ']'], '', $icol)] = true;
            }
        }
    }

    public function getDeleteSql(array $pkvals): array
    {
        $sqls = [];

        $oldrows = $this->getRecordFromPrimaryKeys($pkvals);
        foreach ($oldrows as $oldrow) {
            // to comment
            $comment = $this->commentize($oldrow);

            // to WHERE string
            $wheres      = array_intersect_key($oldrow, $this->flippedPrimaryKeys);
            $whereString = $this->buildWhere($wheres);

            // to SQL
            $sqls[] = "$comment\nDELETE FROM $this->quotedName WHERE $whereString";
        }

        return $sqls;
    }

    public function getInsertSql(iterable $newrows, int $bulksize): Generator
    {
        if ($bulksize) {
            [$first, $newrows] = iterator_split($newrows, [1]);
            if (!$first) {
                return yield from [];
            }

            $columns = array_keys($first[0]);
            foreach (iterator_chunk(iterator_join([$first, $newrows]), $bulksize) as $chunk) {
                $values = [];
                foreach ($chunk as $newrow) {
                    $value = [];
                    foreach ($columns as $column) {
                        $value[] = $this->conn->quote($newrow[$column]);
                    }
                    $values[] = '(' . implode(',', $value) . ')';
                }
                yield "INSERT INTO $this->quotedName (" . implode(',', $columns) . ") VALUES \n" . implode(",\n", $values);
            }

            return;
        }

        $isMysql      = $this->conn->getDatabasePlatform() instanceof MySqlPlatform;
        $columnString = implode(', ', $this->conn->quoteIdentifier(array_keys($this->columns)));
        foreach ($newrows as $newrow) {
            if ($isMysql) {
                // to VALUES string
                $valueString = implode(', ', $this->joinKeyValue($newrow));

                // to SQL
                yield "INSERT INTO $this->quotedName SET $valueString";
            }
            else {
                // to VALUES string
                $valueString = implode(', ', $this->conn->quote($newrow));

                // to SQL
                yield "INSERT INTO $this->quotedName ($columnString) VALUES ($valueString)";
            }
        }
    }

    public function getUpdateSql(array $pkvals, array $newrows): array
    {
        $sqls = [];

        $oldrows = $this->getRecordFromPrimaryKeys($pkvals);
        foreach ($oldrows as $oldrow) {
            $id     = implode("\t", array_intersect_key($oldrow, $this->flippedPrimaryKeys));
            $newrow = $newrows[$id];

            // no diff row
            if (!($deltas = array_diff_assoc($newrow, $oldrow))) {
                continue;
            }

            // to comment
            $comments = array_intersect_key($oldrow, $deltas);
            $comment  = $this->commentize($comments);

            // to VALUES string
            $valueString = implode(", ", $this->joinKeyValue($deltas));

            // to WHERE string
            $wheres      = array_intersect_key($newrow, $this->flippedPrimaryKeys);
            $whereString = $this->buildWhere($wheres);

            // to SQL
            $sqls[] = "$comment\nUPDATE $this->quotedName SET $valueString WHERE $whereString";
        }

        return $sqls;
    }

    public function getPrimaryRows(): Generator
    {
        // fetch primary values
        $sql = "
            SELECT   {$this->primaryKeyString}
            FROM     {$this->quotedName}
            WHERE    {$this->filterCondition}
            ORDER BY {$this->primaryKeyString}
        ";

        foreach ($this->conn->queryUnbuffered($sql) as $row) {
            $id = implode("\t", $row);
            yield $id => $row;
        }
    }

    public function getAllRows(): Generator
    {
        // fetch records values
        $columnString = implode(', ', $this->conn->quoteIdentifier(array_keys(array_diff_key($this->columns, $this->ignoreColumns))));
        $sql          = "
            SELECT   {$columnString}
            FROM     {$this->quotedName}
            WHERE    {$this->filterCondition}
            ORDER BY {$this->primaryKeyString}
        ";

        foreach ($this->conn->queryUnbuffered($sql) as $row) {
            yield $this->fillDefaultValue($row);
        }
    }

    public function getOrphanRows(ForeignKeyConstraint $foreignKey): Generator
    {
        $implode = fn($glue, $array) => implode($glue, $array);
        $quote   = fn($v) => $this->conn->quoteIdentifier($v);

        $localTableName   = $this->quotedName;
        $foreignTableName = $quote($foreignKey->getForeignTableName());

        $columns        = [];
        $onCondition    = [];
        $whereCondition = [];

        foreach (array_combine($foreignKey->getLocalColumns(), $foreignKey->getForeignColumns()) as $local => $foreign) {
            $columns[]        = "{$localTableName}.{$quote($local)}";
            $onCondition[]    = "{$foreignTableName}.{$quote($foreign)} = {$localTableName}.{$quote($local)}";
            $whereCondition[] = "{$localTableName}.{$quote($local)} IS NOT NULL";
            $whereCondition[] = "{$foreignTableName}.{$quote($foreign)} IS NULL";
        }

        $sql = "
            SELECT    {$implode(',', $columns)}
            FROM      {$this->quotedName}
            LEFT JOIN {$foreignTableName} ON {$implode(' AND ', $onCondition)}
            WHERE     {$implode(' AND ', $whereCondition)}
        ";

        return $this->conn->queryUnbuffered($sql);
    }

    public function associateRecords(iterable $rows): Generator
    {
        foreach ($rows as $row) {
            $id = implode("\t", array_intersect_key($row, $this->flippedPrimaryKeys));
            foreach ($this->ignoreColumns as $ignoreColumn => $dummy) {
                if (array_key_exists($ignoreColumn, $row)) {
                    $row[$ignoreColumn] = '';
                }
            }
            yield $id => $row;
        }
    }

    public function fillDefaultValue(array $row): array
    {
        $platform = $this->conn->getDatabasePlatform();
        foreach ($this->columns as $name => $column) {
            if ($column->hasPlatformOption('generation')) {
                unset($row[$name]);
                continue;
            }
            if (!array_key_exists($name, $row)) {
                if ($column->getDefault() !== null) {
                    $row[$name] = $column->getDefault();
                }
                elseif (!$column->getNotnull()) {
                    $row[$name] = null;
                }
                else {
                    $row[$name] = $column->getType()->convertToPHPValue('', $platform);
                }
            }
        }
        return $row;
    }

    private function getRecordFromPrimaryKeys(array $tuples): Generator
    {
        // prepare sql of primary key record
        $columns      = array_diff_key($this->columns, $this->ignoreColumns);
        $columnString = implode(', ', $this->conn->quoteIdentifier(array_keys($columns)));
        $tuplesString = $this->buildWhere($tuples);
        $sql          = "
            SELECT   {$columnString}
            FROM     {$this->quotedName}
            WHERE    {$this->filterCondition} AND ($tuplesString)
            ORDER BY {$this->primaryKeyString}
        ";

        return $this->conn->executeQuery($sql)->iterateAssociative();
    }

    private function parseCondition($conds): string
    {
        $icharactor = $this->conn->getDatabasePlatform()->getIdentifierQuoteCharacter();
        $identifier = "$icharactor?([_a-z][_a-z0-9]*)$icharactor?";
        $tableName  = $this->table->getName();

        $wheres = [];
        foreach ((array) $conds as $cond) {
            if (preg_match_all("/($identifier\\.)?$identifier/i", $cond, $matches)) {
                $result = [];
                foreach ($matches[0] as $i => $dummy) {
                    $key = $matches[2][$i];
                    $val = $matches[3][$i];

                    $key = $key === '' ? $tableName : $key;

                    $result[$key][] = $val;
                }

                if (array_key_exists($tableName, $result)) {
                    foreach ($this->table->getColumns() as $columnName => $column) {
                        if (in_array($columnName, $result[$tableName])) {
                            $wheres[] = $cond;
                        }
                    }
                }
            }
        }

        if ($wheres) {
            return implode(' AND ', $wheres);
        }

        return '1';
    }

    private function commentize(array $data, int $width = 80): string
    {
        $keyvalues = $this->joinKeyValue(array_map(function ($val) use ($width) {
            if (is_string($val)) {
                return mb_strimwidth($val, 0, $width, '...');
            }
            return $val;
        }, $data), ' : ');

        $comment = implode(",\n  ", $keyvalues);
        $comment = str_replace('*/', '* /', $comment);

        return "/* current record\n  $comment\n*/";
    }

    private function joinKeyValue(array $array, string $separator = ' = '): array
    {
        $keys = $this->conn->quoteIdentifier(array_keys($array));
        $vals = $this->conn->quote(array_values($array));

        $maxlen = max(array_map('strlen', $keys));

        return array_map(function ($key, $val) use ($maxlen, $separator) {
            return $key . str_repeat(' ', $maxlen - strlen($key)) . "{$separator}{$val}";
        }, $keys, $vals);
    }

    private function buildWhere(array $whereArray): string
    {
        if (count($whereArray) === 0) {
            return "0";
        }

        if (count($whereArray, COUNT_RECURSIVE) === count($whereArray)) {
            $and = $this->joinKeyValue($whereArray);
            return '(' . implode(' AND ', $and) . ')';
        }
        else {
            $first = reset($whereArray);
            if (count($first) === 1) {
                reset($first);
                $column = key($first);
                $values = [];
                foreach ($whereArray as $where) {
                    $values[] = $this->conn->quote($where[$column]);
                }
                return '(' . $this->conn->quoteIdentifier($column) . ' IN (' . implode(',', $values) . '))';
            }
            $or = [];
            foreach ($whereArray as $values) {
                $or[] = $this->buildWhere($values);
            }
            return '(' . implode(' OR ', $or) . ')';
        }
    }
}
