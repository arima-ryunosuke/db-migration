<?php

namespace ryunosuke\Test\DbMigration\FileType;

use ryunosuke\DbMigration\FileType\AbstractFile;

class SqlTest extends AbstractFileTestCase
{
    function getFile($encoding = 'utf8'): AbstractFile
    {
        return AbstractFile::create(self::$tmpdir . "/dummy.$encoding.sql", [
            'quoteIdentifier' => fn($v) => $v,
            'quoteValue'      => fn($v) => $v,
        ]);
    }

    function test_yield()
    {
        $file = AbstractFile::create(self::$tmpdir . '/dummy.sql', [
            'yield' => true,
        ]);

        file_put_contents($file->pathinfo()['fullname'], <<<DATA
        INSERT INSERT t_table(id) VALUES(1);
        INSERT INSERT t_table SET
          id = 1
        ;
        INSERT INSERT t_table(name) VALUES
          (";"),
          (';'),
          (`;`)
        DATA,);

        $this->assertEquals([
            'INSERT INSERT t_table(id) VALUES(1)',
            'INSERT INSERT t_table SET
  id = 1',
            'INSERT INSERT t_table(name) VALUES
  (";"),
  (\';\'),
  (`;`)',
        ], iterator_to_array($file->readRecords()));
    }

    public function test_multiline()
    {
        $file = AbstractFile::create(self::$tmpdir . '/dummy.sql', [
            'multiline'       => true,
            'quoteIdentifier' => fn($v) => $v,
            'quoteValue'      => fn($v) => $v,
        ]);
        iterator_to_array($file->writeRecords([
            ['a' => 'a1', 'b' => 'b1', 'c' => 'c1'],
            ['a' => 'a2', 'b' => 'b2', 'c' => 'c2'],
        ]));
        $actual = file_get_contents($file->pathinfo()['fullname']);
        $this->assertEquals(<<<EXPECTED
        INSERT INTO dummy (a, b, c) VALUES
        (a1, b1, c1),
        (a2, b2, c2);

        EXPECTED, $actual);
    }

    public function test_delimiter()
    {
        $contents = <<<SQL
        CREATE TABLE ttt(
            id int comment '///'
        ) COMMENT "///"
        ///
        INSERT INTO ttt VALUES('///', "///")///
        SQL;
        $expected = [
            <<<SQL1
            CREATE TABLE ttt(
                id int comment '///'
            ) COMMENT "///"
            SQL1,
            <<<SQL2
            INSERT INTO ttt VALUES('///', "///")
            SQL2,
        ];

        $file = AbstractFile::create(self::$tmpdir . '/dummy.sql', ['delimiter' => '///', 'yield' => true]);
        file_put_contents($file->pathinfo()['fullname'], $contents);
        $this->assertEquals($expected, iterator_to_array($file->readMigration()));

        $file = AbstractFile::create(self::$tmpdir . '/dummy.sql', ['delimiter' => '///', 'yield' => false]);
        file_put_contents($file->pathinfo()['fullname'], $contents);
        $this->assertEquals($expected, iterator_to_array($file->readMigration()));
    }
}
