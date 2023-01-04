<?php

namespace ryunosuke\Test\DbMigration\FileType;

use ryunosuke\DbMigration\FileType\AbstractFile;
use ryunosuke\DbMigration\FileType\Php;

class PhpTest extends AbstractFileTestCase
{
    function getFile($encoding = 'utf8'): AbstractFile
    {
        return new Php(self::$tmpdir . "/dummy.$encoding.php", []);
    }

    function test_callback()
    {
        $file     = self::$tmpdir . '/callback.php';
        $contents = '<?php return function ($connection) {return [$connection];};';
        file_put_contents($file, $contents);
        $phpFile = new Php($file, [
            'connection' => 'hoge',
        ]);
        $this->assertContainsString('closure file', iterator_to_array($phpFile->writeRecords([])));
        $this->assertStringEqualsFile($file, $contents);
        $this->assertEquals(['hoge'], $phpFile->readRecords());
    }

    function test_options()
    {
        $file = new Php(self::$tmpdir . '/dummy.json', [
            'indent'    => 2,
            'inline'    => 2,
            'multiline' => true,
        ]);

        $records = [
            ['id' => 1, 'name' => 'hoge', 'mbstring' => 'あいうえお'],
            ['id' => 2, 'name' => 'fuga', 'mbstring' => 'かきくけこ'],
            ['id' => 3, 'name' => 'piyo', 'mbstring' => 'さしすせそ'],
        ];

        iterator_to_array($file->writeRecords($records));
        $actual = file_get_contents($file->pathinfo()['fullname']);
        $this->assertEquals(<<<EXPECTED
        <?php return [
          [
            'id'       => 1,
            'name'     => 'hoge',
            'mbstring' => 'あいうえお',
          ],
          [
            'id'       => 2,
            'name'     => 'fuga',
            'mbstring' => 'かきくけこ',
          ],
          [
            'id'       => 3,
            'name'     => 'piyo',
            'mbstring' => 'さしすせそ',
          ],
        ];
        
        EXPECTED, $actual);

        $actual = $file->writeSchema([
            'array'       => [1, 2, 3],
            'hash'        => [
                'a' => 'A',
                'b' => 'B',
            ],
            'empty'       => [],
            'emptyempty'  => [[]],
            'emptyempty1' => [[[1]]],
            'nest'        => [
                'hash'  => [
                    'a'         => 'A',
                    'b'         => 'B',
                    'hash'      => [
                        'x' => 'X',
                    ],
                    'multiline' => [
                        'one' => "multiline",
                        'two' => "multi\nline",
                    ],
                ],
                'array' => [
                    [1, 2, 3, ['X']],
                ],
            ],
            'multiline'   => "multi\nline",
            'null'        => null,
            'int'         => 123,
            'string'      => 'ABC',
        ]);
        $this->assertEquals(<<<EXPECTED
        <?php return [
          'array'       => [
            1,
            2,
            3,
          ],
          'hash'        => [
            'a' => 'A',
            'b' => 'B',
          ],
          'empty'       => [],
          'emptyempty'  => [
            [],
          ],
          'emptyempty1' => [
            [[1]],
          ],
          'nest'        => [
            'hash'  => [ 'a' => 'A', 'b' => 'B', 'hash' => [ 'x' => 'X' ], 'multiline' => [ 'one' => 'multiline', 'two' => "multi\\nline" ] ],
            'array' => [[1, 2, 3, ['X']]],
          ],
          'multiline'   => <<<'SQL'
            multi
            line
            SQL,
          'null'        => null,
          'int'         => 123,
          'string'      => 'ABC',
        ];
        
        EXPECTED, $actual);
    }
}
