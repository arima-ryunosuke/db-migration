<?php

namespace ryunosuke\DbMigration\FileType;

use DomainException;
use Generator;
use function ryunosuke\DbMigration\iterator_split;

class Csv extends AbstractFile
{
    private const NULL = "\\N";

    private const TYPE_MAP = [
        'boolean' => 'bool',
        'integer' => 'int',
        'double'  => 'float',
    ];

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
        $typed     = $this->options['typed'] ?? false;

        $stream = $this->stream('w');
        if ($this->bom) {
            $stream->fwrite(self::BOM);
        }
        foreach ($rows as $i => $row) {
            if ($typed) {
                $newrow = [];
                foreach ($row as $c => $v) {
                    $type = gettype($v);
                    if (isset(self::TYPE_MAP[$type])) {
                        $c = "$c:" . self::TYPE_MAP[$type];
                    }
                    $newrow[$c] = $v;
                }
                $row = $newrow;
            }

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
        $typed     = $this->options['typed'] ?? false;

        $stream = $this->stream('r');
        $stream->setFlags($stream::READ_CSV);
        $stream->setCsvControl($delimiter);

        [$header, $stream] = iterator_split($stream, [1]);
        $header    = $header[0];
        $header[0] = preg_replace("#^" . self::BOM . "#u", '', $header[0]);

        if ($typed) {
            $types     = [];
            $newheader = [];
            foreach ($header as $c => $v) {
                $rpos = strrpos($v, ':');
                if ($rpos !== false) {
                    $types[$c] = substr($v, $rpos + 1);
                    $v         = substr($v, 0, $rpos);
                }
                $newheader[$c] = $v;
            }
            $header = $newheader;
        }

        foreach ($stream as $fields) {
            if ($fields === [null]) {
                continue;
            }
            foreach ($fields as $c => $v) {
                if ($v === self::NULL) {
                    $fields[$c] = null;
                }
                elseif (isset($types[$c])) {
                    settype($fields[$c], $types[$c]);
                }
            }
            yield array_combine($header, $fields);
        }
    }
}
