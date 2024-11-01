<?php

namespace ryunosuke\Test\DbMigration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type;
use ryunosuke\DbMigration\Connection;

class ConnectionTest extends AbstractTestCase
{
    function test_lockGlobal()
    {
        /** @var Connection $tmpcon */
        $tmpcon = DriverManager::getConnection($this->connection->getParams() + ['wrapperClass' => Connection::class]);
        $tmpcon->lockGlobal('uu', 0);

        $this->assertTrue($this->connection->lockGlobal('tt', 0));
        $this->assertFalse($this->connection->lockGlobal('uu', 0));

        $tmpcon->close();
    }

    function test_disableConstraint()
    {
        $this->assertFalse($this->connection->disableConstraint(true));
        $this->assertEquals([
            'uc' => 0,
            'fc' => 0,
        ], $this->connection->executeQuery('SELECT @@UNIQUE_CHECKS AS uc, @@FOREIGN_KEY_CHECKS AS fc')->fetchAssociative());

        $this->assertTrue($this->connection->disableConstraint(false));
        $this->assertEquals([
            'uc' => 1,
            'fc' => 1,
        ], $this->connection->executeQuery('SELECT @@UNIQUE_CHECKS AS uc, @@FOREIGN_KEY_CHECKS AS fc')->fetchAssociative());
    }

    function test_queryUnbuffered()
    {
        $parser = new DsnParser();

        $table = new Table('t_many');
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(['id']);

        /** @var Connection $conn */
        $conn = DriverManager::getConnection($parser->parse('pdo-sqlite://:memory:') + ['wrapperClass' => Connection::class]);
        $this->readyTable($conn->createSchemaManager(), $table);
        $this->insertMultiple($conn, 't_many', [['id' => 1], ['id' => 2]]);
        $this->assertEquals([['id' => 1], ['id' => 2]], [...$conn->queryUnbuffered('select * from t_many')]);

        /** @var Connection $conn */
        $conn = DriverManager::getConnection($parser->parse('pdo-mysql://' . $GLOBALS['db']) + ['wrapperClass' => Connection::class]);
        $this->readyTable($conn->createSchemaManager(), $table);
        $this->insertMultiple($conn, 't_many', [['id' => 1], ['id' => 2]]);
        $this->assertEquals([['id' => 1], ['id' => 2]], [...$conn->queryUnbuffered('select * from t_many')]);

        /** @var Connection $conn */
        $conn = DriverManager::getConnection($parser->parse('mysqli://' . $GLOBALS['db']) + ['wrapperClass' => Connection::class]);
        $this->readyTable($conn->createSchemaManager(), $table);
        $this->insertMultiple($conn, 't_many', [['id' => 1], ['id' => 2]]);
        $this->assertEquals([['id' => 1], ['id' => 2]], [...$conn->queryUnbuffered('select * from t_many')]);
    }

    function test_insert()
    {
        $conn = DriverManager::getConnection($this->connection->getParams() + ['wrapperClass' => Connection::class]);
        $table_hoge = $this->createSimpleTable('hoge', 'integer', 'id');
        $table_hoge->getColumn('id')->setAutoincrement(true);
        $this->readyTable($this->schema, $table_hoge);

        $this->assertEquals(1, $conn->insert('hoge', []));
        $this->assertEquals(1, $conn->insert('hoge', ['id' => 3]));
        $this->assertEquals([
            ['id' => 1],
            ['id' => 3],
        ], $conn->fetchAllAssociative('select * from hoge'));
    }

    function test_upsert()
    {
        $table_hoge = $this->createSimpleTable('hoge', 'integer', 'id', 'name');
        $table_hoge->getColumn('id')->setAutoincrement(true);
        $table_hoge->getColumn('name')->setDefault('X');
        $table_hoge->getColumn('name')->setType(Type::getType('string'));

        $conns = (function () {
            yield DriverManager::getConnection($this->connection->getParams() + ['wrapperClass' => Connection::class]) => [
                'initial'  => [],
                'affected' => 2,
            ];
            yield DriverManager::getConnection(['driver' => 'sqlite3', 'memory' => true] + ['wrapperClass' => Connection::class]) => [
                'initial'  => ['name' => 'X'],
                'affected' => 1,
            ];
        })();
        foreach ($conns as $conn => ['initial' => $initial, 'affected' => $affected]) {
            /** @var Connection $conn */
            $this->readyTable($conn->createSchemaManager(), $table_hoge);

            $this->assertEquals(1, $conn->upsert('hoge', $initial));
            $this->assertEquals(1, $conn->upsert('hoge', ['id' => 2, 'name' => 'A']));
            $this->assertEquals($affected, $conn->upsert('hoge', ['id' => 2, 'name' => 'B']));
            $this->assertEquals([
                ['id' => 1, 'name' => 'X'],
                ['id' => 2, 'name' => 'B'],
            ], $conn->fetchAllAssociative('select * from hoge'));
        }
    }

    function test_quote()
    {
        $parser = new DsnParser();

        /** @var Connection $conn */
        $conn = DriverManager::getConnection($parser->parse('pdo-mysql://' . $GLOBALS['db']) + ['wrapperClass' => Connection::class]);
        $conn->maintainType(true);
        $this->assertSame("NULL", $conn->quote(null));
        $this->assertSame("FALSE", $conn->quote(false));
        $this->assertSame("TRUE", $conn->quote(true));
        $this->assertSame(123, $conn->quote(123));
        $this->assertSame(3.14, $conn->quote(3.14));
        $this->assertSame("'abc'", $conn->quote('abc'));
        $this->assertSame(["NULL", "FALSE", 123, 3.14, "'abc'"], $conn->quote([null, false, 123, 3.14, 'abc']));

        /** @var Connection $conn */
        $conn = DriverManager::getConnection($parser->parse('mysqli://' . $GLOBALS['db']) + ['wrapperClass' => Connection::class]);
        $conn->maintainType(false);
        $this->assertSame("NULL", $conn->quote(null));
        $this->assertSame("''", $conn->quote(false));
        $this->assertSame("'1'", $conn->quote(true));
        $this->assertSame("'123'", $conn->quote(123));
        $this->assertSame("'3.14'", $conn->quote(3.14));
        $this->assertSame("'abc'", $conn->quote('abc'));
        $this->assertSame(["NULL", "''", "'123'", "'3.14'", "'abc'"], $conn->quote([null, false, 123, 3.14, 'abc']));
    }

    function test_quoteIdentifier()
    {
        $this->assertEquals("``", $this->connection->quoteIdentifier(''));
        $this->assertEquals("`123`", $this->connection->quoteIdentifier(123));
        $this->assertEquals("`abc`", $this->connection->quoteIdentifier('abc'));
        $this->assertEquals(["``", "`123`", "`abc`"], $this->connection->quoteIdentifier(['', 123, 'abc']));
    }
}
