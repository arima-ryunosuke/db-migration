<?php

namespace ryunosuke\Test\DbMigration\FileType;

use DomainException;
use ryunosuke\DbMigration\FileType\AbstractFile;
use ryunosuke\DbMigration\FileType\Csv;

class CsvTest extends AbstractFileTestCase
{
    function getFile($encoding = 'utf8'): AbstractFile
    {
        return new Csv(self::$tmpdir . "/dummy.$encoding.csv", []);
    }

    public function test_records()
    {
        $file = new Csv(self::$tmpdir . '/dummy.csv', ['delimiter' => ";"]);

        $records = [
            ['main.id' => 1, 'name' => 'A', 'sub.mainid' => 1, 'id' => 'a', 'mbstring' => 'あいうえお'],
            ['main.id' => '', 'name' => '', 'sub.mainid' => 1, 'id' => 'b', 'mbstring' => 'かきくけこ'],
            ['main.id' => 1, 'name' => 'A', 'sub.mainid' => 1, 'id' => 'c', 'mbstring' => 'さしすせそ'],
        ];

        $this->assertEquals($records, iterator_to_array($file->writeRecords($records)));
        $this->assertEquals(<<<CSV
            main.id;name;sub.mainid;id;mbstring
            1;A;1;a;あいうえお
            ;;1;b;かきくけこ
            1;A;1;c;さしすせそ
            
            CSV, (string) $file);
        $this->assertEquals([
            "main" => [
                [
                    "id"   => "1",
                    "name" => "A",
                ],
            ],
            "sub"  => [
                [
                    "mainid"   => "1",
                    "id"       => "a",
                    "mbstring" => "あいうえお",
                ],
                [
                    "mainid"   => "1",
                    "id"       => "b",
                    "mbstring" => "かきくけこ",
                ],
                [
                    "mainid"   => "1",
                    "id"       => "c",
                    "mbstring" => "さしすせそ",
                ],
            ],
        ], $file->readMigration());

        $records = [
            ['id' => 1, 'name' => 'A'],
        ];

        $this->assertEquals($records, iterator_to_array($file->writeRecords($records)));
        $this->assertException(new DomainException('specified table name'), fn() => $file->readMigration());
    }

    public function test_schema()
    {
        $file = new Csv('dummy.csv', []);

        $this->assertException(new DomainException('does not support readSchema'), fn() => $file->readSchema());

        $this->assertException(new DomainException('does not support writeSchema'), fn() => $file->writeSchema([]));
    }
}
