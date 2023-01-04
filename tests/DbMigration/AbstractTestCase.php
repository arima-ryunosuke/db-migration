<?php

namespace ryunosuke\Test\DbMigration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Table;
use PHPUnit\Framework\Error\Error;

abstract class AbstractTestCase extends \PHPUnit\Framework\TestCase
{
    protected static $tmpdir;

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var AbstractSchemaManager
     */
    protected $schema;

    /**
     * @var array
     */
    protected $dbparams;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$tmpdir = sys_get_temp_dir() . '/ryumig';
        is_dir(self::$tmpdir) or mkdir(self::$tmpdir, 0777, true);
    }

    /**
     * for get closure of method
     *
     * @param string $name
     * @return \Closure
     */
    public function __get($name)
    {
        // compatible PHPUnit_Framework_TestCase::__get
        if (is_callable('parent::__get')) {
            /** @noinspection PhpUndefinedMethodInspection */
            return parent::__get($name);
        }

        // if exsists method and @closurable, return that closure
        if (method_exists($this, $name)) {
            $refclass = new \ReflectionClass($this);
            $method   = $refclass->getMethod($name);
            if (strpos($method->getDocComment(), '@closurable') !== false) {
                $method->setAccessible(true);
                return $method->getClosure($this);
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->rmdir_r(self::$tmpdir);
        is_dir(self::$tmpdir) or mkdir(self::$tmpdir, 0777, true);

        $unset = function ($array, $key) {
            unset($array[$key]);
            return $array;
        };

        $params = $this->parseDsn($GLOBALS['db']);

        // drop schema
        $c = DriverManager::getConnection($unset($params, 'dbname'));
        $this->readyDatabase($c->createSchemaManager(), $params['dbname']);
        $c->close();

        // get connection
        $this->connection = DriverManager::getConnection($params);

        // get schema
        $this->schema = $this->connection->createSchemaManager();

        $this->dbparams = $params;
    }

    protected function rmdir_r($dir_path = null)
    {
        if (!strlen($dir_path)) {
            return false;
        }
        if (!file_exists($dir_path)) {
            return true;
        }
        if (is_file($dir_path) or is_link($dir_path)) {
            return @unlink($dir_path);
        }

        $dir = @scandir($dir_path);
        if (!is_array($dir)) {
            return false;
        }

        $dir = array_diff($dir, ['.', '..']);
        foreach ($dir as $item) {
            if (!$this->rmdir_r($dir_path . '/' . $item)) {
                return false;
            }
        }

        return @rmdir($dir_path);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->connection->close();
    }

    public function parseDsn($dsn)
    {
        $parseDatabaseUrl = new \ReflectionMethod('\\Doctrine\\DBAL\\DriverManager', 'parseDatabaseUrl');
        $parseDatabaseUrl->setAccessible(true);
        $params = $parseDatabaseUrl->invoke(null, ['url' => $dsn]);
        unset($params['url']);
        return $params;
    }

    public function readyDatabase(AbstractSchemaManager $schema_manager, $database)
    {
        try {
            $schema_manager->dropDatabase($database);
        }
        catch (\Throwable $t) {
        }

        try {
            $schema_manager->createDatabase($database);
        }
        catch (\Throwable $t) {
        }
    }

    public function readyTable(AbstractSchemaManager $schema_manager, $table)
    {
        try {
            $schema_manager->dropTable($table);
        }
        catch (\Throwable $t) {
        }

        try {
            $schema_manager->createTable($table);
        }
        catch (\Throwable $t) {
        }
    }

    public function readyView(AbstractSchemaManager $schema_manager, $view)
    {
        try {
            $schema_manager->dropView($view);
        }
        catch (\Throwable $t) {
        }

        try {
            $schema_manager->createView($view);
        }
        catch (\Throwable $t) {
        }
    }

    public function createSimpleTable($name, $type)
    {
        $table   = new Table($name);
        $columns = array_slice(func_get_args(), 2);
        foreach ($columns as $column) {
            $table->addColumn($column, $type);
        }
        $table->setPrimaryKey([
            reset($columns),
        ]);
        return $table;
    }

    public function readyPhpFile($filename, $contents)
    {
        if ($filename[0] !== '/') {
            $filename = self::$tmpdir . "/$filename";
        }
        file_put_contents($filename, '<?php return ' . var_export($contents, true) . ';');
        return $filename;
    }

    public function insertMultiple(Connection $conn, $table, $records)
    {
        $conn->beginTransaction();

        foreach ($records as $record) {
            if (is_string($record)) {
                $record = json_decode($record, true);
            }
            $conn->insert($table, $record);
        }

        $conn->commit();
    }

    public static function assertException(\Exception $e, callable $callback)
    {
        try {
            call_user_func_array($callback, array_slice(func_get_args(), 2));
        }
        catch (Error $ex) {
            throw $ex;
        }
        catch (\Exception $ex) {
            self::assertInstanceOf(get_class($e), $ex);
            if ($e->getCode() > 0) {
                self::assertEquals($e->getCode(), $ex->getCode());
            }
            if (strlen($e->getMessage()) > 0) {
                self::assertStringContainsString($e->getMessage(), $ex->getMessage());
            }
            return;
        }
        self::fail(get_class($e) . ' is not thrown');
    }

    public static function assertExceptionMessage($message, callable $callback)
    {
        $args    = func_get_args();
        $args[0] = new \Exception($message);
        call_user_func_array('self::assertException', $args);
    }

    public static function assertContainsString($needle, $haystack, $message = '')
    {
        if (is_array($haystack) || is_object($haystack) && $haystack instanceof \Traversable) {
            foreach ($haystack as $val) {
                if (strpos($val, $needle) !== false) {
                    //for assertion count
                    self::assertTrue(true);
                    return;
                }
            }
            self::assertContains($needle, [], $message);
        }
        elseif (is_string($haystack)) {
            self::assertStringContainsString($needle, $haystack, $message);
        }
    }

    public static function assertNotContainsString($needle, $haystack, $message = '')
    {
        if (is_array($haystack) || is_object($haystack) && $haystack instanceof \Traversable) {
            foreach ($haystack as $val) {
                if (strpos($val, $needle) !== false) {
                    self::assertContains($needle, [], $message);
                }
            }
            //for assertion count
            self::assertTrue(true);
        }
        elseif (is_string($haystack)) {
            self::assertStringNotContainsString($needle, $haystack, $message);
        }
    }

    public static function assertFileContains($needle, $haystack, $message = '')
    {
        self::assertStringContainsString($needle, file_get_contents($haystack), $message);
    }

    public static function assertFileNotContains($needle, $haystack, $message = '')
    {
        self::assertStringNotContainsString($needle, file_get_contents($haystack), $message);
    }
}
