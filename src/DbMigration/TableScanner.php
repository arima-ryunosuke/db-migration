<?php

namespace ryunosuke\DbMigration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Schema\Table;
use ryunosuke\DbMigration\Exception\MigrationException;

class TableScanner
{
    /** @var int count of 1 page fetching */
    public static $pageCount = 10000;

    /** @var \Doctrine\DBAL\Connection */
    private $conn;

    /** @var \Doctrine\DBAL\Schema\Table */
    private $table;

    /** @var string */
    private $quotedName;

    /** @var string */
    private $filterCondition;

    /** @var array */
    private $primaryKeys;

    /** @var array */
    private $flippedPrimaryKeys;

    /** * @var \Doctrine\DBAL\Schema\Column[] */
    private $columns;

    /** @var string[] */
    private $ignoreColumns;

    /** @var string */
    private $primaryKeyString;

    /**
     * constructor
     *
     * @param Connection $conn
     * @param Table $table
     * @param array $filterCondition
     * @param array $ignoreColumn
     */
    public function __construct(Connection $conn, Table $table, $filterCondition, $ignoreColumn = [])
    {
        if (!$table->hasPrimaryKey()) {
            throw new MigrationException("has no primary key.");
        }

        $ichar = $conn->getDatabasePlatform()->getIdentifierQuoteCharacter();

        // set property from argument
        $this->conn = $conn;
        $this->table = $table;

        // set property from property
        $this->quotedName = $conn->quoteIdentifier($this->table->getName());
        $this->primaryKeys = $this->table->getPrimaryKeyColumns();
        $this->flippedPrimaryKeys = array_flip($this->primaryKeys);
        $this->primaryKeyString = implode(', ', Utility::quoteIdentifier($this->conn, $this->primaryKeys));

        // column to array(ColumnNanme => Column)
        $this->columns = $table->getColumns();

        // parse condition
        $this->filterCondition = $this->parseCondition($filterCondition, $ichar);

        // ignore columns
        $this->ignoreColumns = [];
        foreach ($ignoreColumn as $icol) {
            $modifier = $table->getName();
            if (strpos($icol, '.') !== false) {
                list($modifier, $icol) = explode('.', $icol);
            }
            if (str_replace(['`', '"', '[', ']'], '', $modifier) === $table->getName()) {
                $this->ignoreColumns[str_replace(['`', '"', '[', ']'], '', $icol)] = true;
            }
        }
    }

    public function switchBufferedQuery($flag)
    {
        $pdo = $this->conn->getWrappedConnection();
        $bufferedSupport = $pdo instanceof \PDO && $this->conn->getDatabasePlatform() instanceof MySqlPlatform;
        $return = null;

        if ($bufferedSupport) {
            $return = $pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            $pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $flag);
        }

