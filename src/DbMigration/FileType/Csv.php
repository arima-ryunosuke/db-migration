<?php

namespace ryunosuke\DbMigration\FileType;

use DomainException;
use Generator;

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

    public function readRecords(): array
    {
        return $this->read();
    }

    public function writeRecords(iterable $rows): Generator
    {
        $delimiter = $this->options['delimiter'] ?? ',';

        $stream = $this->stream('w');
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

    protected function read(): array
    {
        $delimiter = $this->options['delimiter'] ?? ',';

        $result = [];
        $header = [];
        $stream = $this->stream('r');
        $stream->setFlags($stream::READ_CSV);
        $stream->setCsvControl($delimiter);
        foreach ($stream as $fields) {
            if ($fields === [null]) {
                continue;
            }
            // first row is used as CSV header
            if (!$header) {
                $header = $fields;
            }
            else {
                foreach ($fields as $c => $v) {
                    if ($v === self::NULL) {
                        $fields[$c] = null;
                    }
                }
                $result[] = array_combine($header, $fields);
            }
        }

        return $result;
    }
}
