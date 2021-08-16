<?php

namespace ryunosuke\Test\DbMigration;

use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\View;
use ryunosuke\DbMigration\Exception\MigrationException;
use ryunosuke\DbMigration\Transporter;
use ryunosuke\DbMigration\Utility;
use Symfony\Component\Yaml\Yaml;

class TransporterTest extends AbstractTestCase
{
    /**
     * @var Transporter
     */
    private $transporter;

    /**
     * @var \ReflectionClass
     */
    private $refClass;

    protected function setup()
    {
        parent::setUp();

        $table = new Table('hoge');
        $table->addColumn('id', 'integer');
        $table->addColumn('name', 'string', ['length' => 10]);
        $table->addColumn('data', 'float', ['scale' => 5]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['name'], 'SECONDARY');
        $table->addUniqueIndex(['data']);
        $this->oldSchema->dropAndCreateTable($table);

        $table = new Table('fuga');
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(['id']);
        $table->addForeignKeyConstraint('hoge', ['id'], ['id']);
        $this->oldSchema->dropAndCreateTable($table);

        $table = new Table('parent');
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(['id']);
        $this->oldSchema->dropAndCreateTable($table);

        $table = new Table('child');
        $table->addColumn('id', 'integer');
        $table->addColumn('pid', 'integer');
        $table->setPrimaryKey(['id', 'pid']);
        $table->addForeignKeyConstraint('parent', ['id'], ['id']);
        $indexes = new \ReflectionProperty($table, 'implicitIndexes');
        $indexes->setAccessible(true);
        foreach ($indexes->getValue($table) as $index) {
            /** @var Index $index */
            $table->dropIndex($index->getName());
        }
        $this->oldSchema->dropAndCreateTable($table);

        $table = new Table('zzz');
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(['id']);
        $table->addTrigger('trg1', 'INSERT INTO hoge VALUES()', [
            'Timing' => 'BEFORE',
            'Event'  => 'UPDATE',
        ]);
        $table->addTrigger('trg2', 'INSERT INTO hoge VALUES()', [
            'Timing' => 'AFTER',
            'Event'  => 'DELETE',
        ]);
        $this->oldSchema->dropAndCreateTable($table);

        $view = new View('vvview', 'select * from hoge');
        $this->oldSchema->dropAndCreateView($view);

        $this->insertMultiple($this->old, 'hoge', array_map(function ($i) {
            return [
                'id'   => $i,
                'name' => 'name-' . $i,
                'data' => $i + 0.5,
            ];
        }, range(1, 10)));


        // create migration table different name
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('hogera', 'integer', 'id'));
        $this->newSchema->dropAndCreateTable($this->createSimpleTable('fugata', 'integer', 'id'));

        // create migration table no pkey
        $table = $this->createSimpleTable('nopkey', 'integer', 'id');
        $table->dropPrimaryKey();
        $this->oldSchema->dropAndCreateTable($table);
        $this->newSchema->dropAndCreateTable($table);

