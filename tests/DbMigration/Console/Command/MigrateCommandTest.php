<?php

namespace ryunosuke\Test\DbMigration\Console\Command;

use Doctrine\DBAL\Logging\DebugStack;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\View;
use Doctrine\DBAL\Types\Type;
use ryunosuke\DbMigration\Console\Command\MigrateCommand;

class MigrateCommandTest extends AbstractTestCase
{
    protected $commandName = 'migrate';

    protected function setup(): void
    {
        parent::setUp();

        $migtable = $this->createSimpleTable('migtable', 'integer', 'id', 'code');
        $migtable->addUniqueIndex([
            'code',
        ], 'unq_index');

        $longtable = $this->createSimpleTable('longtable', 'integer', 'id');
        $longtable->addColumn('text_data', 'text');
        $longtable->addColumn('blob_data', 'blob');
        $nopkeytable = $this->createSimpleTable('nopkeytable', 'integer', 'id');
        $nopkeytable->dropPrimaryKey();
        $this->readyTable($this->schema, $migtable);
        $this->readyTable($this->schema, $longtable);
        $this->readyTable($this->schema, $nopkeytable);
        $this->readyTable($this->schema, $this->createSimpleTable('difftable', 'integer', 'code'));
        $this->readyTable($this->schema, $this->createSimpleTable('igntable', 'integer', 'id', 'code'));
        $this->readyTable($this->schema, $this->createSimpleTable('unqtable', 'integer', 'id', 'code'));
        $this->readyTable($this->schema, $this->createSimpleTable('sametable', 'integer', 'id'));
        $this->readyTable($this->schema, $this->createSimpleTable('drptable', 'integer', 'id'));
        $this->readyTable($this->schema, $this->createSimpleTable('eventtable', 'integer', 'id'));

        $view = new View('simpleview', 'select 1');
        $this->schema->createView($view);

        $this->connection->insert('migtable', [
            'id'   => 5,
            'code' => 2,
        ]);
        $this->connection->insert('migtable', [
            'id'   => 9,
            'code' => 999,
        ]);
        $this->connection->insert('sametable', [
            'id' => 9,
        ]);

        $this->app->add(new MigrateCommand());

        $this->defaultArgs = [
            '--format' => 'none',
            '-n'       => true,
            'dsn'      => $GLOBALS['db'],
        ];
    }

