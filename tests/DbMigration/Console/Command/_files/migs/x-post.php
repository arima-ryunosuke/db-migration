<?php

return function ($connection) {
    $GLOBALS['post-migration'] = $connection->createSchemaManager()->tableExists('notexist');

    return [];
};
