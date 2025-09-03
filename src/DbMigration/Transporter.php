<?php

namespace ryunosuke\DbMigration;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Event;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Routine;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\Trigger;
use Doctrine\DBAL\Schema\View;
use Generator;
use ryunosuke\DbMigration\FileType\AbstractFile;
use ryunosuke\DbMigration\FileType\Tool\Exportion;

class Transporter
{
    private Connection $connection;

    private AbstractPlatform $platform;

    private AbstractSchemaManager $schemaManager;

    private Schema $schema;

    /** @var bool[] */
    private array $disableds = [
        'table'   => false,
        'view'    => false,
        'trigger' => false,
        'routine' => false,
        'event'   => false,
    ];

    private int $bulksize = 0;

    private ?string $directory = null;

    private array $dataDescriptionOptions = [
        'inline'    => 4,
        'indent'    => 4,
        'multiline' => false,
        'align'     => null,
        'delimiter' => null,
        'yield'     => false,
    ];

    private array $defaultColumnAttributes = [
        'length'           => null,
        'precision'        => null,
        'scale'            => 0,
        'fixed'            => false,
        'autoincrement'    => false,
        'columnDefinition' => null,
    ];

    private array $defaultIndexAttributes = [
        'primary' => false,
        'flag'    => [],
        'option'  => [],
    ];

    private array $ignoreTableOptionAttributes = [
        'autoincrement'  => null,
        'create_options' => null,
    ];

    private array $ignoreColumnOptionAttributes = [
        // for ryunosuke/dbal
        'beforeColumn' => null,
    ];

    private array $ignoreViewOptionAttributes = [
        'updatable' => null,
    ];

    private array $ignoreTriggerOptionAttributes = [
        // something
    ];

    private array $ignoreRoutineOptionAttributes = [
        // something
    ];

    private array $ignoreEventOptionAttributes = [
        'timeZone' => null,
        'interval' => null,
    ];

    public function __construct(Connection $connection)
    {
        $this->connection    = $connection;
        $this->platform      = $connection->getDatabasePlatform();
        $this->schemaManager = $connection->createSchemaManager();
        $this->schema        = $this->schemaManager->introspectSchema();

        $this->platform->enableDiffComment(true);
    }

    public function setDisabled(array $disableds)
    {
        $this->disableds = $disableds + $this->disableds;
    }

    public function setBulkSize(int $bulksize)
    {
        $this->bulksize = $bulksize;
    }

    public function setDirectory(?string $directory)
    {
        $this->directory = $directory;
    }

    public function setDataDescriptionOptions(array $options)
    {
        foreach ($options as $name => $value) {
            $this->dataDescriptionOptions[$name] = $value;
        }
    }

