<?php

namespace ryunosuke\DbMigration\FileType;

use Generator;
use function ryunosuke\DbMigration\file_set_contents;
use function ryunosuke\DbMigration\quoteexplode;
use function ryunosuke\DbMigration\strpos_quoted;

class Sql extends AbstractFile
{
    public function sqlable(): bool
    {
        return true;
    }

    public function readSchema(): array
    {
        return iterator_to_array($this->read());
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

    public function readRecords(): Generator
    {
        yield from $this->read();
    }

    public function writeRecords(iterable $rows): Generator
    {
        $delimiter = $this->options['delimiter'] ?? ';';
        $qtable    = $this->options['quoteIdentifier']($this->pathinfo['filename']);
        $multiline = $this->options['multiline'] ?? false;

        $stream = $this->stream('w');

        $first = true;
        foreach ($rows as $row) {
            $columns = implode(', ', $this->options['quoteIdentifier'](array_keys($row)));
            $values  = implode(', ', $this->options['quoteValue']($row));

            if ($multiline) {
                if ($first) {
                    $stream->fwrite("INSERT INTO $qtable ($columns) VALUES\n");
                    $stream->fwrite("($values)");
                }
                else {
                    $stream->fwrite(",\n($values)");
                }
            }
            else {
                $stream->fwrite("INSERT INTO $qtable ($columns) VALUES ($values)$delimiter\n");
            }

            yield $row;
            $first = false;
        }

        if (!$first && $multiline) {
            $stream->fwrite("$delimiter\n");
        }
    }

    public function readMigration(): array
    {
        return iterator_to_array($this->readRecords());
    }

    protected function read(): Generator
    {
        $delimiter = $this->options['delimiter'] ?? ';';

        if (!($this->options['yield'] ?? true)) {
            if ($delimiter === ';') {
                yield (string) $this;
            }
            else {
                yield from array_filter(array_map('trim', quoteexplode($delimiter, (string) $this, null, "\"'`")), 'strlen');
            }
            return;
        }

        $buffer = '';
        foreach ($this->stream('r') as $line) {
            $p = strpos_quoted($line, $delimiter, 0, "\"'`");
            if ($p !== false) {
                yield trim($buffer . substr($line, 0, $p));
                $buffer = substr($line, $p + strlen($delimiter));
            }
            else {
                $buffer .= $line;
            }
        }
        if (strlen(trim($buffer))) {
            yield trim($buffer);
        }
    }
}