        // create migration table different pkey
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('diffpkey', 'integer', 'id'));
        $this->newSchema->dropAndCreateTable($this->createSimpleTable('diffpkey', 'integer', 'seq'));

        // create migration table different column
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('diffcolumn', 'integer', 'id'));
        $this->newSchema->dropAndCreateTable($this->createSimpleTable('diffcolumn', 'integer', 'id', 'seq'));

        // create migration table different type
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('difftype', 'string', 'id'));
        $this->newSchema->dropAndCreateTable($this->createSimpleTable('difftype', 'integer', 'id'));

        // create migration table different record
        $table = $this->createSimpleTable('foo', 'integer', 'id');
        $table->addColumn('c_int', 'integer');
        $table->addColumn('c_float', 'float');
        $table->addColumn('c_varchar', 'string');
        $table->addColumn('c_text', 'text');
        $table->addColumn('c_datetime', 'datetime');

        $this->oldSchema->dropAndCreateTable($table);
        $this->newSchema->dropAndCreateTable($table);

        $this->insertMultiple($this->old, 'foo', [
            '{"id":0,"c_int":1,"c_float":1.2,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":1,"c_int":2,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":2,"c_int":1,"c_float":2,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":3,"c_int":1,"c_float":1,"c_varchar":"charX","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":4,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"textX","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":5,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"1999-01-01 00:00:00"}',
            '{"id":6,"c_int":2,"c_float":2,"c_varchar":"charX","c_text":"textX","c_datetime":"1999-01-01 00:00:00"}',
            '{"id":8,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":9,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":99,"c_int":1,"c_float":1.2,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
        ]);
        $this->insertMultiple($this->new, 'foo', [
            '{"id":-2,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":-1,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":0,"c_int":1,"c_float":1.2,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":1,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":2,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":3,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":4,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":5,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":6,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":99,"c_int":2,"c_float":1.4,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
        ]);

        $this->transporter = new Transporter($this->old);
        $this->refClass = new \ReflectionClass($this->transporter);

        $this->transporter->setDirectory('table', null);
        $this->transporter->setDirectory('view', null);

        Utility::getSchema($this->old, true);
        Utility::getSchema($this->new, true);
    }

    /**
     * @test
     */
    function explodeSql()
    {
        $method = $this->refClass->getMethod('explodeSql');
        $method->setAccessible(true);

        $this->assertEquals([''], $method->invoke($this->transporter, ''));
        $this->assertEquals(['hoge', 'fuga'], $method->invoke($this->transporter, 'hoge;fuga'));
        $this->assertEquals(['hoge', 'fuga', ''], $method->invoke($this->transporter, 'hoge;fuga;'));
        $this->assertEquals(['"ho;ge"'], $method->invoke($this->transporter, '"ho;ge"'));
        $this->assertEquals(['aa"ho;ge"bb'], $method->invoke($this->transporter, 'aa"ho;ge"bb'));
        $this->assertEquals(['h\"o', 'ge'], $method->invoke($this->transporter, 'h\\"o;ge'));
        $this->assertEquals(['"ho;\";ge"'], $method->invoke($this->transporter, '"ho;\";ge"'));
        $this->assertEquals(['"ho\';ge"'], $method->invoke($this->transporter, '"ho\';ge"'));
        $this->assertEquals(['あ', 'い'], $method->invoke($this->transporter, 'あ;い'));
    }

    /**
     * @test
     */
    function exportDDL()
    {
        $this->transporter->exportDDL(self::$tmpdir . '/table.sql');
        $this->assertFileContains('CREATE TABLE hoge', self::$tmpdir . '/table.sql');

        $this->transporter->exportDDL(self::$tmpdir . '/table.php');
        $this->transporter->exportDDL(self::$tmpdir . '/table.json');
        $this->transporter->exportDDL(self::$tmpdir . '/table.yaml');

        $php = include self::$tmpdir . '/table.php';
        $json = json_decode(file_get_contents(self::$tmpdir . '/table.json'), true);
        $yaml = Yaml::parse(file_get_contents(self::$tmpdir . '/table.yaml'));

        $this->assertEquals($php, $json);
        $this->assertEquals($json, $yaml);
        $this->assertEquals($yaml, $php);

        $this->assertArrayHasKey('table', $php);
        $this->assertArrayHasKey('zzz', $php['table']);
        $this->assertArrayHasKey('trigger', $php['table']['zzz']);
        $this->assertArrayHasKey('trg1', $php['table']['zzz']['trigger']);

        $this->assertException(new \DomainException("is not supported"), function () {
            $this->transporter->exportDDL(self::$tmpdir . '/table.ext');
        });
    }

    /**
     * @test
     */
    function exportDDL_filter()
    {
        $this->transporter->exportDDL(self::$tmpdir . '/table.sql', ['.*g.*'], ['fuga']);
        $sql = file_get_contents(self::$tmpdir . '/table.sql');
        $this->assertContains('CREATE TABLE hoge', $sql);
        $this->assertNotContains('CREATE TABLE fuga', $sql);

        $this->transporter->exportDDL(self::$tmpdir . '/table.yaml', ['.*g.*'], []);
        $yaml = Yaml::parse(file_get_contents(self::$tmpdir . '/table.yaml'));
        $this->assertEquals(['fuga', 'hoge', 'hogera'], array_keys($yaml['table']));

        $this->transporter->exportDDL(self::$tmpdir . '/table.yaml', ['hoge', 'fuga'], []);
        $yaml = Yaml::parse(file_get_contents(self::$tmpdir . '/table.yaml'));
        $this->assertEquals(['fuga', 'hoge', 'hogera'], array_keys($yaml['table']));

        $this->transporter->exportDDL(self::$tmpdir . '/table.yaml', ['.*g.*'], ['fuga']);
        $yaml = Yaml::parse(file_get_contents(self::$tmpdir . '/table.yaml'));
        $this->assertEquals(['hoge', 'hogera'], array_keys($yaml['table']));
    }

    /**
     * @test
     */
    function exportDDL_noview()
    {
        $this->transporter->enableView(false);
        $this->transporter->exportDDL(self::$tmpdir . '/table.sql');
        $sql = file_get_contents(self::$tmpdir . '/table.sql');
        $this->assertNotContains('CREATE VIEW vvview', $sql);

        $this->transporter->exportDDL(self::$tmpdir . '/table.yaml');
        $yaml = Yaml::parse(file_get_contents(self::$tmpdir . '/table.yaml'));
        $this->assertEmpty($yaml['view']);
    }

    /**
     * @test
     */
    function exportDML()
    {
        $this->transporter->exportDML(self::$tmpdir . '/hoge.sql');
        $this->assertFileContains("INSERT INTO `hoge` (`id`, `name`, `data`) VALUES ('1', 'name-1', '1.5')", self::$tmpdir . '/hoge.sql');

        $this->transporter->exportDML(self::$tmpdir . '/hoge.php');
        $this->transporter->exportDML(self::$tmpdir . '/hoge.json');
        $this->transporter->exportDML(self::$tmpdir . '/hoge.yaml');

        $php = include self::$tmpdir . '/hoge.php';
        $json = json_decode(file_get_contents(self::$tmpdir . '/hoge.json'), true);
        $yaml = Yaml::parse(file_get_contents(self::$tmpdir . '/hoge.yaml'));

        $this->assertEquals($php, $json);
        $this->assertEquals($json, $yaml);
        $this->assertEquals($yaml, $php);

        $this->assertException(new \DomainException("is not supported"), function () {
            $this->transporter->exportDML(self::$tmpdir . '/hoge.ext');
        });
    }

    /**
     * @test
     */
    function exportDML_closure()
    {
        $array = var_export([
            [
                'id'   => '1',
                'name' => 'name1',
                'data' => '1.1',
            ],
            [
                'id'   => '2',
                'name' => 'name2',
                'data' => '1.2',
            ],
            [
                'id'   => '3',
                'name' => 'name3',
                'data' => '1.3',
            ],
        ], true);
        $contents = "<?php return function(){return $array;};";
        file_put_contents(self::$tmpdir . "/hoge.php", $contents);
        $result = $this->transporter->exportDML(self::$tmpdir . "/hoge.php");
        $this->assertContains('skipped', $result);
        $this->assertStringEqualsFile(self::$tmpdir . "/hoge.php", $contents);
    }

    /**
     * @test
     */
    function exportDML_where()
    {
        $this->transporter->exportDML(self::$tmpdir . '/hoge.sql', ['id=2'], []);
        $this->assertFileContains("INSERT INTO `hoge` (`id`, `name`, `data`) VALUES ('2', 'name-2', '2.5')", self::$tmpdir . '/hoge.sql');
        $this->assertFileNotContains("INSERT INTO `hoge` (`id`, `name`, `data`) VALUES ('3', 'name-3', '3.5')", self::$tmpdir . '/hoge.sql');
    }

    /**
     * @test
     */
    function exportDML_ignore()
    {
        $this->transporter->exportDML(self::$tmpdir . '/hoge.sql', [], ['name']);
        $this->assertFileContains("INSERT INTO `hoge` (`id`, `data`, `name`) VALUES ('1', '1.5', '')", self::$tmpdir . '/hoge.sql');
    }

    /**
     * @test
     */
    function importDDL()
    {
        $supported = ['sql', 'php', 'json', 'yaml'];
        foreach ($supported as $ext) {
            $this->transporter->exportDDL(self::$tmpdir . "/table.$ext");
        }
        foreach ($supported as $ext) {
            foreach ($this->oldSchema->listTableNames() as $tname) {
                $this->oldSchema->dropTable($tname);
            }
            foreach ($this->oldSchema->listViews() as $vname => $view) {
                $this->oldSchema->dropView($vname);
            }
            $this->transporter->importDDL(self::$tmpdir . "/table.$ext");
            $this->assertTrue($this->oldSchema->tablesExist('hoge'));
            $this->assertTrue($this->oldSchema->tablesExist('fuga'));
            $this->assertEquals(['trg1', 'trg2'], array_keys($this->oldSchema->listTableDetails('zzz')->getTriggers()));
            $this->assertEquals(['vvview'], array_keys($this->oldSchema->listViews()));
        }

        $this->assertException(new \DomainException("is not supported"), function () {
            $this->transporter->importDDL(self::$tmpdir . '/table.ext');
        });

        $this->assertException(new \RuntimeException("platform is different"), function () {
            $this->transporter->exportDDL(self::$tmpdir . "/table.json");
            $schema = json_decode(file_get_contents(self::$tmpdir . "/table.json"), true);
            $schema['platform'] = 'unknown';
            file_put_contents(self::$tmpdir . "/table.json", json_encode($schema));
            $this->transporter->importDDL(self::$tmpdir . '/table.json');
        });
    }

    /**
     * @test
     */
    function importDDL_filter()
    {
        $supported = ['php', 'json', 'yaml'];
        foreach ($supported as $ext) {
            $this->transporter->exportDDL(self::$tmpdir . "/table.$ext");
        }
        foreach ($supported as $ext) {
            foreach ($this->oldSchema->listTableNames() as $tname) {
                $this->oldSchema->dropTable($tname);
            }
            foreach ($this->oldSchema->listViews() as $vname => $view) {
                $this->oldSchema->dropView($vname);
            }
            $this->transporter->importDDL(self::$tmpdir . "/table.$ext", ['.*g.*'], ['fuga']);
            $this->assertTrue($this->oldSchema->tablesExist('hoge'));
            $this->assertFalse($this->oldSchema->tablesExist('fuga'));
        }

        $this->transporter->exportDDL(self::$tmpdir . "/table.sql");
        $this->assertException(new \DomainException("sql is not supported"), function () {
            $this->transporter->importDDL(self::$tmpdir . '/table.sql', ['dummy']);
        });
    }

    /**
     * @test
     */
    function importDDL_noview()
    {
        $this->transporter->enableView(true);
        $this->transporter->exportDDL(self::$tmpdir . "/table.yaml");
        foreach ($this->oldSchema->listTableNames() as $tname) {
            $this->oldSchema->dropTable($tname);
        }
        foreach ($this->oldSchema->listViews() as $vname => $view) {
            $this->oldSchema->dropView($vname);
        }

        $this->transporter->enableView(false);
        $this->transporter->importDDL(self::$tmpdir . "/table.yaml");
        $this->assertEmpty($this->oldSchema->listViews());
    }

    /**
     * @test
     */
    function importDML()
    {
        $this->old->delete('fuga', [0]);
        $this->insertMultiple($this->old, 'fuga', array_map(function ($i) {
            return [
                'id' => $i,
            ];
        }, range(1, 10)));

        $supported = ['sql', 'php', 'json', 'yaml', 'csv'];
        foreach ($supported as $ext) {
            $this->transporter->exportDML(self::$tmpdir . "/fuga.$ext");
        }
        foreach ($supported as $ext) {
            $this->old->delete('fuga', [0]);
            $this->transporter->importDML(self::$tmpdir . "/fuga.$ext");
            $this->assertEquals(10, $this->old->fetchOne('SELECT COUNT(*) FROM fuga'));
        }

        $this->assertException(new \DomainException("is not supported"), function () {
            $this->transporter->importDML(self::$tmpdir . '/fuga.ext');
        });
    }

    /**
     * @test
     */
    function importDML_closure()
    {
        $array = var_export([
            [
                'id'   => '1',
                'name' => 'name1',
                'data' => '1.1',
            ],
            [
                'id'   => '2',
                'name' => 'name2',
                'data' => '1.2',
            ],
            [
                'id'   => '3',
                'name' => 'name3',
                'data' => '1.3',
            ],
        ], true);
        file_put_contents(self::$tmpdir . "/hoge.php", "<?php return function(){return $array;};");
        $this->old->delete('hoge', [0]);
        $this->transporter->importDML(self::$tmpdir . "/hoge.php");
        $this->assertEquals(3, $this->old->fetchOne('SELECT COUNT(*) FROM hoge'));
    }


    /**
     * @test
     */
    function migrateDdl()
    {
        $ddls = $this->transporter->migrateDDL($this->new);

        $this->assertContainsString('CREATE TABLE fugata', $ddls);
        $this->assertContainsString('DROP TABLE hogera', $ddls);
    }

    /**
     * @test
     */
    function migrateDml()
    {
        $dmls = $this->transporter->migrateDML($this->new, 'foo', ['1']);
        $this->assertCount(11, $dmls);

        foreach ($dmls as $sql) {
            $this->old->executeStatement($sql);
        }

        $dmls = $this->transporter->migrateDML($this->new, 'foo', ['1']);
        $this->assertCount(0, $dmls);
    }

    /**
     * @test
     */
    function migrateDml_where()
    {
        $dmls = $this->transporter->migrateDML($this->new, 'foo', ['id = -1']);
        $this->assertCount(1, $dmls);
    }

    /**
     * @test
     */
    function migrateDml_ignore()
    {
        // c_int,c_float しか違いがないので無視すれば差分なしのはず
        $dmls = $this->transporter->migrateDML($this->new, 'foo', ['id = 99'], ['c_int', 'c_float']);
        $this->assertCount(0, $dmls);

        // 修飾してもテーブルが一致すれば同様のはず
        $dmls = $this->transporter->migrateDML($this->new, 'foo', ['id = 99'], ['foo.c_int', 'foo.c_float']);
        $this->assertCount(0, $dmls);

        // クォートできるはず
        $dmls = $this->transporter->migrateDML($this->new, 'foo', ['id = 99'], ['`foo`.`c_int`', '`c_float`']);
        $this->assertCount(0, $dmls);

        // テーブルが不一致なら普通に差分ありのはず
        $dmls = $this->transporter->migrateDML($this->new, 'foo', ['id = 99'], ['bar.c_int', 'bar.c_float']);
        $this->assertCount(1, $dmls);

        // INSERT には影響しないはず
        $dmls1 = $this->transporter->migrateDML($this->new, 'foo', ['id = -1']);
        $dmls2 = $this->transporter->migrateDML($this->new, 'foo', ['id = -1'], ['c_int', 'c_float', 'c_varchar']);
        $this->assertEquals($dmls1, $dmls2);
    }

    /**
     * @test
     */
    function migrateDml_dmltypes()
    {
        // UPDATE は含まれないはず
        $dmls = $this->transporter->migrateDML($this->new, 'foo', [], [], ['update' => false]);
        $this->assertCount(4, $dmls);
    }

    /**
     * @test
     */
    function migrateDml_name()
    {
        $e = new SchemaException("There is no table with name", SchemaException::TABLE_DOESNT_EXIST);
        $migrateDml = [$this->transporter, 'migrateDml'];

        $this->assertException($e, $migrateDml, $this->new, 'notable', ['1']);
    }

    /**
     * @test
     */
    function migrateDml_nopkey()
    {
        $e = new MigrationException("has no primary key");
        $migrateDml = [$this->transporter, 'migrateDml'];

        $this->assertException($e, $migrateDml, $this->new, 'nopkey', ['1']);
    }

    /**
     * @test
     */
    function migrateDml_equals()
    {
        $e = new MigrationException("has different definition");
        $migrateDml = [$this->transporter, 'migrateDml'];

        $this->assertException($e, $migrateDml, $this->new, 'diffpkey', ['1']);
        $this->assertException($e, $migrateDml, $this->new, 'diffcolumn', ['1']);
        $this->assertException($e, $migrateDml, $this->new, 'difftype', ['1']);
    }

    /**
     * @test
     */
    function implicit()
    {
        $this->transporter->exportDDL(self::$tmpdir . '/table.yml');
        $this->assertFileNotContains('IDX_', self::$tmpdir . '/table.yml');

        foreach ($this->oldSchema->listTableNames() as $tname) {
            $this->oldSchema->dropTable($tname);
        }
        foreach ($this->oldSchema->listViews() as $vname => $view) {
            $this->oldSchema->dropView($vname);
        }
        $this->transporter->importDDL(self::$tmpdir . '/table.yml');
        $this->assertCount(1, $this->oldSchema->listTableIndexes('child'));
    }

    /**
     * @test
     */
    function ordered()
    {
        $method = $this->refClass->getMethod('tableToArray');
        $method->setAccessible(true);

        $table = new Table('ordered');
        $table->addColumn('id1', 'integer')->setAutoincrement(true);
        $table->addColumn('id2', 'integer');
        $table->addColumn('id3', 'integer');
        $table->addIndex(['id1'], 'idx_zzz');
        $table->addIndex(['id2'], 'idx_yyy');
        $table->addIndex(['id3'], 'idx_xxx');
        $table->setPrimaryKey(['id1', 'id2', 'id3']);
        $table->addForeignKeyConstraint('parent', ['id1'], ['id'], [], 'fk_zzz');
        $table->addForeignKeyConstraint('parent', ['id2'], ['id'], [], 'fk_yyy');
        $table->addForeignKeyConstraint('parent', ['id3'], ['id'], [], 'fk_xxx');
        $table->addOption('collation', 'utf8_bin');

        $tablearray = $method->invoke($this->transporter, $table);
        $this->assertEquals(['id1', 'id2', 'id3'], array_keys($tablearray['column']));
        $this->assertEquals(['primary', 'idx_xxx', 'idx_yyy', 'idx_zzz'], array_keys($tablearray['index']));
        $this->assertEquals(['fk_xxx', 'fk_yyy', 'fk_zzz'], array_keys($tablearray['foreign']));
        $this->assertEquals([
            'collation'      => 'utf8_bin',
            'charset'        => 'utf8',
            'create_options' => [],
        ], $tablearray['option']);
    }

    /**
     * @test
     */
    function bulkmode()
    {
        $insert = $this->refClass->getMethod('insert');
        $insert->setAccessible(true);

        $affected = $insert->invoke($this->transporter, 'hoge', []);
        $this->assertEquals(0, $affected);

        $this->old->delete('hoge', [0]);
        $this->transporter->setBulkMode(false);
        $affected = $insert->invoke($this->transporter, 'hoge', [
            [
                'id'   => 1,
                'name' => 'r1',
                'data' => 1,
            ],
            [
                'id'   => 2,
                'name' => 'r2',
                'data' => 2,
            ],
        ]);
        $this->assertEquals(2, $affected);
        $this->assertEquals(2, $this->old->fetchOne('SELECT COUNT(*) FROM hoge'));

        $this->old->delete('hoge', [0]);
        $this->transporter->setBulkMode(true);
        $affected = $insert->invoke($this->transporter, 'hoge', [
            [
                'id'   => 1,
                'name' => 'r1',
                'data' => 1,
            ],
            [
                'data' => 2,
                'id'   => 2,
                'name' => 'r2',
            ],
        ]);
        $this->assertEquals(2, $affected);
        $this->assertEquals(2, $this->old->fetchOne('SELECT COUNT(*) FROM hoge'));
    }

    /**
     * @test
     */
    function getImplicitIndexes()
    {
        $method = $this->refClass->getMethod('getImplicitIndexes');
        $method->setAccessible(true);

        $table = new Table('implicit');
        $table->addColumn('id', 'integer');
        $table->addColumn('pid', 'integer');
        $table->addColumn('name', 'string');
        $table->addIndex(['name'], 'idx_name');
        $table->setPrimaryKey(['id']);
        $table->addForeignKeyConstraint('parent', ['pid'], ['id'], [], 'fk_implicit');
        $this->assertCount(1, $method->invoke($this->transporter, $table));

        $this->assertEmpty(@$method->invoke($this->transporter, new \stdClass()));
        $this->assertContains('is undefined', error_get_last()['message']);
    }

    static function encodeDataProvider()
    {
        $supported = [
            [
                'sql',
                <<<SQL
INSERT INTO `hoge` (`id`, `name`, `data`) VALUES ('1', 'あいうえお', '3.14');
INSERT INTO `hoge` (`id`, `name`, `data`) VALUES ('2', 'かきくけこ', '6.28');
SQL
            ],
            [
                'php',
                <<<PHP
<?php return [
    [
        'id'   => '1',
        'name' => 'あいうえお',
        'data' => '3.14',
    ],
    [
        'id'   => '2',
        'name' => 'かきくけこ',
        'data' => '6.28',
    ]
];
PHP
            ],
            [
                'json',
                <<<JSON
[
    {
        "id": "1",
        "name": "あいうえお",
        "data": "3.14"
    },
    {
        "id": "2",
        "name": "かきくけこ",
        "data": "6.28"
    }
]
JSON
            ],
            [
                'yaml',
                <<<YAML
-
    id: '1'
    name: あいうえお
    data: '3.14'
-
    id: '2'
    name: かきくけこ
    data: '6.28'
YAML
            ],
            [
                'csv',
                <<<CSV
id,name,data
1,あいうえお,3.14
2,かきくけこ,6.28
CSV
            ],
        ];
        array_walk_recursive($supported, function (&$v) {
            $v = mb_convert_encoding($v, 'SJIS-win', mb_internal_encoding());
        });
        return $supported;
    }

    /**
     * @dataProvider encodeDataProvider
     * @param $ext
     * @param $content
     * @test
     */
    function encoding($ext, $content)
    {
        $this->transporter->setEncoding($ext, 'SJIS-win');

        $this->old->delete('hoge', [0]);
        $this->old->insert('hoge', [
            'id'   => 1,
            'name' => 'あいうえお',
            'data' => 3.14,
        ]);
        $this->old->insert('hoge', [
            'id'   => 2,
            'name' => 'かきくけこ',
            'data' => 6.28,
        ]);

        $this->transporter->exportDML(self::$tmpdir . "/hoge.$ext");
        $this->assertStringEqualsFile(self::$tmpdir . "/hoge.$ext", "$content\n");

        $this->old->delete('hoge', [0]);
        $this->transporter->importDML(self::$tmpdir . "/hoge.$ext");
        $this->assertEquals([
            'id'   => 1,
            'name' => 'あいうえお',
            'data' => 3.14,
        ], $this->old->fetchAssociative('SELECT * FROM hoge'));
    }

    /**
     * @dataProvider encodeDataProvider
     * @param $ext
     * @param $content
     * @test
     */
    function encoding_via_filename($ext, $content)
    {
        $this->old->delete('hoge', [0]);
        $this->old->insert('hoge', [
            'id'   => 1,
            'name' => 'あいうえお',
            'data' => 3.14,
        ]);
        $this->old->insert('hoge', [
            'id'   => 2,
            'name' => 'かきくけこ',
            'data' => 6.28,
        ]);

        $this->transporter->exportDML(self::$tmpdir . "/hoge.sjis-win.$ext");
        $this->assertStringEqualsFile(self::$tmpdir . "/hoge.sjis-win.$ext", "$content\n");

        $this->old->delete('hoge', [0]);
        $this->transporter->importDML(self::$tmpdir . "/hoge.sjis-win.$ext");
        $this->assertEquals([
            'id'   => 1,
            'name' => 'あいうえお',
            'data' => 3.14,
        ], $this->old->fetchAssociative('SELECT * FROM hoge'));
    }

    static function expandDataProvider()
    {
        return [
            [
                'php',
                "<?php return [
    'platform' => 'mysql',
    'table'    => [
        'child'      => include 'table/child.php',
        'diffcolumn' => include 'table/diffcolumn.php',
        'diffpkey'   => include 'table/diffpkey.php',
        'difftype'   => include 'table/difftype.php',
        'foo'        => include 'table/foo.php',
        'fuga'       => include 'table/fuga.php',
        'hoge'       => include 'table/hoge.php',
        'hogera'     => include 'table/hogera.php',
        'nopkey'     => include 'table/nopkey.php',
        'parent'     => include 'table/parent.php',
        'zzz'        => include 'table/zzz.php',
    ],
    'view'     => [
        'vvview' => include 'view/vvview.php',
    ],
];
",
            ],
            [
                'yaml',
                "---
platform: mysql
table:
  child: !include table/child.yaml
  diffcolumn: !include table/diffcolumn.yaml
  diffpkey: !include table/diffpkey.yaml
  difftype: !include table/difftype.yaml
  foo: !include table/foo.yaml
  fuga: !include table/fuga.yaml
  hoge: !include table/hoge.yaml
  hogera: !include table/hogera.yaml
  nopkey: !include table/nopkey.yaml
  parent: !include table/parent.yaml
  zzz: !include table/zzz.yaml
view:
  vvview: !include view/vvview.yaml
...
",
            ],
            [
                'json',
                '{
    "platform": "mysql",
    "table": {
        "child": "!include: table/child.json",
        "diffcolumn": "!include: table/diffcolumn.json",
        "diffpkey": "!include: table/diffpkey.json",
        "difftype": "!include: table/difftype.json",
        "foo": "!include: table/foo.json",
        "fuga": "!include: table/fuga.json",
        "hoge": "!include: table/hoge.json",
        "hogera": "!include: table/hogera.json",
        "nopkey": "!include: table/nopkey.json",
        "parent": "!include: table/parent.json",
        "zzz": "!include: table/zzz.json"
    },
    "view": {
        "vvview": "!include: view/vvview.json"
    }
}
',
            ],
        ];
    }

    /**
     * @dataProvider expandDataProvider
     * @test
     * @param $ext
     * @param $content
     */
    function directory($ext, $content)
    {
        $this->transporter->setDirectory('table', 'table');
        $this->transporter->setDirectory('view', 'view');

        $this->transporter->exportDDL(self::$tmpdir . "/table.$ext");

        $this->assertFileExists(self::$tmpdir . "/table/child.$ext");
        $this->assertFileExists(self::$tmpdir . "/table/fuga.$ext");
        $this->assertFileExists(self::$tmpdir . "/table/hoge.$ext");
        $this->assertFileExists(self::$tmpdir . "/table/parent.$ext");
        $this->assertFileExists(self::$tmpdir . "/view/vvview.$ext");
        $this->assertStringEqualsFile(self::$tmpdir . "/table.$ext", $content);

        foreach ($this->oldSchema->listTableNames() as $tname) {
            $this->oldSchema->dropTable($tname);
        }
        foreach ($this->oldSchema->listViews() as $vname => $view) {
            $this->oldSchema->dropView($vname);
        }
        $this->transporter->importDDL(self::$tmpdir . "/table.$ext");
        $this->assertTrue($this->oldSchema->tablesExist('child'));
        $this->assertTrue($this->oldSchema->tablesExist('fuga'));
        $this->assertTrue($this->oldSchema->tablesExist('hoge'));
        $this->assertTrue($this->oldSchema->tablesExist('parent'));
        $this->assertArrayHasKey('vvview', $this->oldSchema->listViews());
    }

    /**
     * @test
     */
    function ymlOption()
    {
        // 2 inline, 8 indent
        $this->transporter->setYmlOption('inline', 2);
        $this->transporter->setYmlOption('indent', 8);
        $this->transporter->exportDDL(self::$tmpdir . '/table.yml');
        $this->assertFileContains('        child: { column: { id: { type: integer, default: null, notnull: true, unsigned:', self::$tmpdir . '/table.yml');

        // 4 inline, 8 indent
        $this->transporter->setYmlOption('inline', 4);
        $this->transporter->setYmlOption('indent', 1);
        $this->transporter->exportDDL(self::$tmpdir . '/table.yml');
        $this->assertFileContains('   PRIMARY: { column: [id, pid], primary: true, unique: true, option: { lengths: [null, null] } }', self::$tmpdir . '/table.yml');
    }

    /**
     * @test
     */
    function parseFilename()
    {
        $parseFilename = $this->refClass->getMethod('parseFilename');
        $parseFilename->setAccessible(true);

        $this->assertEquals([
            'dirname'   => '/dir',
            'basename'  => 'x.txt',
            'filename'  => 'x',
            'extension' => 'txt',
            'encoding'  => '',
        ], $parseFilename->invoke($this->transporter, '/dir/x.txt'));

        $this->assertEquals([
            'dirname'   => '.',
            'basename'  => 'x',
            'filename'  => 'x',
            'extension' => '',
            'encoding'  => '',
        ], $parseFilename->invoke($this->transporter, 'x'));

        $this->assertEquals([
            'dirname'   => '/dir',
            'basename'  => 'x.utf8.txt',
            'filename'  => 'x',
            'extension' => 'txt',
            'encoding'  => 'utf8',
        ], $parseFilename->invoke($this->transporter, '/dir/x.utf8.txt'));

        $this->assertEquals([
            'dirname'   => '.',
            'basename'  => 'x.sjis-win.txt',
            'filename'  => 'x',
            'extension' => 'txt',
            'encoding'  => 'sjis-win',
        ], $parseFilename->invoke($this->transporter, 'x.sjis-win.txt'));
    }
}
