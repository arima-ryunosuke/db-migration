<?php

namespace ryunosuke\DbMigration\Console\Command;

use Doctrine\DBAL\DriverManager;
use ryunosuke\DbMigration\Transporter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
            new InputOption('migration', 'm', InputOption::VALUE_OPTIONAL, 'Specify migration directory.'),
            new InputOption('include', 'i', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Target tables pattern (enable comma separated value)'),
            new InputOption('exclude', 'e', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Except tables pattern (enable comma separated value)'),
            new InputOption('where', 'w', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Where condition.'),
            new InputOption('ignore', 'g', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Ignore column.'),
            new InputOption('table-directory', null, InputOption::VALUE_OPTIONAL, 'Specify separative directory name for tables.', null),
            new InputOption('view-directory', null, InputOption::VALUE_OPTIONAL, 'Specify separative directory name for views.', null),
            new InputOption('csv-encoding', null, InputOption::VALUE_OPTIONAL, 'Specify CSV encoding.', 'SJIS-win'),
            new InputOption('yml-inline', null, InputOption::VALUE_OPTIONAL, 'Specify YML inline nest level.', 4),
            new InputOption('yml-indent', null, InputOption::VALUE_OPTIONAL, 'Specify YML indent size.', 4),
            new InputOption('config', 'C', InputOption::VALUE_OPTIONAL, 'Specify Configuration filepath'),
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
        $transporter->setEncoding('csv', $this->input->getOption('csv-encoding'));
        $transporter->setDirectory('table', $this->input->getOption('table-directory'));
        $transporter->setDirectory('view', $this->input->getOption('view-directory'));
        $transporter->setYmlOption('inline', $this->input->getOption('yml-inline'));
        $transporter->setYmlOption('indent', $this->input->getOption('yml-indent'));
        $ddl = $transporter->exportDDL(array_shift($files), $includes, $excludes);
        $this->logger->info($ddl);
        foreach ($files as $filename) {
            $dml = $transporter->exportDML($filename, $wheres, $ignores);
            $this->logger->info($dml);
        }

        return 0;
    }
}