        return $return;
    }

    /**
     * compare another instance
     *
     * @param TableScanner $that
     * @return boolean
     */
    public function equals(TableScanner $that)
    {
        // compare primary key name
        if ($this->primaryKeyString !== $that->primaryKeyString) {
            return false;
        }

        // compare column name
        if (array_fill_keys(array_keys($this->columns), '') != array_fill_keys(array_keys($that->columns), '')) {
            return false;
        }

        // compare column attribute
        foreach ($this->columns as $name => $thisColumn) {
            $thatColumn = $that->columns[$name];

            if (strval($thisColumn->getType()) !== strval($thatColumn->getType())) {
                return false;
            }
        }

        return true;
    }

    /**
     * get DELETE sql from primary tuples
     *
     * @param array $tuples
     * @param TableScanner $that
     * @return array
     */
    public function getDeleteSql(array $tuples, TableScanner $that)
    {
        $sqls = [];
        for ($page = 0; true; $page++) {
            $oldrows = $that->getRecordFromPrimaryKeys($tuples, true, $page);

            // loop for limited rows
            $count = count($sqls);
            while (($oldrow = $oldrows->fetch()) !== false) {
                // to comment
                $comment = $this->commentize($oldrow);

                // to WHERE string
                $wheres = array_intersect_key($oldrow, $this->flippedPrimaryKeys);
                $whereString = $this->buildWhere($wheres);

                // to SQL
                $sqls[] = "$comment\nDELETE FROM $this->quotedName WHERE $whereString";
            }

            // no more rows
            if ($count === count($sqls)) {
                break;
            }
        }

        return $sqls;
    }

    /**
     * get INSERT sql from primary tuples
     *
     * @param array $tuples
     * @param TableScanner $that
     * @return array
     */
    public function getInsertSql(array $tuples, TableScanner $that)
    {
        $isMysql = $this->conn->getDatabasePlatform() instanceof MySqlPlatform;
        $sqls = [];
        $columnString = implode(', ', Utility::quoteIdentifier($this->conn, array_keys($this->columns)));
        for ($page = 0; true; $page++) {
            $newrows = $that->getRecordFromPrimaryKeys($tuples, false, $page);

            // loop for limited rows
            $count = count($sqls);
            while (($newrow = $newrows->fetch()) !== false) {
                if ($isMysql) {
                    // to VALUES string
                    $valueString = implode(', ', $this->joinKeyValue($newrow));

                    // to SQL
                    $sqls[] = "INSERT INTO $this->quotedName SET $valueString";
                }
                else {
                    // to VALUES string
                    $valueString = implode(', ', Utility::quote($this->conn, $newrow));

                    // to SQL
                    $sqls[] = "INSERT INTO $this->quotedName ($columnString) VALUES ($valueString)";
                }
            }

            // no more rows
            if ($count === count($sqls)) {
                break;
            }
        }

        return $sqls;
    }

    /**
     * get UPDATE sql from primary tuples
     *
     * @param array $tuples
     * @param TableScanner $that
     * @return array
     */
    public function getUpdateSql(array $tuples, TableScanner $that)
    {
        $sqls = [];
        for ($page = 0; true; $page++) {
            $oldrows = $this->getRecordFromPrimaryKeys($tuples, true, $page);
            $newrows = $that->getRecordFromPrimaryKeys($tuples, true, $page);

            // loop for limited rows
            $count = count($sqls);
            while (($oldrow = $oldrows->fetch()) !== false && ($newrow = $newrows->fetch()) !== false) {
                // no diff row
                if (!($deltas = array_diff_assoc($newrow, $oldrow))) {
                    continue;
                }

                // to comment
                $comments = array_intersect_key($oldrow, $deltas);
                $comment = $this->commentize($comments);

                // to VALUES string
                $valueString = implode(", ", $this->joinKeyValue($deltas));

                // to WHERE string
                $wheres = array_intersect_key($newrow, $this->flippedPrimaryKeys);
                $whereString = $this->buildWhere($wheres);

                // to SQL
                $sqls[] = "$comment\nUPDATE $this->quotedName SET $valueString WHERE $whereString";
            }

            // no more rows
            if ($count === count($sqls)) {
                break;
            }
        }

        return $sqls;
    }

    /**
     * get primary key tuples
     *
     * @return array
     */
    public function getPrimaryRows()
    {
        // fetch primary values
        $sql = "
            SELECT   {$this->primaryKeyString}
            FROM     {$this->quotedName}
            WHERE    {$this->filterCondition}
            ORDER BY {$this->primaryKeyString}
        ";

        $result = [];
        foreach ($this->conn->query($sql) as $row) {
            $id = implode("\t", $row);
            $result[$id] = $row;
        }

        return $result;
    }

    /**
     * get all rows
     *
     * @return \Doctrine\DBAL\Driver\Statement
     */
    public function getAllRows()
    {
        // fetch records values
        $columnString = implode(', ', Utility::quoteIdentifier($this->conn, array_keys(array_diff_key($this->columns, $this->ignoreColumns))));
        $sql = "
            SELECT   {$columnString}
            FROM     {$this->quotedName}
            WHERE    {$this->filterCondition}
            ORDER BY {$this->primaryKeyString}
        ";

        return $this->conn->query($sql);
    }

    public function fillDefaultValue($row)
    {
        $platform = $this->conn->getDatabasePlatform();
        foreach ($this->columns as $name => $column) {
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

    /**
     * get record from primary key tuples
     *
     * @param array $tuples
     * @param bool $ignore
     * @param int $page
     * @return \Doctrine\DBAL\Driver\Statement
     */
    private function getRecordFromPrimaryKeys(array $tuples, $ignore, $page = null)
    {
        $stuples = $tuples;
        if ($page !== null) {
            $stuples = array_slice($tuples, intval($page) * self::$pageCount, self::$pageCount);
        }

        // prepare sql of primary key record
        $columns = $ignore ? array_diff_key($this->columns, $this->ignoreColumns) : $this->columns;
        $columnString = implode(', ', Utility::quoteIdentifier($this->conn, array_keys($columns)));
        $tuplesString = $this->buildWhere($stuples);
        $sql = "
            SELECT   {$columnString}
            FROM     {$this->quotedName}
            WHERE    {$this->filterCondition} AND ($tuplesString)
            ORDER BY {$this->primaryKeyString}
        ";

        return $this->conn->query($sql);
    }

    private function parseCondition($conds, $icharactor)
    {
        $identifier = "$icharactor?([_a-z][_a-z0-9]*)$icharactor?";
        $tableName = $this->table->getName();

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

    /**
     * array to comment string
     *
     * @param array $data
     * @param int $width
     * @return string
     */
    private function commentize(array $data, $width = 80)
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

    /**
     * join key and value of array
     *
     * @param array $array
     * @param string $separator
     * @return array
     */
    private function joinKeyValue(array $array, $separator = ' = ')
    {
        $keys = Utility::quoteIdentifier($this->conn, array_keys($array));
        $vals = Utility::quote($this->conn, array_values($array));

        $maxlen = max(array_map('strlen', $keys));

        return array_map(function ($key, $val) use ($maxlen, $separator) {
            return $key . str_repeat(' ', $maxlen - strlen($key)) . "{$separator}{$val}";
        }, $keys, $vals);
    }

    /**
     * build quoted where string from array
     *
     * @param array $whereArray
     * @return string
     */
    private function buildWhere(array $whereArray)
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
