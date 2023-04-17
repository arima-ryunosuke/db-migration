<?php

namespace ryunosuke\Test\DbMigration;

use Doctrine\DBAL\DriverManager;
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

    function test_quote()
    {
        $this->assertEquals("NULL", $this->connection->quote(null));
        $this->assertEquals("'123'", $this->connection->quote(123));
        $this->assertEquals("'abc'", $this->connection->quote('abc'));
        $this->assertEquals(["NULL", "'123'", "'abc'"], $this->connection->quote([null, 123, 'abc']));
    }

    function test_quoteIdentifier()
    {
        $this->assertEquals("``", $this->connection->quoteIdentifier(null));
        $this->assertEquals("`123`", $this->connection->quoteIdentifier(123));
        $this->assertEquals("`abc`", $this->connection->quoteIdentifier('abc'));
        $this->assertEquals(["``", "`123`", "`abc`"], $this->connection->quoteIdentifier([null, 123, 'abc']));
    }
}
