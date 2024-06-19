<?php

namespace ryunosuke\Test\DbMigration;

use ryunosuke\DbMigration\MigrationTable;

class MigrationTableTest extends AbstractTestCase
{
    public function test_exists_create_alter_drop()
    {
        $migrationTable = new MigrationTable($this->connection, 'migtable');

        $this->assertEquals([], $migrationTable->diff());
        $this->assertFalse($migrationTable->exists());
        $this->assertTrue($migrationTable->create());
        $this->assertTrue($migrationTable->exists());
        $this->assertFalse($migrationTable->create());

        $this->assertFalse($migrationTable->alter());
        $this->connection->executeStatement('ALTER TABLE migtable ADD COLUMN dummy INT');
        $this->assertEquals(['ALTER TABLE migtable DROP dummy'], $migrationTable->diff());
        $this->assertTrue($migrationTable->alter());

        $this->assertTrue($migrationTable->drop());
        $this->assertFalse($migrationTable->exists());
        $this->assertFalse($migrationTable->drop());
    }

    public function test_glob()
    {
        $migrationTable = new MigrationTable($this->connection, 'migtable');
        $versions       = $migrationTable->glob(__DIR__ . '/_files/migs');
        $this->assertEquals([
            'aaa.sql' => 'insert into hoge values ()',
            'bbb.php' => "<?php\nreturn 'insert into hoge values ()';\n",
        ], array_map('strval', $versions));
    }

    public function test_apply()
    {
        $this->schema->createTable($this->createSimpleTable('ttt', 'string', 'name'));

        $migrationTable = new MigrationTable($this->connection, 'migtable');
        $migrationTable->drop();
        $this->assertEquals([], $migrationTable->fetch());
        $migrationTable->create();

        $this->assertEquals(2, $migrationTable->apply('1.sql', [
            'insert into ttt values("from sql1")',
            'insert into ttt values("from sql2")',
        ]));
        $this->assertEquals(2, $migrationTable->apply('2.sql', [
            'ttt' => [
                ['name' => 'from array1'],
                ['name' => 'from array2'],
                ['name' => 'from array2'],
            ],
        ]));

        // attached
        $versions = $migrationTable->fetch();
        $this->assertJsonStringEqualsJsonString(json_encode(['insert into ttt values("from sql1")', 'insert into ttt values("from sql2")']), $versions['1.sql']['logs']);
        $this->assertJsonStringEqualsJsonString(json_encode([["name" => "from array1"], ["name" => "from array2"], ["name" => "from array2"]]), $versions['2.sql']['logs']);

        // migrated
        $this->assertEquals([
            ['name' => 'from array1'],
            ['name' => 'from array2'],
            ['name' => 'from sql1'],
            ['name' => 'from sql2'],
        ], $this->connection->fetchAllAssociative('select * from ttt'));

        $this->assertEquals(4, $migrationTable->apply('11.sql', (array) 'update ttt set name = concat(name, " suffix")'));
        $this->assertEquals(3, $migrationTable->apply('12.sql', (array) 'delete from ttt where name <> "from sql1 suffix"'));
    }

    public function test_attach_detach()
    {
        $migrationTable = new MigrationTable($this->connection, 'migtable');
        $migrationTable->create();

        $this->assertEquals(3, $migrationTable->attach(['aaa', 'bbb', 'ccc']));
        $this->assertEquals(['aaa', 'bbb', 'ccc'], array_keys($migrationTable->fetch()));
        $this->assertEquals(2, $migrationTable->detach(['aaa', 'ccc']));
        $this->assertEquals(['bbb'], array_keys($migrationTable->fetch()));
    }
}
