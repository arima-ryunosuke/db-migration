<?php

namespace ryunosuke\Test\DbMigration;

use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\View;
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

        $this->transporter = new Transporter($this->old);
        $this->refClass = new \ReflectionClass($this->transporter);

        $this->transporter->setDirectory('table', null);
        $this->transporter->setDirectory('view', null);
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
        $this->assertEquals(['fuga', 'hoge'], array_keys($yaml['table']));

        $this->transporter->exportDDL(self::$tmpdir . '/table.yaml', ['hoge', 'fuga'], []);
        $yaml = Yaml::parse(file_get_contents(self::$tmpdir . '/table.yaml'));
        $this->assertEquals(['fuga', 'hoge'], array_keys($yaml['table']));

        $this->transporter->exportDDL(self::$tmpdir . '/table.yaml', ['.*g.*'], ['fuga']);
        $yaml = Yaml::parse(file_get_contents(self::$tmpdir . '/table.yaml'));
        $this->assertEquals(['hoge'], array_keys($yaml['table']));
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
            $this->assertEquals(10, $this->old->fetchColumn('SELECT COUNT(*) FROM fuga'));
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
        $this->assertEquals(3, $this->old->fetchColumn('SELECT COUNT(*) FROM hoge'));
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
        $table->addColumn('id1', 'integer');
        $table->addColumn('id2', 'integer');
        $table->addColumn('id3', 'integer');
        $table->addIndex(['id1'], 'idx_zzz');
        $table->addIndex(['id2'], 'idx_yyy');
        $table->addIndex(['id3'], 'idx_xxx');
        $table->setPrimaryKey(['id1', 'id2', 'id3']);
        $table->addForeignKeyConstraint('parent', ['id1'], ['id'], [], 'fk_zzz');
        $table->addForeignKeyConstraint('parent', ['id2'], ['id'], [], 'fk_yyy');
        $table->addForeignKeyConstraint('parent', ['id3'], ['id'], [], 'fk_xxx');

        $tablearray = $method->invoke($this->transporter, $table);
        $this->assertEquals(['primary', 'idx_xxx', 'idx_yyy', 'idx_zzz'], array_keys($tablearray['index']));
        $this->assertEquals(['fk_xxx', 'fk_yyy', 'fk_zzz'], array_keys($tablearray['foreign']));
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
        $this->assertEquals(2, $this->old->fetchColumn('SELECT COUNT(*) FROM hoge'));

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
        $this->assertEquals(2, $this->old->fetchColumn('SELECT COUNT(*) FROM hoge'));
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

    /**
     * @test
     */
    function encoding()
    {
        $this->transporter->setEncoding('sql', 'SJIS-win');
        $this->transporter->setEncoding('php', 'SJIS-win');
        $this->transporter->setEncoding('json', 'SJIS-win');
        $this->transporter->setEncoding('yaml', 'SJIS-win');
        $this->transporter->setEncoding('csv', 'SJIS-win');

        $supported = [
            'sql'  => "INSERT INTO `hoge` (`id`, `name`, `data`) VALUES ('1', 'あいうえお', '3.14');
",
            'php'  => "<?php return array(
[
    'id'   => '1',
    'name' => 'あいうえお',
    'data' => '3.14',
]
);
",
            'json' => '[
{
    "id": "1",
    "name": "あいうえお",
    "data": "3.14"
}
]
',
            'yaml' => "-
    id: '1'
    name: あいうえお
    data: '3.14'
",
            'csv'  => "id,name,data
1,あいうえお,3.14
",
        ];
        mb_convert_variables('SJIS-win', mb_internal_encoding(), $supported);

        $this->old->delete('hoge', [0]);
        $this->old->insert('hoge', [
            'id'   => 1,
            'name' => 'あいうえお',
            'data' => 3.14,
        ]);

        foreach ($supported as $ext => $expected) {
            $this->transporter->exportDML(self::$tmpdir . "/hoge.$ext");
            $this->assertStringEqualsFile(self::$tmpdir . "/hoge.$ext", $expected);
        }
        foreach ($supported as $ext => $expected) {
            $this->old->delete('hoge', [0]);
            $this->transporter->importDML(self::$tmpdir . "/hoge.$ext");
            $this->assertEquals([
                'id'   => 1,
                'name' => 'あいうえお',
                'data' => 3.14,
            ], $this->old->fetchAssoc('SELECT * FROM hoge'));
        }
    }

    static function expandDataProvider()
    {
        return [
            [
                'php',
                "<?php return
[
    'platform' => 'mysql',
    'table'    => [
        'child'  => include 'table/child.php',
        'fuga'   => include 'table/fuga.php',
        'hoge'   => include 'table/hoge.php',
        'parent' => include 'table/parent.php',
        'zzz'    => include 'table/zzz.php',
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
  fuga: !include table/fuga.yaml
  hoge: !include table/hoge.yaml
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
        "fuga": "!include: table/fuga.json",
        "hoge": "!include: table/hoge.json",
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
}
