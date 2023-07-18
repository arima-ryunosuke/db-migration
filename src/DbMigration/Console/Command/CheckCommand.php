<?php

namespace ryunosuke\DbMigration\Console\Command;

use Doctrine\DBAL\DriverManager;
use ryunosuke\DbMigration\Connection;
use ryunosuke\DbMigration\Transporter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CheckCommand extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('check')->setDescription('Check constraint.');
        $this->setDefinition([
            new InputArgument('dsn', InputArgument::OPTIONAL, 'Specify target DSN.'),
            ...$this->getCommonOptions([
                'include',
                'exclude',
                'indent', // for dump method
                'event',
                'config',
            ]),
        ]);
        $this->setHelp(<<<EOT
            Check constraint (e.g. foreign key).
             e.g. `dbmigration check mysql://user:pass@localhost/dbname`
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

        // option
        $includes = $this->splitByComma($this->input->getOption('include'));
        $excludes = $this->splitByComma($this->input->getOption('exclude'));

        // get target Connection
        /** @var Connection $conn */
        $params = $this->parseDsn($this->input->getArgument('dsn'));
        $conn   = DriverManager::getConnection($params);

        $this->event($conn);

        $transporter = new Transporter($conn);

        // check foreign key constraint
        $diffs = $transporter->checkForeignKeyConstraint($includes, $excludes);
        foreach ($diffs as $tablename => $diff) {
            foreach ($diff as $foreign => $orphans) {
                if ($orphans->valid()) {
                    $this->logger->log("-- $tablename records don't have in table $foreign below.");
                    foreach ($orphans as $row) {
                        $this->logger->log($this->dump($row));
                    }
                }
            }
        }

        // do other constraint

        return 0;
    }
}
