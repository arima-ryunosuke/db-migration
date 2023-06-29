<?php

namespace ryunosuke\DbMigration\Console\Command;

use Doctrine\DBAL\DriverManager;
use ryunosuke\DbMigration\Connection;
use ryunosuke\DbMigration\Transporter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ExportCommand extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('export')->setDescription('Export to DDL,DML files.');
        $this->setDefinition([
            new InputArgument('dsn', InputArgument::OPTIONAL, 'Specify target DSN.'),
            new InputArgument('files', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Specify database files. First argument is meaned schema'),
            ...$this->getCommonOptions([
                'maintain-type',
                'directory',
                'migration',
                'transaction',
                'disable',
                'include',
                'exclude',
                'where',
                'ignore',
                'inline',
                'indent',
                'multiline',
                'align',
                'delimiter',
                'event',
                'config',
            ]),
        ]);
        $this->setHelp(<<<EOT
            Export to DDL,DML files based on extension.
             e.g. `dbmigration export mysql://user:pass@localhost/dbname schema.yml table1.yml table2.yml`
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

        // option
        $includes = $this->splitByComma($this->input->getOption('include'));
        $excludes = $this->splitByComma($this->input->getOption('exclude'));
        if ($this->input->getOption('migration')) {
            $excludes[] = '^' . basename($this->input->getOption('migration')) . '$';
        }

        // get target Connection
        /** @var Connection $conn */
        $params = $this->parseDsn($this->input->getArgument('dsn'));
        $conn   = DriverManager::getConnection($params);

        $conn->maintainType($this->input->getOption('maintain-type') ?? false); // set default true or delete in future scope

        $this->event($conn);

        // export sql files from argument
        $transporter = new Transporter($conn);
        $transporter->setDisabled(array_fill_keys((array) $this->input->getOption('disable'), true));
        $transporter->setDirectory($this->input->getOption('directory'));
        $transporter->setDataDescriptionOptions([
            'inline'    => $this->input->getOption('inline'),
            'indent'    => $this->input->getOption('indent'),
            'multiline' => $this->input->getOption('multiline'),
            'align'     => $this->input->getOption('align'),
            'delimiter' => $this->input->getOption('delimiter'),
        ]);

        $ddl = $transporter->exportDDL(array_shift($files), $includes, $excludes);
        $this->logger->info($ddl);

        $this->transact($conn, function () use ($conn, $transporter, $files) {
            foreach ($transporter->globTable($files) as $filename) {
                $this->transact($conn, function () use ($transporter, $filename) {
                    $wheres  = (array) $this->input->getOption('where') ?: [];
                    $ignores = (array) $this->input->getOption('ignore') ?: [];

                    $dmls = $transporter->exportDML($filename, $wheres, $ignores);
                    foreach ($dmls as $dml) {
                        $this->logger->info(fn($v) => $this->dump($v), $dml, true);
                    }
                });
            }
        });

        return 0;
    }
}
