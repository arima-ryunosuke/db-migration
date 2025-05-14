<?php

namespace ryunosuke\Test\DbMigration\Console\Command;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Event;
use Doctrine\DBAL\Schema\Routine;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\Trigger;
use Doctrine\DBAL\Schema\View;
use Doctrine\DBAL\Types\Type;
use ryunosuke\DbMigration\Console\Command\DumpCommand;
use function ryunosuke\DbMigration\process;
use function ryunosuke\DbMigration\rm_rf;

class DumpCommandTest extends AbstractTestCase
{
    protected $commandName = 'dump';

    protected function setup(): void
    {
        parent::setUp();

        $this->readyObject($this->schema, (new Table('table1', [new Column('id', Type::getType('integer'))]))->setPrimaryKey(['id']));
        $this->readyObject($this->schema, new View('view1', 'select * from table1'));
        $this->readyObject($this->schema, new Trigger('trigger1', 'call function1()', 'table1', [
            'timing' => 'before',
            'event'  => 'update',
        ]));
        $this->readyObject($this->schema, new Event('event1', 'call procedure1()', [
            'status'        => 'ENABLE',
            'since'         => '2011-02-03 12:34:56',
            'until'         => '2022-03-04 12:34:56',
            'intervalValue' => '1',
            'intervalField' => 'DAY',
            'completion'    => 'PRESERVE',
            'comment'       => '',
        ]));
        $this->readyObject($this->schema, new Routine('procedure1', 'insert into table1(id) values(1)', [
            'type'          => Routine::TYPE_PROCEDURE,
            'parameters'    => [],
            'deterministic' => false,
            'dataAccess'    => 'READS SQL DATA',
            'securityType'  => 'DEFINER',
            'comment'       => '',
        ]));
        $this->readyObject($this->schema, new Routine('function1', 'return now()', [
            'type'                  => Routine::TYPE_FUNCTION,
            'returnTypeDeclaration' => 'datetime',
            'parameters'            => [],
            'deterministic'         => false,
            'dataAccess'            => 'READS SQL DATA',
            'securityType'          => 'DEFINER',
            'comment'               => '',
        ]));

        $this->connection->insert('table1', ['id' => 1]);

        $this->app->add(new DumpCommand());

        $this->defaultArgs = [
            'dsn' => AbstractTestCase::TEST_SCHEME . $GLOBALS['db'],
        ];
    }

    /**
     * @test
     */
    function run_()
    {
        $dir = self::$tmpdir . '/dump';
        rm_rf($dir);

        $result = $this->runApp([
            'directory' => $dir,
        ]);

        $this->assertStringContainsString('table1', $result);
        $this->assertStringContainsString('view1', $result);
        $this->assertStringContainsString('event1', $result);
        $this->assertStringContainsString('procedure1', $result);
        $this->assertStringContainsString('function1', $result);

        $databasename = $this->connection->getDatabase();
        $databasefile = "$dir/database.sql";
        $this->assertFileExists($databasefile);
        $this->assertFileContains("foreign_key_checks = 0", $databasefile);
        $this->assertFileContains("CREATE OR REPLACE VIEW", $databasefile);
        $this->assertFileContains("SET foreign_key_checks", $databasefile);
        $this->assertFileNotContains("DROP DATABASE IF EXISTS `$databasename`", $databasefile);
        $this->assertFileContains("-- CREATE DATABASE `$databasename`", $databasefile);
        $this->assertFileNotContains("USE `$databasename`", $databasefile);

        $tablefile = "$dir/table/table1.sql";
        $this->assertFileExists($tablefile);
        $this->assertFileContains("DROP TABLE IF EXISTS", $tablefile);
        $this->assertFileContains("TABLE `table1`", $tablefile);
        $this->assertFileContains("INSERT INTO `table1`", $tablefile);

        $viewfile = "$dir/view/view1.sql";
        $this->assertFileExists($viewfile);
        $this->assertFileContains("DROP VIEW IF EXISTS", $viewfile);
        $this->assertFileContains("VIEW `view1`", $viewfile);
        $this->assertFileContains("SET character_set_client", $viewfile);

        $triggerfile = "$dir/trigger/trigger1.sql";
        $this->assertFileExists($triggerfile);
        $this->assertFileContains("DROP TRIGGER IF EXISTS", $triggerfile);
        $this->assertFileContains("DELIMITER $$$", $triggerfile);
        $this->assertFileContains("TRIGGER `trigger1`", $triggerfile);
        $this->assertFileContains("SET sql_mode", $triggerfile);

        $eventfile = "$dir/event/event1.sql";
        $this->assertFileExists($eventfile);
        $this->assertFileContains("DROP EVENT IF EXISTS", $eventfile);
        $this->assertFileContains("DELIMITER $$$", $eventfile);
        $this->assertFileContains("EVENT `event1`", $eventfile);
        $this->assertFileContains("SET time_zone", $eventfile);

        $procedurefile = "$dir/routine/procedure1.sql";
        $this->assertFileExists($procedurefile);
        $this->assertFileContains("DROP PROCEDURE IF EXISTS", $procedurefile);
        $this->assertFileContains("DELIMITER $$$", $procedurefile);
        $this->assertFileContains("PROCEDURE `procedure1`", $procedurefile);
        $this->assertFileContains("SET sql_mode", $procedurefile);

        $functionfile = "$dir/routine/function1.sql";
        $this->assertFileExists($functionfile);
        $this->assertFileContains("DROP FUNCTION IF EXISTS", $functionfile);
        $this->assertFileContains("DELIMITER $$$", $functionfile);
        $this->assertFileContains("FUNCTION `function1`", $functionfile);
        $this->assertFileContains("SET sql_mode", $functionfile);
    }

