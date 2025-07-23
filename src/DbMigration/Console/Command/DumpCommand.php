<?php

namespace ryunosuke\DbMigration\Console\Command;

use Doctrine\DBAL\DriverManager;
use ryunosuke\DbMigration\Connection;
use ryunosuke\DbMigration\Transporter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function ryunosuke\DbMigration\array_sprintf;

class DumpCommand extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('dump')->setDescription('Dump schema and records.');
        $this->setDefinition([
            new InputArgument('dsn', InputArgument::OPTIONAL, 'Specify target DSN.'),
            new InputArgument('files', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Specify output files.'),
            new InputOption('recreate', 'R', InputOption::VALUE_OPTIONAL, 'Add DROP DATABASE/CREATE DATABASE.', ''),
            new InputOption('no-autoincrement', 'A', InputOption::VALUE_NONE, 'Add RESET auto_increment.'),
            ...$this->getCommonOptions([
                'migration',
                'include',
                'exclude',
                'disable',
                'transaction',
                'event',
                'config',
            ]),
        ]);
        $this->setHelp(<<<EOT
            Dump database (e.g. sakila).
             e.g. `dbmigration dump mysql://user:pass@localhost/sakila path/to/out.sql`
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

        // no nomalize(relative is important)
        $files = $this->input->getArgument('files');

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

        $this->event($conn);

        // export sql files from argument
        $transporter = new Transporter($conn);
        $transporter->setDisabled(array_fill_keys((array) $this->input->getOption('disable'), true));
        $transporter->setDataDescriptionOptions([
            'multiline' => true,
        ]);

        $generators = $transporter->dump(array_shift($files), $this->input->getOption('recreate'), $includes, $excludes, [
            'no-autoincrement' => $this->input->getOption('no-autoincrement'),
        ]);
        $this->transact($conn, function () use ($conn, $transporter, $generators) {
            foreach ($generators as $meta => $generator) {
                if ($generator === null) {
                    $this->logger->info("skip <info>{$meta[0]}</info>");
                    continue;
                }

                $this->output->write("dump <info>{$meta[0]}</info> to <comment>{$meta[1]}</comment> ");

                $progress = $this->logger->progress($generator(), 0.1);
                foreach ($progress as $status) {
                    $this->output->write($status);
                }

                $result = array_sprintf($progress->getReturn(), function ($v, $k) {
                    $v = is_int($v) ? number_format($v, 0) : $v;
                    $v = is_float($v) ? number_format($v, 3) : $v;
                    return "$k:<comment>$v</comment>";
                }, ', ');
                $this->logger->log("($result)");
            }
        });

        return 0;
    }
}
