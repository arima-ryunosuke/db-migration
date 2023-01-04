<?php

namespace ryunosuke\DbMigration\FileType;

use Generator;
use function ryunosuke\DbMigration\file_set_contents;
use function ryunosuke\DbMigration\quoteexplode;

class Sql extends AbstractFile
{
    public function sqlable(): bool
    {
        return true;
    }

    public function readSchema(): array
    {
        return $this->read();
    }

    public function writeSchema(array $schemaArray): string
    {
        $delimiter = $this->options['delimiter'] ?? ';';
        $contents  = [];
        foreach ($schemaArray as $category => $sqls) {
            if ($sqls) {
                $contents[] = "-- begin $category\n";
                foreach ($sqls as $sql) {
                    $contents[] = "{$sql}$delimiter\n";
                }
                $contents[] = "-- end $category\n\n";
            }
        }

        $contents = implode("", $contents);
        file_set_contents($this->pathinfo['fullname'], $contents);
        return $contents;
    }

    public function readRecords(): array
    {
        return $this->read();
    }

    public function writeRecords(iterable $rows): Generator
    {
        $delimiter = $this->options['delimiter'] ?? ';';
        $qtable    = $this->options['quoteIdentifier']($this->pathinfo['filename']);

        $stream = $this->stream('w');
        foreach ($rows as $row) {
            $columns = implode(', ', $this->options['quoteIdentifier'](array_keys($row)));
            $values  = implode(', ', $this->options['quoteValue']($row));

            $stream->fwrite("INSERT INTO $qtable ($columns) VALUES ($values)$delimiter\n");
            yield $row;
        }
    }

    public function readMigration(): array
    {
        return $this->read();
    }

    protected function read(): array
    {
        $delimiter = $this->options['delimiter'] ?? ';';
        if ($delimiter === ';') {
            return [(string) $this];
        }
        else {
            return array_filter(array_map('trim', quoteexplode($delimiter, (string) $this, null, "\"'`")), 'strlen');
        }
    }
}
