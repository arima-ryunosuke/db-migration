<?php
namespace ryunosuke\Test\DbMigration;

use ryunosuke\Test\DbMigration\AbstractTestCase;
use ryunosuke\DbMigration\Generator;
use ryunosuke\DbMigration\MigrationException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\SchemaException;

class GeneratorTest extends AbstractTestCase
{

    protected $old, $new;

    /**
     * @closurable
     */
    private function getDML($old, $new, $tables)
    {
        return Generator::getDML($old, $new, $tables);
    }

    protected function setup()
    {
        parent::setUp();
        
        // create migration table different name
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('hoge', 'integer', 'id'));
        $this->newSchema->dropAndCreateTable($this->createSimpleTable('fuga', 'integer', 'id'));
        
        // create migration table no pkey
        $table = $this->createSimpleTable('nopkey', 'integer', 'id');
        $table->dropPrimaryKey();
        $this->oldSchema->dropAndCreateTable($table);
        $this->newSchema->dropAndCreateTable($table);
        
        // create migration table different pkey
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('diffpkey', 'integer', 'id'));
        $this->newSchema->dropAndCreateTable($this->createSimpleTable('diffpkey', 'integer', 'seq'));
        
        // create migration table different column
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('diffcolumn', 'integer', 'id'));
        $this->newSchema->dropAndCreateTable($this->createSimpleTable('diffcolumn', 'integer', 'id', 'seq'));
        
        // create migration table different type
        $this->oldSchema->dropAndCreateTable($this->createSimpleTable('difftype', 'string', 'id'));
        $this->newSchema->dropAndCreateTable($this->createSimpleTable('difftype', 'integer', 'id'));
        
        // create migration table different record
        $table = $this->createSimpleTable('foo', 'integer', 'id');
        $table->addColumn('c_int', 'integer');
        $table->addColumn('c_float', 'float');
        $table->addColumn('c_varchar', 'string');
        $table->addColumn('c_text', 'text');
        $table->addColumn('c_datetime', 'datetime');
        
        $this->oldSchema->dropAndCreateTable($table);
        $this->newSchema->dropAndCreateTable($table);
        
        $this->insertMultiple($this->old, 'foo', array(
            '{"id":0,"c_int":1,"c_float":1.2,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":1,"c_int":2,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":2,"c_int":1,"c_float":2,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":3,"c_int":1,"c_float":1,"c_varchar":"charX","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":4,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"textX","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":5,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"1999-01-01 00:00:00"}',
            '{"id":6,"c_int":2,"c_float":2,"c_varchar":"charX","c_text":"textX","c_datetime":"1999-01-01 00:00:00"}',
            '{"id":8,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":9,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}'
        ));
        $this->insertMultiple($this->new, 'foo', array(
            '{"id":-2,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":-1,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":0,"c_int":1,"c_float":1.2,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":1,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":2,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":3,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":4,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":5,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}',
            '{"id":6,"c_int":1,"c_float":1,"c_varchar":"char","c_text":"text","c_datetime":"2000-01-01 00:00:00"}'
        ));
    }

    /**
     * @test
     */
    function migrate_ddl()
    {
        $ddls = Generator::getDDL($this->old, $this->new);
        
        $this->assertContainsString('CREATE TABLE fuga', $ddls);
        $this->assertContainsString('DROP TABLE hoge', $ddls);
    }

    /**
     * @test
     */
    function migrate_dml()
    {
        $dmls = $this->getDML($this->old, $this->new, array(
            '1' => array(
                'foo',
                'foo'
            )
        ));
        $this->assertCount(10, $dmls);
        
        foreach ($dmls as $sql) {
            $this->old->exec($sql);
        }
        
        $dmls = $this->getDML($this->old, $this->new, array(
            '1' => array(
                'foo',
                'foo'
            )
        ));
        $this->assertCount(0, $dmls);
    }

    /**
     * @test
     */
    function migrate_dml_where()
    {
        $dmls = $this->getDML($this->old, $this->new, array(
            'id = -1' => array(
                'foo',
                'foo'
            )
        ));
        $this->assertCount(1, $dmls);
    }

    /**
     * @test
     */
    function migrate_dml_name()
    {
        $e = new SchemaException("There is no table with name", SchemaException::TABLE_DOESNT_EXIST);
        
        $this->assertException($e, $this->getDML, $this->old, $this->new, array(
            '1' => array(
                'notable',
                'fuga'
            )
        ));
        
        $this->assertException($e, $this->getDML, $this->old, $this->new, array(
            '1' => array(
                'hoge',
                'notable'
            )
        ));
    }

    /**
     * @test
     */
    function migrate_dml_nopkey()
    {
        $e = new MigrationException("has no primary key");
        
        $this->assertException($e, $this->getDML, $this->old, $this->new, array(
            '1' => array(
                'nopkey',
                'fuga'
            )
        ));
        
        $this->assertException($e, $this->getDML, $this->old, $this->new, array(
            '1' => array(
                'hoge',
                'nopkey'
            )
        ));
    }

    /**
     * @test
     */
    function migrate_dml_equals()
    {
        $e = new MigrationException("has different definition");
        
        $this->assertException($e, $this->getDML, $this->old, $this->new, array(
            '1' => array(
                'diffpkey',
                'diffpkey'
            )
        ));
        
        $this->assertException($e, $this->getDML, $this->old, $this->new, array(
            '1' => array(
                'diffcolumn',
                'diffcolumn'
            )
        ));
        
        $this->assertException($e, $this->getDML, $this->old, $this->new, array(
            '1' => array(
                'difftype',
                'difftype'
            )
        ));
    }
}
