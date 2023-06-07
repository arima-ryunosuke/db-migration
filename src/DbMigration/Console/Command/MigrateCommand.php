<?php

namespace ryunosuke\DbMigration\Console\Command;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Event\ConnectionEventArgs;
use ryunosuke\DbMigration\Connection;
use ryunosuke\DbMigration\FileType\AbstractFile;
use ryunosuke\DbMigration\MigrationTable;
use ryunosuke\DbMigration\Transporter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function ryunosuke\DbMigration\iterator_join;
use function ryunosuke\DbMigration\iterator_split;

class MigrateCommand extends AbstractCommand
{
    /** @var Transporter */
    private $transporter;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('migrate')->setDescription('Migrate DDL,DML from files.');
        $this->setDefinition([
            new InputArgument('dsn', InputArgument::OPTIONAL, 'Specify target DSN.'),
            new InputArgument('files', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Specify database files. First argument is meaned schema.'),
            ...$this->getCommonOptions([
                'disable-constraint',
                'directory',
                'migration',
                'transaction',
                'type',
                'dml-type',
                'ignore',
                'bulk-insert',
                'inline',
                'indent',
                'delimiter',
                'check',
                'force',
                'yield', // It's a bit meaningless
                'format',
                'omit',
                'event',
                'config',
            ]),
        ]);
        $this->setHelp(<<<EOT
            Migrate dsn from files.
             e.g. `dbmigration migrate mysql://srchost/dbname table1.yml data.yml`
            EOT
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setInputOutput($input, $output);

        $this->logger->trace(fn($v) => $this->dump($v), $this->input->getArguments(), true);
        $this->logger->trace(fn($v) => $this->dump($v), $this->input->getOptions(), true);

        // get target Connection
        /** @var Connection $conn */
        $conn = DriverManager::getConnection($this->parseDsn($this->input->getArgument('dsn')));

        if (!$conn->lockGlobal("migrating." . $conn->getDatabase(), 0)) {
            $this->logger->log("-- <comment>migration did not execute. because executing by other connection</comment>");
        }

        $conn->disableConstraint($this->input->getOption('disable-constraint'));

        $this->event($conn);

        $transporter = new Transporter($conn);
        $transporter->setDirectory($this->input->getOption('directory'));
        $transporter->setBulkSize($this->input->getParameterOption('--bulk-insert', 0) ?? PHP_INT_MAX);
        $transporter->setDataDescriptionOptions([
            'inline'    => $this->input->getOption('inline'),
            'indent'    => $this->input->getOption('indent'),
            'delimiter' => $this->input->getOption('delimiter'),
        ]);
        $this->transporter = $transporter;

        $files = $this->normalizeFile($this->input->getArgument('files'));

        // migrate
        try {
            // pre migration
            $conn->getEventManager()->dispatchEvent('pre-migration', new ConnectionEventArgs($conn));

            // DDL
            $this->migrateDDL($conn, $files);

            // DML
            $this->migrateDML($conn, $files);

            // Data
            $this->migrateData($conn);
        }
        finally {
            // post migration
            $conn->getEventManager()->dispatchEvent('post-migration', new ConnectionEventArgs($conn));
            $conn->close();
        }

        return 0;
    }

    private function migrateDDL(Connection $conn, &$files)
    {
        if (!$files || !in_array($this->input->getOption('type'), explode(',', ',ddl'))) {
            return;
        }

        $this->logger->log("-- <comment>diff DDL</comment>");

        $force = $this->input->getOption('force');

        $excludes = $this->input->getOption('migration') ? ['^' . basename($this->input->getOption('migration')) . '$'] : [];

        // get ddl
        $sqls = $this->transporter->migrateDDL(array_shift($files), $excludes);
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
                    $conn->executeStatement($sql);
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
            $this->transporter->refresh();
        }
    }

