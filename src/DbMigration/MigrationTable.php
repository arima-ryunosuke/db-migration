<?php

namespace ryunosuke\DbMigration;

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use DomainException;
use Generator;
use ryunosuke\DbMigration\FileType\AbstractFile;

class MigrationTable
{
    private Connection $connection;

    private AbstractSchemaManager $schemaManager;

    private Table $table;

    public function __construct(Connection $connection, string $tableName)
    {
        $this->connection    = $connection;
        $this->schemaManager = $this->connection->createSchemaManager();

        $this->table = new Table($tableName, [
            new Column('version', Type::getType('string'), ['length' => 255]),
            new Column('apply_at', Type::getType('datetime')),
            new Column('logs', Type::getType('json')),
        ], [
            new Index('PRIMARY', ['version'], true, true),
        ]);
    }

    public function exists(): bool
    {
        return $this->schemaManager->tableExists($this->table->getName());
    }

    public function create(): bool
    {
        if (!$this->exists()) {
            $this->schemaManager->createTable($this->table);
            return true;
        }
        return false;
    }

    public function diff(): array
    {
        if ($this->exists()) {
            $table     = $this->schemaManager->introspectTable($this->table->getName());
            $tableDiff = $this->schemaManager->createComparator()->compareTables($table, $this->table);
            return $this->connection->getDatabasePlatform()->getAlterTableSQL($tableDiff);
        }
        return [];
    }

    public function alter(): bool
    {
        $diff = $this->diff();
        if ($diff) {
            foreach ($diff as $sql) {
                $this->connection->executeStatement($sql);
            }
            return true;
        }
        return false;
    }

    public function drop(): bool
    {
        if ($this->exists()) {
            $this->schemaManager->dropTable($this->table->getName());
            return true;
        }
        return false;
    }

    public function glob($migdir): array
    {
        $migfiles = [];
        foreach (glob($migdir . '/*') as $filename) {
            try {
                $file = AbstractFile::create($filename, [
                    'connection' => $this->connection,
                ]);

                $migfiles[$file->pathinfo()['basename']] = $file;
            }
            catch (DomainException) {
                // through unsupported file
            }
        }
        return $migfiles;
    }

    public function fetch(): array
    {
        if (!$this->exists()) {
            return [];
        }
        return $this->connection->executeQuery("SELECT * FROM " . $this->table->getName())->fetchAllAssociativeIndexed();
    }

    public function apply(string $version, iterable $records): Generator
    {
        $tablenames = array_flip($this->schemaManager->listTableNames());

        $result = (object) [
            'affect' => 0,
            'logs'   => [],
        ];
        $insert = function ($value) use (&$insert, $result, $tablenames) {
            if (!is_iterable($value)) {
                yield $value;
                $result->logs[] = $value;
                $result->affect += $this->connection->executeStatement($value);
                return;
            }

            foreach ($value as $k => $v) {
                if (isset($tablenames[$k])) {
                    foreach ($v as $row) {
                        $sql = $this->connection->buildInsertSql($k, $row, true);
                        yield $sql;
                        $result->logs[] = $row;
                        $result->affect += $this->connection->executeStatement($sql);
                    }
                }
                else {
                    yield from $insert($v);
                }
            }
            return;
        };

        yield from $insert($records);

        $this->connection->insert($this->table->getName(), [
            'version'  => $version,
            'apply_at' => date('Y-m-d H:i:s'),
            'logs'     => json_encode($result->logs),
        ]);
        return $result->affect;
    }

    public function attach($version): int
    {
        $now      = date('Y-m-d H:i:s');
        $affected = 0;
        foreach ((array) $version as $v) {
            $affected += $this->connection->insert($this->table->getName(), [
                'version'  => $v,
                'apply_at' => $now,
                'logs'     => 'null',
            ]);
        }
        return $affected;
    }

    public function detach($version): int
    {
        $affected = 0;
        foreach ((array) $version as $v) {
            $affected += $this->connection->delete($this->table->getName(), [
                'version' => $v,
            ]);
        }
        return $affected;
    }
}
