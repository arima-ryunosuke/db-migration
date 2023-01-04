<?php

namespace ryunosuke\DbMigration\Console\Command;

use Doctrine\DBAL\DriverManager;
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
            new InputArgument('srcdsn', InputArgument::REQUIRED, 'Specify source DSN.'),
            new InputArgument('files', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Specify database files. First argument is meaned schema'),
            ...$this->getCommonOptions([
                'directory',
                'migration',
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

        $this->logger->trace('var_export', $this->input->getArguments(), true);
        $this->logger->trace('var_export', $this->input->getOptions(), true);

        $files = $this->normalizeFile($this->input->getArgument('files'));

        // option
        $includes = (array) $this->input->getOption('include');
        $excludes = (array) $this->input->getOption('exclude');
        if ($this->input->getOption('migration')) {
            $excludes[] = '^' . basename($this->input->getOption('migration')) . '$';
        }
        $wheres = (array) $this->input->getOption('where') ?: [];
        $ignores = (array) $this->input->getOption('ignore') ?: [];

        // get target Connection
        $params = $this->parseDsn($this->input->getArgument('srcdsn'));
        $conn = DriverManager::getConnection($params);

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
        foreach ($files as $filename) {
            $dml = $transporter->exportDML($filename, $wheres, $ignores);
            $this->logger->info($dml);
        }

        return 0;
    }
}
