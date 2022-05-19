<?php

namespace ryunosuke\DbMigration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Table;

class MigrationTable
{
    /** @var Connection */
    private $connection;

    /** @var Table */
    private $table;

    public function __construct(Connection $connection, $tableName)
    {
        $this->connection = $connection;

        $this->table = new Table($tableName, [
            new Column('version', \Doctrine\DBAL\Types\Type::getType('string')),
            new Column('apply_at', \Doctrine\DBAL\Types\Type::getType('datetime')),
            new Column('sqls', \Doctrine\DBAL\Types\Type::getType('text'), [
                'default' => null,
                'notnull' => false,
            ]),
        ], [
            new Index('PRIMARY', ['version'], true, true),
        ]);
    }

    public function exists()
    {
        return $this->connection->createSchemaManager()->tablesExist($this->table->getName());
    }

    public function create()
    {
        if (!$this->exists()) {
            $this->connection->createSchemaManager()->createTable($this->table);
            return true;
        }
        return false;
    }

    public function diff()
    {
        if ($this->exists()) {
            $sm = $this->connection->createSchemaManager();
            $table = $sm->listTableDetails($this->table->getName());
            $tableDiff = $sm->createComparator()->diffTable($table, $this->table);
            if ($tableDiff === false) {
                return [];
            }
            $diff = new SchemaDiff([], [$tableDiff]);
            return $diff->toSql($this->connection->getDatabasePlatform());
        }
        return [];
    }

    public function alter()
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

    public function drop()
    {
        if ($this->exists()) {
            $this->connection->createSchemaManager()->dropTable($this->table);
            return true;
        }
        return false;
    }

    public function glob($migdir)
    {
        $migfiles = glob($migdir . '/*.{sql,php}', GLOB_BRACE);
        return array_combine(array_map('basename', $migfiles), array_map('file_get_contents', $migfiles));
    }

    public function fetch()
    {
        if (!$this->exists()) {
            return [];
        }
        return $this->connection->executeQuery("SELECT * FROM " . $this->table->getName())->fetchAllAssociativeIndexed();
    }

    public function apply($version, $content)
    {
        $affected = 0;
        $ext = pathinfo($version, PATHINFO_EXTENSION);
        switch ($ext) {
            default:
                throw new \InvalidArgumentException("'$ext' is not supported.");

            case 'sql':
                $sqls = (array) $content;
                $affected += $this->connection->executeStatement($content);
                break;
            case 'php':
                $connection = $this->connection;
                $return = eval("?>$content;");
                if ($return instanceof \Closure) {
                    $return = call_user_func($return, $connection);
                }
                $sqls = array_filter((array) $return);
                foreach ($sqls as $sql) {
                    $affected += $this->connection->executeStatement($sql);
                }
                break;
        }
        $this->attach($version);
        $this->connection->update($this->table->getName(), [
            'sqls' => implode(';', $sqls),
        ], [
            'version' => $version,
        ]);
        return $affected;
    }

    public function attach($version)
    {
        $now = date('Y-m-d H:i:s');
        $affected = 0;
        foreach ((array) $version as $v) {
            $affected += $this->connection->insert($this->table->getName(), [
                'version'  => $v,
                'apply_at' => $now,
                'sqls'     => null,
            ]);
        }
        return $affected;
    }

    public function detach($version)
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
