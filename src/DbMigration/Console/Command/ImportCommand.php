<?php

namespace ryunosuke\DbMigration\Console\Command;

use Doctrine\DBAL\DriverManager;
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
            new InputArgument('dstdsn', InputArgument::REQUIRED, 'Specify destination DSN (if not exists create database).'),
            new InputArgument('files', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Specify database files. First argument is meaned schema.'),
            ...$this->getCommonOptions([
                'directory',
                'migration',
                'bulk-insert',
                'inline',
                'indent',
                'delimiter',
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

        $this->logger->trace('var_export', $this->input->getArguments(), true);
        $this->logger->trace('var_export', $this->input->getOptions(), true);

        $files = $this->normalizeFile($this->input->getArgument('files'));

        // option
        $includes = (array) $this->input->getOption('include');
        $excludes = (array) $this->input->getOption('exclude');

        // get target Connection
        $dstdsn = $this->input->getArgument('dstdsn');
        $params = $this->parseDsn($dstdsn);
        $dbname = $params['dbname'] ?: 'tmp_' . md5(implode('', array_map('filemtime', $files)));
        if (!$this->confirm("recreate <error>$dstdsn</error> really?", true)) {
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

        $params['dbname'] = $dbname;
        $conn = DriverManager::getConnection($params);

        $this->event($conn);

        $transporter = new Transporter($conn);
        $transporter->setDirectory($this->input->getOption('directory'));
        $transporter->setBulkMode($this->input->getOption('bulk-insert'));
        $transporter->setDataDescriptionOptions([
            'inline'    => $this->input->getOption('inline'),
            'indent'    => $this->input->getOption('indent'),
            'delimiter' => $this->input->getOption('delimiter'),
        ]);

        // importDDL
        $ddlfile = array_shift($files);
        $this->logger->info("-- <info>importDDL</info> $ddlfile");
        $sqls = $transporter->importDDL($ddlfile, $includes, $excludes);
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

        // importDML
        foreach ($files as $filename) {
            $this->logger->info("-- <info>importDML</info> $filename");
            $sqls = $transporter->importDML($filename);
            $conn->beginTransaction();
            try {
                foreach ($sqls as $sql) {
                    $this->logger->debug([$this, 'formatSql'], $sql);

                    $conn->executeStatement($sql);
                }
                $conn->commit();
            }
            catch (\Exception $ex) {
                $conn->rollBack();
                $this->logger->log('/* <error>' . $ex->getMessage() . '</error> */');
                if ($this->confirm('exit?', true)) {
                    throw $ex;
                }
            }
        }

        // create migration table and attach all
        $migration = $this->input->getOption('migration');
        if ($migration) {
            $this->logger->info("-- <info>attachMigration</info> $migration");
            $migtable = basename($migration);

            $migrationTable = new MigrationTable($conn, $migtable);
            $migrationTable->create();

            $migfiles = $migrationTable->glob($migration);
            foreach ($migfiles as $version => $sql) {
                $this->logger->debug($sql);
                $migrationTable->attach($version);
            }
        }

        return 0;
    }
}
