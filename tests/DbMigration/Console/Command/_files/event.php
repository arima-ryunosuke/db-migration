<?php

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Event\ConnectionEventArgs;
use Doctrine\DBAL\Event\Listeners\SQLSessionInit;
use Doctrine\DBAL\Event\SchemaCreateTableEventArgs;
use Doctrine\DBAL\Events;
use Symfony\Component\Console\Command\Command;

return function (Command $command, Connection $connection) {
    return [
        Events::postConnect         => [
            new SQLSessionInit("SET @postConnect = '" . $command->getName() . "'"),
        ],
        Events::onSchemaCreateTable => function (SchemaCreateTableEventArgs $args) use ($connection) {
            $connection->executeStatement("SET @onSchemaCreateTable = '" . $args->getTable()->getName() . "'");
        },
    ];
};
