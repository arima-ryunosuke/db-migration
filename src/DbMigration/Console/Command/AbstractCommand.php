<?php

namespace ryunosuke\DbMigration\Console\Command;

use ryunosuke\DbMigration\Console\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

abstract class AbstractCommand extends Command
{
    /** @var InputInterface */
    protected $input;

    /** @var OutputInterface */
    protected $output;

    /** @var Logger */
    protected $logger;

    protected function setInputOutput(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->logger = new Logger($input, $output);

        $this->config();
    }

    protected function config()
    {
        $configFile = $this->input->getOption('config');
        if (!strlen($configFile)) {
            return;
        }
        if (!file_exists($configFile)) {
            throw new \InvalidArgumentException("'$configFile' is not exists.");
        }

        $allConfig = require $configFile;
        $config = ($allConfig[$this->getName()] ?? []) + ($allConfig['default'] ?? []);
        foreach ($config as $name => $value) {
            $definition = $this->getDefinition();
            if (ctype_digit("$name")) {
                if ($definition->hasArgument($name = (int) $name)) {
                    $definition->getArgument($name)->setDefault($value);
                }
            }
            else {
                if ($definition->hasOption($name)) {
                    $definition->getOption($name)->setDefault($value);
                }
            }
        }
    }

    protected function choice($message, $choices = [], $default = 0)
    {
        // filter and check $choices
        $choices = array_filter((array) $choices, 'strlen');
        if (!$choices) {
            throw new \InvalidArgumentException('$choices is empty.');
        }

        // detect default value
        if (is_int($default)) {
            if (!isset($choices[$default])) {
                throw new \InvalidArgumentException("default index '$default' is undefined.");
            }
            $default = $choices[$default];
        }
        $defkey = array_search($default, $choices);
        if ($defkey === false) {
            throw new \InvalidArgumentException("default value '$default' is undefined.");
        }

        // default value to ucfirst
        $choices[$defkey] = ucfirst($choices[$defkey]);

        // question
        $selection = implode('/', $choices);
        $question = new Question("<question>{$message} [{$selection}]:</question>", $default);
        $answer = $this->getHelper('question')->ask($this->input, $this->output, $question);

        // return answer index
        $return = null;
        foreach ($choices as $index => $choice) {
            if (stripos($choice, $answer) === 0) {
                if (isset($return)) {
                    throw new \UnexpectedValueException("ambiguous forward match.");
                }
                $return = $index;
            }
        }
        if (!isset($return)) {
            throw new \UnexpectedValueException("'$answer' is invalid answer.");
        }
        return $return;
    }

    protected function confirm($message, $default = true)
    {
        return $this->choice($message, ['y', 'n'], $default ? 0 : 1) === 0;
    }

    protected function parseDsn($dsn)
    {
        $parseDatabaseUrl = new \ReflectionMethod('\\Doctrine\\DBAL\\DriverManager', 'parseDatabaseUrl');
        $parseDatabaseUrl->setAccessible(true);
        $params = $parseDatabaseUrl->invoke(null, ['url' => $dsn]);
        unset($params['url']);

        // for .my.cnf
        if (isset($_SERVER['HOME']) && stripos($params['driver'], 'mysql') !== false) {
            $mycnf = $_SERVER['HOME'] . '/.my.cnf';
            if (is_readable($mycnf)) {
                $mycnfini = parse_ini_file($mycnf, true);
                if (!isset($params['user']) && isset($mycnfini['client']['user'])) {
                    $params['user'] = $mycnfini['client']['user'];
                }
                if (!isset($params['password']) && isset($mycnfini['client']['password'])) {
                    $params['password'] = $mycnfini['client']['password'];
                }
            }
        }

        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $params += [
                'user' => (posix_getpwuid(posix_geteuid())['name']),
            ];
        }

        if (isset($params['password']) && $params['password'] === '') {
            $question = new Question("<question>input password of $dsn: </question>");
            $question->setHidden(getenv('SHELL_INTERACTIVE') === false); // for test
            $params['password'] = $this->getHelper('question')->ask($this->input, $this->output, $question);
        }

        return $params;
    }

    protected function normalizeFile($files)
    {
        $result = [];
        foreach ($files as $file) {
            $filePath = realpath($file);

            if (false === $filePath) {
                $filePath = $file;
            }

            if (is_dir($filePath)) {
                throw new \InvalidArgumentException(sprintf("'<info>%s</info>' is directory.", $filePath));
            }

            $result[] = $filePath;
        }
        return $result;
    }

    public function formatSql($sql)
    {
        $sql .= ';';
        switch ($this->input->getOption('format')) {
            case 'pretty':
                $sql = \SqlFormatter::format($sql, true);
                break;
            case 'format':
                $sql = \SqlFormatter::format($sql, false);
                break;
            case 'highlight':
                $sql = \SqlFormatter::highlight($sql);
                break;
            case 'compress':
                $sql = \SqlFormatter::compress($sql);
                break;
        }

        $omitlength = intval($this->input->getOption('omit')) ?: 65535;
        if (mb_strlen($sql) > $omitlength) {
            $sql = mb_strimwidth($sql, 0, $omitlength, "\n...(omitted)");
        }

        return $sql;
    }
}
