<?php

namespace ryunosuke\Test\DbMigration\FileType;

use ryunosuke\DbMigration\FileType\AbstractFile;
use ryunosuke\DbMigration\FileType\Sql;

class SqlTest extends AbstractFileTestCase
{
    function getFile($encoding = 'utf8'): AbstractFile
    {
        return new Sql(self::$tmpdir . "/dummy.$encoding.sql", [
            'quoteIdentifier' => fn($v) => $v,
            'quoteValue'      => fn($v) => $v,
        ]);
    }

    public function test_delimiter()
    {
        $file = new Sql(self::$tmpdir . '/dummy.sql', ['delimiter' => '///']);
        file_put_contents($file->pathinfo()['fullname'], <<<SQL
        CREATE TABLE ttt(
            id int comment '///'
        ) COMMENT "///"
        ///
        INSERT INTO ttt VALUES('///', "///")///
        SQL,);
        $this->assertEquals([
            <<<SQL1
            CREATE TABLE ttt(
                id int comment '///'
            ) COMMENT "///"
            SQL1,
            <<<SQL2
            INSERT INTO ttt VALUES('///', "///")
            SQL2,
        ], $file->readMigration());
    }
}
