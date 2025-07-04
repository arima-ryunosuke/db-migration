<?php

namespace ryunosuke\DbMigration\FileType;

use Generator;
use ryunosuke\DbMigration\FileType\Tool\Binary;
use ryunosuke\DbMigration\FileType\Tool\Exportion;
use function ryunosuke\DbMigration\file_set_contents;
use function ryunosuke\DbMigration\is_hasharray;
use function ryunosuke\DbMigration\strpos_quoted;

class Json extends AbstractFile
{
    public function readSchema(): array
    {
        return iterator_to_array($this->read());
    }

    public function writeSchema(array $schemaArray): string
    {
        $contents = $this->encode($schemaArray, $this->options) . "\n";

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

        $stream = $this->stream('w');
        $stream->fwrite("[\n");
        foreach ($rows as $i => $row) {
            if ($i !== 0) {
                $stream->fwrite(",\n");
            }

            $stream->fwrite($indent1 . $this->encode($row, ['inline' => 99999] + $this->options, 1));
            yield $row;
        }
        $stream->fwrite("\n]\n");
    }

    public function readMigration(): Generator
    {
        yield from $this->decode((string) $this);
    }

    protected function encode($data, $options, $nest = 0)
    {
        $inline  = $nest >= ($options['inline'] ?? 4);
        $indent0 = $inline ? "" : str_repeat(" ", ($options['indent'] ?? 4) * ($nest + 0));
        $indent1 = $inline ? "" : str_repeat(" ", ($options['indent'] ?? 4) * ($nest + 1));

        if ($data instanceof Binary) {
            return json_encode('!binary: ' . $data->base64(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($data instanceof Exportion) {
            return json_encode('!include: ' . $data->export($this->pathinfo['dirname'], fn($data) => $this->encode($data, $options)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (is_array($data)) {
            if (count($data) === 0) {
                return '[]';
            }

            $is_hash  = is_hasharray($data);
            $align    = !$inline && ($options['align'] ?? false);
            $space    = $inline ? " " : "";
            $break    = $inline ? "" : "\n";
            $last_key = array_key_last($data);
            $keys     = array_map(fn($k) => $this->encode("$k", $options, $nest), array_combine($keys = array_keys($data), $keys));
            $maxlen   = max(array_map('strlen', $keys));

            $result = ($is_hash ? "{" : "[") . ($is_hash ? $space : "") . $break;

            foreach ($keys as $k => $qk) {
                $result .= $indent1;
                $result .= $is_hash ? $qk : "";
                $result .= $is_hash ? ": " : "";
                $result .= $is_hash && $align ? str_repeat(' ', $maxlen - strlen($qk)) : "";
                $result .= $this->encode($data[$k], $options, $nest + 1);
                $result .= $k === $last_key ? $break : ",$space$break";
            }

            return $result . ($inline && $is_hash ? " " : "") . $indent0 . ($is_hash ? "}" : "]");
        }

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function decode($data)
    {
        return json_decode($data, true);
    }

    protected function read(): Generator
    {
        if (!($this->options['yield'] ?? true)) {
            $result = $this->decode((string) $this);

            array_walk_recursive($result, function (&$value) {
                if (preg_match('#^!binary: (.*)#', $value ?? '', $m)) {
                    $value = base64_decode($m[1]);
                }
                if (preg_match('#^!include: (.*)#', $value ?? '', $m)) {
                    $value = $this->decode(file_get_contents("{$this->pathinfo['dirname']}/{$m[1]}"));
                }
            });

            return yield from $result;
        }

        // this code is experiment
        $first  = true;
        $buffer = '';
        foreach ($this->stream('r') as $line) {
            if ($first) {
                $p = strpos_quoted($line, '[', 0, '"');
                if ($p !== null) {
                    $first = false;
                    $line  = substr($line, $p + 1);
                }
            }

            $buffer .= $line;
            $p      = strpos_quoted($buffer, [',', ']'], 0, ['"' => '"', '{' => '}']);
            if ($p !== null) {
                yield $this->decode(substr($buffer, 0, $p));
                $buffer = substr($buffer, $p + 1);
            }
        }
        if (strlen(trim($buffer))) {
            $p = strpos_quoted($buffer, [',', ']'], 0, ['"' => '"', '{' => '}']);
            yield $this->decode(substr($buffer, 0, $p));
        }
    }
}
