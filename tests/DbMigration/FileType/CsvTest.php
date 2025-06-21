<?php

namespace ryunosuke\Test\DbMigration\FileType;

use DomainException;
use ryunosuke\DbMigration\FileType\AbstractFile;
use ryunosuke\DbMigration\FileType\Tool\Binary;

class CsvTest extends AbstractFileTestCase
{
    function getFile($encoding = 'utf8'): AbstractFile
    {
        return AbstractFile::create(self::$tmpdir . "/dummy.$encoding.csv", []);
    }

    public function test_records()
    {
        $file = AbstractFile::create(self::$tmpdir . '/dummy.csv', ['delimiter' => ";"]);

        // single
        $records = [
            ['main.id' => 1, 'name' => 'A'],
            ['main.id' => 2, 'name' => 'B'],
            ['main.id' => 3, 'name' => 'C'],
        ];

        $this->assertEquals($records, iterator_to_array($file->writeRecords($records)));
        $this->assertEquals(<<<CSV
            main.id;name
            1;A
            2;B
            3;C
            
            CSV, (string) $file);
        $this->assertEquals([
            "main" => [
                [
                    "id"   => "1",
                    "name" => "A",
                ],
                [
                    "id"   => "2",
                    "name" => "B",
                ],
                [
                    "id"   => "3",
                    "name" => "C",
                ],
            ],
        ], array_map('iterator_to_array', iterator_to_array($file->readMigration())));

        // multiple
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
        ], iterator_to_array($file->readMigration()));

        $records = [
            ['id' => 1, 'name' => 'A'],
        ];

        $this->assertEquals($records, iterator_to_array($file->writeRecords($records)));
        $this->assertException(new DomainException('specified table name'), fn() => iterator_to_array($file->readMigration()));
    }

    public function test_typed()
    {
        $file = AbstractFile::create(self::$tmpdir . '/dummy.ttsv', []);

        $records = [
            ['main.id' => 1, 'name' => 'A', 'score' => 1.23, 'flag' => false],
            ['main.id' => 2, 'name' => 'B', 'score' => 2.34, 'flag' => true],
            ['main.id' => 3, 'name' => 'C', 'score' => 3.45, 'flag' => true],
        ];

        $write = iterator_to_array($file->writeRecords($records));
        $this->assertEquals([
            ['main.id:int' => 1, 'name' => 'A', 'score:float' => 1.23, 'flag:bool' => false],
            ['main.id:int' => 2, 'name' => 'B', 'score:float' => 2.34, 'flag:bool' => true],
            ['main.id:int' => 3, 'name' => 'C', 'score:float' => 3.45, 'flag:bool' => true],
        ], $write);

        $read = iterator_to_array($file->readRecords());
        $this->assertSame($records, $read);
    }

    public function test_null()
    {
        $file = AbstractFile::create(self::$tmpdir . '/dummy.csv', []);

        iterator_to_array($file->writeRecords([['id' => 1, 'name' => null]]));
        $this->assertSame([['id' => '1', 'name' => null]], iterator_to_array($file->readRecords()));
    }

    public function test_binary()
    {
        $file = AbstractFile::create(self::$tmpdir . '/dummy.csv', []);

        iterator_to_array($file->writeRecords([['id' => 1, 'name' => new Binary("\0\1\2")]]));
        $this->assertSame([['id' => '1', 'name' => "\0\1\2"]], iterator_to_array($file->readRecords()));
    }

    public function test_bom()
    {
        $bomfile = AbstractFile::create(self::$tmpdir . '/dummy.utf8.csv', []);

        iterator_to_array($bomfile->writeRecords([['id' => 1]]));
        $this->assertEquals([['id' => 1]], iterator_to_array($bomfile->readRecords()));
        $this->assertEquals("id\n1\n", (string) $bomfile);
        $this->assertFileContains("\xEF\xBB\xBF", $bomfile->pathinfo()['fullname']);

        $nobomfile = AbstractFile::create(self::$tmpdir . '/dummy.utf8n.csv', []);

        iterator_to_array($nobomfile->writeRecords([['id' => 1]]));
        $this->assertEquals([['id' => 1]], iterator_to_array($nobomfile->readRecords()));
        $this->assertEquals("id\n1\n", (string) $nobomfile);
        $this->assertFileNotContains("\xEF\xBB\xBF", $nobomfile->pathinfo()['fullname']);
    }

    public function test_schema()
    {
        $file = AbstractFile::create('dummy.csv', []);

        $this->assertException(new DomainException('does not support readSchema'), fn() => $file->readSchema());

        $this->assertException(new DomainException('does not support writeSchema'), fn() => $file->writeSchema([]));
    }
}