    private function migrateDML(Connection $conn, &$files)
    {
        if (!$files || !in_array($this->input->getOption('type'), explode(',', ',dml'))) {
            return;
        }

        $this->logger->log("-- <comment>diff DML</comment>");

        $dmlflag = $this->transact($conn, function () use ($conn, $files) {
            $dmltypes = array_fill_keys($this->splitByComma($this->input->getOption('dml-type')), true);
            $ignores  = $this->splitByComma($this->input->getOption('ignore'));

            $dmlflag = false;
            foreach ($files as $filename) {
                $sqls = $this->transporter->migrateDML($filename, $dmltypes, $ignores);

                // display sql(max 1000)
                [$show_sqls, $rest_sqls] = iterator_split($sqls, [1000]);
                if (!$show_sqls) {
                    $this->logger->info("-- $filename is skipped by no diff.");
                    continue;
                }

                $this->logger->log("-- $filename has diff:");

                foreach ($show_sqls as $sql) {
                    $this->logger->log([$this, 'formatSql'], $sql);
                }
                if ($rest_sqls->valid() && $this->confirm('display more?', true)) {
                    foreach ($rest_sqls as $sql) {
                        $show_sqls[] = $sql;
                        $this->logger->log([$this, 'formatSql'], $sql);
                    }
                }

                // exec if noconfirm or confirm answer is "y"
                $dmlflag = true;
                if ($this->confirm('exec this query?', true)) {
                    $this->transact($conn, function () use ($conn, $show_sqls, $rest_sqls) {
                        foreach (iterator_join([$show_sqls, $rest_sqls]) as $sql) {
                            $conn->executeStatement($sql);
                        }
                    }, function (\Exception $ex) {
                        $this->logger->log('/* <error>' . $ex->getMessage() . '</error> */');
                        if (!$this->input->getOption('force') && $this->confirm('exit?', true)) {
                            throw $ex;
                        }
                    });
                }
            }
            return $dmlflag;
        });

        if (!$dmlflag) {
            $this->logger->log("-- no diff table.");
        }
    }

    private function migrateData(Connection $conn)
    {
        $migration = $this->input->getOption('migration');
        if (!$migration) {
            return;
        }

        $this->logger->log("-- <comment>diff Data</comment>");

        $migtable       = basename($migration);
        $migrationTable = new MigrationTable($conn, $migtable);

        if (!$migrationTable->exists()) {
            if ($this->confirm('create migration table? (' . $migtable . ')', true)) {
                if ($migrationTable->create()) {
                    $this->logger->log("-- <info>" . $migtable . "</info> <comment>is created.</comment>");
                }
            }
        }
        if ($migrationTable->diff()) {
            if ($this->confirm('alter migration table? (' . $migtable . ')', true)) {
                if ($migrationTable->alter()) {
                    $this->logger->log("-- <info>" . $migtable . "</info> <comment>is altered.</comment>");
                }
            }
        }

        $new      = $migrationTable->glob($migration);
        $old      = $migrationTable->fetch();
        $upgrades = array_diff_key($new, $old);

        $this->transact($conn, function () use ($conn, $migrationTable, $migration, $new, $old, $upgrades) {
            foreach ($upgrades as $version => $file) {
                /** @var AbstractFile $file */
                $this->logger->log((string) $file);

                if ($this->input->getOption('check')) {
                    continue;
                }

                $answer = $this->choice('exec this file?', ['y', 'n', 'p'], 0);
                if ($answer === 2) {
                    $migrationTable->attach($version);
                    continue;
                }
                if ($answer === 1) {
                    continue;
                }
                if ($answer === 0) {
                    $this->transact($conn, function () use ($migrationTable, $version, $file) {
                        $affected = $migrationTable->apply($version, $file->readMigration());
                        $this->logger->log("-- <comment>Affected rows: $affected</comment>");
                    }, function (\Exception $ex) {
                        $this->logger->log('/* <error>' . $ex->getMessage() . '</error> */');
                        if (!$this->input->getOption('force') && $this->confirm('exit?', true)) {
                            throw $ex;
                        }
                    });
                }
            }
        });

        if (!$upgrades) {
            $this->logger->log("-- no diff data.");
        }

        $degrades = array_diff_key($old, $new);
        if ($degrades) {
            foreach ($degrades as $applied => $version) {
                $this->logger->log("-- <info>[{$version['apply_at']}] $applied</info>");
            }
            if ($this->confirm('probably down migration. delete applying these?', true)) {
                $migrationTable->detach(array_keys($degrades));
            }
        }
    }

    protected function confirm($message, $default = true)
    {
        if ($this->input->getOption('check')) {
            return false;
        }

        return parent::confirm($message, $default);
    }
}
