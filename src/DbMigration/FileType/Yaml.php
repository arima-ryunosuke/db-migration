<?php

namespace ryunosuke\DbMigration\FileType;

use Generator;
use ryunosuke\DbMigration\FileType\Tool\Binary;
use ryunosuke\DbMigration\FileType\Tool\Exportion;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml as SymfonyYaml;
use function ryunosuke\DbMigration\file_set_contents;
use function ryunosuke\DbMigration\is_hasharray;

class Yaml extends AbstractFile
{
    public function readSchema(): array
    {
        return iterator_to_array($this->read());
    }

    public function writeSchema(array $schemaArray): string
    {
        $contents = $this->encode($schemaArray, $this->options, 0) . "\n";

        file_set_contents($this->pathinfo['fullname'], $contents);

        return $contents;
    }

    public function readRecords(): Generator
    {
        yield from $this->read();
    }

    public function writeRecords(iterable $rows): Generator
    {
        $indent1 = str_repeat(" ", ($this->options['indent'] ?? 4) * 1);
        $flow    = $this->options['flowstyle'] ?? false;

        $stream = $this->stream('w');
        if ($flow) {
            $stream->fwrite("[\n");
        }
        foreach ($rows as $row) {
            if ($flow) {
                $stream->fwrite($indent1);
            }
            else {
                $stream->fwrite("-\n");
            }

            $stream->fwrite($this->encode($row, ['inline' => 99999] + $this->options, 1));

            if ($flow) {
                $stream->fwrite(",\n");
            }
            else {
                $stream->fwrite("\n");
            }
            yield $row;
        }
        if ($flow) {
            $stream->fwrite("]\n");
        }
    }

    public function readMigration(): array
    {
        return $this->decode((string) $this);
    }

    protected function encode($data, $options, $nest = 0)
    {
        $inline    = $nest >= ($options['inline'] ?? 4);
        $inline1   = ($nest + 1) >= ($options['inline'] ?? 4);
        $indent0   = $inline ? "" : str_repeat(" ", ($options['indent'] ?? 4) * ($nest + 0));
        $indent1   = $inline ? "" : str_repeat(" ", ($options['indent'] ?? 4) * ($nest + 1));
        $multiline = $options['multiline'] ?? false;
        $flow      = $options['flowstyle'] ?? false;

        if ($data instanceof Binary) {
            return "!!binary " . $data->base64();
        }
        if ($data instanceof Exportion) {
            return "!include " . $data->export($this->pathinfo['dirname'], fn($data) => $this->encode($data, $options));
        }

        if (is_array($data)) {
            if (count($data) === 0) {
                return '{  }';
            }

            $is_hash  = is_hasharray($data);
            $align    = $options['align'] ?? false;
            $last_key = array_key_last($data);
            $keys     = array_map(fn($k) => $this->encode($k, $options), array_combine($keys = array_keys($data), $keys));
            $maxlen   = max(array_map('strlen', $keys));

            if ($inline) {
                $result = ($is_hash ? "{ " : "[");

                foreach ($keys as $k => $qk) {
                    $result .= $is_hash ? "$qk: " : "";
                    $result .= $this->encode($data[$k], $options, $nest + 1);
                    $result .= $k === $last_key ? "" : ", ";
                }

                return $result . ($is_hash ? " }" : "]");
            }
            elseif ($flow) {
                $result = ($is_hash ? "{\n" : "[\n");

                foreach ($keys as $k => $qk) {
                    $result .= $indent1;
                    $result .= $is_hash ? "$qk: " : "";
                    $result .= $is_hash && $align ? str_repeat(' ', $maxlen - strlen($qk)) : "";
                    $result .= $this->encode($data[$k], $options, $nest + 1);
                    $result .= ",\n";
                }

                return $result . "$indent0" . ($is_hash ? "}" : "]");
            }
            else {
                $result = "";

                foreach ($keys as $k => $qk) {
                    $oneline = $data[$k] instanceof Binary || $data[$k] instanceof Exportion || $data[$k] === [] || is_scalar($data[$k]) || is_null($data[$k]) || $inline1;

                    $result .= $indent0;
                    $result .= $is_hash ? "$qk:" : "-";
                    $result .= $is_hash && $align && $oneline ? str_repeat(' ', $maxlen - strlen($qk)) : "";
                    $result .= $oneline ? " " : "\n";
                    $result .= $this->encode($data[$k], $options, $nest + 1);
                    $result .= $k === $last_key ? "" : "\n";
                }

                return $result . "";
            }
        }

        if (!$inline && !$flow && $multiline && is_string($data) && strpos($data, "\n") !== false) {
            $indent        = ($options['indent'] ?? 4) * ($nest + ($flow ? 1 : 0));
            $multicontents = SymfonyYaml::dump([$data], 1, $indent, SymfonyYaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
            return substr($multicontents, 2);
        }

        return SymfonyYaml::dump($data);
    }

    protected function decode($data)
    {
        return SymfonyYaml::parse($data, SymfonyYaml::PARSE_CUSTOM_TAGS);
    }

    protected function read(): Generator
    {
        if (!($this->options['yield'] ?? true)) {
            $result = $this->decode((string) $this);

            array_walk_recursive($result, function (&$value) {
                if ($value instanceof TaggedValue) {
                    switch ($value->getTag()) {
                        case 'include':
                            $value = $this->decode(file_get_contents("{$this->pathinfo['dirname']}/{$value->getValue()}"));
                            break;
                    }
                }
            });

            return yield from $result;
        }

        // this code is experiment
        $buffer = '';
        foreach ($this->stream('r') as $line) {
            if (substr($line, 0, 1) === '-') {
                if (strlen($buffer)) {
                    yield $this->decode("-$buffer")[0];
                    $buffer = substr($line, 1);
                }
                else {
                    $buffer .= substr($line, 1);
                }
            }
            else {
                $buffer .= $line;
            }
        }
        if (strlen(trim($buffer))) {
            yield $this->decode("-$buffer")[0];
        }
    }
}
