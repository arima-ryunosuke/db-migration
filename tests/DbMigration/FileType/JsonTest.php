<?php

namespace ryunosuke\Test\DbMigration\FileType;

use ryunosuke\DbMigration\FileType\AbstractFile;
use ryunosuke\DbMigration\FileType\Tool\Binary;

class JsonTest extends AbstractFileTestCase
{
    function getFile($encoding = 'utf8'): AbstractFile
    {
        return AbstractFile::create(self::$tmpdir . "/dummy.$encoding.json", []);
    }

    function test_options()
    {
        $file = AbstractFile::create(self::$tmpdir . '/dummy.json', [
            'indent' => 2,
            'inline' => 2,
        ]);

        $records = [
            ['id' => 1, 'name' => 'hoge', 'mbstring' => 'あいうえお'],
            ['id' => 2, 'name' => 'fuga', 'mbstring' => 'かきくけこ'],
            ['id' => 3, 'name' => 'piyo', 'mbstring' => 'さしすせそ'],
        ];

        iterator_to_array($file->writeRecords($records));
        $actual = file_get_contents($file->pathinfo()['fullname']);
        $this->assertEquals(<<<EXPECTED
        [
          {
            "id": 1,
            "name": "hoge",
            "mbstring": "あいうえお"
          },
          {
            "id": 2,
            "name": "fuga",
            "mbstring": "かきくけこ"
          },
          {
            "id": 3,
            "name": "piyo",
            "mbstring": "さしすせそ"
          }
        ]
        
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
                    'a'    => 'A',
                    'b'    => 'B',
                    'hash' => [
                        'x' => 'X',
                    ],
                ],
                'array' => [
                    [1, 2, 3, ['X']],
                ],
            ],
            'null'        => null,
            'int'         => 123,
            'string'      => 'ABC',
        ]);
        $this->assertEquals(<<<EXPECTED
        {
          "array": [
            1,
            2,
            3
          ],
          "hash": {
            "a": "A",
            "b": "B"
          },
          "empty": [],
          "emptyempty": [
            []
          ],
          "emptyempty1": [
            [[1]]
          ],
          "nest": {
            "hash": { "a": "A", "b": "B", "hash": { "x": "X" } },
            "array": [[1, 2, 3, ["X"]]]
          },
          "null": null,
          "int": 123,
          "string": "ABC"
        }
        
        EXPECTED, $actual);
    }

    public function test_binary()
    {
        $file = AbstractFile::create(self::$tmpdir . '/dummy.json', [
            'yield' => false,
        ]);

        iterator_to_array($file->writeRecords([['id' => 1, 'name' => new Binary("\0\1\2")]]));
        $this->assertSame([['id' => 1, 'name' => "\0\1\2"]], iterator_to_array($file->readRecords()));
    }

    function test_yield()
    {
        $file = AbstractFile::create(self::$tmpdir . '/dummy.json', [
            'yield' => true,
        ]);

        file_put_contents($file->pathinfo()['fullname'], <<<DATA
        [{"a":"line1A","b":"line1B"},{"a":"line2A","b":"line2B"},
            {"a":"sameA","b":"sameB"},
            {
                "a":"flowA",
                "b":"flowB"
            },
            {
                "a": "styleA",
                 "b":"styleB"
            },
            {"a":"misc{,}","b":"misc[,]"},
        {"a":"last1A","b":"last1B"},{"a":"last2A","b":"last2B"},]
        DATA,);

        $this->assertEquals([
            [
                'a' => 'line1A',
                'b' => 'line1B',
            ],
            [
                'a' => 'line2A',
                'b' => 'line2B',
            ],
            [
                'a' => 'sameA',
                'b' => 'sameB',
            ],
            [
                'a' => 'flowA',
                'b' => 'flowB',
            ],
            [
                'a' => 'styleA',
                'b' => 'styleB',
            ],
            [
                'a' => 'misc{,}',
                'b' => 'misc[,]',
            ],
            [
                'a' => 'last1A',
                'b' => 'last1B',
            ],
            [
                'a' => 'last2A',
                'b' => 'last2B',
            ],
        ], iterator_to_array($file->readRecords()));
    }
}
