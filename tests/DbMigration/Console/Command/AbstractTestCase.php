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

    protected function getStringStream($inputStream)
    {
        $stream = fopen('php://memory', 'w+');
        foreach ($inputStream as $k => $v) {
            if (is_string($k)) {
                fwrite($stream, str_repeat($k . PHP_EOL, $v));
            }
            else {
                fwrite($stream, $v . PHP_EOL);
            }
        }
        rewind($stream);
        return $stream;
    }

    /**
     * @closurable
     * @param array $inputArray
     * @param resource|array $inputStream
     * @return string
     */
    protected function runApp($inputArray, $inputStream = [])
    {
        $inputArray = [
                'command' => $this->commandName,
            ] + $inputArray + $this->defaultArgs;

        $input  = new ArrayInput($inputArray);
        $output = new BufferedOutput();

        if ($inputStream) {
            if (is_array($inputStream)) {
                $inputStream = $this->getStringStream($inputStream);
            }
            $input->setStream($inputStream);
        }

        $this->app->run($input, $output);

        return $output->fetch();
    }
}
