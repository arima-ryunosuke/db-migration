<?php

namespace ryunosuke\DbMigration\FileType;

use DomainException;
use Generator;
use ryunosuke\DbMigration\FileType\Tool\Binary;
use function ryunosuke\DbMigration\iterator_join;
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
                if ($v instanceof Binary) {
                    $row[$c] = "\\B" . $v->base64();
                }
            }

            $stream->fputcsv($row, $delimiter);
            yield $row;
        }
    }

    public function readMigration(): Generator
    {
        [$first, $rows] = iterator_split($this->read(), [1], true);

        $mapper = [];
        foreach (array_keys(reset($first)) as $n => $column_name) {
            $original = $column_name;
            $parts    = explode('.', $column_name, 2);
            if (count($parts) === 2) {
                [$table_name, $column_name] = $parts;
            }
            if (!isset($table_name)) {
                throw new DomainException("'{$this->pathinfo['fullname']}'#$n is not specified table name.");
            }
            $mapper[$table_name][$column_name] = $original;
        }

        // single table can enable generator
        if (count($mapper) === 1) {
            $table_name = array_keys($mapper)[0];
            $columns    = array_keys($mapper[$table_name]);
            yield $table_name => (function () use ($first, $rows, $columns) {
                foreach (iterator_join([$first, $rows]) as $n => $fields) {
                    yield $n => array_combine($columns, $fields);
                }
            })();
            return;
        }

        $table_records = [];
        foreach (iterator_join([$first, $rows]) as $n => $fields) {
            foreach ($mapper as $table_name => $originals) {
                $columns = array_flip($originals);
                $row     = array_intersect_key($fields, $columns);

                $table_records[$table_name][$n] = array_combine($columns, $row);
            }
        }

        foreach ($table_records as &$records) {
            $records = array_filter($records, fn($fields) => strlen(implode('', $fields)));
            $records = array_unique($records, SORT_REGULAR);
        }

        yield from $table_records;
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
                elseif (substr($v, 0, 2) === "\\B") {
                    $fields[$c] = base64_decode(substr($v, 2));
                }
                elseif (isset($types[$c])) {
                    settype($fields[$c], $types[$c]);
                }
            }
            yield array_combine($header, $fields);
        }
    }
}
