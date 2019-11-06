<?php

namespace ryunosuke\DbMigration\Console\Command;

use Doctrine\DBAL\DriverManager;
use ryunosuke\DbMigration\Exception\CancelException;
use ryunosuke\DbMigration\MigrationTable;
use ryunosuke\DbMigration\Transporter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
            new InputOption('migration', 'm', InputOption::VALUE_OPTIONAL, 'Specify migration directory.'),
            new InputOption('include', 'i', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Target tables pattern (enable comma separated value)'),
            new InputOption('exclude', 'e', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Except tables pattern (enable comma separated value)'),
            new InputOption('table-directory', null, InputOption::VALUE_OPTIONAL, 'Specify separative directory name for tables.', null),
            new InputOption('view-directory', null, InputOption::VALUE_OPTIONAL, 'Specify separative directory name for views.', null),
            new InputOption('csv-encoding', null, InputOption::VALUE_OPTIONAL, 'Specify CSV encoding.', 'SJIS-win'),
            new InputOption('bulk-insert', null, InputOption::VALUE_NONE, 'Enable bulk insert'),
            new InputOption('format', null, InputOption::VALUE_OPTIONAL, 'Format output SQL (none, pretty, format, highlight or compress. default pretty)', 'pretty'),
            new InputOption('omit', 'o', InputOption::VALUE_REQUIRED, 'Omit size for long SQL'),
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
        $dbname = $params['dbname'] ?? md5(implode('', array_map('filemtime', $files)));
        unset($params['dbname']);
        DriverManager::getConnection($params)->getSchemaManager()->dropAndCreateDatabase($dbname);
        $params['dbname'] = $dbname;
        $conn = DriverManager::getConnection($params);

        if (!$this->confirm("recreate <error>$dstdsn</error> really?", true)) {
            throw new CancelException('canceled.');
        }

        $transporter = new Transporter($conn);
        $transporter->setEncoding('csv', $this->input->getOption('csv-encoding'));
        $transporter->setDirectory('table', $this->input->getOption('table-directory'));
        $transporter->setDirectory('view', $this->input->getOption('view-directory'));
        $transporter->setBulkMode($this->input->getOption('bulk-insert'));

        // importDDL
        $ddlfile = array_shift($files);
        $this->logger->info("-- <info>importDDL</info> $ddlfile");
        $sqls = $transporter->importDDL($ddlfile, $includes, $excludes);
        foreach ($sqls as $sql) {
            $this->logger->debug([$this, 'formatSql'], $sql);
        }

        // importDML
        foreach ($files as $filename) {
            $this->logger->info("-- <info>importDML</info> $filename");
            $conn->beginTransaction();
            try {
                $rows = $transporter->importDML($filename);
                foreach ($rows as $row) {
                    $this->logger->debug('var_export', $row, true);
                }
                $conn->commit();
            }
            catch (\Exception $ex) {
                $conn->rollBack();
                throw $ex;
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
    }
}
