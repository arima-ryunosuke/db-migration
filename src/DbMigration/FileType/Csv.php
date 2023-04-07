<?php

namespace ryunosuke\DbMigration\FileType;

use DomainException;
use Generator;
use ryunosuke\DbMigration\Utility;
use function ryunosuke\DbMigration\iterator_split;

class Csv extends AbstractFile
{
    private const NULL = "\\N";

    public function readSchema(): array
    {
        throw $this->newUnsupported(__FUNCTION__);
    }

    public function writeSchema(array $schemaArray): string
    {
        throw $this->newUnsupported(__FUNCTION__);
    }

    public function readRecords(): Generator
    {
        yield from $this->read();
    }

    public function writeRecords(iterable $rows): Generator
    {
        $delimiter = $this->options['delimiter'] ?? ',';

        $stream = $this->stream('w');
        if ($this->bom) {
            $stream->fwrite(self::BOM);
        }
        foreach ($rows as $i => $row) {
            if ($i === 0) {
                $stream->fputcsv(array_keys($row), $delimiter);
            }

            foreach ($row as $c => $v) {
                if ($v === null) {
                    $row[$c] = self::NULL;
                }
            }

            $stream->fputcsv($row, $delimiter);
            yield $row;
        }
    }

    public function readMigration(): array
    {
        $table_records = [];
        foreach ($this->read() as $n => $fields) {
            $table_name = null;
            foreach ($fields as $column_name => $value) {
                $parts = explode('.', $column_name, 2);
                if (count($parts) === 2) {
                    [$table_name, $column_name] = $parts;
                }
                if (!isset($table_name)) {
                    throw new DomainException("'{$this->pathinfo['fullname']}'#$n is not specified table name.");
                }
                $table_records[$table_name][$n][$column_name] = $value;
            }
        }

        foreach ($table_records as &$records) {
            $records = array_filter($records, fn($fields) => strlen(implode('', $fields)));
            $records = array_unique($records, SORT_REGULAR);
        }

        return $table_records;
    }

    protected function read(): Generator
    {
        $delimiter = $this->options['delimiter'] ?? ',';

        $stream = $this->stream('r');
        $stream->setFlags($stream::READ_CSV);
        $stream->setCsvControl($delimiter);

        [$header, $stream] = iterator_split($stream, [1]);
        $header    = $header[0];
        $header[0] = preg_replace("#^" . self::BOM . "#u", '', $header[0]);
        foreach ($stream as $fields) {
            if ($fields === [null]) {
                continue;
            }
            foreach ($fields as $c => $v) {
                if ($v === self::NULL) {
                    $fields[$c] = null;
                }
            }
            yield array_combine($header, $fields);
        }
    }
}
