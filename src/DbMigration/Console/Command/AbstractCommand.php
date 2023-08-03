<?php

namespace ryunosuke\DbMigration\Console\Command;

use Doctrine\DBAL\Tools\DsnParser;
use ryunosuke\DbMigration\Connection;
use ryunosuke\DbMigration\Console\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use function ryunosuke\DbMigration\sql_format;
use function ryunosuke\DbMigration\var_pretty;

abstract class AbstractCommand extends Command
{
    public const SCHEME_DRIVERS = [
        'mysql' => 'pdo_mysql', // for compatible
    ];

    /** @var InputInterface */
    protected $input;

    /** @var OutputInterface */
    protected $output;

    /** @var Logger */
    protected $logger;

    protected function getCommonOptions($optnames)
    {
        $options = [
            'type'               => [
                't',
                InputOption::VALUE_OPTIONAL,
                'Migration SQL type (ddl, dml. default both)',
            ],
            'dml-type'           => [
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Specify dml type (enable comma separated value. e.g. --dml-type insert,update)',
                ['insert', 'update', 'delete'],
            ],
            'check'              => [
                'c',
                InputOption::VALUE_NONE,
                'Check only (Dry run. force no-interaction)',
            ],
            'force'              => [
                'f',
                InputOption::VALUE_NONE,
                'Force continue, ignore errors',
            ],
            'disable-constraint' => [
                null,
                InputOption::VALUE_NONE,
                'Disable constraint (e.g. foreign key, unique, etc)',
            ],
            'maintain-type'      => [
                null,
                InputOption::VALUE_NEGATABLE,
                'Maintain type as much as possible.',
            ],
            'transaction'        => [
                'T',
                InputOption::VALUE_OPTIONAL,
                'Specify transaction nest level (0 is not transaction, 1 is only top level, 2 is only per-table)',
                1,
            ],
            'bulk-insert'        => [
                null,
                InputOption::VALUE_OPTIONAL,
                'Specify bulk insert chunk size',
                null,
            ],
            'where'              => [
                'w',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Where condition.',
            ],
            'ignore'             => [
                'g',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Ignore column.',
            ],
            'indent'             => [
                null,
                InputOption::VALUE_OPTIONAL,
                'Specify php/json/yaml indent size.',
                4,
            ],
            'inline'             => [
                null,
                InputOption::VALUE_OPTIONAL,
                'Specify php/json/yaml inline nest level.',
                4,
            ],
            'multiline'          => [
                null,
                InputOption::VALUE_NONE,
                'Specify php/yaml literal multiline.',
            ],
            'align'              => [
                null,
                InputOption::VALUE_NONE,
                'Specify php/json/yaml align key value.',
            ],
            'delimiter'          => [
                null,
                InputOption::VALUE_REQUIRED,
                'Specify sql/csv delimiter.',
            ],
            'yield'              => [
                null,
                InputOption::VALUE_NONE,
                'Specify sql/json/yaml generator mode.',
            ],
            'disable'            => [
                'D',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Specify disabled schema object (enable comma separated value. e.g. --disable view,trigger)',
            ],
            'migration'          => [
                'm',
                InputOption::VALUE_OPTIONAL,
                'Specify migration directory.',
            ],
            'include'            => [
                'i',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Target tables pattern (enable comma separated value. e.g. --include table1,table2)',
            ],
            'exclude'            => [
                'e',
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Except tables pattern (enable comma separated value. e.g. --exclude table1,table2)',
            ],
            'directory'          => [
                'd',
                InputOption::VALUE_OPTIONAL,
                'Specify separative directory name.',
                null,
            ],
            'format'             => [
                null,
                InputOption::VALUE_OPTIONAL,
                'Format output SQL (none, pretty, format. default pretty)',
                'pretty',
            ],
            'omit'               => [
                'o',
                InputOption::VALUE_REQUIRED,
                'Omit size for long SQL',
            ],
            'event'              => [
                'E',
                InputOption::VALUE_OPTIONAL,
                'Specify Event filepath',
            ],
            'config'             => [
                'C',
                InputOption::VALUE_OPTIONAL,
                'Specify Configuration filepath',
            ],
        ];
        return array_map(fn($name) => new InputOption($name, ...$options[$name]), $optnames);
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);

