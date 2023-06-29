<?php

namespace ryunosuke\DbMigration\Console\Command;

use Doctrine\DBAL\DriverManager;
use ryunosuke\DbMigration\Connection;
use ryunosuke\DbMigration\Exception\CancelException;
use ryunosuke\DbMigration\MigrationTable;
use ryunosuke\DbMigration\Transporter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ImportCommand extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('import')->setDescription('Import from DDL,DML files.');
        $this->setDefinition([
            new InputArgument('dsn', InputArgument::OPTIONAL, 'Specify target DSN (if not exists create database).'),
            new InputArgument('files', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Specify database files. First argument is meaned schema.'),
            ...$this->getCommonOptions([
                'disable-constraint',
                'maintain-type',
                'directory',
                'migration',
                'transaction',
                'bulk-insert',
                'inline',
                'indent',
                'delimiter',
                'yield',
                'format',
                'omit',
                'event',
                'config',
            ]),
        ]);
        $this->setHelp(<<<EOT
            Import from DDL,DML files based on extension.
             e.g. `dbmigration import mysql://user:pass@localhost/dbname schema.yml table1.yml table2.yml`
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

        $files = $this->normalizeFile($this->input->getArgument('files'));

        // get target Connection
        $dsn    = $this->input->getArgument('dsn');
        $params = $this->parseDsn($dsn);
        $dbname = $params['dbname'];
        if (!$this->confirm("recreate <error>$dsn</error> really?", true)) {
            throw new CancelException('canceled.');
        }

        unset($params['dbname']);
        $smanager = DriverManager::getConnection($params)->createSchemaManager();
        try {
            $smanager->dropDatabase($dbname);
            $this->logger->info("-- <info>drop database</info> $dbname");
        }
        catch (\Throwable $t) {
            $this->logger->info("-- <info>no drop database</info> $dbname");
        }
        $smanager->createDatabase($dbname);
        $this->logger->info("-- <info>create database</info> $dbname");

        /** @var Connection $conn */
        $params['dbname'] = $dbname;
        $conn             = DriverManager::getConnection($params);

        $conn->disableConstraint($this->input->getOption('disable-constraint'));
        $conn->maintainType($this->input->getOption('maintain-type') ?? false); // set default true or delete in future scope

        $this->event($conn);

        $transporter = new Transporter($conn);
        $transporter->setDirectory($this->input->getOption('directory'));
        $transporter->setBulkSize($this->input->getParameterOption('--bulk-insert', 0) ?? PHP_INT_MAX);
        $transporter->setDataDescriptionOptions([
            'inline'    => $this->input->getOption('inline'),
            'indent'    => $this->input->getOption('indent'),
            'delimiter' => $this->input->getOption('delimiter'),
            'yield'     => $this->input->getOption('yield'),
        ]);

        // importDDL
        $ddlfile = array_shift($files);
        $this->logger->info("-- <info>importDDL</info> $ddlfile");
        $sqls = $transporter->importDDL($ddlfile);
        foreach ($sqls as $sql) {
            $this->logger->debug([$this, 'formatSql'], $sql);

            try {
                $conn->executeStatement($sql);
            }
            catch (\Exception $ex) {
                $this->logger->log('/* <error>' . $ex->getMessage() . '</error> */');
                if ($this->confirm('exit?', true)) {
                    throw $ex;
                }
            }
        }

        $transporter->refresh();

        $this->transact($conn, function () use ($conn, $transporter, $files) {
            // importDML
            foreach ($files as $filename) {
                $this->logger->info("-- <info>importDML</info> $filename");
                $sqls = $transporter->importDML($filename);

                $this->transact($conn, function () use ($conn, $sqls) {
                    foreach ($sqls as $sql) {
                        $this->logger->debug([$this, 'formatSql'], $sql);

                        $conn->executeStatement($sql);
                    }
                }, function (\Exception $ex) {
                    $this->logger->log('/* <error>' . $ex->getMessage() . '</error> */');
                    if ($this->confirm('exit?', true)) {
                        throw $ex;
                    }
                });
            }
        });


        // create migration table and attach all
        $migration = $this->input->getOption('migration');
        if ($migration) {
            $this->logger->info("-- <info>attachMigration</info> $migration");
            $migtable = basename($migration);

            $migrationTable = new MigrationTable($conn, $migtable);
            $migrationTable->create();

            $migfiles = $migrationTable->glob($migration);
            foreach ($migfiles as $version => $sql) {
                $this->logger->debug((string) $sql);
                $migrationTable->attach($version);
            }
        }

        return 0;
    }
}
