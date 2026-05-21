<?php

return function ($connection) {
    $GLOBALS['pre-migration'] = $connection->createSchemaManager()->tableExists('notexist');

    return [];
};
