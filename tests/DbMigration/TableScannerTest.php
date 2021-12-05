<?php

namespace ryunosuke\Test\DbMigration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
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
        $this->readyTable($this->oldSchema, $table_hoge);

        $table_fuga = new Table('fuga');
        $table_fuga->addColumn('id1', 'integer');
        $table_fuga->addColumn('id2', 'integer');
        $table_fuga->addColumn('data', 'string');
        $table_fuga->setPrimaryKey(['id1', 'id2']);
        $this->readyTable($this->oldSchema, $table_fuga);

        $this->scanner = new TableScanner($this->old, $table_hoge, ['TRUE']);
        $this->refClass = new \ReflectionClass($this->scanner);

        $this->scanner_fuga = new TableScanner($this->old, $table_fuga, ['TRUE']);
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
                'id1'  => $i,
                'id2'  => $i,
                'data' => $i,
            ];
        }, range(1, 10));
        $this->insertMultiple($this->old, 'fuga', $rows);

        $tuples = $this->scanner_fuga->getPrimaryRows();

        $refmethod = new \ReflectionMethod($this->scanner_fuga, 'getRecordFromPrimaryKeys');
        $refmethod->setAccessible(true);

        $this->assertEquals($rows, iterator_to_array($refmethod->invoke($this->scanner_fuga, $tuples, false)));
    }

    /**
     * @test
     */
    function getRecordFromPrimaryKeys_page()
    {
        $this->insertMultiple($this->old, 'hoge', array_map(function ($i) {
            return [
                'id' => $i,
            ];
        }, range(1, 10)));

        TableScanner::$pageCount = 4;

        $method = 'getRecordFromPrimaryKeys';
        $tuples = $this->scanner->getPrimaryRows();

        $this->assertCount(4, $this->invoke($method, $tuples, true, 0));
        $this->assertCount(4, $this->invoke($method, $tuples, true, 1));
        $this->assertCount(2, $this->invoke($method, $tuples, true, 2));
        $this->assertCount(0, $this->invoke($method, $tuples, true, 3));
    }

    /**
     * @test
     */
    function buildWhere()
    {
        $method = 'buildWhere';

        $expected = "((`id`    = '1' AND `subid` = '1') OR (`id`    = '1' AND `subid` = '2'))";
        $this->assertEquals($expected, $this->invoke($method, [
            ['id' => 1, 'subid' => 1],
            ['id' => 1, 'subid' => 2],
        ]));

        $expected = "(`id` IN ('1','2'))";
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
        $con = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'pdo' => new \PDO('sqlite::memory:')]);

        $table = new Table('deftable',
            [
                new Column('id', Type::getType('integer')),
                new Column('havedef', Type::getType('integer'), ['default' => 9]),
                new Column('nullable', Type::getType('integer'), ['notnull' => false]),
            ],
            [new Index('PRIMARY', ['id'], true, true)]
        );

        $this->readyTable($con->createSchemaManager(), $table);

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
        $old = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'pdo' => new \PDO('sqlite::memory:')]);
        $new = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'pdo' => new \PDO('sqlite::memory:')]);

        $table = new Table('hogetable',
            [new Column('id', Type::getType('integer'))],
            [new Index('PRIMARY', ['id'], true, true)]
        );

        $this->readyTable($old->createSchemaManager(), $table);
        $this->readyTable($new->createSchemaManager(), $table);

        $this->insertMultiple($new, 'hogetable', [['id' => 1]]);

        $scanner = new TableScanner($old, $table, ['1']);
        $inserts = $scanner->getInsertSql([['id' => 1]], new TableScanner($new, $table, ['1']));

        // sqlite no support INSERT SET syntax. Therefore VALUES (value)
        $this->assertStringContainsString('INSERT INTO "hogetable" ("id") VALUES', $inserts[0]);
    }
}
