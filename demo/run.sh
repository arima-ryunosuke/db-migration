#!/bin/bash

cd $(dirname $(dirname $(readlink -f $0)))

function techo {
  echo -e "\e[0;42m$*\e[0m"
}

techo "import current database definitation. (imitate running database)"
php dbmigration.phar import mysql://127.0.0.1/demo_migration demo/current/* -n -v

techo "export current database definitation and records. (as sql)"
php dbmigration.phar export mysql://127.0.0.1/demo_migration /tmp/schema.sql /tmp/RecordTable.sql -m demo/migration -v

techo "export current database definitation and records. (as yml)"
php dbmigration.phar export mysql://127.0.0.1/demo_migration /tmp/schema.yml /tmp/RecordTable.yml -m demo/migration -v

techo "migrate latest database definitation. (no-interaction)"
php dbmigration.phar import mysql://127.0.0.1/demo_latest demo/latest/* -m demo/migration -n -v
php dbmigration.phar migrate mysql://127.0.0.1/demo_migration mysql://127.0.0.1/demo_latest -m demo/migration -n -v

techo "confirm no diff"
php dbmigration.phar migrate mysql://127.0.0.1/demo_migration mysql://127.0.0.1/demo_latest -m demo/migration --check
