<?php

namespace ryunosuke\Test\DbMigration\Console\Command;

use Doctrine\DBAL\Logging\DebugStack;
use Doctrine\DBAL\Schema\View;
use ryunosuke\DbMigration\Console\Command\MigrateCommand;

class MigrateCommandTest extends AbstractTestCase
{
    protected $commandName = 'migrate';

    protected function setup()
    {
        parent::setUp();

        $migtable = $this->createSimpleTable('migtable', 'integer', 'id', 'code');
        $migtable->addUniqueIndex([
            'code'
        ], 'unq_index');

        $longtable = $this->createSimpleTable('longtable', 'integer', 'id');
        $longtable->addColumn('text_data', 'text');
        $longtable->addColumn('blob_data', 'blob');
        $nopkeytable = $this->createSimpleTable('nopkeytable', 'integer', 'id');
        $nopkeytable->dropPrimaryKey();
        $this->oldSchema->dropAndCreateTable($migtable);
        $this->oldSchema->dropAndCreateTable($longtable);
        $this->oldSchema->dropAndCreateTable($nopkeytable);
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('difftable', 'integer', 'code'));
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('igntable', 'integer', 'id', 'code'));
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('unqtable', 'integer', 'id', 'code'));
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('sametable', 'integer', 'id'));
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('drptable', 'integer', 'id'));

        $view = new View('simpleview', 'select 1');
        $this->oldSchema->createView($view);

        $this->old->insert('migtable', [
            'id'   => 5,
            'code' => 2
        ]);
        $this->old->insert('migtable', [
            'id'   => 9,
            'code' => 999
        ]);
        $this->old->insert('sametable', [
            'id' => 9
        ]);

        $this->app->add(new MigrateCommand());

        $this->defaultArgs = [
            '--format' => 'none',
            '-n'       => true,
            'srcdsn'   => $GLOBALS['old_db'],
            'dstdsn'   => $GLOBALS['new_db'],
        ];
    }

    /**
     * @test
     */
    function run_()
    {
        $this->new->executeStatement(file_get_contents($this->getFile('table.sql')));
        $this->new->executeStatement(file_get_contents($this->getFile('data.sql')));

        $result = $this->runApp([
            '-vvv' => true,
        ]);

        $this->assertStringContainsString('ALTER TABLE igntable', $result);
        $this->assertStringContainsString('DELETE FROM `migtable`', $result);
        $this->assertStringContainsString('INSERT INTO `migtable`', $result);
        $this->assertStringContainsString('UPDATE `migtable` SET', $result);
        $this->assertStringNotContainsString('`sametable`', $result);
    }

    /**
     * @test
     */
    function run_type_ddl()
    {
        $this->new->executeStatement(file_get_contents($this->getFile('table.sql')));
        $this->new->executeStatement(file_get_contents($this->getFile('data.sql')));

        $result = $this->runApp([
            '--type' => 'ddl',
        ]);

        $this->assertStringContainsString('ALTER TABLE igntable', $result);
        $this->assertStringNotContainsString('DELETE FROM `migtable`', $result);
        $this->assertStringNotContainsString('INSERT INTO `migtable`', $result);
        $this->assertStringNotContainsString('UPDATE `migtable` SET', $result);
    }

    /**
     * @test
     */
    function run_type_dml()
    {
        $this->new->executeStatement(file_get_contents($this->getFile('table.sql')));
        $this->new->executeStatement(file_get_contents($this->getFile('data.sql')));

        $result = $this->runApp([
            '--type' => 'dml',
        ]);

        $this->assertStringNotContainsString('ALTER TABLE igntable', $result);
        $this->assertStringContainsString('DELETE FROM `migtable`', $result);
        $this->assertStringContainsString('INSERT INTO `migtable`', $result);
        $this->assertStringContainsString('UPDATE `migtable` SET', $result);
    }

    /**
     * @test
     */
    function run_type_data()
    {
        $this->new->executeStatement(file_get_contents($this->getFile('table.sql')));

        $result = $this->runApp([
            '--migration' => $this->getFile('migs'),
        ]);
        $this->assertStringContainsString('insert into notexist VALUES(1)', $result);
        $this->assertStringContainsString('insert into notexist (id) VALUES(7);', $result);
        $this->assertStringContainsString('insert into notexist (id) VALUES(8);', $result);
        $this->assertStringContainsString('insert into notexist (id) VALUES(9);', $result);
        $this->assertEquals([1, 7, 8, 9, 21, 22], $this->old->executeQuery('select * from notexist')->fetchFirstColumn());
        $this->assertEquals(['aaa.sql', 'bbb.sql', 'ccc.php'], $this->old->executeQuery('select * from migs')->fetchFirstColumn());

        $this->old->executeStatement('insert into migs values("hoge", "2011-12-24 12:34:56")');
        $result = $this->runApp([
            '--migration' => $this->getFile('migs'),
        ]);
        $this->assertStringNotContainsString('insert into notexist VALUES(1)', $result);
        $this->assertStringNotContainsString('insert into notexist (id) VALUES(7);', $result);
        $this->assertStringNotContainsString('insert into notexist (id) VALUES(8);', $result);
        $this->assertStringNotContainsString('insert into notexist (id) VALUES(9);', $result);
        $this->assertStringContainsString('[2011-12-24 12:34:56] hoge', $result);
        $this->assertEquals([1, 7, 8, 9, 21, 22], $this->old->executeQuery('select * from notexist')->fetchFirstColumn());
        $this->assertEquals(['aaa.sql', 'bbb.sql', 'ccc.php'], $this->old->executeQuery('select * from migs')->fetchFirstColumn());

        $result = $this->runApp([
            '--migration' => $this->getFile('nodir'),
        ]);
        $this->assertStringContainsString('-- no diff data', $result);

        $this->assertExceptionMessage("'invalid query'", $this->runApp, [
            '--migration' => $this->getFile('migs_invalid'),
        ]);
    }

    /**
     * @test
     */
    function run_type_data_choise()
    {
        $this->new->executeStatement(file_get_contents($this->getFile('table.sql')));
        $this->runApp([]);

        unset($this->defaultArgs['-n']);

        /** @var MigrateCommand $command */
        $command = $this->app->get('migrate');
        $command->getHelper('question')->setInputStream($this->getEchoStream('y', ['n' => 3], 'y'));

        $result = $this->runApp([
            '--migration' => $this->getFile('migs'),
        ]);
        $this->assertStringContainsString('migs is created', $result);
        $this->assertEquals([], $this->old->executeQuery('select * from notexist')->fetchFirstColumn());
        $this->assertEquals([], $this->old->executeQuery('select * from migs')->fetchFirstColumn());

        /** @var MigrateCommand $command */
        $command = $this->app->get('migrate');
        $command->getHelper('question')->setInputStream($this->getEchoStream(['p' => 3], 'y'));

        $result = $this->runApp([
            '--migration' => $this->getFile('migs'),
        ]);
        $this->assertStringNotContainsString('migs is created', $result);
        $this->assertEquals([], $this->old->executeQuery('select * from notexist')->fetchFirstColumn());
        $this->assertEquals(['aaa.sql', 'bbb.sql', 'ccc.php'], $this->old->executeQuery('select * from migs')->fetchFirstColumn());
    }

    /**
     * @test
     */
    function run_xcludes()
    {
        $this->new->executeStatement(file_get_contents($this->getFile('table.sql')));
        $this->new->executeStatement(file_get_contents($this->getFile('data.sql')));

        $result = $this->runApp([
            '-v'        => true,
            '--include' => [
                'migtable'
            ],
            '--exclude' => [
                'igntable',
                'migtable',
                'drptable',
            ],
        ]);

        $this->assertStringNotContainsString('DELETE FROM `migtable`', $result);
        $this->assertStringNotContainsString('INSERT INTO `migtable`', $result);
        $this->assertStringNotContainsString('UPDATE `migtable` SET', $result);
        $this->assertStringNotContainsString('`sametable`', $result);
        $this->assertStringNotContainsString('`drptable`', $result);
    }

    /**
     * @test
     */
    function run_dmltypes()
    {
        $this->new->executeStatement(file_get_contents($this->getFile('table.sql')));
        $this->new->executeStatement(file_get_contents($this->getFile('data.sql')));

        $result = $this->runApp([
            '-v'          => true,
            '--type'      => 'dml',
            '--no-insert' => true,
            '--no-delete' => true,
        ]);

        $this->assertStringContainsString('UPDATE ', $result);
        $this->assertStringNotContainsString('INSERT ', $result);
        $this->assertStringNotContainsString('DELETE ', $result);
    }

    /**
     * @test
     */
    function run_trigger()
    {
        $this->old->executeStatement('CREATE TRIGGER trg_remove BEFORE INSERT ON migtable FOR EACH ROW DELETE FROM migtable');
        $this->old->executeStatement('CREATE TRIGGER trg_change BEFORE UPDATE ON migtable FOR EACH ROW DELETE FROM migtable');
        $this->new->executeStatement(file_get_contents($this->getFile('table.sql')));
        $this->new->executeStatement('CREATE TRIGGER trg_create AFTER UPDATE ON migtable FOR EACH ROW DELETE FROM migtable');
        $this->new->executeStatement('CREATE TRIGGER trg_change AFTER UPDATE ON migtable FOR EACH ROW
BEGIN
  INSERT INTO migtable VALUES();
  DELETE FROM migtable;
END');

        $result = $this->runApp([
            '-v' => true,
        ]);

        $this->assertStringContainsString('CREATE TRIGGER trg_create AFTER UPDATE', $result);
        $this->assertStringContainsString('DROP TRIGGER trg_change', $result);
        $this->assertStringContainsString('CREATE TRIGGER trg_change AFTER UPDATE', $result);
        $this->assertStringContainsString('DROP TRIGGER trg_remove', $result);
        $this->assertStringNotContainsString('CREATE TRIGGER trg_remove', $result);
    }

    /**
     * @test
     */
    function run_view()
    {
        $result = $this->runApp([
            '-v'       => true,
            '--noview' => false,
        ]);
        $this->assertStringContainsString('simpleview', $result);

        $result = $this->runApp([
            '-v'       => true,
            '--noview' => true,
        ]);
        $this->assertStringNotContainsString('simpleview', $result);
    }

    /**
     * @test
     */
    function run_throwable_ddl()
    {
        $this->new->executeStatement(file_get_contents($this->getFile('table.sql')));
        $this->insertMultiple($this->old, 'unqtable', [
            [
                'id'   => 2,
                'code' => 7
            ],
            [
                'id'   => 3,
                'code' => 7
            ]
        ]);

        $result = $this->runApp([
            '--force' => true,
        ]);

        $this->assertStringContainsString("key 'unq_index'", $result);

        $this->assertExceptionMessage("key 'unq_index'", $this->runApp, [
            '--force' => false,
        ]);
    }

    /**
     * @test
     */
    function run_throwable_dml()
    {
        $this->new->executeStatement(file_get_contents($this->getFile('table.sql')));
        $this->new->executeStatement(file_get_contents($this->getFile('data.sql')));
        $this->insertMultiple($this->old, 'migtable', [
            [
                'id'   => 19,
                'code' => 20
            ],
            [
                'id'   => 20,
                'code' => 19
            ]
        ]);

        $result = $this->runApp([
            '--force' => true,
        ]);

        $this->assertStringContainsString("key 'unq_index'", $result);

        $count = $this->old->fetchOne("select COUNT(*) from migtable");
        $this->assertEquals($count, $this->old->fetchOne("select COUNT(*) from migtable"));

        $this->assertExceptionMessage("key 'unq_index'", $this->runApp, [
            '--force' => false,
        ]);
    }

    /**
     * @test
     */
    function run_throwable_migration()
    {
        $this->new->executeStatement(file_get_contents($this->getFile('table.sql')));
        $this->new->executeStatement(file_get_contents($this->getFile('data.sql')));

        $result = $this->runApp([
            '-v'     => true,
            '--type' => 'dml',
        ]);

        $this->assertStringContainsString('difftable    is skipped by has different definition between schema', $result);
        $this->assertStringContainsString('nopkeytable  is skipped by has no primary key', $result);
    }

    /**
     * @test
     */
    function run_callback()
    {
        $cfile = self::$tmpdir . '/callback.php';
        file_put_contents($cfile, "
<?php
return [
    'pre-migration'  => function () { echo 'pre-migration'; },
    'post-migration' => function () { echo 'post-migration'; },
];");

        $this->expectOutputString("\npre-migration\npost-migration");
        $this->runApp([
            '--callback' => $cfile,
        ]);
    }

    /**
     * @test
     */
    function run_omission_sql()
    {
        $this->new->executeStatement(file_get_contents($this->getFile('table.sql')));

        $this->insertMultiple($this->old, 'migtable', array_map(function ($i) {
            return [
                'id'   => $i + 100,
                'code' => $i * 10
            ];
        }, range(1, 1001)));

        $result = $this->runApp([]);

        $this->assertLessThanOrEqual(1000, substr_count($result, 'DELETE FROM `migtable`'));
    }

    /**
     * @test
     */
    function run_verbosity()
    {
        $this->new->executeStatement(file_get_contents($this->getFile('table.sql')));
        $this->new->executeStatement(file_get_contents($this->getFile('data.sql')));

        $result = $this->runApp([
            '-c' => true,
            '-q' => true,
        ]);

        $this->assertEmpty($result);

        $result = $this->runApp([
            '-c' => true,
        ]);

        $this->assertStringNotContainsString('is skipped by no diff', $result);

        $result = $this->runApp([
            '-c' => true,
            '-v' => true,
            '-i' => 'notexist,igntable,nopkeytable,sametable,difftable',
            '-e' => 'difftable',
        ]);

        $this->assertStringContainsString('is skipped by include option', $result);
        $this->assertStringContainsString('is skipped by exclude option', $result);
        $this->assertStringContainsString('is skipped by not exists', $result);
        $this->assertStringContainsString('is skipped by no record', $result);
        $this->assertStringContainsString('is skipped by has no primary key', $result);
        $this->assertStringContainsString('is skipped by no diff', $result);
    }

    /**
     * @test
     */
    function run_no_interaction()
    {
        $this->new->executeStatement(file_get_contents($this->getFile('table.sql')));
        $this->new->executeStatement(file_get_contents($this->getFile('heavy.sql')));
        unset($this->defaultArgs['-n']);

        /** @var MigrateCommand $command */
        $command = $this->app->get('migrate');
        $command->getHelper('question')->setInputStream($this->getEchoStream(['y' => 100]));

        $result = $this->runApp([]);

        $this->assertStringContainsString('total query count is 1023', $result);
        $this->assertStringContainsString("INSERT INTO `sametable` SET `id` = '1024';", $result);
    }

    /**
     * @test
     */
    function run_dryrun()
    {
        $this->new->executeStatement(file_get_contents($this->getFile('table.sql')));
        $this->new->executeStatement(file_get_contents($this->getFile('data.sql')));

        $logger = new DebugStack();
        $this->old->getConfiguration()->setSQLLogger($logger);

        $this->runApp([
            '--migration' => $this->getFile('migs'),
            '--check'     => true,
        ]);

        // if dryrun, old DB queries are empty
        $this->assertEmpty($logger->queries);
    }
}
