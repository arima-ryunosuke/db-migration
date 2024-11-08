<?php

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;

return function (Command $command, Connection $connection) {
    $connection->executeStatement("SET @initialized = 'true'");

    return [
        'pre-migration'  => function (Connection $connection) {
            $connection->insert('eventtable', [
                'event' => 'pre-migration',
            ]);
        },
        'post-migration' => function (Connection $connection) {
            $connection->insert('eventtable', [
                'event' => 'post-migration',
            ]);
        },
    ];
};
