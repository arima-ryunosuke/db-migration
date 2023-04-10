<?php

namespace ryunosuke\Test\DbMigration\Console\Command;

use ryunosuke\DbMigration\Console\Command\ExportCommand;

class ExportCommandTest extends AbstractTestCase
{
    protected $commandName = 'export';

    protected function setup(): void
    {
        parent::setUp();

        $this->readyTable($this->schema, $this->createSimpleTable('gentable', 'integer', 'id', 'code'));

        $this->connection->insert('gentable', [
            'id'   => 1,
            'code' => 10,
        ]);

        $this->app->add(new ExportCommand());

        $this->defaultArgs = [
            'dsn' => AbstractTestCase::TEST_SCHEME . $GLOBALS['db'],
        ];
    }

    /**
     * @test
     */
    function run_ddl()
    {
        $createfile = self::$tmpdir . '/create.php';
        !file_exists($createfile) or unlink($createfile);

        $result = $this->runApp([
            'files' => [
                str_replace('\\', '/', $createfile),
            ],
        ]);

        $this->assertEquals('', $result);
        $this->assertFileExists($createfile);
    }

    /**
     * @test
     */
    function run_ddl_vvv()
    {
        $createfile = self::$tmpdir . '/create.php';
        !file_exists($createfile) or unlink($createfile);

        $result = $this->runApp([
            '-vvv'  => true,
            'files' => [
                str_replace('\\', '/', $createfile),
            ],
        ]);

        $this->assertStringContainsString('gentable', $result);
        $this->assertFileExists($createfile);
        $this->assertEquals('integer', (include $createfile)['table']['gentable']['column']['id']['type']);
    }

    /**
     * @test
     */
    function run_dml()
    {
        $this->runApp([
            'files' => [
                str_replace('\\', '/', self::$tmpdir . '/table.php'),
                str_replace('\\', '/', self::$tmpdir . '/gentable.php'),
            ],
        ]);

        $this->assertEquals([
            [
                'id'   => '1',
                'code' => '10',
            ],
        ], include self::$tmpdir . '/gentable.php');
    }

    /**
     * @test
     */
    function run_dml_where()
    {
        $this->runApp([
            '--where' => 'gentable.id=-1',
            'files'   => [
                str_replace('\\', '/', self::$tmpdir . '/table.php'),
                str_replace('\\', '/', self::$tmpdir . '/gentable.php'),
            ],
        ]);

        $this->assertEquals([], include self::$tmpdir . '/gentable.php');
    }

    /**
     * @test
     */
    function run_dml_ignore()
    {
        $this->runApp([
            '--ignore' => 'gentable.id',
            'files'    => [
                str_replace('\\', '/', self::$tmpdir . '/table.php'),
                str_replace('\\', '/', self::$tmpdir . '/gentable.php'),
            ],
        ]);

        $this->assertEquals([
            [
                'id'   => '0',
                'code' => '10',
            ],
        ], include self::$tmpdir . '/gentable.php');
    }

    /**
     * @test
     */
    function run_data()
    {
        $this->runApp([
            '--migration' => 'gentable',
            'files'       => [
                str_replace('\\', '/', self::$tmpdir . '/table.php'),
            ],
        ]);

        $schema = include self::$tmpdir . '/table.php';
        $this->assertArrayNotHasKey('gentable', $schema['table']);
    }

    /**
     * @test
     */
    function run_notfile()
    {
        $this->assertExceptionMessage('is directory', $this->runApp, [
            'files' => [
                $this->getFile(null),
            ],
        ]);
    }
}
