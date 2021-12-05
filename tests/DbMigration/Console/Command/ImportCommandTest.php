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
            'dstdsn'   => $GLOBALS['old_db'],
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
                $this->getFile('table.sql'),
                $this->getFile('data.sql'),
            ],
        ]);
        $this->assertStringContainsString("importDDL", $result);
        $this->assertStringContainsString("importDML", $result);
        $this->assertStringContainsString("attachMigration", $result);
        $this->assertStringNotContainsString("diff DDL", $result);
        $this->assertStringNotContainsString("diff DML", $result);
        $this->assertTrue($this->oldSchema->tablesExist(['migs']));
    }

    /**
     * @test
     */
    function run_nonedb()
    {
        $file = sys_get_temp_dir() . '/dummy.sql';
        file_put_contents($file, "CREATE TABLE dummy (id INT) -- " . uniqid('dummy', true));

        $result = $this->runApp([
            '-v'    => true,
            'files' => [
                $file,
            ],
            'dstdsn' => $GLOBALS['none_db'],
        ]);
        $this->assertStringContainsString("no drop database tmp_", $result);
        $this->assertStringContainsString("create database tmp_", $result);
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
                $this->getFile('table.sql'),
                $this->getFile('data.sql'),
            ],
        ]);
    }

    /**
     * @test
     */
    function run_rollback()
    {
        $this->assertExceptionMessage("very invalid sql", $this->runApp, [
            '-v'    => true,
            'files' => [
                $this->getFile('table.sql'),
                $this->getFile('invalid.sql'),
            ],
        ]);
    }
}
