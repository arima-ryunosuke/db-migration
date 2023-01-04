<?php

namespace ryunosuke\Test\DbMigration\FileType;

use ryunosuke\DbMigration\FileType\AbstractFile;
use ryunosuke\DbMigration\FileType\Yaml;

class YamlTest extends AbstractFileTestCase
{
    function getFile($encoding = 'utf8'): AbstractFile
    {
        return new Yaml(self::$tmpdir . "/dummy.$encoding.yaml", []);
    }

    function test_options()
    {
        $file = new Yaml(self::$tmpdir . '/dummy.yaml', [
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
        -
          id: 1
          name: hoge
          mbstring: あいうえお
        -
          id: 2
          name: fuga
          mbstring: かきくけこ
        -
          id: 3
          name: piyo
          mbstring: さしすせそ
        
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
        array:
          - 1
          - 2
          - 3
        hash:
          a: A
          b: B
        empty: {  }
        emptyempty:
          - {  }
        emptyempty1:
          - [[1]]
        nest:
          hash: { a: A, b: B, hash: { x: X }, multiline: { one: multiline, two: "multi\\nline" } }
          array: [[1, 2, 3, [X]]]
        multiline: |-
          multi
          line
        'null': null
        int: 123
        string: ABC
        
        EXPECTED, $actual);
    }

    function test_yaml5()
    {
        $file = new Yaml(self::$tmpdir . '/dummy.yaml5', [
            'indent'    => 2,
            'inline'    => 2,
            'multiline' => true,
            'flowstyle' => true,
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
            id: 1,
            name: hoge,
            mbstring: あいうえお,
          },
          {
            id: 2,
            name: fuga,
            mbstring: かきくけこ,
          },
          {
            id: 3,
            name: piyo,
            mbstring: さしすせそ,
          },
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
        {
          array: [
            1,
            2,
            3,
          ],
          hash: {
            a: A,
            b: B,
          },
          empty: {  },
          emptyempty: [
            {  },
          ],
          emptyempty1: [
            [[1]],
          ],
          nest: {
            hash: { a: A, b: B, hash: { x: X }, multiline: { one: multiline, two: "multi\\nline" } },
            array: [[1, 2, 3, [X]]],
          },
          multiline: "multi\\nline",
          'null': null,
          int: 123,
          string: ABC,
        }
        
        EXPECTED, $actual);
    }
}