    /**
     * @test
     */
    function run_recreate()
    {
        $dir = self::$tmpdir . '/dump';
        rm_rf($dir);

        $this->runApp([
            'directory'  => $dir,
            '--recreate' => null,
        ]);

        $databasename = $this->connection->getDatabase();
        $databasefile = "$dir/database.sql";
        $this->assertFileExists($databasefile);
        $this->assertFileContains("DROP DATABASE IF EXISTS `$databasename`", $databasefile);
        $this->assertFileContains("CREATE DATABASE `$databasename`", $databasefile);
        $this->assertFileContains("USE `$databasename`", $databasefile);

        $this->runApp([
            'directory'  => $dir,
            '--recreate' => 'newdb',
        ]);

        $databasefile = "$dir/database.sql";
        $this->assertFileExists($databasefile);
        $this->assertFileContains("DROP DATABASE IF EXISTS `newdb`", $databasefile);
        $this->assertFileContains("CREATE DATABASE `newdb`", $databasefile);
        $this->assertFileContains("USE `newdb`", $databasefile);
    }

    /**
     * @test
     */
    function run_disable()
    {
        $dir = self::$tmpdir . '/dump';
        rm_rf($dir);

        $result = $this->runApp([
            'directory' => $dir,
            '--disable' => ['event', 'routine'],
        ]);

        $this->assertStringContainsString('table1', $result);
        $this->assertStringContainsString('view1', $result);

        $this->assertStringNotContainsString('event1', $result);
        $this->assertStringNotContainsString('procedure1', $result);
        $this->assertStringNotContainsString('function1', $result);

        $this->assertFileExists("$dir/table/table1.sql");
        $this->assertFileExists("$dir/view/view1.sql");
        $this->assertFileExists("$dir/trigger/trigger1.sql");

        $this->assertFileDoesNotExist("$dir/event/event1.sql");
        $this->assertFileDoesNotExist("$dir/routine/procedure1.sql");
        $this->assertFileDoesNotExist("$dir/routine/function1.sql");
    }

    /**
     * @test
     */
    function run_exclude()
    {
        $dir = self::$tmpdir . '/dump';
        rm_rf($dir);

        $result = $this->runApp([
            'directory'   => $dir,
            '--exclude'   => ['view1'],
            '--migration' => 'table1',
        ]);

        $this->assertStringNotContainsString('table1', $result);
        $this->assertStringNotContainsString('view1', $result);

        $this->assertStringContainsString('event1', $result);
        $this->assertStringContainsString('procedure1', $result);
        $this->assertStringContainsString('function1', $result);

        $this->assertFileDoesNotExist("$dir/table/table1.sql");
    }

    /**
     * @test
     * @runInSeparateProcess
     */
    function integration()
    {
        $mysql_cmd = (function ($command) {
            $paths = explode(PATH_SEPARATOR, getenv('PATH'));
            foreach ($paths as $path) {
                if (is_executable($fullpath = $path . DIRECTORY_SEPARATOR . $command)) {
                    return $fullpath;
                }
            }
            return null;
        })('mysql');
        if ($mysql_cmd === null) {
            $this->markTestSkipped('not detect mysql command');
        }

        $dir = self::$tmpdir . '/dump';
        rm_rf($dir);

        $databasename      = $this->connection->getDatabase();
        $dummydatabasename = $databasename . '_dummy';

        $this->schema->dropDatabase($dummydatabasename);

        $this->runApp([
            'directory'  => $dir,
            '--recreate' => $dummydatabasename,
        ]);

        $params = $this->connection->getParams();
        process($mysql_cmd, [
            '-h' => $params['host'],
            '-u' => $params['user'],
            "-p{$params['password']}",
        ],
            file_get_contents("$dir/database.sql"),
            $stdout, $stderr,
            $dir,
        );

        $this->assertEmpty($stdout);

        $this->connection->executeStatement("USE $dummydatabasename");
        $schema = $this->schema->introspectSchema();

        $this->assertEquals(['test_migration_dummy.table1'], array_keys($schema->getTables()));
        $this->assertEquals(['test_migration_dummy.view1'], array_keys($schema->getViews()));
        $this->assertEquals(['test_migration_dummy.trigger1'], array_keys($schema->getTriggers()));
        $this->assertEquals(['test_migration_dummy.event1'], array_keys($schema->getEvents()));
        $this->assertEquals(['test_migration_dummy.function1', 'test_migration_dummy.procedure1'], array_keys($schema->getRoutines()));

        $this->assertEquals([1], $this->connection->fetchFirstColumn("SELECT * FROM $dummydatabasename.table1"));
    }
}
