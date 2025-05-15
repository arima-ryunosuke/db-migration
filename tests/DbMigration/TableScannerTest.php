<?php

namespace ryunosuke\Test\DbMigration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use ryunosuke\DbMigration\Connection;
use ryunosuke\DbMigration\TableScanner;

class TableScannerTest extends AbstractTestCase
{
    /**
     * @var TableScanner
     */
    private $scanner, $scanner_fuga;

    /**
     * @var \ReflectionClass
     */
    private $refClass;

    protected function setup(): void
    {
        parent::setUp();

        $table_hoge = $this->createSimpleTable('hoge', 'integer', 'id');
        $this->readyObject($this->schema, $table_hoge);

        $table_fuga = new Table('fuga');
        $table_fuga->addColumn('id1', 'integer');
        $table_fuga->addColumn('id2', 'integer');
        $table_fuga->addColumn('fid1', 'integer', ['Notnull' => false]);
        $table_fuga->addColumn('fid2', 'integer', ['Notnull' => false]);
        $table_fuga->addColumn('data', 'string');
        $table_fuga->addColumn('ignored', 'string');
        $table_fuga->setPrimaryKey(['id1', 'id2']);
        $this->readyObject($this->schema, $table_fuga);

        $this->schema->createForeignKey(new ForeignKeyConstraint(['fid1'], 'hoge', ['id'], 'fk_fuga_hoge1'), 'fuga');
        $this->schema->createForeignKey(new ForeignKeyConstraint(['fid2'], 'hoge', ['id'], 'fk_fuga_hoge2'), 'fuga');

        $this->scanner  = new TableScanner($this->connection, $table_hoge, ['TRUE']);
        $this->refClass = new \ReflectionClass($this->scanner);

        $this->scanner_fuga = new TableScanner($this->connection, $table_fuga, ['TRUE'], ['fuga.ignored']);
    }

