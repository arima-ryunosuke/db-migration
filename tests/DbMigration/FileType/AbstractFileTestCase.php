<?php

namespace ryunosuke\Test\DbMigration\FileType;

use Generator;
use RuntimeException;
use ryunosuke\DbMigration\FileType\AbstractFile;
use ryunosuke\Test\DbMigration\AbstractTestCase;
use SplFileInfo;
use SplFileObject;

abstract class AbstractFileTestCase extends AbstractTestCase
{
    abstract function getFile($encoding = 'utf8'): AbstractFile;

    public function test_convert()
    {
        $file = $this->getFile();

        $records = [
            ['id' => 1, 'name' => 'hoge', 'mbstring' => 'あいうえお'],
            ['id' => 2, 'name' => 'fuga', 'mbstring' => 'かきくけこ'],
            ['id' => 3, 'name' => 'piyo', 'mbstring' => 'さしすせそ'],
        ];

        $this->assertEquals($records, iterator_to_array($file->writeRecords($records)));
        $this->assertEquals('UTF-8', mb_detect_encoding(file_get_contents($file->pathinfo()['fullname']), ['sjis', 'utf8']));

        if ($file->sqlable()) {
            $this->assertContainsString('あいうえお', iterator_to_array($file->readRecords()));
        }
        else {
            $this->assertEquals($records, iterator_to_array($file->readRecords()));
        }
    }

    public function test_convert_sjis()
    {
        $file = $this->getFile('sjis');

        $records = [
            ['id' => 1, 'name' => 'hoge', 'mbstring' => 'あいうえお'],
            ['id' => 2, 'name' => 'fuga', 'mbstring' => 'かきくけこ'],
            ['id' => 3, 'name' => 'piyo', 'mbstring' => 'さしすせそ'],
        ];

        $this->assertEquals($records, iterator_to_array($file->writeRecords($records)));
        $this->assertEquals('SJIS', mb_detect_encoding(file_get_contents($file->pathinfo()['fullname']), ['utf8', 'sjis']));

        if ($file->sqlable()) {
            $this->assertContainsString('あいうえお', iterator_to_array($file->readRecords()));
        }
        else {
            $this->assertEquals($records, iterator_to_array($file->readRecords()));
        }
    }

    public function test_misc()
    {
        $file = new class(self::$tmpdir . '/subdir/notfound.sjis.txt', []) extends AbstractFile {
            public function readSchema(): array { return []; }

            public function writeSchema(array $schemaArray): string { return ''; }

            public function readRecords(): Generator { yield from []; }

            public function writeRecords(iterable $rows): Generator { yield null; }

            public function readMigration(): array { return []; }

            public function stream(string $mode): SplFileObject { return parent::stream($mode); }
        };

        $this->assertException(new RuntimeException('is not exist'), fn() => $file->stream('r'));

        $this->assertInstanceOf(SplFileInfo::class, $file->stream('w'));
        $this->assertDirectoryExists($file->pathinfo()['dirname']);

        $this->assertEquals('notfound.sjis.txt', $file->stream('c')->getFilename());

        $stream = $file->open('w');
        $stream->fwrite('a');

        $stream = $file->stream('w');
        $stream->fwrite('b');

        $stream = $file->open('w');
        $stream->fwrite('c');

        $this->assertEquals('abc', file_get_contents($file->pathinfo()['fullname']));
    }
}
