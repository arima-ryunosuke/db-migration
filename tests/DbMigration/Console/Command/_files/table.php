<?php return [
    'platform' => 'mysql',
    'table'    => [
        'difftable'   => [
            'column'  => [
                'id' => [
                    'type'                => 'integer',
                    'default'             => null,
                    'notnull'             => true,
                    'unsigned'            => false,
                    'comment'             => null,
                    'platformOptions'     => [],
                    'customSchemaOptions' => [],
                ],
            ],
            'index'   => [
                'PRIMARY' => [
                    'column'  => ['id'],
                    'primary' => true,
                    'unique'  => true,
                    'option'  => [
                        'lengths' => [null],
                    ],
                ],
            ],
            'foreign' => [],
            'option'  => [
                'engine'         => 'InnoDB',
                'collation'      => 'utf8mb4_bin',
                'charset'        => 'utf8mb4',
                'comment'        => '',
                'create_options' => [],
            ],
        ],
        'igntable'    => [
            'column'  => [
                'id'   => [
                    'type'                => 'integer',
                    'default'             => null,
                    'notnull'             => true,
                    'unsigned'            => false,
                    'comment'             => null,
                    'platformOptions'     => [],
                    'customSchemaOptions' => [],
                ],
                'code' => [
                    'type'                => 'integer',
                    'default'             => null,
                    'notnull'             => true,
                    'unsigned'            => false,
                    'comment'             => null,
                    'platformOptions'     => [],
                    'customSchemaOptions' => [],
                ],
                'name' => [
                    'type'                => 'string',
                    'default'             => null,
                    'notnull'             => false,
                    'length'              => 16,
                    'fixed'               => true,
                    'comment'             => null,
                    'platformOptions'     => [
                        'charset'   => 'utf8mb4',
                        'collation' => 'utf8mb4_bin',
                    ],
                    'customSchemaOptions' => [],
                ],
            ],
            'index'   => [
                'PRIMARY' => [
                    'column'  => ['id'],
                    'primary' => true,
                    'unique'  => true,
                    'option'  => [
                        'lengths' => [null],
                    ],
                ],
            ],
            'foreign' => [],
            'option'  => [
                'engine'         => 'InnoDB',
                'collation'      => 'utf8mb4_bin',
                'charset'        => 'utf8mb4',
                'comment'        => '',
                'create_options' => [],
            ],
        ],
        'longtable'   => [
            'column'  => [
                'id'        => [
                    'type'                => 'integer',
                    'default'             => null,
                    'notnull'             => true,
                    'unsigned'            => false,
                    'comment'             => null,
                    'platformOptions'     => [],
                    'customSchemaOptions' => [],
                ],
                'text_data' => [
                    'type'                => 'text',
                    'default'             => null,
                    'notnull'             => true,
                    'length'              => 65535,
                    'comment'             => null,
                    'platformOptions'     => [
                        'charset'   => 'utf8mb4',
                        'collation' => 'utf8mb4_bin',
                    ],
                    'customSchemaOptions' => [],
                ],
                'blob_data' => [
                    'type'                => 'blob',
                    'default'             => null,
                    'notnull'             => true,
                    'length'              => 65535,
                    'comment'             => null,
                    'platformOptions'     => [],
                    'customSchemaOptions' => [],
                ],
            ],
            'index'   => [
                'PRIMARY' => [
                    'column'  => ['id'],
                    'primary' => true,
                    'unique'  => true,
                    'option'  => [
                        'lengths' => [null],
                    ],
                ],
            ],
            'foreign' => [],
            'option'  => [
                'engine'         => 'InnoDB',
                'collation'      => 'utf8mb4_bin',
                'charset'        => 'utf8mb4',
                'comment'        => '',
                'create_options' => [],
            ],
        ],
        'migtable'    => [
            'column'  => [
                'id'   => [
                    'type'                => 'integer',
                    'default'             => null,
                    'notnull'             => true,
                    'unsigned'            => false,
                    'comment'             => null,
                    'platformOptions'     => [],
                    'customSchemaOptions' => [],
                ],
                'code' => [
                    'type'                => 'integer',
                    'default'             => null,
                    'notnull'             => true,
                    'unsigned'            => false,
                    'comment'             => null,
                    'platformOptions'     => [],
                    'customSchemaOptions' => [],
                ],
            ],
            'index'   => [
                'PRIMARY'   => [
                    'column'  => ['id'],
                    'primary' => true,
                    'unique'  => true,
                    'option'  => [
                        'lengths' => [null],
                    ],
                ],
                'unq_index' => [
                    'column' => ['code'],
                    'unique' => true,
                    'option' => [
                        'lengths' => [null],
                    ],
                ],
            ],
            'foreign' => [],
            'option'  => [
                'engine'         => 'InnoDB',
                'collation'      => 'utf8mb4_bin',
                'charset'        => 'utf8mb4',
                'comment'        => '',
                'create_options' => [],
            ],
        ],
        'nopkeytable' => [
            'column'  => [
                'id' => [
                    'type'                => 'integer',
                    'default'             => null,
                    'notnull'             => true,
                    'unsigned'            => false,
                    'comment'             => null,
                    'platformOptions'     => [],
                    'customSchemaOptions' => [],
                ],
            ],
            'index'   => [],
            'foreign' => [],
            'option'  => [
                'engine'         => 'InnoDB',
                'collation'      => 'utf8mb4_bin',
                'charset'        => 'utf8mb4',
                'comment'        => '',
                'create_options' => [],
            ],
        ],
        'notexist'    => [
            'column'  => [
                'id' => [
                    'type'                => 'integer',
                    'default'             => null,
                    'notnull'             => true,
                    'unsigned'            => false,
                    'comment'             => null,
                    'platformOptions'     => [],
                    'customSchemaOptions' => [],
                ],
            ],
            'index'   => [
                'PRIMARY' => [
                    'column'  => ['id'],
                    'primary' => true,
                    'unique'  => true,
                    'option'  => [
                        'lengths' => [null],
                    ],
                ],
            ],
            'foreign' => [],
            'option'  => [
                'engine'         => 'InnoDB',
                'collation'      => 'utf8mb4_bin',
                'charset'        => 'utf8mb4',
                'comment'        => '',
                'create_options' => [],
            ],
        ],
        'sametable'   => [
            'column'  => [
                'id' => [
                    'type'                => 'integer',
                    'default'             => null,
                    'notnull'             => true,
                    'unsigned'            => false,
                    'comment'             => null,
                    'platformOptions'     => [],
                    'customSchemaOptions' => [],
                ],
            ],
            'index'   => [
                'PRIMARY' => [
                    'column'  => ['id'],
                    'primary' => true,
                    'unique'  => true,
                    'option'  => [
                        'lengths' => [null],
                    ],
                ],
            ],
            'foreign' => [],
            'option'  => [
                'engine'         => 'InnoDB',
                'collation'      => 'utf8mb4_bin',
                'charset'        => 'utf8mb4',
                'comment'        => '',
                'create_options' => [],
            ],
        ],
        'unqtable'    => [
            'column'  => [
                'id'   => [
                    'type'                => 'integer',
                    'default'             => null,
                    'notnull'             => true,
                    'unsigned'            => false,
                    'comment'             => null,
                    'platformOptions'     => [],
                    'customSchemaOptions' => [],
                ],
                'code' => [
                    'type'                => 'integer',
                    'default'             => null,
                    'notnull'             => true,
                    'unsigned'            => false,
                    'comment'             => null,
                    'platformOptions'     => [],
                    'customSchemaOptions' => [],
                ],
            ],
            'index'   => [
                'PRIMARY'   => [
                    'column'  => ['id'],
                    'primary' => true,
                    'unique'  => true,
                    'option'  => [
                        'lengths' => [null],
                    ],
                ],
                'unq_index' => [
                    'column' => ['code'],
                    'unique' => true,
                    'option' => [
                        'lengths' => [null],
                    ],
                ],
            ],
            'foreign' => [],
            'option'  => [
                'engine'         => 'InnoDB',
                'collation'      => 'utf8mb4_bin',
                'charset'        => 'utf8mb4',
                'comment'        => '',
                'create_options' => [],
            ],
        ],
        'parenttable' => [
            'column'  => [
                'id' => [
                    'type'                => 'integer',
                    'default'             => null,
                    'notnull'             => true,
                    'unsigned'            => false,
                    'comment'             => null,
                    'platformOptions'     => [],
                    'customSchemaOptions' => [],
                ],
            ],
            'index'   => [
                'PRIMARY' => [
                    'column'  => ['id'],
                    'primary' => true,
                    'unique'  => true,
                    'option'  => [
                        'lengths' => [null],
                    ],
                ],
            ],
            'foreign' => [],
            'option'  => [
                'engine'         => 'InnoDB',
                'collation'      => 'utf8mb4_bin',
                'charset'        => 'utf8mb4',
                'comment'        => '',
                'create_options' => [],
            ],
        ],
        'childtable'  => [
            'column'  => [
                'id'        => [
                    'type'                => 'integer',
                    'default'             => null,
                    'notnull'             => true,
                    'unsigned'            => false,
                    'comment'             => null,
                    'platformOptions'     => [],
                    'customSchemaOptions' => [],
                ],
                'parent_id' => [
                    'type'                => 'integer',
                    'default'             => null,
                    'notnull'             => true,
                    'unsigned'            => false,
                    'comment'             => null,
                    'platformOptions'     => [],
                    'customSchemaOptions' => [],
                ],
            ],
            'index'   => [
                'PRIMARY'         => [
                    'column'  => ['id'],
                    'primary' => true,
                    'unique'  => true,
                    'option'  => [
                        'lengths' => [null],
                    ],
                ],
                'fk_child_parent' => [
                    'column'  => ['parent_id'],
                    'primary' => false,
                    'unique'  => false,
                    'option'  => [
                        'lengths' => [null],
                    ],
                ],
            ],
            'foreign' => [
                'fk_child_parent' => [
                    'table'  => 'parenttable',
                    'column' => [
                        'parent_id' => 'id',
                    ],
                    'option' => [
                        'onDelete' => 'NO ACTION',
                        'onUpdate' => 'CASCADE',
                    ],
                ],
            ],
            'option'  => [
                'engine'         => 'InnoDB',
                'collation'      => 'utf8mb4_bin',
                'charset'        => 'utf8mb4',
                'comment'        => '',
                'create_options' => [],
            ],
        ],
    ],
    'view'     => [],
    'trigger'  => [],
    'routine'  => [],
    'event'    => [],
];
