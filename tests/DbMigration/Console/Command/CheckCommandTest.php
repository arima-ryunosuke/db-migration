<?php

namespace ryunosuke\Test\DbMigration\Console\Command;

use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use ryunosuke\DbMigration\Console\Command\CheckCommand;

class CheckCommandTest extends AbstractTestCase
{
    protected $commandName = 'check';

    protected function setup(): void
    {
        parent::setUp();

        $table_hoge = $this->createSimpleTable('hoge', 'integer', 'id');
        $this->readyTable($this->schema, $table_hoge);

        $table_fuga = $this->createSimpleTable('fuga', 'integer', 'id');
        $table_fuga->addColumn('fid1', 'integer', ['Notnull' => false]);
        $table_fuga->addColumn('fid2', 'integer', ['Notnull' => false]);
        $this->readyTable($this->schema, $table_fuga);

        $this->schema->createForeignKey(new ForeignKeyConstraint(['fid1'], 'hoge', ['id'], 'fk_fuga_hoge1'), 'fuga');
        $this->schema->createForeignKey(new ForeignKeyConstraint(['fid2'], 'hoge', ['id'], 'fk_fuga_hoge2'), 'fuga');

        $this->app->add(new CheckCommand());

        $this->defaultArgs = [
            '-n'  => true,
            'dsn' => AbstractTestCase::TEST_SCHEME . $GLOBALS['db'],
        ];
    }

    /**
     * @test
     */
    function run_()
    {
        $this->connection->disableConstraint(true);

        $rows = array_map(function ($i) {
            return [
                'id' => $i,
            ];
        }, range(1, 6));
        $this->insertMultiple($this->connection, 'hoge', $rows);

        $rows = array_map(function ($i) {
            return [
                'id'   => $i * 10,
                'fid1' => $i,
                'fid2' => $i % 2 === 0 ? $i : null,
            ];
        }, range(1, 10));
        $this->insertMultiple($this->connection, 'fuga', $rows);

        $this->connection->disableConstraint(false);

        $result = $this->runApp([]);
        $result = strtr($result, ['"' => '']);
        $this->assertStringNotContainsString('(id): (60)', $result);
        $this->assertStringNotContainsString('fid1: 6', $result);
        $this->assertStringContainsString('(id): (70)', $result);
        $this->assertStringContainsString('fid1: 7', $result);
        $this->assertStringContainsString('(id): (80)', $result);
        $this->assertStringContainsString('fid1: 8', $result);
        $this->assertStringContainsString('(id): (90)', $result);
        $this->assertStringContainsString('fid1: 9', $result);
        $this->assertStringContainsString('(id): (100)', $result);
        $this->assertStringContainsString('fid1: 10', $result);
        $this->assertStringContainsString('fid2: 8', $result);
        $this->assertStringNotContainsString('fid2: 9', $result);
        $this->assertStringContainsString('fid2: 10', $result);
    }
}
