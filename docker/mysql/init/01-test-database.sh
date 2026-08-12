#!/bin/bash
set -e

# MYSQL_DATABASE (online_shop) and MYSQL_USER are provisioned by the base
# image's own init before docker-entrypoint-initdb.d runs. This adds the
# second database phpunit.xml points Pest at, granting the same app user
# access to it — otherwise `pest` fails against a fresh volume with
# "Access denied ... to database 'online_shop_test'", matching what CI's
# MySQL service provisions for the same reason.
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<-EOSQL
	CREATE DATABASE IF NOT EXISTS online_shop_test;
	GRANT ALL PRIVILEGES ON online_shop_test.* TO '$MYSQL_USER'@'%';
	FLUSH PRIVILEGES;
EOSQL