    /**
     * @test
     */
    function run_()
    {
        $result = $this->runApp([
            '-vvv'  => true,
            'files' => [
                $this->getFile('table.php'),
                $this->getFile('data/difftable.php'),
                $this->getFile('data/longtable.php'),
                $this->getFile('data/migtable.php'),
                $this->getFile('data/notexist.php'),
                $this->getFile('data/sametable.php'),
            ],
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
        $result = $this->runApp([
            '--type' => 'ddl',
            'files'  => [
                $this->getFile('table.php'),
                $this->getFile('data/difftable.php'),
                $this->getFile('data/longtable.php'),
                $this->getFile('data/migtable.php'),
                $this->getFile('data/notexist.php'),
                $this->getFile('data/sametable.php'),
            ],
        ]);
        $this->assertStringContainsString('ALTER TABLE igntable', $result);
        $this->assertStringNotContainsString('DELETE FROM `migtable`', $result);
        $this->assertStringNotContainsString('INSERT INTO `migtable`', $result);
        $this->assertStringNotContainsString('UPDATE `migtable` SET', $result);

        $result = $this->runApp([
            '--type' => 'ddl',
            'files'  => [
                $this->getFile('table.php'),
            ],
        ]);
        $this->assertStringContainsString('no diff schema', $result);
    }

    /**
     * @test
     */
    function run_type_dml()
    {
        $result = $this->runApp([
            '--type' => 'dml',
            'files'  => [
                $this->getFile('data/longtable.php'),
                $this->getFile('data/migtable.php'),
                $this->getFile('data/sametable.php'),
            ],
        ]);

        $this->assertStringNotContainsString('ALTER TABLE igntable', $result);
        $this->assertStringContainsString('DELETE FROM `migtable`', $result);
        $this->assertStringContainsString('INSERT INTO `migtable`', $result);
        $this->assertStringContainsString('UPDATE `migtable` SET', $result);

        $result = $this->runApp([
            '--type' => 'dml',
            'files'  => [
                $this->getFile('data/longtable.php'),
                $this->getFile('data/migtable.php'),
                $this->getFile('data/sametable.php'),
            ],
        ]);

        $this->assertStringContainsString('no diff table', $result);
    }

    /**
     * @test
     */
    function run_type_data()
    {
        $result = $this->runApp([
            '--migration' => $this->getFile('migs'),
            'files'       => [
                $this->getFile('table.php'),
            ],
        ]);
        $this->assertStringContainsString('insert into notexist VALUES(11)', $result);
        $this->assertStringContainsString('INSERT INTO notexist (id) VALUES(32)', $result);
        $this->assertStringContainsString('INSERT INTO notexist (id) VALUES(41)', $result);
        $this->assertStringContainsString('INSERT INTO notexist (id) VALUES(51)', $result);
        $this->assertEquals([11, 12, 21, 22, 31, 32, 33, 41, 42, 51, 52], $this->connection->executeQuery('select * from notexist')->fetchFirstColumn());
        $this->assertEquals(['1.sql', '2.tsv', '3.php', '4.json', '5.yaml'], $this->connection->executeQuery('select * from migs')->fetchFirstColumn());

        $this->connection->executeStatement('insert into migs values("hoge", "2011-12-24 12:34:56", "null")');
        $result = $this->runApp([
            '--migration' => $this->getFile('migs'),
        ]);
        $this->assertStringNotContainsString('insert into notexist VALUES(1)', $result);
        $this->assertStringNotContainsString('insert into notexist (id) VALUES(7);', $result);
        $this->assertStringNotContainsString('insert into notexist (id) VALUES(8);', $result);
        $this->assertStringNotContainsString('insert into notexist (id) VALUES(9);', $result);
        $this->assertStringContainsString('[2011-12-24 12:34:56] hoge', $result);
        $this->assertEquals([11, 12, 21, 22, 31, 32, 33, 41, 42, 51, 52], $this->connection->executeQuery('select * from notexist')->fetchFirstColumn());
        $this->assertEquals(['1.sql', '2.tsv', '3.php', '4.json', '5.yaml'], $this->connection->executeQuery('select * from migs')->fetchFirstColumn());

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
        unset($this->defaultArgs['-n']);

        $this->questionSetInputStream('y', ['n' => 5], 'y');

        $result = $this->runApp([
            '--migration' => $this->getFile('migs'),
        ]);
        $this->assertStringContainsString('migs is created', $result);
        $this->assertEquals([], $this->connection->executeQuery('select * from migs')->fetchFirstColumn());

        $this->questionSetInputStream(['p' => 5], 'y');

        $result = $this->runApp([
            '--migration' => $this->getFile('migs'),
        ]);
        $this->assertStringNotContainsString('migs is created', $result);
        $this->assertEquals(['1.sql', '2.tsv', '3.php', '4.json', '5.yaml'], $this->connection->executeQuery('select * from migs')->fetchFirstColumn());
    }

    /**
     * @test
     */
    function run_type_data_alter()
    {
        $this->schema->createTable(new Table('dummy', [new Column('id', Type::getType('integer'))]));
        $result = $this->runApp([
            '--migration' => $this->getFile('dummy'),
        ]);
        $this->assertStringContainsString('dummy is altered', $result);
    }

    /**
     * @test
     */
    function run_dmltypes()
    {
        $result = $this->runApp([
            '-v'         => true,
            '--type'     => 'dml',
            '--dml-type' => 'update',
            'files'      => [
                $this->getFile('data/longtable.php'),
                $this->getFile('data/migtable.php'),
                $this->getFile('data/sametable.php'),
            ],
        ]);

        $this->assertStringContainsString('UPDATE ', $result);
        $this->assertStringNotContainsString('INSERT ', $result);
        $this->assertStringNotContainsString('DELETE ', $result);
    }

    /**
     * @test
     */
    function run_throwable_ddl()
    {
        $this->insertMultiple($this->connection, 'unqtable', [
            [
                'id'   => 2,
                'code' => 7,
            ],
            [
                'id'   => 3,
                'code' => 7,
            ],
        ]);

        $result = $this->runApp([
            '--force' => true,
            'files'   => [
                $this->getFile('table.php'),
            ],
        ]);

        $this->assertStringContainsString("Duplicate entry '7'", $result);

        $this->assertExceptionMessage("Duplicate entry '7'", $this->runApp, [
            '--force' => false,
            'files'   => [
                $this->getFile('table.php'),
            ],
        ]);
    }

    /**
     * @test
     */
    function run_throwable_dml()
    {
        $file = self::$tmpdir . '/migtable.json';
        file_put_contents($file, json_encode([
            [
                'id'   => 19,
                'code' => 19,
            ],
            [
                'id'   => 20,
                'code' => 19,
            ],
        ]));

        $result = $this->runApp([
            '--type'  => 'dml',
            '--force' => true,
            'files'   => [
                $file,
            ],
        ]);

        $this->assertStringContainsString("Duplicate entry '19'", $result);

        $this->assertExceptionMessage("Duplicate entry '19'", $this->runApp, [
            '--type'  => 'dml',
            '--force' => false,
            'files'   => [
                $file,
            ],
        ]);
    }

    /**
     * @test
     */
    function run_event()
    {
        $this->runApp([
            '--event' => __DIR__ . '/_files/event.php',
            '--check' => true,
        ]);

        $this->assertEquals(2, $this->connection->fetchOne("select COUNT(*) from eventtable"));
    }

    /**
     * @test
     */
    function run_omission_sql()
    {
        unset($this->defaultArgs['-n']);

        $this->insertMultiple($this->connection, 'migtable', array_map(function ($i) {
            return [
                'id'   => $i + 100,
                'code' => $i * 10,
            ];
        }, range(1, 1001)));

        $this->questionSetInputStream(['n' => 100]);
        $result = $this->runApp([
            '--type' => 'dml',
            'files'  => [
                $this->getFile('migtable.php'),
            ],
        ]);

        $this->assertLessThanOrEqual(1000, substr_count($result, 'DELETE FROM `migtable`'));

        $this->questionSetInputStream(['y' => 100]);
        $result = $this->runApp([
            '--type' => 'dml',
            'files'  => [
                $this->getFile('migtable.php'),
            ],
        ]);

        $this->assertGreaterThan(1000, substr_count($result, 'DELETE FROM `migtable`'));
    }

    /**
     * @test
     */
    function run_verbosity()
    {
        $result = $this->runApp([
            '-c'    => true,
            '-q'    => true,
            'files' => [
                $this->getFile('table.php'),
                $this->getFile('data/difftable.php'),
                $this->getFile('data/longtable.php'),
                $this->getFile('data/migtable.php'),
                $this->getFile('data/sametable.php'),
            ],
        ]);

        $this->assertEmpty($result);

        $result = $this->runApp([
            '-c'    => true,
            'files' => [
                $this->getFile('table.php'),
                $this->getFile('data/difftable.php'),
                $this->getFile('data/longtable.php'),
                $this->getFile('data/migtable.php'),
                $this->getFile('data/sametable.php'),
            ],
        ]);

        $this->assertStringNotContainsString('is skipped by no diff', $result);
    }

    /**
     * @test
     */
    function run_dryrun()
    {
        $logger = new DebugStack();
        $this->connection->getConfiguration()->setSQLLogger($logger);

        $this->runApp([
            '--migration' => $this->getFile('migs'),
            '--check'     => true,
        ]);

        // if dryrun, old DB queries are empty
        $this->assertEmpty($logger->queries);
    }
}
