<?php

namespace ryunosuke\Test\DbMigration;

use Doctrine\DBAL\Schema\Event;
use Doctrine\DBAL\Schema\Exception\TableDoesNotExist;
use Doctrine\DBAL\Schema\Routine;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\Trigger;
use Doctrine\DBAL\Schema\View;
use ryunosuke\DbMigration\Exception\MigrationException;
use ryunosuke\DbMigration\Transporter;
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

    protected function setup(): void
    {
        parent::setUp();

        $table = new Table('hoge');
        $table->addColumn('id', 'integer');
        $table->addColumn('name', 'string', ['length' => 10]);
        $table->addColumn('data', 'float', ['scale' => 5]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['name'], 'SECONDARY');
        $table->addUniqueIndex(['data']);
        $this->readyTable($this->schema, $table);

        $table = new Table('fuga');
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(['id']);
        $table->addForeignKeyConstraint('hoge', ['id'], ['id']);
        $this->readyTable($this->schema, $table);

        $table = new Table('parent');
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(['id']);
        $this->readyTable($this->schema, $table);

        $table = new Table('child');
        $table->addColumn('id', 'integer');
        $table->addColumn('pid', 'integer');
        $table->setPrimaryKey(['id', 'pid']);
        $table->addForeignKeyConstraint('parent', ['id'], ['id']);
        foreach ($table->getIndexes() as $index) {
            if ($index->hasFlag('implicit')) {
                $table->dropIndex($index->getName());
            }
        }
        $this->readyTable($this->schema, $table);

        $table = new Table('zzz');
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(['id']);
        $this->readyTable($this->schema, $table);
        $this->schema->createTrigger(new Trigger('trg1', "INSERT INTO hoge\nVALUES()", 'zzz', [
            'timing' => 'BEFORE',
            'event'  => 'UPDATE',
        ]));
        $this->schema->createTrigger(new Trigger('trg2', "INSERT INTO hoge\nVALUES()", 'zzz', [
            'timing' => 'AFTER',
            'event'  => 'DELETE',
        ]));
        $this->schema->createRoutine(new Routine('function1', "RETURN\n1", [
            'type'                  => 'FUNCTION',
            'parameters'            => [],
            'returnTypeDeclaration' => 'int',
            'deterministic'         => true,
            'dataAccess'            => 'READS SQL DATA',
            'comment'               => 'function1comment',
        ]));
        $this->schema->createEvent(new Event('event1', "SELECT\n1", [
            'intervalValue' => '1',
            'intervalField' => 'YEAR',
            'since'         => '2022-12-24 00:00:00',
            'until'         => '2023-12-24 00:00:00',
            'completion'    => 'PRESERVE',
            'status'        => 'ENABLE',
            'comment'       => 'event1comment',
        ]));

        $view = new View('vvview', 'select * from hoge');
        $this->readyView($this->schema, $view);

        $this->insertMultiple($this->connection, 'hoge', array_map(function ($i) {
            return [
                'id'   => $i,
                'name' => 'name-' . $i,
                'data' => $i + 0.5,
            ];
        }, range(1, 10)));


        // create migration table different name
        $this->readyTable($this->schema, $this->createSimpleTable('hogera', 'integer', 'id'));

        // create migration table no pkey
        $table = $this->createSimpleTable('nopkey', 'integer', 'id');
        $table->dropPrimaryKey();
        $this->readyTable($this->schema, $table);

        // create migration table different pkey
        $this->readyTable($this->schema, $this->createSimpleTable('diffpkey', 'integer', 'id'));

        // create migration table different column
        $this->readyTable($this->schema, $this->createSimpleTable('diffcolumn', 'integer', 'id'));

        // create migration table different type
        $this->readyTable($this->schema, $this->createSimpleTable('difftype', 'string', 'id'));

        // create migration table different type
        $this->readyTable($this->schema, $this->createSimpleTable('diffpos', 'integer', 'id', 'name', 'hoge', 'fuga'));

        // create migration table different record
        $table = $this->createSimpleTable('foo', 'integer', 'id');
        $table->addColumn('c_int', 'integer');
        $table->addColumn('c_float', 'float');
        $table->addColumn('c_varchar', 'string');
        $table->addColumn('c_text', 'text');
        $table->addColumn('c_datetime', 'datetime');

        $this->readyTable($this->schema, $table);

        $this->insertMultiple($this->connection, 'foo', [
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

        $this->transporter = new Transporter($this->connection);
        $this->refClass    = new \ReflectionClass($this->transporter);

        $this->transporter->setDirectory('');
    }

    /**
     * @test
     */
    function globTable()
    {
        // empty
        $actual = $this->transporter->globTable([]);
        $this->assertEquals([], $actual);

        // nomatch
        $actual = $this->transporter->globTable(['/not/notmatch.yml']);
        $this->assertEquals([], $actual);

        // fixed only
        $actual = $this->transporter->globTable(['/hoge/child.yml', '/hoge/hoge.json', '/hoge/foo.yaml']);
        $this->assertEquals([
            '/hoge/child.yml',
            '/hoge/foo.yaml',
            '/hoge/hoge.json',
        ], $actual);

        // in ex fixed
        $actual = $this->transporter->globTable(['/hoge/diff*.yml', '/hoge/!diffc*.yml', '/hoge/hoge.json']);
        $this->assertEquals([
            '/hoge/diffpkey.yml',
            '/hoge/diffpos.yml',
            '/hoge/difftype.yml',
            '/hoge/hoge.json',
        ], $actual);

        // in in fixed
        $actual = $this->transporter->globTable(['/hoge/diff*.yml', '/hoge/h*.yml', '/hoge/hoge.json']);
        $this->assertEquals([
            '/hoge/diffcolumn.yml',
            '/hoge/diffpkey.yml',
            '/hoge/diffpos.yml',
            '/hoge/difftype.yml',
            '/hoge/hoge.json',
            '/hoge/hoge.yml',
            '/hoge/hogera.yml',
        ], $actual);

        // ex in fixed
        $actual = $this->transporter->globTable(['/hoge/!diff*.yml', '/hoge/difft*.yml', '/hoge/hoge.json']);
        $this->assertNotContains('/hoge/diffcolumn.yml', $actual);
        $this->assertNotContains('/hoge/diffpkey.yml', $actual);
        $this->assertContains('/hoge/difftype.yml', $actual);
        $this->assertContains('/hoge/hoge.json', $actual);

        // ex ex fixed
        $actual = $this->transporter->globTable(['/hoge/!diff*.yml', '/hoge/!h*.yml', '/hoge/hoge.json']);
        $this->assertNotContains('/hoge/diffcolumn.yml', $actual);
        $this->assertNotContains('/hoge/diffpkey.yml', $actual);
        $this->assertNotContains('/hoge/difftype.yml', $actual);
        $this->assertNotContains('/hoge/hogera.yml', $actual);
        $this->assertContains('/hoge/hoge.json', $actual);
    }

    /**
     * @test
     */
    function checkForeignKeyConstraint()
    {
        // for coverage
        $diff = $this->transporter->checkForeignKeyConstraint(['hoge'], ['fuga']);

        $this->assertEquals([], $diff);
    }

    /**
     * @test
     */
    function exportDDL()
    {
        $this->transporter->exportDDL(self::$tmpdir . '/table.php');
        $this->transporter->exportDDL(self::$tmpdir . '/table.json');
        $this->transporter->exportDDL(self::$tmpdir . '/table.yaml');

        $php  = include self::$tmpdir . '/table.php';
        $json = json_decode(file_get_contents(self::$tmpdir . '/table.json'), true);
        $yaml = Yaml::parse(file_get_contents(self::$tmpdir . '/table.yaml'));

        $this->assertEquals($php, $json);
        $this->assertEquals($json, $yaml);
        $this->assertEquals($yaml, $php);

        $this->assertArrayHasKey('table', $php);
        $this->assertArrayHasKey('zzz', $php['table']);
        $this->assertArrayHasKey('trigger', $php);
        $this->assertArrayHasKey('trg1', $php['trigger']);

        $this->assertEquals('', $this->transporter->exportDDL(''));

        $this->assertException(new \DomainException("is not supported"), function () {
            $this->transporter->exportDDL(self::$tmpdir . '/table.ext');
        });
    }

    /**
     * @test
     */
    function exportDDL_filter()
    {
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
        $this->transporter->setDisabled(['view' => true]);

        $this->transporter->exportDDL(self::$tmpdir . '/table.yaml');
        $yaml = Yaml::parse(file_get_contents(self::$tmpdir . '/table.yaml'));
        $this->assertEmpty($yaml['view']);
    }

    /**
     * @test
     */
    function exportDDL_sql()
    {
        $this->transporter->exportDDL(self::$tmpdir . '/table.sql');
        $this->assertFileContains('CREATE TABLE child', self::$tmpdir . '/table.sql');
        $this->assertFileContains('ALTER TABLE child', self::$tmpdir . '/table.sql');
        $this->assertFileContains('CREATE VIEW', self::$tmpdir . '/table.sql');
        $this->assertFileContains('CREATE TRIGGER', self::$tmpdir . '/table.sql');
        $this->assertFileContains('CREATE FUNCTION', self::$tmpdir . '/table.sql');
        $this->assertFileContains('CREATE EVENT', self::$tmpdir . '/table.sql');
    }

    /**
     * @test
     */
    function exportDML()
    {
        iterator_to_array($this->transporter->exportDML(self::$tmpdir . '/hoge.sql'));
        $this->assertFileContains("INSERT INTO `hoge` (`id`, `name`, `data`) VALUES (1, 'name-1', 1.5)", self::$tmpdir . '/hoge.sql');

        iterator_to_array($this->transporter->exportDML(self::$tmpdir . '/hoge.php'));
        iterator_to_array($this->transporter->exportDML(self::$tmpdir . '/hoge.json'));
        iterator_to_array($this->transporter->exportDML(self::$tmpdir . '/hoge.yaml'));

        $php  = include self::$tmpdir . '/hoge.php';
        $json = json_decode(file_get_contents(self::$tmpdir . '/hoge.json'), true);
        $yaml = Yaml::parse(file_get_contents(self::$tmpdir . '/hoge.yaml'));

        $this->assertEquals($php, $json);
        $this->assertEquals($json, $yaml);
        $this->assertEquals($yaml, $php);

        $this->assertException(new \DomainException("is not supported"), function () {
            iterator_to_array($this->transporter->exportDML(self::$tmpdir . '/hoge.ext'));
        });
    }

    /**
     * @test
     */
    function exportDML_closure()
    {
        $array    = var_export([
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
        $result = implode("\n", iterator_to_array($this->transporter->exportDML(self::$tmpdir . "/hoge.php")));
        $this->assertStringContainsString('closure file', $result);
        $this->assertStringEqualsFile(self::$tmpdir . "/hoge.php", $contents);
    }

    /**
     * @test
     */
    function exportDML_where()
    {
        iterator_to_array($this->transporter->exportDML(self::$tmpdir . '/hoge.php', ['id=2'], []));
        $this->assertEquals([
            [
                'id'   => '2',
                'name' => 'name-2',
                'data' => '2.5',
            ],
        ], include self::$tmpdir . '/hoge.php');
    }

    /**
     * @test
     */
    function exportDML_ignore()
    {
        iterator_to_array($this->transporter->exportDML(self::$tmpdir . '/hoge.php', ['id=2'], ['name']));
        $this->assertEquals([
            [
                'id'   => '2',
                'name' => '',
                'data' => '2.5',
            ],
        ], include self::$tmpdir . '/hoge.php');
    }

    /**
     * @test
     */
    function importDDL()
    {
        $supported = ['php', 'json', 'yaml', 'yaml5'];
        foreach ($supported as $ext) {
            $this->transporter->exportDDL(self::$tmpdir . "/table.$ext");
        }
        foreach ($supported as $ext) {
            $ddls = $this->transporter->importDDL(self::$tmpdir . "/table.$ext");
            $this->assertContainsString('CREATE TABLE hoge', $ddls);
            $this->assertContainsString('CREATE TABLE fuga', $ddls);
            $this->assertContainsString('CREATE TRIGGER trg1', $ddls);
            $this->assertContainsString('CREATE TRIGGER trg2', $ddls);
            $this->assertContainsString('CREATE VIEW vvview', $ddls);
        }

        $this->assertEquals([], $this->transporter->importDDL(''));

        $this->assertException(new \DomainException("is not supported"), function () {
            $this->transporter->importDDL(self::$tmpdir . '/table.ext');
        });
    }

    /**
     * @test
     */
    function importDML()
    {
        $this->insertMultiple($this->connection, 'fuga', array_map(function ($i) {
            return [
                'id' => $i,
            ];
        }, range(1, 10)));

        $supported = ['sql', 'php', 'json', 'yaml', 'yaml5', 'csv'];
        foreach ($supported as $ext) {
            iterator_to_array($this->transporter->exportDML(self::$tmpdir . "/fuga.$ext"));
        }
        foreach ($supported as $ext) {
            $dmls = iterator_to_array($this->transporter->importDML(self::$tmpdir . "/fuga.$ext"));
            $this->assertEquals(10, substr_count(implode(";", $dmls), 'INSERT INTO'));
        }

        $this->assertException(new \DomainException("is not supported"), function () {
            iterator_to_array($this->transporter->importDML(self::$tmpdir . '/fuga.ext'));
        });
    }

    /**
     * @test
     */
    function importDML_closure()
    {
        $fn = $this->readyPhpFile("hoge.php", [
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
        ]);

        $dmls = iterator_to_array($this->transporter->importDML($fn));
        $this->assertEquals(3, substr_count(implode(";", $dmls), 'INSERT INTO'));
    }

    /**
     * @test
     */
    function importDML_bulksize()
    {
        $fn = $this->readyPhpFile("hoge.php", [
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
        ]);

        $this->transporter->setBulkSize(0);
        $dmls = iterator_to_array($this->transporter->importDML($fn));
        $this->assertCount(3, $dmls);

        $this->transporter->setBulkSize(2);
        $dmls = iterator_to_array($this->transporter->importDML($fn));
        $this->assertCount(2, $dmls);

        $this->transporter->setBulkSize(999);
        $dmls = iterator_to_array($this->transporter->importDML($fn));
        $this->assertCount(1, $dmls);
    }

    /**
     * @test
     */
    function migrateDdl()
    {
        $fn   = $this->readyPhpFile("ddl.php", [
            'table' => [
                'fugata'  => [
                    'column' => [
                        'hoge_id' => [
                            'type' => 'smallint',
                        ],
                    ],
                    'option' => [],
                ],
                'diffpos' => [
                    'column' => [
                        'id'   => [
                            'type' => 'integer',
                        ],
                        'name' => [
                            'type' => 'integer',
                        ],
                        'fuga' => [
                            'type' => 'integer',
                        ],
                        'hoge' => [
                            'type' => 'integer',
                        ],
                    ],
                    'option' => [],
                ],
            ],
        ]);
        $ddls = $this->transporter->migrateDDL($fn);

        $this->assertContainsString('CREATE TABLE fugata', $ddls);
        $this->assertContainsString('DROP TABLE hogera', $ddls);
        $this->assertContainsString('CHANGE hoge hoge INT NOT NULL AFTER fuga', $ddls);
        $this->assertContainsString('CHANGE fuga fuga INT NOT NULL AFTER name', $ddls);
        $this->assertNotContainsString('CHANGE name', $ddls);

        $this->assertEquals([], $this->transporter->migrateDDL(''));

        $this->assertException(new \DomainException('does not support migrateDDL'), fn() => $this->transporter->migrateDDL("ddl.sql"));
    }

    /**
     * @test
     */
    function migrateDdl_exclude()
    {
        $fn   = $this->readyPhpFile("ddl.php", [
            'table' => [
                'hogera' => [
                    'column' => [
                        'hoge_id' => [
                            'type' => 'smallint',
                        ],
                    ],
                    'option' => [],
                ],
            ],
        ]);
        $ddls = $this->transporter->migrateDDL($fn, ['hogera']);

        $this->assertNotContainsString('hogera', $ddls);
    }

    /**
     * @test
     */
    function migrateDml()
    {
        $fn   = $this->readyPhpFile("foo.php", [
            ['id' => -2, 'c_int' => 1, 'c_float' => 1, 'c_varchar' => 'char', 'c_text' => 'text', 'c_datetime' => '2000-01-01 00:00:00',],
            ['id' => -1, 'c_int' => 1, 'c_float' => 1, 'c_varchar' => 'char', 'c_text' => 'text', 'c_datetime' => '2000-01-01 00:00:00',],
            ['id' => 0, 'c_int' => 1, 'c_float' => 1.2, 'c_varchar' => 'char', 'c_text' => 'text', 'c_datetime' => '2000-01-01 00:00:00',],
            ['id' => 1, 'c_int' => 1, 'c_float' => 1, 'c_varchar' => 'char', 'c_text' => 'text', 'c_datetime' => '2000-01-01 00:00:00',],
            ['id' => 2, 'c_int' => 1, 'c_float' => 1, 'c_varchar' => 'char', 'c_text' => 'text', 'c_datetime' => '2000-01-01 00:00:00',],
            ['id' => 3, 'c_int' => 1, 'c_float' => 1, 'c_varchar' => 'char', 'c_text' => 'text', 'c_datetime' => '2000-01-01 00:00:00',],
            ['id' => 4, 'c_int' => 1, 'c_float' => 1, 'c_varchar' => 'char', 'c_text' => 'text', 'c_datetime' => '2000-01-01 00:00:00',],
            ['id' => 5, 'c_int' => 1, 'c_float' => 1, 'c_varchar' => 'char', 'c_text' => 'text', 'c_datetime' => '2000-01-01 00:00:00',],
            ['id' => 6, 'c_int' => 1, 'c_float' => 1, 'c_varchar' => 'char', 'c_text' => 'text', 'c_datetime' => '2000-01-01 00:00:00',],
            ['id' => 99, 'c_int' => 2, 'c_float' => 1.4, 'c_varchar' => 'char', 'c_text' => 'text', 'c_datetime' => '2000-01-01 00:00:00',],
        ]);
        $dmls = iterator_to_array($this->transporter->migrateDML($fn, ['insert' => true, 'update' => true, 'delete' => true]));
        $this->assertCount(11, $dmls);

        foreach ($dmls as $sql) {
            $this->connection->executeStatement($sql);
        }

        $dmls = iterator_to_array($this->transporter->migrateDML($fn, ['insert' => true, 'update' => true, 'delete' => true]));
        $this->assertCount(0, $dmls);
    }

    /**
     * @test
     */
    function migrateDml_sql()
    {
        file_put_contents(self::$tmpdir . "/foo.sql", '
            INSERT INTO foo VALUES (-3, 1, 1, "text1", "text2", NOW())
        ');
        $dmls = iterator_to_array($this->transporter->migrateDML(self::$tmpdir . "/foo.sql"));
        $this->assertCount(1, $dmls);

        foreach ($dmls as $sql) {
            $this->connection->executeStatement($sql);
        }

        $this->assertEquals(-3, $this->connection->fetchOne('SELECT id FROM foo WHERE id=-3'));
    }

    /**
     * @test
     */
    function migrateDml_dmltypes()
    {
        $fn = $this->readyPhpFile("foo.php", [
            ['id' => 99, 'c_int' => 1, 'c_float' => 1.2, 'c_varchar' => 'char', 'c_text' => 'change', 'c_datetime' => '2000-01-01 00:00:00',],
            ['id' => 999, 'c_int' => 1, 'c_float' => 1.2, 'c_varchar' => 'char', 'c_text' => 'text', 'c_datetime' => '2000-01-01 00:00:00',],
        ]);

        $dmls = iterator_to_array($this->transporter->migrateDML($fn, ['insert' => true, 'update' => false, 'delete' => false]));
        $this->assertContainsString('INSERT', implode("\n", $dmls));
        $this->assertNotContainsString('UPDATE', implode("\n", $dmls));
        $this->assertNotContainsString('DELETE', implode("\n", $dmls));

        $dmls = iterator_to_array($this->transporter->migrateDML($fn, ['insert' => false, 'update' => true, 'delete' => false]));
        $this->assertNotContainsString('INSERT', implode("\n", $dmls));
        $this->assertContainsString('UPDATE', implode("\n", $dmls));
        $this->assertNotContainsString('DELETE', implode("\n", $dmls));

        $dmls = iterator_to_array($this->transporter->migrateDML($fn, ['insert' => false, 'update' => false, 'delete' => true]));
        $this->assertNotContainsString('INSERT', implode("\n", $dmls));
        $this->assertNotContainsString('UPDATE', implode("\n", $dmls));
        $this->assertContainsString('DELETE', implode("\n", $dmls));
    }

    /**
     * @test
     */
    function migrateDml_name()
    {
        $e          = new TableDoesNotExist("notable");
        $migrateDml = [$this->transporter, 'migrateDml'];

        $fn = $this->readyPhpFile("notable.php", [
            ['id' => -2, 'c_int' => 1, 'c_float' => 1, 'c_varchar' => 'char', 'c_text' => 'text', 'c_datetime' => '2000-01-01 00:00:00',],
        ]);
        $this->assertException($e, $migrateDml, $fn);
    }

    /**
     * @test
     */
    function migrateDml_nopkey()
    {
        $e          = new MigrationException("has no primary key");
        $migrateDml = [$this->transporter, 'migrateDml'];

        $fn = $this->readyPhpFile("nopkey.php", [
            ['id' => -2, 'c_int' => 1, 'c_float' => 1, 'c_varchar' => 'char', 'c_text' => 'text', 'c_datetime' => '2000-01-01 00:00:00',],
        ]);
        $this->assertException($e, $migrateDml, $fn);
    }

    /**
     * @test
     */
    function implicit()
    {
        $this->transporter->exportDDL(self::$tmpdir . '/table.yml');
        $this->assertFileNotContains('IDX_', self::$tmpdir . '/table.yml');

        $ddls = $this->transporter->importDDL(self::$tmpdir . '/table.yml');
        $this->assertNotContainsString('IDX_', $ddls);
    }

    /**
     * @test
     */
    function ordered()
    {
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

        $definitation = $this->transporter->getDefinition();
        $tablearray   = $definitation['table']['array']($table);
        $this->assertEquals(['id1', 'id2', 'id3'], array_keys($tablearray['column']));
        $this->assertEquals(['primary', 'idx_xxx', 'idx_yyy', 'idx_zzz'], array_keys($tablearray['index']));
        $this->assertEquals(['fk_xxx', 'fk_yyy', 'fk_zzz'], array_keys($tablearray['foreign']));
        $this->assertEquals([
            'collation' => 'utf8_bin',
            'charset'   => 'utf8',
        ], $tablearray['option']);
    }

    static function encodeDataProvider()
    {
        $supported = [
            [
                'sql',
                <<<SQL
INSERT INTO `hoge` (`id`, `name`, `data`) VALUES (1, 'あいうえお', 3.14);
INSERT INTO `hoge` (`id`, `name`, `data`) VALUES (2, 'かきくけこ', 6.28);
SQL
                ,
            ],
            [
                'php',
                <<<PHP
<?php return [
    [
        'id'   => 1,
        'name' => 'あいうえお',
        'data' => 3.14,
    ],
    [
        'id'   => 2,
        'name' => 'かきくけこ',
        'data' => 6.28,
    ],
];
PHP
                ,
            ],
            [
                'json',
                <<<JSON
[
    {
        "id": 1,
        "name": "あいうえお",
        "data": 3.14
    },
    {
        "id": 2,
        "name": "かきくけこ",
        "data": 6.28
    }
]
JSON
                ,
            ],
            [
                'yaml',
                <<<YAML
-
    id: 1
    name: あいうえお
    data: 3.14
-
    id: 2
    name: かきくけこ
    data: 6.28
YAML
                ,
            ],
            [
                'yaml5',
                <<<YAML5
[
    {
        id: 1,
        name: あいうえお,
        data: 3.14,
    },
    {
        id: 2,
        name: かきくけこ,
        data: 6.28,
    },
]
YAML5
                ,
            ],
            [
                'csv',
                <<<CSV
id,name,data
1,あいうえお,3.14
2,かきくけこ,6.28
CSV
                ,
            ],
            [
                'tsv',
                <<<TSV
id	name	data
1	あいうえお	3.14
2	かきくけこ	6.28
TSV
                ,
            ],
            [
                'tcsv',
                <<<TCSV
id:int,name,data:float
1,あいうえお,3.14
2,かきくけこ,6.28
TCSV
                ,
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
        $this->connection->delete('hoge', [0]);
        $this->connection->insert('hoge', [
            'id'   => 1,
            'name' => 'あいうえお',
            'data' => 3.14,
        ]);
        $this->connection->insert('hoge', [
            'id'   => 2,
            'name' => 'かきくけこ',
            'data' => 6.28,
        ]);

        iterator_to_array($this->transporter->exportDML(self::$tmpdir . "/hoge.sjis-win.$ext"));
        $this->assertStringEqualsFile(self::$tmpdir . "/hoge.sjis-win.$ext", "$content\n");

        $dmls = iterator_to_array($this->transporter->importDML(self::$tmpdir . "/hoge.sjis-win.$ext"));
        $this->assertContainsString('あいうえお', $dmls);
        $this->assertContainsString('かきくけこ', $dmls);
    }

    static function expandDataProvider()
    {
        return [
            [
                'php',
                <<<PHP
                <?php return [
                        'table'   => [
                                'child'      => include 'object/table/child.php',
                                'diffcolumn' => include 'object/table/diffcolumn.php',
                                'diffpkey'   => include 'object/table/diffpkey.php',
                                'diffpos'    => include 'object/table/diffpos.php',
                                'difftype'   => include 'object/table/difftype.php',
                                'foo'        => include 'object/table/foo.php',
                                'fuga'       => include 'object/table/fuga.php',
                                'hoge'       => include 'object/table/hoge.php',
                                'hogera'     => include 'object/table/hogera.php',
                                'nopkey'     => include 'object/table/nopkey.php',
                                'parent'     => include 'object/table/parent.php',
                                'zzz'        => include 'object/table/zzz.php',
                        ],
                        'view'    => [
                                'vvview' => include 'object/view/vvview.php',
                        ],
                        'trigger' => [
                                'trg1' => include 'object/trigger/trg1.php',
                                'trg2' => include 'object/trigger/trg2.php',
                        ],
                        'routine' => [
                                'function1' => include 'object/routine/function1.php',
                        ],
                        'event'   => [
                                'event1' => include 'object/event/event1.php',
                        ],
                ];
                
                PHP,
            ],
            [
                'yaml',
                <<<YAML
                table:
                        child:      !include object/table/child.yaml
                        diffcolumn: !include object/table/diffcolumn.yaml
                        diffpkey:   !include object/table/diffpkey.yaml
                        diffpos:    !include object/table/diffpos.yaml
                        difftype:   !include object/table/difftype.yaml
                        foo:        !include object/table/foo.yaml
                        fuga:       !include object/table/fuga.yaml
                        hoge:       !include object/table/hoge.yaml
                        hogera:     !include object/table/hogera.yaml
                        nopkey:     !include object/table/nopkey.yaml
                        parent:     !include object/table/parent.yaml
                        zzz:        !include object/table/zzz.yaml
                view:
                        vvview: !include object/view/vvview.yaml
                trigger:
                        trg1: !include object/trigger/trg1.yaml
                        trg2: !include object/trigger/trg2.yaml
                routine:
                        function1: !include object/routine/function1.yaml
                event:
                        event1: !include object/event/event1.yaml
                
                YAML,
            ],
            [
                'yaml5',
                <<<YAML5
                {
                        table:   {
                                child:      !include object/table/child.yaml5,
                                diffcolumn: !include object/table/diffcolumn.yaml5,
                                diffpkey:   !include object/table/diffpkey.yaml5,
                                diffpos:    !include object/table/diffpos.yaml5,
                                difftype:   !include object/table/difftype.yaml5,
                                foo:        !include object/table/foo.yaml5,
                                fuga:       !include object/table/fuga.yaml5,
                                hoge:       !include object/table/hoge.yaml5,
                                hogera:     !include object/table/hogera.yaml5,
                                nopkey:     !include object/table/nopkey.yaml5,
                                parent:     !include object/table/parent.yaml5,
                                zzz:        !include object/table/zzz.yaml5,
                        },
                        view:    {
                                vvview: !include object/view/vvview.yaml5,
                        },
                        trigger: {
                                trg1: !include object/trigger/trg1.yaml5,
                                trg2: !include object/trigger/trg2.yaml5,
                        },
                        routine: {
                                function1: !include object/routine/function1.yaml5,
                        },
                        event:   {
                                event1: !include object/event/event1.yaml5,
                        },
                }
                
                YAML5,
            ],
            [
                'json',
                <<<JSON
                {
                        "table":   {
                                "child":      "!include: object/table/child.json",
                                "diffcolumn": "!include: object/table/diffcolumn.json",
                                "diffpkey":   "!include: object/table/diffpkey.json",
                                "diffpos":    "!include: object/table/diffpos.json",
                                "difftype":   "!include: object/table/difftype.json",
                                "foo":        "!include: object/table/foo.json",
                                "fuga":       "!include: object/table/fuga.json",
                                "hoge":       "!include: object/table/hoge.json",
                                "hogera":     "!include: object/table/hogera.json",
                                "nopkey":     "!include: object/table/nopkey.json",
                                "parent":     "!include: object/table/parent.json",
                                "zzz":        "!include: object/table/zzz.json"
                        },
                        "view":    {
                                "vvview": "!include: object/view/vvview.json"
                        },
                        "trigger": {
                                "trg1": "!include: object/trigger/trg1.json",
                                "trg2": "!include: object/trigger/trg2.json"
                        },
                        "routine": {
                                "function1": "!include: object/routine/function1.json"
                        },
                        "event":   {
                                "event1": "!include: object/event/event1.json"
                        }
                }

                JSON,
            ],
        ];
    }

    /**
     * @dataProvider expandDataProvider
     * @test
     * @param $ext
     * @param $content
     */
    function setDataDescriptionOptions($ext, $content)
    {
        $this->transporter->setDirectory('object');
        $this->transporter->setDataDescriptionOptions([
            'inline'    => 2,
            'indent'    => 8,
            'multiline' => true,
            'align'     => true,
        ]);

        $this->transporter->exportDDL(self::$tmpdir . "/table.$ext");

        $this->assertFileExists(self::$tmpdir . "/object/table/child.$ext");
        $this->assertFileExists(self::$tmpdir . "/object/table/fuga.$ext");
        $this->assertFileExists(self::$tmpdir . "/object/table/hoge.$ext");
        $this->assertFileExists(self::$tmpdir . "/object/table/parent.$ext");
        $this->assertFileExists(self::$tmpdir . "/object/view/vvview.$ext");
        $this->assertFileExists(self::$tmpdir . "/object/trigger/trg1.$ext");
        $this->assertFileExists(self::$tmpdir . "/object/trigger/trg1.$ext");
        $this->assertFileExists(self::$tmpdir . "/object/routine/function1.$ext");
        $this->assertFileExists(self::$tmpdir . "/object/event/event1.$ext");
        $this->assertStringEqualsFile(self::$tmpdir . "/table.$ext", $content);

        $ddls = $this->transporter->importDDL(self::$tmpdir . "/table.$ext");
        $this->assertContainsString('CREATE TABLE child', $ddls);
        $this->assertContainsString('CREATE TABLE fuga', $ddls);
        $this->assertContainsString('CREATE TABLE hoge', $ddls);
        $this->assertContainsString('CREATE TABLE parent', $ddls);
        $this->assertContainsString('CREATE VIEW vvview', $ddls);
    }

    /**
     * @test
     */
    function setDataDescriptionOptions_misc()
    {
        // 2 inline, 8 indent
        $this->transporter->setDataDescriptionOptions([
            'delimiter' => '//',
        ]);
        $this->transporter->exportDDL(self::$tmpdir . '/table.sql');
        $this->assertFileContains("CREATE TRIGGER trg1 BEFORE UPDATE ON zzz FOR EACH ROW INSERT INTO hoge\nVALUES()//", self::$tmpdir . '/table.sql');
    }

    /**
     * @test
     */
    function exchangeSchemaFromSQL()
    {
        $stripSchemaOf = $this->refClass->getMethod('stripSchemaOf');
        $stripSchemaOf->setAccessible(true);
        $restoreSchemaOf = $this->refClass->getMethod('restoreSchemaOf');
        $restoreSchemaOf->setAccessible(true);

        $schemaname = $this->connection->getDatabase();

        $dataset = [
            [
                'original' => "select '$schemaname'",
                'replaced' => "select '$schemaname'",
            ],
            [
                'original' => "select `$schemaname`",
                'replaced' => "select `$schemaname`",
            ],
            [
                'original' => "select `$schemaname`.hoge",
                'replaced' => "select /*`schema`.*/hoge",
            ],
            [
                'original' => "select `$schemaname`.`fuga`",
                'replaced' => "select /*`schema`.*/`fuga`",
            ],
            [
                'original' => "select `$schemaname` . `piyo`",
                'replaced' => "select /*`schema` .*/ `piyo`",
            ],
            [
                'original' => "select `$schemaname`.hoge, `$schemaname`.`fuga`, `$schemaname` . `piyo`",
                'replaced' => "select /*`schema`.*/hoge, /*`schema`.*/`fuga`, /*`schema` .*/ `piyo`",
            ],
            [
                'original' => "select 'notschema'",
                'replaced' => "select 'notschema'",
            ],
            [
                'original' => "select `notschema`",
                'replaced' => "select `notschema`",
            ],
            [
                'original' => "select `notschema`.hoge",
                'replaced' => "select `notschema`.hoge",
            ],
            [
                'original' => "select `notschema`.`fuga`",
                'replaced' => "select `notschema`.`fuga`",
            ],
            [
                'original' => "select `notschema` . `piyo`",
                'replaced' => "select `notschema` . `piyo`",
            ],
            [
                'original' => "select `notschema`.hoge, `notschema`.`fuga`, `notschema` . `piyo`",
                'replaced' => "select `notschema`.hoge, `notschema`.`fuga`, `notschema` . `piyo`",
            ],
        ];

        foreach ($dataset as $name => $data) {
            $message = "$name: {$data['original']} VS {$data['replaced']}";
            $this->assertEquals($data['replaced'], $stripSchemaOf->invoke($this->transporter, $data['original']), $message);
            $this->assertEquals($data['original'], $restoreSchemaOf->invoke($this->transporter, $data['replaced']), $message);
        }
    }
}
