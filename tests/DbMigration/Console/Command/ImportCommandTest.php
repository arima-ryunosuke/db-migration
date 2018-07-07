<?php
namespace ryunosuke\Test\DbMigration\Console\Command;

use Doctrine\DBAL\Logging\DebugStack;
use Doctrine\DBAL\Schema\View;
use ryunosuke\DbMigration\Console\Command\ImportCommand;

class ImportCommandTest extends AbstractTestCase
{
    protected $commandName = 'import';

    protected function setup()
    {
        parent::setUp();

        $this->app->add(new ImportCommand());

        $this->defaultArgs = array(
            '--format' => 'none',
            '-n'       => true,
            'dstdsn' => $GLOBALS['old_db'],
        );
    }

    /**
     * @test
     */
    function run_()
    {
        $result = $this->runApp(array(
            '-v'     => true,
            '-m'     => $this->getFile('migs'),
            'files'  => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql'),
            )
        ));
        $this->assertContains("importDDL", $result);
        $this->assertContains("importDML", $result);
        $this->assertContains("attachMigration", $result);
        $this->assertNotContains("diff DDL", $result);
        $this->assertNotContains("diff DML", $result);
        $this->assertTrue($this->oldSchema->tablesExist(['migs']));
    }

    /**
     * @test
     */
    function run_cancel()
    {
        unset($this->defaultArgs['-n']);

        /** @var ImportCommand $command */
        $command = $this->app->get('import');
        $command->getQuestionHelper()->setInputStream($this->getEchoStream(array('n' => 100)));
        $this->assertExceptionMessage("canceled", $this->runApp, array(
            '-v'     => true,
            'files'  => array(
                $this->getFile('table.sql'),
                $this->getFile('data.sql'),
            )
        ));
    }

    /**
     * @test
     */
    function run_rollback()
    {
        $this->assertExceptionMessage("very invalid sql", $this->runApp, array(
            '-v'     => true,
            'files'  => array(
                $this->getFile('table.sql'),
                $this->getFile('invalid.sql'),
            )
        ));
    }
}
