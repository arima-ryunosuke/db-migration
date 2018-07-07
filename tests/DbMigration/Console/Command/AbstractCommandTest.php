<?php

namespace ryunosuke\Test\DbMigration\Console\Command;

use ryunosuke\DbMigration\Console\Command\AbstractCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class AbstractCommandTest extends AbstractTestCase
{
    /** @var ConcreteCommand */
    private $command;

    protected $defaultArgs = [
        '-n' => true,
    ];

    protected function setup()
    {
        parent::setUp();

        $this->command = new ConcreteCommand('test');
    }

    function test_choice()
    {
        $input = new ArrayInput([], $this->command->getDefinition());
        $output = new BufferedOutput();
        $this->command->setInputOutput($input, $output);

        // default integer
        $this->command->getQuestionHelper()->setInputStream($this->getEchoStream(' '));
        $this->assertEquals(1, $this->command->choice('hoge', ['a', 'b', 'c'], 1));
        $this->assertEquals("hoge [a/B/c]:", $output->fetch());

        // default string
        $this->command->getQuestionHelper()->setInputStream($this->getEchoStream(' '));
        $this->assertEquals(2, $this->command->choice('hoge', ['a', 'b', 'c'], 'c'));
        $this->assertEquals("hoge [a/b/C]:", $output->fetch());

        // select
        $this->command->getQuestionHelper()->setInputStream($this->getEchoStream('b'));
        $this->assertEquals(1, $this->command->choice('hoge', ['a', 'b', 'c'], 0));
        $this->assertEquals("hoge [A/b/c]:", $output->fetch());

        // foward match
        $this->command->getQuestionHelper()->setInputStream($this->getEchoStream('cc'));
        $this->assertEquals(2, $this->command->choice('hoge', ['aaa', 'bbb', 'cccc'], 0));
        $this->assertEquals("hoge [Aaa/bbb/cccc]:", $output->fetch());
    }

    function test_choice_exception()
    {
        $input = new ArrayInput([], $this->command->getDefinition());
        $output = new BufferedOutput();
        $this->command->setInputOutput($input, $output);

        // empty choises
        $this->assertException(new \InvalidArgumentException('empty'), function () {
            $this->command->choice('hoge', ['']);
        });

        // undefined default integer
        $this->assertException(new \InvalidArgumentException('is undefined'), function () {
            $this->command->choice('hoge', ['a'], 1);
        });

        // undefined default string
        $this->assertException(new \InvalidArgumentException('is undefined'), function () {
            $this->command->choice('hoge', ['a'], 'b');
        });

        // ambiguous forward match
        $this->command->getQuestionHelper()->setInputStream($this->getEchoStream('aa'));
        $this->assertException(new \UnexpectedValueException('ambiguous'), function () {
            $this->command->choice('hoge', ['aaA', 'aaB']);
        });

        // invalid answer
        $this->command->getQuestionHelper()->setInputStream($this->getEchoStream('c'));
        $this->assertException(new \UnexpectedValueException('invalid answer'), function () {
            $this->command->choice('hoge', ['a', 'b']);
        });
    }

    function test_confirm()
    {
        $input = new ArrayInput([], $this->command->getDefinition());
        $output = new BufferedOutput();
        $this->command->setInputOutput($input, $output);

        // default true
        $this->command->getQuestionHelper()->setInputStream($this->getEchoStream(' '));
        $this->assertTrue($this->command->confirm('hoge', true));
        $this->assertEquals("hoge [Y/n]:", $output->fetch());

        // default false
        $this->command->getQuestionHelper()->setInputStream($this->getEchoStream(' '));
        $this->assertFalse($this->command->confirm('hoge', false));
        $this->assertEquals("hoge [y/N]:", $output->fetch());

        // select
        $this->command->getQuestionHelper()->setInputStream($this->getEchoStream('y'));
        $this->assertTrue($this->command->confirm('hoge', false));
        $this->assertEquals("hoge [y/N]:", $output->fetch());
    }

    function test_parseDsn()
    {
        $input = new ArrayInput([], $this->command->getDefinition());
        $output = new BufferedOutput();
        $this->command->setInputOutput($input, $output);

        $this->assertEquals([
            'driver'   => 'pdo_mysql',
            'host'     => 'hostname',
            'port'     => '3306',
            'user'     => 'user',
            'password' => 'pass',
            'dbname'   => 'dbname',
            'charset'  => 'utf8',
        ], $this->command->parseDsn('mysql://user:pass@hostname:3306/dbname?charset=utf8'));

        $this->assertEquals([
            'driver' => 'pdo_sqlite',
            'host'   => 'hostname',
            'user'   => (posix_getpwuid(posix_geteuid())['name']),
            'path'   => 'dbname',
        ], $this->command->parseDsn('sqlite://hostname/dbname'));
    }

    function test_normalizeFile()
    {
        $input = new ArrayInput([], $this->command->getDefinition());
        $output = new BufferedOutput();
        $this->command->setInputOutput($input, $output);

        $this->assertEquals([__FILE__, 'hoge'], $this->command->normalizeFile([__FILE__, 'hoge']));
        $this->assertExceptionMessage('is directory', function () { $this->command->normalizeFile([__DIR__]); });
    }

    function test_format()
    {
        $input = new ArrayInput([], $this->command->getDefinition());
        $output = new BufferedOutput();
        $this->command->setInputOutput($input, $output);

        $input->setOption('format', '');
        $this->assertEquals('SELECT hoge FROM tablename;', $this->command->formatSql('SELECT hoge FROM tablename'));

        $input->setOption('format', 'compress');
        $this->assertContains('SELECT hoge FROM tablename;', $this->command->formatSql('SELECT   hoge FROM    tablename'));

        $input->setOption('format', 'pretty');
        $this->assertContains("[0m", $this->command->formatSql('SELECT hoge FROM tablename'));
        $this->assertContains("\n", $this->command->formatSql('SELECT hoge FROM tablename'));

        $input->setOption('format', 'format');
        $this->assertContains("\n", $this->command->formatSql('SELECT hoge FROM tablename'));

        $input->setOption('format', 'highlight');
        $this->assertContains("[0m", $this->command->formatSql('SELECT hoge FROM tablename'));

        $input->setOption('omit', '24');
        $input->setOption('format', '');
        $this->assertEquals("SELECT hoge\n...(omitted)", $this->command->formatSql('SELECT hoge FROM tablename'));
    }
}

class ConcreteCommand extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('concrete');
        $this->setDefinition([
            new InputOption('format', null, InputOption::VALUE_OPTIONAL),
            new InputOption('omit', null, InputOption::VALUE_REQUIRED),
        ]);
    }

    public function setInputOutput(InputInterface $input, OutputInterface $output)
    {
        return parent::setInputOutput($input, $output);
    }

    public function choice($message, $choices = [], $default = 0)
    {
        return parent::choice($message, $choices, $default);
    }

    public function confirm($message, $default = true)
    {
        return parent::confirm($message, $default);
    }

    public function parseDsn($dsn)
    {
        return parent::parseDsn($dsn);
    }

    public function normalizeFile($files)
    {
        return parent::normalizeFile($files);
    }

    public function formatSql($sql)
    {
        return parent::formatSql($sql);
    }
}
