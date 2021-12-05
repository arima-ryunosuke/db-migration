<?php

namespace ryunosuke\Test\DbMigration\Console\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

abstract class AbstractTestCase extends \ryunosuke\Test\DbMigration\AbstractTestCase
{
    /**
     * @var Application
     */
    protected $app;

    protected $commandName;

    protected $defaultArgs = [];

    protected function setup(): void
    {
        parent::setUp();

        $this->app = new Application('Test');
        $this->app->setCatchExceptions(false);
        $this->app->setAutoExit(false);
    }

    protected function getFile($filename)
    {
        if ($filename !== null) {
            $filename = "\\_files\\$filename";
        }
        return str_replace('\\', '/', __DIR__ . $filename);
    }

    protected function questionSetInputStream()
    {
        $stream = fopen('php://memory', 'w+');
        foreach (func_get_args() as $arg) {
            if (is_array($arg)) {
                $l = reset($arg);
                $c = key($arg);
                fwrite($stream, str_repeat($c . PHP_EOL, $l));
            }
            else {
                fwrite($stream, $arg . PHP_EOL);
            }
        }
        rewind($stream);

        (fn($stream) => $this->inputStream = $stream)->call($this->app->getHelperSet()->get('question'), $stream);
    }

    /**
     * @closurable
     * @param array $inputArray
     * @return string
     */
    protected function runApp($inputArray)
    {
        $inputArray = [
                'command' => $this->commandName
            ] + $inputArray + $this->defaultArgs;

        $input = new ArrayInput($inputArray);
        $output = new BufferedOutput();

        $this->app->run($input, $output);

        return $output->fetch();
    }
}
