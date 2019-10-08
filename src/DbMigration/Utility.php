<?php

namespace ryunosuke\DbMigration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Symfony\Component\Yaml\Yaml;

class Utility
{
    public static function getSchema(Connection $connection, $delete = false)
    {
        /** @var Schema[] $cache */
        static $cache = [];
        $cacheid = spl_object_hash($connection->getWrappedConnection());
        if ($delete) {
            $cache[$cacheid] = null;
        }
        elseif (!isset($cache[$cacheid])) {
            $cache[$cacheid] = $connection->getSchemaManager()->createSchema();
        }
        return $cache[$cacheid];
    }

    public static function quote(Connection $connection, $value)
    {
        if (is_array($value)) {
            foreach ($value as $n => $v) {
                $value[$n] = self::quote($connection, $v);
            }
            return $value;
        }

        if ($value === null) {
            return 'NULL';
        }

        return $connection->quote($value);
    }

    public static function quoteIdentifier(Connection $connection, $value)
    {
        if (is_array($value)) {
            foreach ($value as $n => $v) {
                $value[$n] = self::quoteIdentifier($connection, $v);
            }
            return $value;
        }

        return $connection->quoteIdentifier($value);
    }

    public static function filterTable($tablename, $includes, $excludes)
    {
        // filter from includes
        $flag = count($includes) > 0;
        foreach ($includes as $include) {
            foreach (array_map('trim', explode(',', $include)) as $regex) {
                if (preg_match("@$regex@i", $tablename)) {
                    $flag = false;
                    break;
                }
            }
        }
        if ($flag) {
            return 1;
        }

        // filter from excludes
        foreach ($excludes as $exclude) {
            foreach (array_map('trim', explode(',', $exclude)) as $regex) {
                if (preg_match("@$regex@i", $tablename)) {
                    return 2;
                }
            }
        }

        return 0;
    }

    public static function var_export($value, $nest = 0)
    {
        $INDENT = 4;

        $export = function ($value, $nest = 0) use (&$export, $INDENT) {
            if (is_array($value)) {
                if ($value === array_values($value)) {
                    return '[' . implode(', ', array_map($export, $value)) . ']';
                }

                $maxlen = max(array_map('strlen', array_keys($value)));
                $spacer = str_repeat(' ', ($nest + 1) * $INDENT);

                $kvl = '';
                foreach ($value as $k => $v) {
                    $align = str_repeat(' ', $maxlen - strlen($k));
                    $kvl .= $spacer . var_export($k, true) . $align . ' => ' . $export($v, $nest + 1) . ",\n";
                }
                return '[' . "\n" . $kvl . str_repeat(' ', $nest * $INDENT) . ']';
            }
            elseif (is_null($value)) {
                return 'null';
            }
            elseif ($value instanceof Exportion) {
                $fname = $value->export();
                return "include " . var_export($fname, true);
            }
            elseif (is_object($value)) {
                return get_class($value) . '::__set_state(' . $export((array) $value, $nest) . ')';
            }
            else {
                return var_export($value, true);
            }
        };

        return $export($value, $nest);
    }

    public static function yaml_emit($value, $options = [])
    {
        $options = array_replace([
            'builtin'  => false,
            'inline'   => null,
            'indent'   => null,
            'callback' => [],
        ], $options);

        if (function_exists('yaml_emit') && ($options['builtin'] || $options['callback'])) {
            return yaml_emit($value, YAML_UTF8_ENCODING, YAML_LN_BREAK, $options['callback']);
        }
        else {
            return Yaml::dump($value, $options['inline'], $options['indent']);
        }
    }

    public static function yaml_parse($input, $options = [])
    {
        $options = array_replace([
            'builtin'  => false,
            'callback' => [],
        ], $options);

        if (function_exists('yaml_parse') && ($options['builtin'] || $options['callback'])) {
            return yaml_parse($input, 0, $ndocs, $options['callback']);
        }
        else {
            return Yaml::parse($input);
        }
    }

    public static function json_encode($value, $options = 0)
    {
        if (defined('JSON_UNESCAPED_UNICODE')) {
            $options |= JSON_UNESCAPED_UNICODE;
        }
        if (defined('JSON_UNESCAPED_SLASHES')) {
            $options |= JSON_UNESCAPED_SLASHES;
        }
        if (defined('JSON_PRETTY_PRINT')) {
            $options |= JSON_PRETTY_PRINT;
        }
        return json_encode($value, $options);
    }

    public static function json_decode($value, $options = [])
    {
        $options = array_replace([
            'callback' => [],
        ], $options);

        $result = json_decode($value, true);
        foreach ($options['callback'] as $prefix => $callback) {
            array_walk_recursive($result, function (&$value) use ($prefix, $callback) {
                if (preg_match('#' . $prefix . ': (.*)#', $value, $m)) {
                    $value = call_user_func($callback, $m[1]);
                }
            });
        }
        return $result;
    }

    public static function array_diff_exists($array1, $array2)
    {
        foreach ($array1 as $key => $val) {
            if (array_key_exists($key, $array2) && $val === $array2[$key]) {
                unset($array1[$key]);
            }
        }

        return $array1;
    }

    public static function file_put_contents($filename, $data)
    {
        $dirname = dirname($filename);
        is_dir($dirname) or mkdir($dirname, 0777, true);
        return file_put_contents($filename, $data);
    }

    public static function mb_convert_variables($to_encoding, $from_encoding, &$vars)
    {
        if (strlen($to_encoding) === 0 || $to_encoding === $from_encoding) {
            return $from_encoding;
        }
        return mb_convert_variables($to_encoding, $from_encoding, $vars);
    }
}
