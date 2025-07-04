<?php

namespace ryunosuke\Test\DbMigration;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type;
use ryunosuke\DbMigration\Connection;
use ryunosuke\DbMigration\FileType\Tool\Binary;

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
        $this->readyObject($conn->createSchemaManager(), $table);
        $this->insertMultiple($conn, 't_many', [['id' => 1], ['id' => 2]]);
        $this->assertEquals([['id' => 1], ['id' => 2]], [...$conn->queryUnbuffered('select * from t_many')]);

        /** @var Connection $conn */
        $conn = DriverManager::getConnection($parser->parse('pdo-mysql://' . $GLOBALS['db']) + ['wrapperClass' => Connection::class]);
        $this->readyObject($conn->createSchemaManager(), $table);
        $this->insertMultiple($conn, 't_many', [['id' => 1], ['id' => 2]]);
        $this->assertEquals([['id' => 1], ['id' => 2]], [...$conn->queryUnbuffered('select * from t_many')]);

        /** @var Connection $conn */
        $conn = DriverManager::getConnection($parser->parse('mysqli://' . $GLOBALS['db']) + ['wrapperClass' => Connection::class]);
        $this->readyObject($conn->createSchemaManager(), $table);
        $this->insertMultiple($conn, 't_many', [['id' => 1], ['id' => 2]]);
        $this->assertEquals([['id' => 1], ['id' => 2]], [...$conn->queryUnbuffered('select * from t_many')]);
    }

    function test_buildInsertSql()
    {
        /** @var Connection $conn */
        $conn = DriverManager::getConnection($this->connection->getParams() + ['wrapperClass' => Connection::class]);

        $this->assertEquals("INSERT INTO hoge (id,name) VALUES ('1','X')", $conn->buildInsertSql('hoge', ['id' => 1, 'name' => 'X'], false));
        $this->assertEquals("INSERT INTO hoge () VALUES ()", $conn->buildInsertSql('hoge', [], false));

        $this->assertEquals("INSERT INTO hoge () VALUES ()", $conn->buildInsertSql('hoge', [], true));
        $this->assertEquals("INSERT INTO hoge (id,name) VALUES ('1','X') AS new ON DUPLICATE KEY UPDATE id = new.id,name = new.name", $conn->buildInsertSql('hoge', ['id' => 1, 'name' => 'X'], true));
    }

    function test_insert()
    {
        $conn       = DriverManager::getConnection($this->connection->getParams() + ['wrapperClass' => Connection::class]);
        $table_hoge = $this->createSimpleTable('hoge', 'integer', 'id');
        $table_hoge->getColumn('id')->setAutoincrement(true);
        $this->readyObject($this->schema, $table_hoge);

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
        $table_hoge->getColumn('name')->setLength(64);

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
            $this->readyObject($conn->createSchemaManager(), $table_hoge);

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
        $conn = DriverManager::getConnection($parser->parse('pdo-sqlite://:memory:') + ['wrapperClass' => Connection::class]);
        $this->assertSame("E'\\\\x686f67652066756761'", $conn->quoteValues(new Binary('hoge fuga')));

        /** @var Connection $conn */
        $conn = DriverManager::getConnection($parser->parse('pdo-mysql://' . $GLOBALS['db']) + ['wrapperClass' => Connection::class]);
        $conn->maintainType(true);
        $this->assertSame("NULL", $conn->quoteValues(null));
        $this->assertSame("FALSE", $conn->quoteValues(false));
        $this->assertSame("TRUE", $conn->quoteValues(true));
        $this->assertSame(123, $conn->quoteValues(123));
        $this->assertSame(3.14, $conn->quoteValues(3.14));
        $this->assertSame("'abc'", $conn->quoteValues('abc'));
        $this->assertSame(["NULL", "FALSE", 123, 3.14, "'abc'"], $conn->quoteValues([null, false, 123, 3.14, 'abc']));
        $this->assertSame("0x686f67652066756761", $conn->quoteValues(new Binary('hoge fuga')));
        $this->assertSame("NULL", $conn->quoteValues(null));

        /** @var Connection $conn */
        $conn = DriverManager::getConnection($parser->parse('mysqli://' . $GLOBALS['db']) + ['wrapperClass' => Connection::class]);
        $conn->maintainType(false);
        $this->assertSame("NULL", $conn->quoteValues(null));
        $this->assertSame("''", $conn->quoteValues(false));
        $this->assertSame("'1'", $conn->quoteValues(true));
        $this->assertSame("'123'", $conn->quoteValues(123));
        $this->assertSame("'3.14'", $conn->quoteValues(3.14));
        $this->assertSame("'abc'", $conn->quoteValues('abc'));
        $this->assertSame(["NULL", "''", "'123'", "'3.14'", "'abc'"], $conn->quoteValues([null, false, 123, 3.14, 'abc']));
        $this->assertSame("0x686f67652066756761", $conn->quoteValues(new Binary('hoge fuga')));
    }

    function test_quoteIdentifier()
    {
        $this->assertEquals("``", $this->connection->quoteIdentifiers(''));
        $this->assertEquals("`123`", $this->connection->quoteIdentifiers(123));
        $this->assertEquals("`abc`", $this->connection->quoteIdentifiers('abc'));
        $this->assertEquals(["``", "`123`", "`abc`"], $this->connection->quoteIdentifiers(['', 123, 'abc']));
    }
}
