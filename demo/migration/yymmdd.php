<?php
return function ($connection) {
    $connection->insert("actor", [
        "first_name" => "appended1 by connection",
        "last_name"  => "appended1 by connection",
    ]);
    return "INSERT INTO actor (first_name, last_name) VALUES ('appended2 by string', 'appended2 by string')";
};
