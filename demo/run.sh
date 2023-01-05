#!/bin/bash

USER=user
PASS=pass

cd $(dirname $(dirname $(readlink -f $0)))

function techo {
  echo -e "\e[0;42m$*\e[0m"
}

# php dbmigration.phar export mysql://$USER:$PASS@127.0.0.1/demo_migration demo/sakila.yaml demo/data/actor.yaml -n -v --multiline --migration demo/migration

techo "import database definitation."
php dbmigration.phar import mysql://$USER:$PASS@127.0.0.1/demo_migration demo/sakila.yaml demo/data/actor.yaml -n -v

techo "migrate database definitation. (no-diff)"
php dbmigration.phar migrate mysql://$USER:$PASS@127.0.0.1/demo_migration demo/sakila.yaml demo/data/actor.yaml -n -v

techo "change database. (ALTER/UPDATE)"
mysql -h 127.0.0.1 -u $USER -p$PASS demo_migration << SQL
ALTER TABLE actor ADD COLUMN dummy INT NOT NULL AFTER last_update;
UPDATE actor SET first_name = "changed" WHERE actor_id = 1;
SQL

techo "migrate database definitation. (diff above and migration)"
php dbmigration.phar migrate mysql://$USER:$PASS@127.0.0.1/demo_migration demo/sakila.yaml demo/data/actor.yaml --dml-type insert --migration demo/migration -n -v

techo "confirm no diff"
php dbmigration.phar migrate mysql://$USER:$PASS@127.0.0.1/demo_migration demo/sakila.yaml demo/data/actor.yaml --dml-type insert --migration demo/migration --check

techo "show migration result"
mysql -h 127.0.0.1 -u $USER -p$PASS demo_migration << SQL
SELECT * FROM actor;
SQL