    private function invoke($methodName, $args)
    {
        $method = $this->refClass->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->scanner, array_slice(func_get_args(), 1));
    }

    /**
     * @test
     */
    function constructor()
    {
        $condition = $this->invoke('parseCondition', '', '`');
        $this->assertEquals('1', $condition);

        $condition = $this->invoke('parseCondition', 'hoge.fuga = 1', '`');
        $this->assertEquals('1', $condition);

        $condition = $this->invoke('parseCondition', 'fuga.id = 1', '`');
        $this->assertEquals('1', $condition);

        $condition = $this->invoke('parseCondition', '`fuga`.`id` = 1', '`');
        $this->assertEquals('1', $condition);

        $condition = $this->invoke('parseCondition', 'hoge.id = 1', '`');
        $this->assertEquals('hoge.id = 1', $condition);

        $condition = $this->invoke('parseCondition', 'id = 1', '`');
        $this->assertEquals('id = 1', $condition);

        $condition = $this->invoke('parseCondition', '`hoge`.`id` = 1', '`');
        $this->assertEquals('`hoge`.`id` = 1', $condition);

        $condition = $this->invoke('parseCondition', '`id` = 1', '`');
        $this->assertEquals('`id` = 1', $condition);

        $condition = $this->invoke('parseCondition', ['`id` > 1', '`id` < 10'], '`');
        $this->assertEquals('`id` > 1 AND `id` < 10', $condition);
    }

    /**
     * @test
     */
    function parseCondition()
    {
        $condition = $this->invoke('parseCondition', '', '`');
        $this->assertEquals('1', $condition);

        $condition = $this->invoke('parseCondition', 'hoge.fuga = 1', '`');
        $this->assertEquals('1', $condition);

        $condition = $this->invoke('parseCondition', 'fuga.id = 1', '`');
        $this->assertEquals('1', $condition);

        $condition = $this->invoke('parseCondition', '`fuga`.`id` = 1', '`');
        $this->assertEquals('1', $condition);

        $condition = $this->invoke('parseCondition', 'hoge.id = 1', '`');
        $this->assertEquals('hoge.id = 1', $condition);

        $condition = $this->invoke('parseCondition', 'id = 1', '`');
        $this->assertEquals('id = 1', $condition);

        $condition = $this->invoke('parseCondition', '`hoge`.`id` = 1', '`');
        $this->assertEquals('`hoge`.`id` = 1', $condition);

        $condition = $this->invoke('parseCondition', '`id` = 1', '`');
        $this->assertEquals('`id` = 1', $condition);

        $condition = $this->invoke('parseCondition', ['`id` > 1', '`id` < 10'], '`');
        $this->assertEquals('`id` > 1 AND `id` < 10', $condition);
    }

    /**
     * @test
     */
    function commentize()
    {
        $comment = $this->invoke('commentize', [
            'col1' => str_repeat('X', 100),
            'col2' => str_repeat('あ', 100),
            'col3' => null,
        ], 10);

        $this->assertStringContainsString('XXXXXXX...', $comment);
        $this->assertStringContainsString('あああ...', $comment);
        $this->assertStringContainsString('NULL', $comment);
    }

    /**
     * @test
     */
    function getInsertSql_bulk()
    {
        $rows      = [
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
            ['id' => 4],
            ['id' => 5],
        ];
        $providers = [
            'array'     => fn() => $rows,
            'generator' => fn() => yield from $rows,
        ];

        foreach ($providers as $provider) {
            $this->assertCount(5, iterator_to_array($this->scanner->getInsertSql($provider(), 0)));

            $sqls = iterator_to_array($this->scanner->getInsertSql($provider(), 3));
            $this->assertCount(2, $sqls);
            $this->assertStringContainsString("(1)", $sqls[0]);
            $this->assertStringContainsString("(2)", $sqls[0]);
            $this->assertStringContainsString("(3)", $sqls[0]);
            $this->assertStringContainsString("(4)", $sqls[1]);
            $this->assertStringContainsString("(5)", $sqls[1]);
        }

        $providers = [
            'array'     => fn() => [],
            'generator' => fn() => yield from [],
        ];

        foreach ($providers as $provider) {
            $this->assertCount(0, iterator_to_array($this->scanner->getInsertSql($provider(), 0)));

            $sqls = iterator_to_array($this->scanner->getInsertSql($provider(), 3));
            $this->assertCount(0, $sqls);
        }
    }

    /**
     * @test
     */
    function getOrphanRows()
    {
        $this->connection->disableConstraint(true);

        $rows = array_map(function ($i) {
            return [
                'id' => $i,
            ];
        }, range(1, 6));
        $this->insertMultiple($this->connection, 'hoge', $rows);

        $rows = array_map(function ($i) {
            return [
                'id1'     => $i,
                'id2'     => $i,
                'fid1'    => $i,
                'fid2'    => $i % 2 === 0 ? $i : null,
                'data'    => $i,
                'ignored' => $i,
            ];
        }, range(1, 10));
        $this->insertMultiple($this->connection, 'fuga', $rows);

        $this->connection->disableConstraint(false);

        $rows = $this->scanner_fuga->getOrphanRows($this->schema->introspectTable('fuga')->getForeignKey('fk_fuga_hoge1'));
        $this->assertCount(4, iterator_to_array($rows));

        $rows = $this->scanner_fuga->getOrphanRows($this->schema->introspectTable('fuga')->getForeignKey('fk_fuga_hoge2'));
        $this->assertCount(2, iterator_to_array($rows));

        $this->connection->disableConstraint(false);
    }

    /**
     * @test
     */
    function getRecordFromPrimaryKeys_empty()
    {
        $rows = $this->invoke('getRecordFromPrimaryKeys', [], true);

        $this->assertCount(0, iterator_to_array($rows));
    }

    /**
     * @test
     */
    function getRecordFromPrimaryKeys_multi()
    {
        $rows = array_map(function ($i) {
            return [
                'id' => $i,
            ];
        }, range(1, 10));
        $this->insertMultiple($this->connection, 'hoge', $rows);

        $rows = array_map(function ($i) {
            return [
                'id1'     => $i,
                'id2'     => $i,
                'fid1'    => null,
                'fid2'    => null,
                'data'    => $i,
                'ignored' => $i,
            ];
        }, range(1, 10));
        $this->insertMultiple($this->connection, 'fuga', $rows);

        $tuples = iterator_to_array($this->scanner_fuga->getPrimaryRows());

        $refmethod = new \ReflectionMethod($this->scanner_fuga, 'getRecordFromPrimaryKeys');
        $refmethod->setAccessible(true);

        $rows = array_map(function ($row) {
            unset($row['ignored']);
            return $row;
        }, $rows);
        $this->assertEquals($rows, iterator_to_array($refmethod->invoke($this->scanner_fuga, $tuples, false)));
    }

    /**
     * @test
     */
    function associateRecords()
    {
        $tuples = iterator_to_array($this->scanner_fuga->associateRecords([
            [
                'id1'     => 1,
                'id2'     => 1,
                'data'    => 'data1',
                'ignored' => 'ignored1',
            ],
            [
                'id1'     => 2,
                'id2'     => 2,
                'data'    => 'data2',
                'ignored' => 'ignored2',
            ],
        ]));
        $this->assertEquals([
            "1\t1" => [
                'id1'     => 1,
                'id2'     => 1,
                'data'    => 'data1',
                'ignored' => '',
            ],
            "2\t2" => [
                'id1'     => 2,
                'id2'     => 2,
                'data'    => 'data2',
                'ignored' => '',
            ],
        ], $tuples);
    }

    /**
     * @test
     */
    function buildWhere()
    {
        $method = 'buildWhere';

        $expected = "((`id`    = 1 AND `subid` = 1) OR (`id`    = 1 AND `subid` = 2))";
        $this->assertEquals($expected, $this->invoke($method, [
            ['id' => 1, 'subid' => 1],
            ['id' => 1, 'subid' => 2],
        ]));

        $expected = "(`id` IN (1,2))";
        $this->assertEquals($expected, $this->invoke($method, [
            ['id' => 1],
            ['id' => 2],
        ]));
    }

    /**
     * @test
     */
    function fillDefaultValue()
    {
        $con = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'pdo' => new \PDO('sqlite::memory:'), 'wrapperClass' => Connection::class]);

        $table = new Table('deftable',
            [
                new Column('id', Type::getType('integer')),
                new Column('havedef', Type::getType('integer'), ['default' => 9]),
                new Column('nullable', Type::getType('integer'), ['notnull' => false]),
                (new Column('generated', Type::getType('integer'), ['notnull' => false]))->setPlatformOption('generation', ['type' => 'STORED']),
            ],
            [new Index('PRIMARY', ['id'], true, true)]
        );

        $this->readyObject($con->createSchemaManager(), $table);

        $scanner = new TableScanner($con, $table, ['1']);

        $this->assertEquals([
            'id'       => 0,
            'havedef'  => 9,
            'nullable' => null,
        ], $scanner->fillDefaultValue([]));
    }

    /**
     * @test
     */
    function getInsertSql_no_mysql()
    {
        $old = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'pdo' => new \PDO('sqlite::memory:'), 'wrapperClass' => Connection::class]);
        $new = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'pdo' => new \PDO('sqlite::memory:'), 'wrapperClass' => Connection::class]);

        $table = new Table('hogetable',
            [new Column('id', Type::getType('integer'))],
            [new Index('PRIMARY', ['id'], true, true)]
        );

        $this->readyObject($old->createSchemaManager(), $table);
        $this->readyObject($new->createSchemaManager(), $table);

        $this->insertMultiple($new, 'hogetable', [['id' => 1]]);

        $scanner = new TableScanner($old, $table, ['1']);
        $inserts = iterator_to_array($scanner->getInsertSql([['id' => 1]], 0));

        // sqlite no support INSERT SET syntax. Therefore VALUES (value)
        $this->assertStringContainsString('INSERT INTO "hogetable" ("id") VALUES', $inserts[0]);
    }
}
