<?php

/** @var \Doctrine\DBAL\Connection $connection */
$connection->insert('notexist', [
    'id' => 21,
]);

return 'INSERT INTO notexist (id) VALUES(22)';
