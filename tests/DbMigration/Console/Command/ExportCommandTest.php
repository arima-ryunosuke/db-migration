<?php

namespace ryunosuke\Test\DbMigration\Console\Command;

use ryunosuke\DbMigration\Console\Command\ExportCommand;

class ExportCommandTest extends AbstractTestCase
{
    protected $commandName = 'export';

    protected function setup(): void
    {
        parent::setUp();

        $this->readyTable($this->oldSchema, $this->createSimpleTable('gentable', 'integer', 'id', 'code'));

        $this->old->insert('gentable', [
            'id'   => 1,
            'code' => 10
        ]);

        $this->app->add(new ExportCommand());

        $this->defaultArgs = [
            'srcdsn' => $GLOBALS['old_db'],
        ];
    }

    /**
     * @test
     */
    function run_ddl()
    {
        $createfile = self::$tmpdir . '/create.sql';
        !file_exists($createfile) or unlink($createfile);

        $result = $this->runApp([
            'files' => [
                str_replace('\\', '/', $createfile),
            ]
        ]);

        $this->assertEquals('', $result);
        $this->assertFileExists($createfile);
    }

    /**
     * @test
     */
    function run_ddl_vvv()
    {
        $createfile = self::$tmpdir . '/create.sql';
        !file_exists($createfile) or unlink($createfile);

        $result = $this->runApp([
            '-vvv'  => true,
            'files' => [
                str_replace('\\', '/', $createfile),
            ]
        ]);

        $this->assertStringContainsString('CREATE TABLE gentable', $result);
        $this->assertFileExists($createfile);
    }

    /**
     * @test
     */
    function run_dml()
    {
        $this->runApp([
            'files' => [
                str_replace('\\', '/', self::$tmpdir . '/table.sql'),
                str_replace('\\', '/', self::$tmpdir . '/gentable.sql'),
            ]
        ]);

        $this->assertFileContains("INSERT INTO `gentable` (`id`, `code`) VALUES ('1', '10')", self::$tmpdir . '/gentable.sql');
    }

    /**
     * @test
     */
    function run_dml_where()
    {
        $this->runApp([
            '--where' => 'gentable.id=-1',
            'files'   => [
                str_replace('\\', '/', self::$tmpdir . '/table.sql'),
                str_replace('\\', '/', self::$tmpdir . '/gentable.sql'),
            ]
        ]);

        $this->assertStringEqualsFile(self::$tmpdir . '/gentable.sql', "\n");
    }

    /**
     * @test
     */
    function run_dml_ignore()
    {
        $this->runApp([
            '--ignore' => 'gentable.id',
            'files'    => [
                str_replace('\\', '/', self::$tmpdir . '/table.sql'),
                str_replace('\\', '/', self::$tmpdir . '/gentable.sql'),
            ]
        ]);

        $this->assertFileContains("INSERT INTO `gentable` (`code`, `id`) VALUES ('10', '0')", self::$tmpdir . '/gentable.sql', "\n");
    }

    /**
     * @test
     */
    function run_data()
    {
        $this->runApp([
            '--migration' => 'gentable',
            'files'       => [
                str_replace('\\', '/', self::$tmpdir . '/table.sql'),
            ]
        ]);

        $this->assertFileNotContains('gentable', self::$tmpdir . '/table.sql');
    }

    /**
     * @test
     */
    function run_notfile()
    {
        $this->assertExceptionMessage('is directory', $this->runApp, [
            'files' => [
                $this->getFile(null)
            ]
        ]);
    }
}
