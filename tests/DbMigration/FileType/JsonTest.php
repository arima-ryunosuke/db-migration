<?php

namespace ryunosuke\Test\DbMigration\FileType;

use ryunosuke\DbMigration\FileType\AbstractFile;
use ryunosuke\DbMigration\FileType\Json;

class JsonTest extends AbstractFileTestCase
{
    function getFile($encoding = 'utf8'): AbstractFile
    {
        return new Json(self::$tmpdir . "/dummy.$encoding.json", []);
    }

    function test_options()
    {
        $file = new Json(self::$tmpdir . '/dummy.json', [
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
}
