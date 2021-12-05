<?php

namespace ryunosuke\DbMigration\Console\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Event\ConnectionEventArgs;
use Doctrine\DBAL\Schema\Table;
use ryunosuke\DbMigration\Exception\MigrationException;
use ryunosuke\DbMigration\MigrationTable;
use ryunosuke\DbMigration\Transporter;
use ryunosuke\DbMigration\Utility;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateCommand extends AbstractCommand
{
    /** @var Transporter */
    private $transporter;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('migrate')->setDescription('Migrate srcdsn to dstdsn.');
        $this->setDefinition([
            new InputArgument('srcdsn', InputArgument::REQUIRED, 'Specify source DSN.'),
            new InputArgument('dstdsn', InputArgument::REQUIRED, 'Specify destination DSN.'),
            new InputOption('migration', 'm', InputOption::VALUE_OPTIONAL, 'Specify migration directory.'),
            new InputOption('type', 't', InputOption::VALUE_OPTIONAL, 'Migration SQL type (ddl, dml. default both)'),
            new InputOption('noview', null, InputOption::VALUE_NONE, 'No migration View.'),
            new InputOption('include', 'i', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Target tables pattern (enable comma separated value)'),
            new InputOption('exclude', 'e', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Except tables pattern (enable comma separated value)'),
            new InputOption('where', 'w', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Where condition.'),
            new InputOption('ignore', 'g', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Ignore column for DML.'),
            new InputOption('no-insert', null, InputOption::VALUE_NONE, 'Not contains INSERT DML'),
            new InputOption('no-delete', null, InputOption::VALUE_NONE, 'Not contains DELETE DML'),
            new InputOption('no-update', null, InputOption::VALUE_NONE, 'Not contains UPDATE DML'),
            new InputOption('callback', null, InputOption::VALUE_OPTIONAL, 'Specify callback php files.'),
            new InputOption('csv-encoding', null, InputOption::VALUE_OPTIONAL, 'Specify CSV encoding.', 'SJIS-win'),
            new InputOption('table-directory', null, InputOption::VALUE_OPTIONAL, 'Specify separative directory name for tables.', null),
            new InputOption('view-directory', null, InputOption::VALUE_OPTIONAL, 'Specify separative directory name for views.', null),
            new InputOption('check', 'c', InputOption::VALUE_NONE, 'Check only (Dry run. force no-interaction)'),
            new InputOption('force', 'f', InputOption::VALUE_NONE, 'Force continue, ignore errors'),
            new InputOption('format', null, InputOption::VALUE_OPTIONAL, 'Format output SQL (none, pretty, format, highlight or compress. default pretty)', 'pretty'),
            new InputOption('omit', 'o', InputOption::VALUE_REQUIRED, 'Omit size for long SQL'),
            new InputOption('event', 'E', InputOption::VALUE_OPTIONAL, 'Specify Event filepath'),
            new InputOption('config', 'C', InputOption::VALUE_OPTIONAL, 'Specify Configuration filepath'),
        ]);
        $this->setHelp(<<<EOT
Migrate srcdsn to dstdsn.
 e.g. `dbmigration migrate mysql://srchost/dbname mysql://dsthost/dbname`
EOT
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setInputOutput($input, $output);

        $this->logger->trace('var_export', $this->input->getArguments(), true);
        $this->logger->trace('var_export', $this->input->getOptions(), true);

        // get target Connection
        $srcConn = DriverManager::getConnection($this->parseDsn($this->input->getArgument('srcdsn')));
        $dstConn = DriverManager::getConnection($this->parseDsn($this->input->getArgument('dstdsn')));

        $this->event($srcConn);

        $this->transporter = new Transporter($srcConn);
        $this->transporter->enableView(!$this->input->getOption('noview'));

        // migrate
        try {
            // pre migration
            $this->doCallback('pre-migration', $srcConn);

            // DDL
            $this->migrateDDL($srcConn, $dstConn);

            // DML
            $this->migrateDML($srcConn, $dstConn);

            // Data
            $this->migrateData($srcConn);

            // post migration
            $this->doCallback('post-migration', $srcConn);
        }
        catch (\Exception $e) {
            // post migration
            $this->doCallback('post-migration', $srcConn);

            throw $e;
        }

        return 0;
    }

    private function migrateDDL(Connection $srcConn, Connection $dstConn)
    {
        if (!in_array($this->input->getOption('type'), explode(',', ',ddl'))) {
            return;
        }

        $force = $this->input->getOption('force');

        $includes = (array) $this->input->getOption('include');
        $excludes = (array) $this->input->getOption('exclude');
        if ($this->input->getOption('migration')) {
            $excludes[] = '^' . basename($this->input->getOption('migration')) . '$';
        }

        $this->logger->log("-- <comment>diff DDL</comment>");

        // get ddl
        $sqls = $this->transporter->migrateDDL($dstConn, $includes, $excludes);
        if (!$sqls) {
            $this->logger->log("-- no diff schema.");
            return;
        }

        $execed = false;
        foreach ($sqls as $sql) {
            // display sql(formatted)
            $this->logger->log([$this, 'formatSql'], $sql);

            // exec if noconfirm or confirm answer is "y"
            if ($this->confirm('exec this query?', true)) {
                try {
                    $srcConn->executeStatement($sql);
                    $execed = true;
                }
                catch (\Exception $e) {
                    $this->logger->log('/* <error>' . $e->getMessage() . '</error> */');
                    if (!$force && $this->confirm('exit?', true)) {
                        throw $e;
                    }
                }
            }
        }

        // reconnect if exec ddl. for recreate schema (Migrator::getSchema)
        if ($execed) {
            Utility::getSchema($srcConn, true);
        }
    }

    private function migrateDML(Connection $srcConn, Connection $dstConn)
    {
        if (!in_array($this->input->getOption('type'), explode(',', ',dml'))) {
            return;
        }

        $force = $this->input->getOption('force');

        $includes = (array) $this->input->getOption('include');
        $excludes = (array) $this->input->getOption('exclude');
        if ($this->input->getOption('migration')) {
            $excludes[] = '^' . basename($this->input->getOption('migration')) . '$';
        }
        $wheres = (array) $this->input->getOption('where') ?: [];
        $ignores = (array) $this->input->getOption('ignore') ?: [];

        $dmltypes = [
            'insert' => !$this->input->getOption('no-insert'),
            'delete' => !$this->input->getOption('no-delete'),
            'update' => !$this->input->getOption('no-update'),
        ];

        $this->logger->log("-- <comment>diff DML</comment>");

        $mapname = function (Table $table) { return $table->getName(); };
        $srctables = array_flip(array_map($mapname, Utility::getSchema($srcConn)->getTables()));
        $dsttables = Utility::getSchema($dstConn)->getTables();
        $maxlength = $dsttables ? max(array_map('strlen', array_map($mapname, $dsttables))) + 1 : 0;
        $dmlflag = false;
        foreach ($dsttables as $table) {
            $tablename = $table->getName();
            $title = sprintf("<info>%-{$maxlength}s</info>", $tablename);

            $filtered = Utility::filterTable($tablename, $includes, $excludes);
            if ($filtered === 1) {
                $this->logger->info("-- $title is skipped by include option.");
                continue;
            }
            elseif ($filtered === 2) {
                $this->logger->info("-- $title is skipped by exclude option.");
                continue;
            }

            // skip to not exists tables
            if (!isset($srctables[$tablename])) {
                $this->logger->info("-- $title is skipped by not exists.");
                continue;
            }

            // skip no has record
            if (!$dstConn->fetchOne("select COUNT(*) from $tablename")) {
                $this->logger->info("-- $title is skipped by no record.");
                continue;
            }

            // get dml
            $sqls = null;
            try {
                $sqls = $this->transporter->migrateDML($dstConn, $tablename, $wheres, $ignores, $dmltypes);
            }
            catch (MigrationException $ex) {
                $this->logger->info("-- $title is skipped by " . $ex->getMessage());
                continue;
            }

            if (!$sqls) {
                $this->logger->info("-- $title is skipped by no diff.");
                continue;
            }

            $this->logger->log("-- $title has diff:");

            // display sql(max 1000)
            $show_sqls = array_slice($sqls, 0, 1000);
            $rest_sqls = array_slice($sqls, 1000);
            foreach ($show_sqls as $sql) {
                $this->logger->log([$this, 'formatSql'], $sql);
            }
            if ($rest_sqls && $this->confirm('display more? (total query count is ' . count($sqls) . ')', true)) {
                foreach ($rest_sqls as $sql) {
                    $this->logger->log([$this, 'formatSql'], $sql);
                }
            }

            // exec if noconfirm or confirm answer is "y"
            $dmlflag = true;
            if ($this->confirm('exec this query?', true)) {
                $srcConn->beginTransaction();

                try {
                    foreach ($sqls as $sql) {
                        $srcConn->executeStatement($sql);
                    }
                    $srcConn->commit();
                }
                catch (\Exception $e) {
                    $srcConn->rollBack();

                    $this->logger->log('/* <error>' . $e->getMessage() . '</error> */');
                    if (!$force && $this->confirm('exit?', true)) {
                        throw $e;
                    }
                }
            }
        }
        if (!$dmlflag) {
            $this->logger->log("-- no diff table.");
        }
    }

    private function migrateData(Connection $dstConn)
    {
        $migration = $this->input->getOption('migration');
        if (!$migration) {
            return;
        }

        $migtable = basename($migration);
        $migrationTable = new MigrationTable($dstConn, $migtable);

        $force = $this->input->getOption('force');

        $this->logger->log("-- <comment>diff Data</comment>");

        if (!$migrationTable->exists()) {
            if ($this->confirm('create migration table? (' . $migtable . ')', true)) {
                if ($migrationTable->create()) {
                    $this->logger->log("-- <info>" . $migtable . "</info> <comment>is created.</comment>");
                }
            }
        }

        $new = $migrationTable->glob($migration);
        $old = $migrationTable->fetch();

        $upgrades = array_diff_key($new, $old);
        foreach ($upgrades as $version => $sql) {
            $this->logger->log($sql);

            if ($this->input->getOption('check')) {
                continue;
            }

            $answer = $this->choice('exec this query?', ['y', 'n', 'p'], 0);
            if ($answer === 2) {
                $migrationTable->attach($version);
                continue;
            }
            if ($answer === 1) {
                continue;
            }
            if ($answer === 0) {
                $dstConn->beginTransaction();

                try {
                    $affected = $migrationTable->apply($version, $sql);
                    $this->logger->log("-- <comment>Affected rows: $affected</comment>");

                    $dstConn->commit();
                }
                catch (\Exception $e) {
                    $dstConn->rollBack();

                    $this->logger->log('/* <error>' . $e->getMessage() . '</error> */');
                    if (!$force && $this->confirm('exit?', true)) {
                        throw $e;
                    }
                }
            }
        }

        if (!$upgrades) {
            $this->logger->log("-- no diff data.");
        }

        $degrades = array_diff_key($old, $new);
        if ($degrades) {
            foreach ($degrades as $applied => $datetime) {
                $this->logger->log("-- <info>[$datetime] $applied</info>");
            }
            if ($this->confirm('probably down migration. delete applying these?', true)) {
                $migrationTable->detach(array_keys($degrades));
            }
        }
    }

    private function doCallback($timing, Connection $conn)
    {
        $conn->getEventManager()->dispatchEvent($timing, new ConnectionEventArgs($conn));

        // for compatible
        if (!file_exists($this->input->getOption('callback'))) {
            return;
        }
        @trigger_error('--callback is deprecated. please use --event', E_USER_DEPRECATED);
        $callbacks = include $this->input->getOption('callback');
        $callbacks[$timing]($conn);
    }

    protected function confirm($message, $default = true)
    {
        if ($this->input->getOption('check')) {
            return false;
        }

        return parent::confirm($message, $default);
    }
}