    public function getDefinition(): array
    {
        $sortAssets = function ($assets) {
            uasort($assets, fn(AbstractAsset $a, AbstractAsset $b) => $a->getName() <=> $b->getName());
            return $assets;
        };
        return [
            'table'   => [
                'get'        => fn(Schema $schema) => $sortAssets($schema->getTables()),
                'add'        => fn(Schema $schema, Table $table) => $schema->addTable($table),
                'drop'       => fn(Schema $schema, Table $table) => $schema->dropTable($table->getName()),
                'array'      => function (Table $table) use ($sortAssets) {
                    // charset
                    $options = $table->getOptions();
                    if (!isset($options['charset']) && isset($options['collation'])) {
                        $options['charset'] = explode('_', $options['collation'])[0];
                    }

                    // entry keys
                    $entry = [
                        'column'  => [],
                        'index'   => [],
                        'foreign' => [],
                        'option'  => array_diff_key($options, $this->ignoreTableOptionAttributes),
                    ];

                    // add columns
                    foreach ($table->getColumns() as $column) {
                        $array = [
                            'type'             => $column->getType()->getName(),
                            'default'          => $column->getDefault(),
                            'notnull'          => $column->getNotnull(),
                            'length'           => $column->getLength(),
                            'precision'        => $column->getPrecision(),
                            'scale'            => $column->getScale(),
                            'fixed'            => $column->getFixed(),
                            'unsigned'         => $column->getUnsigned(),
                            'autoincrement'    => $column->getAutoincrement(),
                            'columnDefinition' => $column->getColumnDefinition(),
                            'comment'          => $column->getComment(),
                            'platformOptions'  => array_diff_key($column->getPlatformOptions(), $this->ignoreColumnOptionAttributes),
                        ];
                        if (!in_array($array['type'], ['smallint', 'integer', 'bigint', 'decimal', 'float'], true)) {
                            unset($array['unsigned']);
                        }

                        $entry['column'][$column->getName()] = array_udiff_assoc($array, $this->defaultColumnAttributes, fn($a, $b) => $a <=> $b);
                    }

                    // add indexes
                    $indexes = $table->getIndexes();
                    array_multisort(array_map(fn(Index $v) => $v->isPrimary() ? '' : $v->getName(), $indexes), $indexes);
                    foreach ($indexes as $index) {
                        $array = [
                            'column'  => $index->getColumns(),
                            'primary' => $index->isPrimary(),
                            'unique'  => $index->isUnique(),
                            'flag'    => $index->getFlags(),
                            'option'  => $index->getOptions(),
                        ];

                        $entry['index'][$index->getName()] = array_udiff_assoc($array, $this->defaultIndexAttributes, fn($a, $b) => $a <=> $b);
                    }

                    // add foreign keys
                    $fkeys = $sortAssets($table->getForeignKeys());
                    foreach ($fkeys as $fkey) {
                        $entry['foreign'][$fkey->getName()] = [
                            'table'  => $fkey->getForeignTableName(),
                            'column' => array_combine($fkey->getLocalColumns(), $fkey->getForeignColumns()),
                            'option' => $fkey->getOptions(),
                        ];
                    }

                    return $entry;
                },
                'object'     => function (string $name, array $array) {
                    // base table
                    $table = new Table($name, [], [], [], [], $array['option']);

                    // add columns
                    $before = null;
                    foreach ($array['column'] ?? [] as $name => $column) {
                        $type = $column['type'];
                        unset($column['type']);
                        $column += $this->defaultColumnAttributes;
                        $column = $table->addColumn($name, $type, $column);
                        if ($before !== null) {
                            $column->setPlatformOption('beforeColumn', $before->getName());
                        }
                        $before = $column;
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

                    return $table;
                },
                'createSQLs' => fn($tables) => $this->platform->getCreateTablesSQL($tables),
                'showCreate' => function ($table) {
                    $data = array_change_key_case($this->connection->fetchAssociative("SHOW CREATE TABLE {$table->getName()}"));
                    return [$data["create table"] . ';'];
                },
            ],
            'view'    => [
                'get'        => fn(Schema $schema) => $sortAssets($schema->getViews()),
                'add'        => fn(Schema $schema, View $view) => $schema->addView($view),
                'drop'       => fn(Schema $schema, View $view) => $schema->dropView($view->getName()),
                'array'      => function (View $view) {
                    return array_replace([
                        'sql' => $this->stripSchemaOf($view->getSql()),
                    ], array_diff_key($view->getOptions(), $this->ignoreViewOptionAttributes));
                },
                'object'     => function ($name, $array) {
                    $array['sql'] = $this->restoreSchemaOf($array['sql']);
                    return new View($name, ...$this->arrayToObject(array_diff_key($array, $this->ignoreViewOptionAttributes), 'sql'));
                },
                'createSQLs' => fn($views) => array_map(fn($view) => $this->platform->getCreateViewSQL($view->getName(), $view->getSql(), $view->getOptions()), $views),
                'showCreate' => function ($view, $no_definer) {
                    $data = array_change_key_case($this->connection->fetchAssociative("SHOW CREATE VIEW {$view->getName()}"));
                    if ($no_definer) {
                        $data["create view"] = $this->stripDefinerOf($data["create view"]);
                    }
                    [$before, $after] = $this->getSetStatements([
                        'character_set_client' => $data['character_set_client'],
                    ]);
                    return [
                        ...$before,
                        $this->stripSchemaOf($data["create view"]) . ';',
                        ...$after,
                    ];
                },
            ],
            'trigger' => [
                'get'        => fn(Schema $schema) => $sortAssets($schema->getTriggers()),
                'add'        => fn(Schema $schema, Trigger $trigger) => $schema->addTrigger($trigger),
                'drop'       => fn(Schema $schema, Trigger $trigger) => $schema->dropTrigger($trigger->getName()),
                'array'      => function (Trigger $trigger) {
                    return array_replace([
                        'statement' => $trigger->getStatement(),
                        'table'     => $trigger->getTableName(),
                    ], array_diff_key($trigger->getOptions(), $this->ignoreTriggerOptionAttributes));
                },
                'object'     => function ($name, $array) {
                    return new Trigger($name, ...$this->arrayToObject(array_diff_key($array, $this->ignoreTriggerOptionAttributes), 'statement', 'table'));
                },
                'createSQLs' => fn($triggers) => array_map(fn($trigger) => $this->platform->getCreateTriggerSQL($trigger), $triggers),
                'showCreate' => function ($trigger, $no_definer) {
                    $data = array_change_key_case($this->connection->fetchAssociative("SHOW CREATE TRIGGER {$trigger->getName()}"));
                    if ($no_definer) {
                        $data["sql original statement"] = $this->stripDefinerOf($data["sql original statement"]);
                    }
                    [$before, $after] = $this->getSetStatements([
                        'sql_mode'             => $this->connection->quote($data['sql_mode']),
                        'character_set_client' => $data['character_set_client'],
                        'collation_connection' => $data['collation_connection'],
                    ]);
                    return [
                        ...$before,
                        "DELIMITER $$$",
                        $data["sql original statement"] . '$$$',
                        "DELIMITER ;",
                        ...$after,
                    ];
                },
            ],
            'routine' => [
                'get'        => fn(Schema $schema) => $sortAssets($schema->getRoutines()),
                'add'        => fn(Schema $schema, Routine $routine) => $schema->addRoutine($routine),
                'drop'       => fn(Schema $schema, Routine $routine) => $schema->dropRoutine($routine->getName()),
                'array'      => function (Routine $routine) {
                    return array_replace([
                        'statement' => $routine->getStatement(),
                    ], array_diff_key($routine->getOptions(), $this->ignoreRoutineOptionAttributes));
                },
                'object'     => function ($name, $array) {
                    return new Routine($name, ...$this->arrayToObject(array_diff_key($array, $this->ignoreRoutineOptionAttributes), 'statement'));
                },
                'createSQLs' => fn($routines) => array_map(fn($routine) => (fn($routine) => $this->platform->{"getCreate{$routine->getType()}SQL"}($routine))($routine), $routines),
                'showCreate' => function ($routine, $no_definer) {
                    $data = array_change_key_case($this->connection->fetchAssociative("SHOW CREATE {$routine->getType()} {$routine->getName()}"));
                    $type = strtolower($routine->getType());
                    if ($no_definer) {
                        $data["create $type"] = $this->stripDefinerOf($data["create $type"]);
                    }
                    [$before, $after] = $this->getSetStatements([
                        'sql_mode'             => $this->connection->quote($data['sql_mode']),
                        'character_set_client' => $data['character_set_client'],
                        'collation_connection' => $data['collation_connection'],
                    ]);
                    return [
                        ...$before,
                        "DELIMITER $$$",
                        $data["create $type"] . '$$$',
                        "DELIMITER ;",
                        ...$after,
                    ];
                },
            ],
            'event'   => [
                'get'        => fn(Schema $schema) => $sortAssets($schema->getEvents()),
                'add'        => fn(Schema $schema, Event $event) => $schema->addEvent($event),
                'drop'       => fn(Schema $schema, Event $event) => $schema->dropEvent($event->getName()),
                'array'      => function (Event $event) {
                    return array_replace([
                        'statement' => $event->getStatement(),
                    ], array_diff_key($event->getOptions(), $this->ignoreEventOptionAttributes));
                },
                'object'     => function ($name, $array) {
                    return new Event($name, ...$this->arrayToObject(array_diff_key($array, $this->ignoreEventOptionAttributes), 'statement'));
                },
                'createSQLs' => fn($events) => array_map(fn($event) => $this->platform->getCreateEventSQL($event), $events),
                'showCreate' => function ($event, $no_definer) {
                    $data = array_change_key_case($this->connection->fetchAssociative("SHOW CREATE EVENT {$event->getName()}"));
                    if ($no_definer) {
                        $data["create event"] = $this->stripDefinerOf($data["create event"]);
                    }
                    [$before, $after] = $this->getSetStatements([
                        'sql_mode'  => $this->connection->quote($data['sql_mode']),
                        'time_zone' => $this->connection->quote($data['time_zone']),
                    ]);
                    return [
                        ...$before,
                        "DELIMITER $$$",
                        $data["create event"] . '$$$',
                        "DELIMITER ;",
                        ...$after,
                    ];
                },
            ],
        ];
    }

    public function globTable(array $filenames): array
    {
        $pathinfos  = array_map(fn($f) => $this->getFileByFilename($f)->pathinfo(), $filenames);
        $tableNames = array_map(fn($t) => $t->getName(), $this->schema->getTables());

        $normalize = fn($pathinfo, $tableName) => strtr(concat($pathinfo['dirname'], '/') . $tableName . $pathinfo['extensions'], ['\\' => '/']);

        $list = null;

        foreach ($pathinfos as $pathinfo) {

            $pattern = $pathinfo['filename'];
            $not     = $pattern[0] === '!';
            if ($not) {
                $pattern = substr($pattern, 1);
            }

            foreach ($tableNames as $tableName) {
                if (fnmatch($pattern, $tableName)) {
                    $path = $normalize($pathinfo, $tableName);

                    if ($not) {
                        if ($list === null) {
                            $list = array_flip(array_map(fn($tableName) => $normalize($pathinfo, $tableName), $tableNames));
                        }
                        unset($list[$path]);
                    }
                    else {
                        if ($list === null) {
                            $list = [];
                        }
                        $list[$path] = true;
                    }
                }
            }
        }

        $result = array_keys(($list ?? []));
        sort($result);
        return $result;
    }

    public function checkForeignKeyConstraint(array $includes = [], array $excludes = []): array
    {
        $result = [];

        foreach ($this->schema->getTables() as $table) {
            $name = $table->getName();

            if ($this->filterTable($name, $includes, $excludes) > 0) {
                continue;
            }

            $scanner = new TableScanner($this->connection, $table, []);

            $foreignKeys = $table->getForeignKeys();
            foreach ($foreignKeys as $foreignKey) {
                $result[$name][$foreignKey->getName()] = $scanner->getOrphanRows($foreignKey);
            }
        }

        return $result;
    }

    public function dump(string $schemaFilename, ?string $dbname, array $includes = [], array $excludes = [], array $options = []): Generator
    {
        $schemaName = $this->connection->getDatabase();
        $dbname     ??= $schemaName;
        $directory  = dirname($schemaFilename);

        $rules = [
            'sql' => [static fn($localfile) => "SOURCE $directory/$localfile;\n"],
            'php' => [static fn($localfile) => "<?php readfile(__DIR__ . " . var_export("/$localfile", true) . ") ?>\n"],
        ];
        $file  = $this->getFileByFilename($schemaFilename);
        [$import] = $rules[$file->pathinfo()['extension']] ?? [null];
        if ($import === null) {
            throw $file->newUnsupported(__FUNCTION__);
        }

        [$before, $after] = $this->getSetStatements([
            'print_identified_with_as_hex' => 1,
            'show_create_table_verbosity'  => 1,
            'sql_quote_show_create'        => 1,
        ]);

        foreach ($before as $sql) {
            $this->connection->executeStatement($sql);
        }

        try {
            $definition = $this->getDefinition();

            $sources = [];
            foreach ($definition as $category => $setting) {
                if ($this->disableds[$category]) {
                    continue;
                }
                foreach ($setting['get']($this->schema) as $object) {
                    $name      = $object->getName();
                    $localname = "$category/$name.sql";
                    $filename  = "$directory/$localname";

                    if ($this->filterTable($name, $includes, $excludes) > 0) {
                        yield [$name, $filename] => null;
                        continue;
                    }

                    $sources[$category][$name] = $localname;
                    yield [$name, $filename] => function () use ($options, $filename, $category, $name, $setting, $object) {
                        $count  = 0;
                        $file   = $this->getFileByFilename($filename);
                        $stream = $file->open('w');

                        // drop
                        $type = strtoupper($category === 'routine' ? $object->getType() : $category);
                        $stream->fwrite("DROP $type IF EXISTS {$this->connection->quoteIdentifier($name)};\n");
                        yield $count++;

                        // create
                        $createSQLs = $setting['showCreate']($object, $options['no-definer'] ?? false);
                        foreach ($createSQLs as $createSQL) {
                            $stream->fwrite("$createSQL\n");
                            yield $count++;
                        }

                        // adjust
                        if ($category === 'table') {
                            if ($options['no-autoincrement'] ?? false) {
                                $stream->fwrite("\n");
                                $stream->fwrite("ALTER TABLE {$this->connection->quoteIdentifier($name)} auto_increment = 1;\n");
                            }
                        }

                        // insert
                        if ($category === 'table') {
                            $stream->fwrite("\n");
                            $scanner = new TableScanner($this->connection, $object, []);
                            foreach ($file->writeRecords($scanner->getAllRows()) as $ignored) {
                                yield $count++;
                            }
                        }

                        // return
                        return [
                            'count' => $count,
                            'size'  => $stream->getSize(),
                        ];
                    };
                }
            }

            yield [$schemaName, $schemaFilename] => function () use ($dbname, $schemaName, $schemaFilename, $import, $sources) {
                $count  = 0;
                $file   = $this->getFileByFilename($schemaFilename);
                $stream = $file->open('w');

                // before,after
                [$before, $after] = $this->getSetStatements([
                    'sql_mode'           => $this->connection->quote("NO_AUTO_VALUE_ON_ZERO"),
                    'sql_notes'          => "0",
                    'time_zone'          => $this->connection->quote("+00:00"),
                    'unique_checks'      => "0",
                    'foreign_key_checks' => "0",
                ]);

                // before
                $stream->fwrite("-- set variable\n");
                foreach ($before as $sql) {
                    $stream->fwrite("$sql\n");
                    yield $count++;
                }
                $stream->fwrite("\n");

                // database
                $stream->fwrite("-- database\n");
                $createSqls = function () use ($dbname, $schemaName) {
                    $qSchemaName = $this->connection->quoteIdentifier($schemaName);
                    $create      = $this->connection->fetchAssociative("SHOW CREATE DATABASE $qSchemaName")['Create Database'];
                    if ($dbname === '') {
                        yield '-- ' . str_replace(["\r\n", "\r", "\n"], ' ', $create) . ";";
                    }
                    else {
                        $qdbname = $this->connection->quoteIdentifier($dbname);
                        yield "DROP DATABASE IF EXISTS $qdbname;";
                        yield str_replace($qSchemaName, $qdbname, $create) . ";";
                        yield "USE $qdbname;";
                    }
                };
                foreach ($createSqls() as $sql) {
                    $stream->fwrite("$sql\n");
                    yield $count++;
                }
                $stream->fwrite("\n");

                // user/grant
                $stream->fwrite("-- user/grant\n");
                $createSqls = function () use ($schemaName) {
                    $users = $this->connection->fetchAllAssociative(<<<SQL
                    SELECT DISTINCT U.GRANTEE AS user
                    FROM information_schema.USER_PRIVILEGES AS U
                    LEFT JOIN information_schema.SCHEMA_PRIVILEGES S USING (GRANTEE)
                    WHERE (
                      (U.PRIVILEGE_TYPE = "SUPER" AND U.IS_GRANTABLE = "YES") OR
                      (S.TABLE_SCHEMA = {$this->connection->quote($schemaName)})
                    )
                    SQL,);
                    foreach ($users as $user) {
                        $createSqls = $this->connection->fetchAllNumeric("SHOW CREATE USER {$user['user']}");
                        foreach ($createSqls as $sql) {
                            yield '-- ' . str_replace(["\r\n", "\r", "\n"], ' ', $sql[0]) . ";";
                        }
                        $grantsSqls = $this->connection->fetchAllNumeric("SHOW GRANTS FOR {$user['user']}");
                        foreach ($grantsSqls as $sql) {
                            yield '  -- ' . str_replace(["\r\n", "\r", "\n"], ' ', $sql[0]) . ";";
                        }
                    }
                };
                foreach ($createSqls() as $sql) {
                    $stream->fwrite("$sql\n");
                    yield $count++;
                }
                $stream->fwrite("\n");

                // preparation
                $stream->fwrite("-- preparation\n");
                foreach ($sources['view'] ?? [] as $name => $dummyView) {
                    $view    = $this->schemaManager->introspectViewAsTable($name);
                    $selects = array_sprintf($view->getColumns(), fn($column) => "1 AS {$this->connection->quoteIdentifier($column->getName())}", ', ');
                    $stream->fwrite("CREATE OR REPLACE VIEW {$this->connection->quoteIdentifier($name)} AS SELECT $selects;\n");
                    yield $count++;
                }
                $stream->fwrite("\n");

                // import
                foreach ($sources as $category => $filenames) {
                    $stream->fwrite("-- $category\n");
                    foreach ($filenames as $filename) {
                        $stream->fwrite($import($filename));
                        yield $count++;
                    }
                    $stream->fwrite("\n");
                }

                // after
                $stream->fwrite("-- reset variable\n");
                foreach ($after as $sql) {
                    $stream->fwrite("$sql\n");
                    yield $count++;
                }

                // return
                return [
                    'count' => $count,
                    'size'  => $stream->getSize(),
                ];
            };
        }
        finally {
            foreach ($after as $sql) {
                $this->connection->executeStatement($sql);
            }

            // auto close/flush
            gc_collect_cycles();
        }
    }

    public function exportDDL(string $filename, array $includes = [], array $excludes = []): string
    {
        if (!strlen($filename)) {
            return '';
        }

        $file       = $this->getFileByFilename($filename);
        $pathinfo   = $file->pathinfo();
        $definition = $this->getDefinition();

        $categories = array_fill_keys(array_keys($definition), []);
        foreach ($definition as $category => $setting) {
            if ($this->disableds[$category]) {
                continue;
            }
            foreach ($setting['get']($this->schema) as $object) {
                $name = $object->getName();

                if ($this->filterTable($name, $includes, $excludes) > 0) {
                    continue;
                }

                if ($file->sqlable()) {
                    $categories[$category][$name] = $object;
                    continue;
                }

                $array = $setting['array']($object);
                if ($this->directory) {
                    $array = new Exportion("$this->directory/$category/$name.{$pathinfo['extension']}", $array);
                }
                $categories[$category][$name] = $array;
            }

            ksort($categories[$category]);
            if ($file->sqlable()) {
                $categories[$category] = $setting['createSQLs']($categories[$category]);
            }
        }

        return $file->writeSchema($categories);
    }

    public function exportDML(string $filename, array $filterCondition = [], array $ignoreColumn = []): Generator
    {
        $file     = $this->getFileByFilename($filename);
        $pathinfo = $file->pathinfo();

        // create TableScanner
        $table   = $this->schema->getTable($pathinfo['filename']);
        $scanner = new TableScanner($this->connection, $table, $filterCondition, $ignoreColumn);

        yield from $file->writeRecords($scanner->getAllRows());
    }

    public function importDDL(string $filename): array
    {
        if (!strlen($filename)) {
            return [];
        }

        $file = $this->getFileByFilename($filename);

        $schemaArray = $file->readSchema();

        if ($file->sqlable()) {
            return $schemaArray;
        }

        $newSchema = $this->schemaFromArray($schemaArray);
        $oldSchema = new Schema([], [], $this->schemaManager->createSchemaConfig());

        $diff = $this->schemaManager->createComparator()->compareSchemas($oldSchema, $newSchema);
        return $this->platform->getAlterSchemaSQL($diff);
    }

    public function importDML(string $filename): Generator
    {
        $file     = $this->getFileByFilename($filename);
        $pathinfo = $file->pathinfo();

        $records = $file->readRecords();

        if ($file->sqlable()) {
            return yield from $records;
        }

        $table   = $this->schema->getTable($pathinfo['filename']);
        $scanner = new TableScanner($this->connection, $table, [], []);

        $dataRecords = $scanner->associateRecords($records);

        return yield from $scanner->getInsertSql($dataRecords, $this->bulksize);
    }

    public function migrateDDL(string $filename, array $excludes = []): array
    {
        if (!strlen($filename)) {
            return [];
        }

        $file = $this->getFileByFilename($filename);

        if ($file->sqlable()) {
            throw $file->newUnsupported(__FUNCTION__);
        }

        $schemaArray = $file->readSchema();

        $newSchema = $this->schemaFromArray($schemaArray);
        $oldSchema = $this->schema;

        $definition = $this->getDefinition();
        foreach ($definition as $setting) {
            foreach ($setting['get']($oldSchema) as $object) {
                if ($this->filterTable($object->getName(), [], $excludes) > 0) {
                    $setting['drop']($oldSchema, $object);
                }
            }
            foreach ($setting['get']($newSchema) as $object) {
                if ($this->filterTable($object->getName(), [], $excludes) > 0) {
                    $setting['drop']($newSchema, $object);
                }
            }
        }

        $diff = $this->schemaManager->createComparator()->compareSchemas($oldSchema, $newSchema);
        return $this->platform->getAlterSchemaSQL($diff);
    }

    public function migrateDML(string $filename, array $dmltypes = [], array $ignoreColumn = []): Generator
    {
        $file     = $this->getFileByFilename($filename);
        $pathinfo = $file->pathinfo();

        $records = $file->readRecords();

        if ($file->sqlable()) {
            return yield from $records;
        }

        // scanner objects
        $table   = $this->schema->getTable($pathinfo['filename']);
        $scanner = new TableScanner($this->connection, $table, [], $ignoreColumn);

        // primary key tuples
        $primaryTuples = iterator_to_array($scanner->getPrimaryRows());
        $dataRecords   = iterator_to_array($scanner->associateRecords($records));

        $dmls = [];

        // DELETE if old only
        if (($dmltypes['delete'] ?? false) && $tuples = array_diff_key($primaryTuples, $dataRecords)) {
            $dmls = array_merge($dmls, $scanner->getDeleteSql($tuples));
        }

        // UPDATE if common
        if (($dmltypes['update'] ?? false) && $tuples = array_intersect_key($primaryTuples, $dataRecords)) {
            $dmls = array_merge($dmls, $scanner->getUpdateSql($tuples, $dataRecords));
        }

        // INSERT if new only
        if (($dmltypes['insert'] ?? false) && $tuples = array_diff_key($dataRecords, $primaryTuples)) {
            $dmls = array_merge($dmls, iterator_to_array($scanner->getInsertSql($tuples, $this->bulksize)));
        }

        return yield from $dmls;
    }

    public function refresh()
    {
        $this->schema = $this->schemaManager->introspectSchema();
    }

    private function getFileByFilename(string $filename): AbstractFile
    {
        $options = array_replace($this->dataDescriptionOptions, [
            'connection' => $this->connection,
        ]);

        return AbstractFile::create($filename, $options);
    }

    private function schemaFromArray(array $schemaArray): Schema
    {
        $schema = new class([], [], $this->schemaManager->createSchemaConfig()) extends Schema {
            public function addTable(Table $table) { return parent::_addTable($table); }
        };

        $definition = $this->getDefinition();
        foreach ($definition as $category => $setting) {
            foreach ($schemaArray[$category] ?? [] as $name => $array) {
                $setting['add']($schema, $setting['object']($name, $array));
            }
        }

        return $schema;
    }

    private function getSetStatements(array $values): array
    {
        $before = $after = [];
        foreach ($values as $name => $value) {
            $before[] = "SET @old_$name = @@$name, $name = $value;";
            $after[]  = "SET $name = @old_$name;";
        }
        return [$before, $after];
    }

    private function arrayToObject(array $array, string ...$unset_keys): array
    {
        $unsets = [];
        foreach ($unset_keys as $key) {
            $unsets[] = $array[$key];
            unset($array[$key]);
        }
        return [...$unsets, $array];
    }

    private function stripDefinerOf(string $sql)
    {
        if ($this->platform instanceof AbstractMySQLPlatform) {
            $sql = preg_replace('/\s*DEFINER\s*=\s*`[^`]+`@`[^`]+`\s*/', ' ', $sql, 1);
        }

        return $sql;
    }

    private function stripSchemaOf(string $sql)
    {
        if ($this->platform instanceof AbstractMySQLPlatform) {
            $schemaName = $this->schema->getName();

            $tokens = parse_php($sql);
            array_shift($tokens);

            $quoted = null;
            foreach ($tokens as $n => $token) {
                if ($token[1] === '`') {
                    if ($quoted === null) {
                        $quoted = $n;
                    }
                    else {
                        $modifier = implode('', array_column(array_slice($tokens, $quoted + 1, $n - $quoted - 1), 1));
                        if ($modifier === $schemaName) {
                            for ($i = $n + 1; $i < count($tokens); $i++) {
                                if ($tokens[$i][0] === T_WHITESPACE) {
                                    continue;
                                }
                                if ($tokens[$i][1] === '.') {
                                    $length  = $i - $quoted + 1;
                                    $comment = "`schema`" . implode('', array_column(array_slice($tokens, $n + 1, $i - $quoted - 2), 1));
                                    $dummy   = array_pad([], $length - 1, null);
                                    array_splice($tokens, $quoted, $length, array_merge($dummy, [[1 => "/*$comment*/"]]));
                                }
                                break;
                            }
                        }
                        $quoted = null;
                    }
                }
            }

            $sql = implode('', array_column($tokens, 1));
        }

        return $sql;
    }

    private function restoreSchemaOf(string $sql)
    {
        if ($this->platform instanceof AbstractMySQLPlatform) {
            $schemaName = $this->schema->getName();

            $tokens = parse_php($sql);
            array_shift($tokens);

            foreach ($tokens as $n => $token) {
                if ($token[0] === T_COMMENT) {
                    $tokens[$n][1] = strtr(substr($token[1], 2, -2), ["`schema`" => "`$schemaName`"]);
                }
            }

            $sql = implode('', array_column($tokens, 1));
        }

        return $sql;
    }

    private function filterTable(string $tablename, array $includes, array $excludes): int
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
}