        $this->config($input, $output);
    }

    protected function setInputOutput(InputInterface $input, OutputInterface $output)
    {
        $this->input  = $input;
        $this->output = $output;
        $this->logger = new Logger($input, $output);
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

        // force connect. postConnect is not completed in time because of automatic connection with getDatabasePlatformVersion.
        $conn->connect();
    }

    protected function config(InputInterface $input, OutputInterface $output)
    {
        $configFile = $input->getOption('config');
        if (!strlen($configFile)) {
            return;
        }
        if (!file_exists($configFile)) {
            throw new \InvalidArgumentException("'$configFile' is not exists.");
        }

        $allConfig = require $configFile;
        $config    = ($allConfig[$this->getName()] ?? []) + ($allConfig['default'] ?? []);
        foreach ($config as $name => $value) {
            $definition = $this->getDefinition();
            $name = ctype_digit("$name") ? (int) $name : $name;
            if ($definition->hasArgument($name)) {
                $definition->getArgument($name)->setDefault($value);
            }
            elseif ($definition->hasOption($name)) {
                $definition->getOption($name)->setDefault($value);
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
        $question  = new Question("<question>{$message} [{$selection}]:</question>", $default);
        $answer    = $this->getHelper('question')->ask($this->input, $this->output, $question);

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
        $parser = new DsnParser(self::SCHEME_DRIVERS);
        $params = $parser->parse($dsn);

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

        return $params + ['wrapperClass' => Connection::class];
    }

    protected function normalizeFile($files)
    {
        $result = [];
        foreach ($files as $file) {
            $filePath = strlen($file) ? realpath($file) : $file;

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

    protected function splitByComma($values)
    {
        $result = [];
        foreach ((array) $values as $value) {
            $result = array_merge($result, array_filter(array_map('trim', explode(',', $value)), 'strlen'));
        }
        return $result;
    }

    protected function transact(Connection $conn, callable $try, ?callable $catch = null)
    {
        $targetLevel = (int) $this->input->getOption('transaction');

        $catch ??= function ($t) { throw $t; };

        if ($conn->beginTransaction($targetLevel)) {
            $this->logger->info("-- <info>begin transaction</info>");
        }
        try {
            $return = $try();
            if ($conn->commit($targetLevel)) {
                $this->logger->info("-- <info>commit transaction</info>");
            }
            return $return;
        }
        catch (\Throwable $t) {
            if ($conn->rollBack($targetLevel)) {
                $this->logger->info("-- <info>rollback transaction</info>");
            }

            $catch($t);
            $this->logger->debug("-- uncatch exception: ", $t);
        }
    }

    public function formatSql($sql)
    {
        $sql .= ';';

        $omitlength = intval($this->input->getOption('omit')) ?: 65535;
        if (mb_strwidth($sql) > $omitlength) {
            $sql = mb_strimwidth($sql, 0, $omitlength, "\n...(omitted)");
        }

        $opt = [
            'indent'    => str_repeat(' ', $this->input->getOption('indent')),
            'nestlevel' => 1,
            'case'      => null,
        ];
        switch ($this->input->getOption('format')) {
            default:
                return $sql;
            case 'pretty':
                return sql_format($sql, $opt + ['highlight' => $this->output->isDecorated() ? 'cli' : false]);
            case 'format':
                return sql_format($sql, $opt + ['highlight' => false]);
        }
    }

    public function dump($value)
    {
        return var_pretty($value, [
            'indent'  => $this->input->getOption('indent'),
            'context' => $this->output->isDecorated() ? 'cli' : 'plain',
            'return'  => true,
            'trace'   => false,
        ]);
    }
}
