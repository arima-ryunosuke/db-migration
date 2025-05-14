<?php

namespace ryunosuke\DbMigration\FileType;

use Closure;
use Generator;
use ryunosuke\DbMigration\FileType\Tool\Binary;
use ryunosuke\DbMigration\FileType\Tool\Exportion;
use function ryunosuke\DbMigration\file_set_contents;
use function ryunosuke\DbMigration\is_hasharray;

class Php extends AbstractFile
{
    public function readSchema(): array
    {
        return $this->read();
    }

    public function writeSchema(array $schemaArray): string
    {
        $contents = $this->export($schemaArray, $this->options);

        file_set_contents($this->pathinfo['fullname'], $contents);

        return $contents;
    }

    public function readRecords(): Generator
    {
        yield from $this->read();
    }

    public function writeRecords(iterable $rows): Generator
    {
        if (file_exists($this->pathinfo['fullname']) && (require $this->pathinfo['fullname']) instanceof Closure) {
            return yield "'{$this->pathinfo['fullname']}' is closure file.";
        }

        $indent1 = str_repeat(" ", ($this->options['indent'] ?? 4) * 1);

        $stream = $this->stream('w');
        $stream->fwrite("<?php return [\n");
        foreach ($rows as $row) {
            $stream->fwrite($indent1 . $this->encode($row, ['inline' => 99999] + $this->options, 1) . ",\n");
            yield $row;
        }
        $stream->fwrite("];\n");
    }

    public function readMigration(): array
    {
        return $this->read();
    }

    protected function encode($data, $options, $nest = 0)
    {
        $inline    = $nest >= ($options['inline'] ?? 4);
        $indent0   = $inline ? "" : str_repeat(" ", ($options['indent'] ?? 4) * ($nest + 0));
        $indent1   = $inline ? "" : str_repeat(" ", ($options['indent'] ?? 4) * ($nest + 1));
        $multiline = $options['multiline'] ?? false;

        if (is_null($data)) {
            return 'null';
        }

        if ($data instanceof Binary) {
            return "base64_decode(" . var_export($data->base64(), true) . ')';
        }
        if ($data instanceof Exportion) {
            return "include " . var_export($data->export($this->pathinfo['dirname'], fn($data) => $this->export($data, $options)), true);
        }

        if (is_array($data)) {
            if (count($data) === 0) {
                return '[]';
            }

            $is_hash  = is_hasharray($data);
            $align    = !$inline && ($options['align'] ?? true);
            $space    = $inline ? " " : "";
            $break    = $inline ? "" : "\n";
            $last_key = array_key_last($data);
            $keys     = array_map(fn($k) => $this->encode($k, $options, $nest), array_combine($keys = array_keys($data), $keys));
            $maxlen   = max(array_map('strlen', $keys));

            $result = "[" . ($is_hash ? $space : "") . $break;

            foreach ($keys as $k => $qk) {
                $result .= $indent1;
                $result .= $is_hash ? $qk : "";
                $result .= $is_hash && $align ? str_repeat(' ', $maxlen - strlen($qk)) : "";
                $result .= $is_hash ? " => " : "";
                $result .= $this->encode($data[$k], $options, $nest + 1);
                $result .= $k === $last_key && $inline ? "" : ",$space$break";
            }

            return $result . ($is_hash ? $space : "") . $indent0 . "]";
        }

        if (is_string($data) && strpos($data, "\n") !== false) {
            if (!$inline && $multiline) {
                $data = preg_replace('#\R#u', "\n$indent1", $data);
                return "<<<'SQL'\n{$indent1}$data\n{$indent1}SQL";
            }
            else {
                return '"' . addcslashes($data, "\r\n\$\"\0\\") . '"';
            }
        }

        return var_export($data, true);
    }

    protected function read(): array
    {
        $result = require $this->filterize($this->pathinfo['fullname'], 'r');
        if ($result instanceof Closure) {
            $result = $result($this->options['connection']);
        }
        return (array) $result;
    }

    protected function export($data, $options)
    {
        return "<?php return {$this->encode($data, $options)};\n";
    }
}
