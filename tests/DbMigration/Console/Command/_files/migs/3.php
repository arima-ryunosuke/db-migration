<?php

return function ($connection) {
    $connection->insert('notexist', [
        'id' => 31,
    ]);

    return [
        'INSERT INTO notexist (id) VALUES(32)',
        'notexist' => [
            [
                'id' => 33,
            ],
        ],
    ];
};
