<?php

namespace ryunosuke\Test\DbMigration;

use ryunosuke\DbMigration\MigrationTable;

class MigrationTableTest extends AbstractTestCase
{
    public function test_exists_create_drop()
    {
        $migrationTable = new MigrationTable($this->old, 'migtable');

        $this->assertFalse($migrationTable->exists());
        $this->assertTrue($migrationTable->create());
        $this->assertTrue($migrationTable->exists());
        $this->assertFalse($migrationTable->create());
        $this->assertTrue($migrationTable->drop());
        $this->assertFalse($migrationTable->exists());
        $this->assertFalse($migrationTable->drop());
    }

    public function test_glob()
    {
        $migrationTable = new MigrationTable($this->old, 'migtable');
        $versions = $migrationTable->glob(__DIR__ . '/_files/migs');
        $this->assertEquals([
            'aaa.sql' => 'insert into hoge values ()',
            'bbb.php' => "<?php\nreturn 'insert into hoge values ()';\n",
        ], $versions);
    }

    public function test_apply()
    {
        $this->oldSchema->createTable($this->createSimpleTable('ttt', 'string', 'name'));

        $migrationTable = new MigrationTable($this->old, 'migtable');
        $migrationTable->drop();
        $migrationTable->create();

        $migrationTable->apply('1.sql', 'insert into ttt values("from sql")');
        $migrationTable->apply('2.php', '<?php return "insert into ttt values(\"from php(return)\")";');
        $migrationTable->apply('3.php', '<?php $connection->insert("ttt", array("name" => "from php(code)"));');
        $migrationTable->apply('4.php', '<?php return function($connection){$connection->insert("ttt", array("name" => "from php(mixed code)"));return "insert into ttt values(\"from php(mixed closure)\")";};');

        // attached
        $this->assertEquals(['1.sql', '2.php', '3.php', '4.php'], array_keys($migrationTable->fetch()));

        // migrated
        $this->assertEquals([
            ['name' => 'from php(code)'],
            ['name' => 'from php(mixed closure)'],
            ['name' => 'from php(mixed code)'],
            ['name' => 'from php(return)'],
            ['name' => 'from sql'],
        ], $this->old->fetchAll('select * from ttt'));

        // throws
        $this->setExpectedException('\InvalidArgumentException');
        $migrationTable->apply('bad.SQL', 'insert into ttt values("bad")');
    }

    public function test_attach_detach()
    {
        $migrationTable = new MigrationTable($this->old, 'migtable');
        $migrationTable->create();

        $this->assertEquals(3, $migrationTable->attach(['aaa', 'bbb', 'ccc']));
        $this->assertEquals(['aaa', 'bbb', 'ccc'], array_keys($migrationTable->fetch()));
        $this->assertEquals(2, $migrationTable->detach(['aaa', 'ccc']));
        $this->assertEquals(['bbb'], array_keys($migrationTable->fetch()));
    }
}
