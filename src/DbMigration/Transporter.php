<?php

namespace ryunosuke\DbMigration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\Trigger;
use ryunosuke\DbMigration\Exception\MigrationException;
use Symfony\Component\Yaml\Yaml;

class Transporter
{
    /** @var Connection */
    private $connection;

    /** @var AbstractPlatform */
    private $platform;

    /** @var bool */
    private $viewEnabled = true;

    /** @var array */
    private $bulkmode = false;

    /** @var array */
    private $encodings = [];

    /** @var array */
    private $directories = [
        'table' => null,
        'view'  => null,
    ];

    /** @var array */
    private $ymlOptions = [
        'inline' => 4,
        'indent' => 4,
    ];

    /** @var array */
    private $defaultColumnAttributes = [
        'length'           => null,
        'precision'        => 10,
        'scale'            => 0,
        'fixed'            => false,
        'autoincrement'    => false,
        'columnDefinition' => null,
    ];

    /** @var array */
    private $defaultIndexAttributes = [
        'primary' => false,
        'flag'    => [],
        'option'  => [],
    ];

    /** @var array */
    private $ignoreColumnOptionAttributes = [
        // for ryunosuke/dbal
        'beforeColumn',
    ];

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->platform = $connection->getDatabasePlatform();
    }

    public function enableView($enabled)
    {
        $this->viewEnabled = $enabled;
    }

    public function setBulkMode($bulkmode)
    {
        $this->bulkmode = $bulkmode;
    }

    public function setEncoding($ext, $encoding)
    {
        $this->encodings[$ext] = $encoding;
    }

    public function setDirectory($objectname, $directory)
    {
        $this->directories[$objectname] = $directory;
    }

    public function setYmlOption($option, $value)
    {
        $this->ymlOptions[$option] = $value;
    }

    public function exportDDL($filename, $includes = [], $excludes = [])
    {
        $schema = Utility::getSchema($this->connection);
        $pathinfo = $this->parseFilename($filename);

        // SQL is special
        if ($pathinfo['extension'] === 'sql') {
            $creates = $alters = $views = [];
            foreach ($schema->getTables() as $table) {
                if (Utility::filterTable($table->getName(), $includes, $excludes) > 0) {
                    continue;
                }
                $sqls = $this->platform->getCreateTableSQL($table, AbstractPlatform::CREATE_INDEXES | AbstractPlatform::CREATE_FOREIGNKEYS | AbstractPlatform::CREATE_TRIGGERS);
                $creates[] = \SqlFormatter::format(array_shift($sqls), false);
                $alters = array_merge($alters, $sqls);
            }
            if ($this->viewEnabled) {
                foreach ($schema->getViews() as $view) {
                    if (Utility::filterTable($view->getName(), $includes, $excludes) > 0) {
                        continue;
                    }
                    $sql = $this->platform->getCreateViewSQL($view->getName(), $view->getSql());
                    $views[] = \SqlFormatter::format($sql, false);
                }
            }
            $content = implode(";\n", array_merge($creates, $alters, $views)) . ";\n";
        }
        else {
            // generate schema array
            $schemaArray = [
                'platform' => $this->platform->getName(),
                'table'    => [],
                'view'     => [],
            ];
            foreach ($schema->getTables() as $table) {
                if (Utility::filterTable($table->getName(), $includes, $excludes) > 0) {
                    continue;
                }
                if ($this->directories['table']) {
                    $fname = $this->directories['table'] . '/' . $table->getName() . '.' . $pathinfo['extension'];
                    $tarray = new Exportion($pathinfo['dirname'], $fname, $this->tableToArray($table));
                }
                else {
                    $tarray = $this->tableToArray($table);
                }
                $schemaArray['table'][$table->getName()] = $tarray;
            }
            if ($this->viewEnabled) {
                foreach ($schema->getViews() as $view) {
                    if (Utility::filterTable($view->getName(), $includes, $excludes) > 0) {
                        continue;
                    }
                    if ($this->directories['view']) {
                        $fname = $this->directories['view'] . '/' . $view->getName() . '.' . $pathinfo['extension'];
                        $varray = new Exportion($pathinfo['dirname'], $fname, ['sql' => $view->getSql()]);
                    }
                    else {
                        $varray = ['sql' => $view->getSql()];
                    }
                    $schemaArray['view'][$view->getName()] = $varray;
                }
            }

            // by Data Description Language
            switch ($pathinfo['extension']) {
                default:
                    throw new \DomainException("'{$pathinfo['extension']}' is not supported.");
                case 'php':
                    if ($this->directories['table']) {
                        $schemaArray['table'] = array_map(function (Exportion $exportion) {
                            return $exportion->setProvider(function ($data) { return "<?php return " . Utility::var_export($data) . ";\n"; });
                        }, $schemaArray['table']);
                    }
                    if ($this->directories['view']) {
                        $schemaArray['view'] = array_map(function (Exportion $exportion) {
                            return $exportion->setProvider(function ($data) { return "<?php return " . Utility::var_export($data) . ";\n"; });
                        }, $schemaArray['view']);
                    }
                    $content = "<?php return " . Utility::var_export($schemaArray) . ";\n";
                    break;
                case 'json':
                    if ($this->directories['table']) {
                        $schemaArray['table'] = array_map(function (Exportion $exportion) {
                            return $exportion->setProvider(function ($data) { return Utility::json_encode($data); });
                        }, $schemaArray['table']);
                    }
                    if ($this->directories['view']) {
                        $schemaArray['view'] = array_map(function (Exportion $exportion) {
                            return $exportion->setProvider(function ($data) { return Utility::json_encode($data); });
                        }, $schemaArray['view']);
                    }
                    $content = Utility::json_encode($schemaArray) . "\n";
                    break;
                case 'yml':
                case 'yaml':
                    $options = $this->ymlOptions;
                    if ($this->directories['table']) {
                        $schemaArray['table'] = array_map(function (Exportion $exportion) {
                            return $exportion->setProvider(function ($data) { return Utility::yaml_emit($data, ['builtin' => true]); });
                        }, $schemaArray['table']);
                    }
                    if ($this->directories['view']) {
                        $schemaArray['view'] = array_map(function (Exportion $exportion) {
                            return $exportion->setProvider(function ($data) { return Utility::yaml_emit($data, ['builtin' => true]); });
                        }, $schemaArray['view']);
                    }
                    if ($this->directories['table'] || $this->directories['view']) {
                        $options['callback'][Exportion::class] = function (Exportion $exportion) {
                            return [
                                'tag'  => '!include',
                                'data' => $exportion->export(),
                            ];
                        };
                    }
                    $content = Utility::yaml_emit($schemaArray, $options);
                    break;
            }
        }

        Utility::file_put_contents($filename, $content);
        return $content;
    }

    public function exportDML($filename, $filterCondition = [], $ignoreColumn = [])
    {
        $pathinfo = $this->parseFilename($filename);

        // create TableScanner
        $tablename = $pathinfo['filename'];
        $table = Utility::getSchema($this->connection)->getTable($tablename);
        $scanner = new TableScanner($this->connection, $table, $filterCondition, $ignoreColumn);

        // for too many records
        $current = $scanner->switchBufferedQuery(false);

        switch ($pathinfo['extension']) {
            default:
                throw new \DomainException("'{$pathinfo['extension']}' is not supported.");
            case 'sql':
                $qtable = Utility::quoteIdentifier($this->connection, $tablename);
                $result = [];
                foreach ($scanner->getAllRows() as $row) {
                    $row = $scanner->fillDefaultValue($row);
                    $columns = implode(', ', Utility::quoteIdentifier($this->connection, array_keys($row)));
                    $values = implode(', ', Utility::quote($this->connection, $row));
                    $result[] = "INSERT INTO $qtable ($columns) VALUES ($values);";
                }
                $result = implode("\n", $result) . "\n";
                break;
            case 'php':
                // through if callback
                if (file_exists($filename) && (require $filename) instanceof \Closure) {
                    return "'$filename' is skipped.";
                }
                $result = [];
                foreach ($scanner->getAllRows() as $row) {
                    $result[] = Utility::var_export($scanner->fillDefaultValue($row), 1);
                }
                $result = "<?php return [\n    " . implode(",\n    ", $result) . "\n];\n";
                break;
            case 'json':
                $result = [];
                foreach ($scanner->getAllRows() as $row) {
                    $result[] = preg_replace('#(\A\\[\R)|(\R]\z)#', '', Utility::json_encode([$scanner->fillDefaultValue($row)]));
                }
                $result = "[\n" . implode(",\n", $result) . "\n]\n";
                break;
            case 'yml':
            case 'yaml':
                $option = $this->ymlOptions;
                $option['inline'] = 999;
                $result = [];
                foreach ($scanner->getAllRows() as $row) {
                    $result[] = Utility::yaml_emit([$scanner->fillDefaultValue($row)], $option);
                }
                $result = implode("", $result);
                break;
            case 'csv':
                $handle = fopen('php://temp', "w");
                $first = true;
                foreach ($scanner->getAllRows() as $row) {
                    // first row is used as CSV header
                    if ($first) {
                        $first = false;
                        fputcsv($handle, array_keys($row));
                    }
                    fputcsv($handle, $row);
                }
                rewind($handle);
                $result = stream_get_contents($handle);
                fclose($handle);
                break;
        }

        // restore
        $scanner->switchBufferedQuery($current);

        Utility::mb_convert_variables($pathinfo['encoding'], mb_internal_encoding(), $result);
        Utility::file_put_contents($filename, $result);
        return $result;
    }

    public function importDDL($filename, $includes = [], $excludes = [])
    {
        $pathinfo = $this->parseFilename($filename);

        switch ($pathinfo['extension']) {
            default:
                throw new \DomainException("'{$pathinfo['extension']}' is not supported.");
            case 'sql':
                if ($includes || $excludes) {
                    throw new \DomainException('sql is not supported include, exclude option.');
                }
                $contents = file_get_contents($filename);
                $this->connection->exec($contents);
                return $this->explodeSql($contents);
            case 'php':
                $schemaArray = require $filename;
                break;
            case 'json':
                $options = [];
                if ($this->directories['table'] || $this->directories['view']) {
                    $dirname = $pathinfo['dirname'];
                    $options['callback'] = [
                        '!include' => function ($value) use ($dirname) {
                            return Utility::json_decode(file_get_contents("$dirname/$value"));
                        },
                    ];
                }
                $schemaArray = Utility::json_decode(file_get_contents($filename), $options);
                break;
            case 'yml':
            case 'yaml':
                $options = $this->ymlOptions;
                if ($this->directories['table'] || $this->directories['view']) {
                    $dirname = $pathinfo['dirname'];
                    $options['callback'] = [
                        '!include' => function ($value) use ($dirname) {
                            return Utility::yaml_parse(file_get_contents("$dirname/$value"), ['builtin' => true]);
                        },
                    ];
                }
                $schemaArray = Utility::yaml_parse(file_get_contents($filename), $options);
                break;
        }

        if (isset($schemaArray['platform']) && $schemaArray['platform']) {
            if ($schemaArray['platform'] !== $this->platform->getName()) {
                throw new \RuntimeException('platform is different.');
            }
        }

        $creates = $alters = $views = [];
        foreach ($schemaArray['table'] as $name => $tarray) {
            if (Utility::filterTable($name, $includes, $excludes) > 0) {
                continue;
            }
            $table = $this->tableFromArray($name, $tarray);
            $sqls = $this->platform->getCreateTableSQL($table, AbstractPlatform::CREATE_INDEXES | AbstractPlatform::CREATE_FOREIGNKEYS | AbstractPlatform::CREATE_TRIGGERS);
            $creates[] = array_shift($sqls);
            $alters = array_merge($alters, $sqls);
        }
        if ($this->viewEnabled) {
            foreach ($schemaArray['view'] as $name => $varray) {
                if (Utility::filterTable($name, $includes, $excludes) > 0) {
                    continue;
                }
                $views[] = $this->platform->getCreateViewSQL($name, $varray['sql']);
            }
        }

        $sqls = array_merge($creates, $alters, $views);
        foreach ($sqls as $sql) {
            $this->connection->exec($sql);
        }

        return $sqls;
    }

    public function importDML($filename)
    {
        $pathinfo = $this->parseFilename($filename);
        $to_encoding = mb_internal_encoding();

        switch ($pathinfo['extension']) {
            default:
                throw new \DomainException("'{$pathinfo['extension']}' is not supported.");
            case 'sql':
                $contents = file_get_contents($filename);
                Utility::mb_convert_variables($to_encoding, $pathinfo['encoding'], $contents);
                $this->connection->exec($contents);
                return $this->explodeSql($contents);
            case 'php':
                $rows = require $filename;
                if ($rows instanceof \Closure) {
                    $rows = $rows($this->connection);
                }
                Utility::mb_convert_variables($to_encoding, $pathinfo['encoding'], $rows);
                break;
            case 'json':
                $contents = file_get_contents($filename);
                Utility::mb_convert_variables($to_encoding, $pathinfo['encoding'], $contents);
                $rows = json_decode($contents, true);
                break;
            case 'yml':
            case 'yaml':
                $contents = file_get_contents($filename);
                Utility::mb_convert_variables($to_encoding, $pathinfo['encoding'], $contents);
                $rows = Yaml::parse($contents);
                break;
            case 'csv':
                $rows = [];
                $header = [];
                if (($handle = fopen($filename, "r")) !== false) {
                    while (($line = fgets($handle)) !== false) {
                        Utility::mb_convert_variables($to_encoding, $pathinfo['encoding'], $line);
                        $data = str_getcsv($line);
                        // first row is used as CSV header
                        if (!$header) {
                            $header = $data;
                        }
                        else {
                            $rows[] = array_combine($header, $data);
                        }
                    }
                    fclose($handle);
                }
                break;
        }

        $this->insert($pathinfo['filename'], $rows);

        return $rows;
    }

    public function migrateDDL(Connection $target, $includes = [], $excludes = [])
    {
        $oldSchema = Utility::getSchema($this->connection);
        $newSchema = Utility::getSchema($target);
        $diff = Comparator::compareSchemas($oldSchema, $newSchema);

        foreach ($diff->newTables as $name => $table) {
            $filterdResult = Utility::filterTable($name, $includes, $excludes);
            if ($filterdResult > 0) {
                unset($diff->newTables[$name]);
            }
        }

        foreach ($diff->changedTables as $name => $table) {
            $filterdResult = Utility::filterTable($name, $includes, $excludes);
            if ($filterdResult > 0) {
                unset($diff->changedTables[$name]);
            }
        }

        foreach ($diff->removedTables as $name => $table) {
            $filterdResult = Utility::filterTable($name, $includes, $excludes);
            if ($filterdResult > 0) {
                unset($diff->removedTables[$name]);
            }
        }

        if (!$this->viewEnabled) {
            $diff->newViews = [];
            $diff->changedViews = [];
            $diff->removedViews = [];
        }

        return $diff->toSql($this->connection->getDatabasePlatform());
    }

    public function migrateDML(Connection $target, $table, array $wheres = [], array $ignores = [], $dmltypes = [])
    {
        // result dmls
        $dmls = [];

        // scanner objects
        $oldSchema = Utility::getSchema($this->connection);
        $newSchema = Utility::getSchema($target);
        $oldScanner = new TableScanner($this->connection, $oldSchema->getTable($table), $wheres, $ignores);
        $newScanner = new TableScanner($target, $newSchema->getTable($table), $wheres, $ignores);

        // check different column definitation
        if (!$oldScanner->equals($newScanner)) {
            throw new MigrationException("has different definition between schema.");
        }

        // primary key tuples
        $oldTuples = $oldScanner->getPrimaryRows();
        $newTuples = $newScanner->getPrimaryRows();

        $defaulttypes = [
            'insert' => true,
            'delete' => true,
            'update' => true,
        ];
        $dmltypes += $defaulttypes;

        // DELETE if old only
        if ($dmltypes['delete'] && $tuples = array_diff_key($oldTuples, $newTuples)) {
            $dmls = array_merge($dmls, $oldScanner->getDeleteSql($tuples, $oldScanner));
        }

        // UPDATE if common
        if ($dmltypes['update'] && $tuples = array_intersect_key($oldTuples, $newTuples)) {
            $dmls = array_merge($dmls, $oldScanner->getUpdateSql($tuples, $newScanner));
        }

        // INSERT if new only
        if ($dmltypes['insert'] && $tuples = array_diff_key($newTuples, $oldTuples)) {
            $dmls = array_merge($dmls, $oldScanner->getInsertSql($tuples, $newScanner));
        }

        return $dmls;
    }

    private function insert($tablename, $rows)
    {
        if (!$rows) {
            return 0;
        }

        $qtable = Utility::quoteIdentifier($this->connection, $tablename);

        if ($this->bulkmode) {
            $columns = array_keys(reset($rows));
            $values = [];
            foreach ($rows as $row) {
                $value = [];
                foreach ($columns as $column) {
                    $value[] = Utility::quote($this->connection, $row[$column]);
                }
                $values[] = '(' . implode(',', $value) . ')';
            }
            $sql = "INSERT INTO $qtable " . " (" . implode(',', $columns) . ") VALUES " . implode(',', $values);
            return $this->connection->executeUpdate($sql);
        }
        else {
            $count = 0;
            foreach ($rows as $row) {
                $count += $this->connection->insert($qtable, $row);
            }
            return $count;
        }
    }

    private function tableToArray(Table $table)
    {
        $options = $table->getOptions();
        if (!isset($options['charset']) && isset($options['collation'])) {
            $options['charset'] = explode('_', $options['collation'])[0];
        }

        // entry keys
        $entry = [
            'column'  => [],
            'index'   => [],
            'foreign' => [],
            'trigger' => [],
            'option'  => $options,
        ];

        // add columns
        $ignoreColumnAttributes = array_flip($this->ignoreColumnOptionAttributes);
        foreach ($table->getColumns() as $column) {
            $array = [
                'type'                => $column->getType()->getName(),
                'default'             => $column->getDefault(),
                'notnull'             => $column->getNotnull(),
                'length'              => $column->getLength(),
                'precision'           => $column->getPrecision(),
                'scale'               => $column->getScale(),
                'fixed'               => $column->getFixed(),
                'unsigned'            => $column->getUnsigned(),
                'autoincrement'       => $column->getAutoincrement(),
                'columnDefinition'    => $column->getColumnDefinition(),
                'comment'             => $column->getComment(),
                'platformOptions'     => array_diff_key($column->getPlatformOptions(), $ignoreColumnAttributes),
                'customSchemaOptions' => array_diff_key($column->getCustomSchemaOptions(), $ignoreColumnAttributes),
            ];
            $array = Utility::array_diff_exists($array, $this->defaultColumnAttributes);
            if (!in_array($array['type'], ['smallint', 'integer', 'bigint', 'decimal', 'float'], true)) {
                unset($array['unsigned']);
            }
            $entry['column'][$column->getName()] = $array;
        }

        // add indexes
        /** @var Index[] $indexes */
        $indexes = array_diff_key($table->getIndexes(), $this->getImplicitIndexes($table));
        uasort($indexes, function (Index $a, Index $b) {
            if ($a->isPrimary() || $b->isPrimary()) {
                return $a->isPrimary() ? -1 : 1;
            }
            return strcmp($a->getName(), $b->getName());
        });
        foreach ($indexes as $index) {
            $array = [
                'column'  => $index->getColumns(),
                'primary' => $index->isPrimary(),
                'unique'  => $index->isUnique(),
                'flag'    => $index->getFlags(),
                'option'  => $index->getOptions(),
            ];
            $array = Utility::array_diff_exists($array, $this->defaultIndexAttributes);
            $entry['index'][$index->getName()] = $array;
        }

        // add foreign keys
        $fkeys = $table->getForeignKeys();
        uasort($fkeys, function (ForeignKeyConstraint $a, ForeignKeyConstraint $b) {
            return strcmp($a->getName(), $b->getName());
        });
        foreach ($fkeys as $fkey) {
            $entry['foreign'][$fkey->getName()] = [
                'table'  => $fkey->getForeignTableName(),
                'column' => array_combine($fkey->getLocalColumns(), $fkey->getForeignColumns()),
                'option' => $fkey->getOptions(),
            ];
        }

        // add trigger
        $triggers = $table->getTriggers();
        uasort($triggers, function (Trigger $a, Trigger $b) {
            return strcmp($a->getName(), $b->getName());
        });
        foreach ($triggers as $trigger) {
            $entry['trigger'][$trigger->getName()] = [
                'statement' => $trigger->getStatement(),
                'option'    => $trigger->getOptions(),
            ];
        }

        return $entry;
    }

    private function tableFromArray($name, array $array)
    {
        // base table
        $table = new Table($name, [], [], [], [], 0, $array['option']);

        // add columns
        foreach ($array['column'] ?? [] as $name => $column) {
            $type = $column['type'];
            unset($column['type']);
            $column += $this->defaultColumnAttributes;
            $table->addColumn($name, $type, $column);
        }

        // add indexes
        foreach ($array['index'] ?? [] as $name => $index) {
            $index += $this->defaultIndexAttributes;
            if ($index['primary']) {
                $table->setPrimaryKey($index['column'], $name);
            }
            elseif ($index['unique']) {
                $table->addUniqueIndex($index['column'], $name, $index['option']);
            }
            else {
                $table->addIndex($index['column'], $name, $index['flag'], $index['option']);
            }
        }

        // add foreign keys
        foreach ($array['foreign'] ?? [] as $name => $fkey) {
            $table->addForeignKeyConstraint($fkey['table'], array_keys($fkey['column']), array_values($fkey['column']), $fkey['option'], $name);
        }

        // add triggers
        foreach ($array['trigger'] ?? [] as $name => $trigger) {
            $table->addTrigger($name, $trigger['statement'], $trigger['option']);
        }

        // ignore implicit index
        foreach ($this->getImplicitIndexes($table) as $index) {
            $table->dropIndex($index->getName());
        }

        return $table;
    }

    private function explodeSql($sqls)
    {
        /// this is used by display only, so very loose.

        $qv = Utility::quote($this->connection, 'v');
        $quoted_chars = [
            '"',
            "'",
            $qv[0],
            $this->connection->getDatabasePlatform()->getIdentifierQuoteCharacter(),
        ];

        $delimiter = ';';
        $escaped_char = '\\';
        $quoted_list = array_flip($quoted_chars);

        preg_match_all('@.?@us', $sqls, $m);
        $chars = $m[0];

        $last_index = 0;
        $escaping = false;
        $quotings = array_fill_keys($quoted_chars, false);

        $result = [];
        foreach ($chars as $i => $c) {
            if ($c === $escaped_char) {
                $escaping = true;
                continue;
            }
            if (isset($quoted_list[$c])) {
                if (!$escaping) {
                    $quotings[$c] = !$quotings[$c];
                    $escaping = false;
                    continue;
                }
            }

            if (count(array_filter($quotings)) === 0 && $c === $delimiter) {
                $result[] = implode('', array_slice($chars, $last_index, $i - $last_index));
                $last_index = $i + 1;
            }

            $escaping = false;
        }
        $result[] = implode('', array_slice($chars, $last_index));

        return $result;
    }

    /**
     * @param Table $table
     * @return Index[]
     */
    private function getImplicitIndexes($table)
    {
        $result = [];
        try {
            $implicitIndexes = new \ReflectionProperty($table, 'implicitIndexes');
            $implicitIndexes->setAccessible(true);
            $result = $implicitIndexes->getValue($table);
        }
        catch (\ReflectionException $ex) {
            // If it is a fatal error, no action can be taken, so convert it to Notice
            trigger_error('Table::$implicitIndexes is undefined.', E_USER_NOTICE);
        }
        return $result;
    }

    public function parseFilename($filename)
    {
        $pathinfo = pathinfo($filename);
        $extension = $pathinfo['extension'] ?? '';
        $pathinfo2 = pathinfo($pathinfo['filename']);
        $encoding = $pathinfo2['extension'] ?? '';
        if (!strlen($encoding) && ($this->encodings[$extension] ?? '')) {
            $encoding = $this->encodings[$extension];
        }

        return [
            'dirname'   => $pathinfo['dirname'],
            'basename'  => $pathinfo['basename'],
            'filename'  => $pathinfo2['filename'],
            'extension' => $extension,
            'encoding'  => $encoding,
        ];
    }
}
