<?php

namespace ryunosuke\DbMigration\Console\Command;

use Doctrine\DBAL\Connection;
use ryunosuke\DbMigration\Console\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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

    protected function getCommonOptions($optnames)
    {
        $options = [
            'type'        => [
                't',
                InputOption::VALUE_OPTIONAL,
                'Migration SQL type (ddl, dml. default both)',
            ],
            'dml-type'    => [
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Specify dml type (enable comma separated value. e.g. --dml-type insert,update)',
                ['insert', 'update', 'delete'],
            ],
            'check'       => [
                'c',
                InputOption::VALUE_NONE,
                'Check only (Dry run. force no-interaction)',
            ],
            'force'       => [
                'f',
                InputOption::VALUE_NONE,
                'Force continue, ignore errors',
            ],
            'bulk-insert' => [
                null,
                InputOption::VALUE_NONE,
                'Enable bulk insert',
            ],
            'where'       => [
                'w',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Where condition.',
            ],
            'ignore'      => [
                'g',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Ignore column.',
            ],
            'indent'      => [
                null,
                InputOption::VALUE_OPTIONAL,
                'Specify php/json/yaml indent size.',
                4,
            ],
            'inline'      => [
                null,
                InputOption::VALUE_OPTIONAL,
                'Specify php/json/yaml inline nest level.',
                4,
            ],
            'multiline'   => [
                null,
                InputOption::VALUE_NONE,
                'Specify php/yaml literal multiline.',
            ],
            'align'       => [
                null,
                InputOption::VALUE_NONE,
                'Specify php/json/yaml align key value.',
            ],
            'delimiter'   => [
                null,
                InputOption::VALUE_REQUIRED,
                'Specify sql/csv delimiter.',
            ],
            'disable'     => [
                'D',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Specify disabled schema object (enable comma separated value. e.g. --disable view,trigger)',
            ],
            'migration'   => [
                'm',
                InputOption::VALUE_OPTIONAL,
                'Specify migration directory.',
            ],
            'include'     => [
                'i',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Target tables pattern (enable comma separated value. e.g. --include table1,table2)',
            ],
            'exclude'     => [
                'e',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Except tables pattern (enable comma separated value. e.g. --exclude table1,table2)',
            ],
            'directory'   => [
                'd',
                InputOption::VALUE_OPTIONAL,
                'Specify separative directory name.',
                null,
            ],
            'format'      => [
                null,
                InputOption::VALUE_OPTIONAL,
                'Format output SQL (none, pretty, format. default pretty)',
                'pretty',
            ],
            'omit'        => [
                'o',
                InputOption::VALUE_REQUIRED,
                'Omit size for long SQL',
            ],
            'event'       => [
                'E',
                InputOption::VALUE_OPTIONAL,
                'Specify Event filepath',
            ],
            'config'      => [
                'C',
                InputOption::VALUE_OPTIONAL,
                'Specify Configuration filepath',
            ],
        ];
        return array_map(fn($name) => new InputOption($name, ...$options[$name]), $optnames);
    }

    protected function setInputOutput(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->logger = new Logger($input, $output);

        $this->config();
    }

    protected function event(Connection $conn)
    {
        $eventFile = $this->input->getOption('event');
        if (!strlen($eventFile)) {
            return;
        }
        if (!file_exists($eventFile)) {
            throw new \InvalidArgumentException("'$eventFile' is not exists.");
        }

        $allEvent = require $eventFile;
        if ($allEvent instanceof \Closure) {
            $allEvent = $allEvent($this, $conn);
        }

        $emanager = $conn->getEventManager();
        foreach ($allEvent as $name => $events) {
            foreach (is_array($events) ? $events : [$events] as $event) {
                if ($event instanceof \Closure) {
                    $event = new class($event) {
                        private $event;

                        public function __construct($event)
                        {
                            $this->event = $event;
                        }

                        public function __call($name, $arguments)
                        {
                            return ($this->event)(...$arguments);
                        }
                    };
                }
                $emanager->addEventListener($name, $event);
            }
        }
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
