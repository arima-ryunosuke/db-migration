<?php

namespace ryunosuke\DbMigration\FileType;

use Doctrine\DBAL\Connection;
use DomainException;
use Generator;
use RuntimeException;
use ryunosuke\DbMigration\FileType\Tool\MbstringFilter;
use ryunosuke\DbMigration\Utility;
use SplFileObject;

abstract class AbstractFile
{
    protected string $internal_encoding;
    protected array  $pathinfo;
    protected array  $options;

    /** @noinspection PhpMissingBreakStatementInspection */
    public static function create(string $filename, array $options): AbstractFile
    {
        if (($options['connection'] ?? null) instanceof Connection) {
            $options['quoteIdentifier'] = fn($value) => Utility::quoteIdentifier($options['connection'], $value);
            $options['quoteValue']      = fn($value) => Utility::quote($options['connection'], $value);
        }

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        switch ($ext) {
            default:
                throw new DomainException("'{$ext}' is not supported.");
            case 'sql':
                return new Sql($filename, $options);
            case 'tsv':
                $options['delimiter'] = "\t";
            case 'csv':
                return new Csv($filename, $options);
            case 'php':
                return new Php($filename, $options);
            case 'json':
                return new Json($filename, $options);
            case 'yml5':
            case 'yaml5':
                $options['flowstyle'] = true;
            case 'yml':
            case 'yaml':
                return new Yaml($filename, $options);
        }
    }

    public function __construct(string $filename, array $options)
    {
        $this->internal_encoding = mb_internal_encoding();

        $pathinfo  = pathinfo($filename);
        $pathinfo2 = pathinfo($pathinfo['filename']);
        $encoding  = $pathinfo2['extension'] ?? '';

        $this->pathinfo = [
            'fullname'  => $filename,
            'dirname'   => $pathinfo['dirname'],
            'basename'  => $pathinfo['basename'],
            'filename'  => $pathinfo2['filename'],
            'extension' => $pathinfo['extension'] ?? '',
            'encoding'  => strlen($encoding) ? $encoding : $this->internal_encoding,
        ];
        $this->options  = $options;
    }

    public function __toString(): string
    {
        $stream = $this->stream('r');

        $contents = '';
        while (!$stream->eof()) {
            $contents .= $stream->fread(4096);
        }
        return $contents;
    }

    public function sqlable(): bool
    {
        return false;
    }

    public function pathinfo(): array
    {
        return $this->pathinfo;
    }

    public function newUnsupported(string $type)
    {
        return new DomainException("'{$this->pathinfo['extension']}' does not support $type.");
    }

    abstract public function readSchema(): array;

    abstract public function writeSchema(array $schemaArray): string;

    abstract public function readRecords(): array;

    abstract public function writeRecords(iterable $rows): Generator;

    abstract public function readMigration(): array;

    protected function stream(string $mode): SplFileObject
    {
        if (strpos($mode, 'r') !== false) {
            if (!file_exists($this->pathinfo['fullname'])) {
                throw new RuntimeException("{$this->pathinfo['fullname']} is not exist.");
            }
        }
        if (strpos($mode, 'w') !== false) {
            if (!file_exists($this->pathinfo['dirname'])) {
                @mkdir($this->pathinfo['dirname'], 0777, true);
            }
        }

        return new SplFileObject($this->filterize($this->pathinfo['fullname'], $mode), $mode);
    }

    protected function filterize(string $filename, string $mode): string
    {
        if ($this->internal_encoding === $this->pathinfo['encoding']) {
            return $filename;
        }

        static $registered = false;
        if (!$registered) {
            $registered = true;
            stream_filter_register("convert.mbstring.*", MbstringFilter::class);
        }

        $filter = '';
        if (strpos($mode, 'r') !== false) {
            $filter .= "/read=convert.mbstring.encoding.{$this->pathinfo['encoding']}:{$this->internal_encoding}";
        }
        if (strpos($mode, 'w') !== false) {
            $filter .= "/write=convert.mbstring.encoding.{$this->internal_encoding}:{$this->pathinfo['encoding']}";
        }

        if ($filter) {
            return "php://filter$filter/resource=$filename";
        }

        return $filename;
    }
}
