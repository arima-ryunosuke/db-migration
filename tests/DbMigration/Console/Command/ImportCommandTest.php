<?php

namespace ryunosuke\Test\DbMigration\Console\Command;

use ryunosuke\DbMigration\Console\Command\ImportCommand;

class ImportCommandTest extends AbstractTestCase
{
    protected $commandName = 'import';

    protected function setup(): void
    {
        parent::setUp();

        $this->app->add(new ImportCommand());

        $this->defaultArgs = [
            '--format' => 'none',
            '-n'       => true,
            'dsn'      => AbstractTestCase::TEST_SCHEME . $GLOBALS['db'],
        ];
    }

    /**
     * @test
     */
    function run_()
    {
        $result = $this->runApp([
            '-v'    => true,
            '-m'    => $this->getFile('migs'),
            'files' => [
                $this->getFile('table.php'),
                $this->getFile('data/difftable.php'),
                $this->getFile('data/longtable.php'),
                $this->getFile('data/migtable.php'),
                $this->getFile('data/notexist.php'),
                $this->getFile('data/sametable.php'),
            ],
        ]);
        $this->assertStringContainsString("importDDL", $result);
        $this->assertStringContainsString("importDML", $result);
        $this->assertStringContainsString("attachMigration", $result);
        $this->assertStringNotContainsString("diff DDL", $result);
        $this->assertStringNotContainsString("diff DML", $result);
        $this->assertTrue($this->schema->tablesExist(['migs']));
    }

    /**
     * @test
     */
    function run_migration()
    {
        unset($this->defaultArgs['-n']);
        $this->questionSetInputStream('y', 'y', 'n', 'p', 'y', 'y');

        $result = $this->runApp([
            '-v'    => true,
            '-m'    => $this->getFile('migs'),
            'files' => [
                $this->getFile('table.php'),
                $this->getFile('data/difftable.php'),
                $this->getFile('data/longtable.php'),
                $this->getFile('data/migtable.php'),
                $this->getFile('data/notexist.php'),
                $this->getFile('data/sametable.php'),
            ],
        ]);
        $this->assertStringContainsString("Attach: 1.sql", $result);
        $this->assertStringNotContainsString("Attach: 2.tsv", $result);
        $this->assertStringNotContainsString("Attach: 3.php", $result);
        $this->assertStringContainsString("Attach: 4.json", $result);
        $this->assertStringContainsString("Attach: 5.yaml", $result);
        $this->assertEquals([
            ['1.sql'],
            ['3.php'], // "p" なので当たっている
            ['4.json'],
            ['5.yaml'],
        ], $this->connection->fetchAllNumeric('select version from migs'));

        $this->questionSetInputStream('y', 'y');
        $this->assertExceptionMessage("'invalid query'", $this->runApp, [
            '-m'    => $this->getFile('migs_invalid'),
            'files' => [
                $this->getFile('table.php'),
            ],
        ]);
    }

    /**
     * @test
     */
    function run_nonedb()
    {
        $dbname = $this->dbparams['dbname'];
        $this->connection->createSchemaManager()->dropDatabase($dbname);

        $file = self::$tmpdir . '/dummy.sql';
        file_put_contents($file, "CREATE TABLE dummy (id INT) -- " . uniqid('dummy', true));

        $result = $this->runApp([
            '-v'    => true,
            'files' => [
                $file,
            ],
            'dsn'   => AbstractTestCase::TEST_SCHEME . $GLOBALS['db'],
        ]);
        $this->assertStringContainsString("no drop database $dbname", $result);
        $this->assertStringContainsString("create database $dbname", $result);
    }

    /**
     * @test
     */
    function run_cancel()
    {
        unset($this->defaultArgs['-n']);

        $this->questionSetInputStream(['n' => 100]);
        $this->assertExceptionMessage("canceled", $this->runApp, [
            '-v'    => true,
            'files' => [
                $this->getFile('table.php'),
                $this->getFile('data/difftable.php'),
                $this->getFile('data/longtable.php'),
                $this->getFile('data/migtable.php'),
                $this->getFile('data/notexist.php'),
                $this->getFile('data/sametable.php'),
            ],
        ]);
    }

    /**
     * @test
     */
    function run_failddl()
    {
        $this->assertExceptionMessage("very invalid sql", $this->runApp, [
            '-v'    => true,
            'files' => [
                $this->getFile('invalid.sql'),
            ],
        ]);
    }

    /**
     * @test
     */
    function run_faildml()
    {
        $this->assertExceptionMessage("very invalid sql", $this->runApp, [
            '-v'    => true,
            'files' => [
                $this->getFile('table.php'),
                $this->getFile('invalid.sql'),
            ],
        ]);
    }
}
