#!/bin/bash
set -e

# MYSQL_DATABASE (amazoff) and MYSQL_USER are provisioned by the base
# image's own init before docker-entrypoint-initdb.d runs. This adds the
# second database phpunit.xml points Pest at, granting the same app user
# access to it — otherwise `pest` fails against a fresh volume with
# "Access denied ... to database 'amazoff_test'", matching what CI's
# MySQL service provisions for the same reason.
#
# The wildcard grant covers `pest --parallel`: Laravel creates one database
# per worker by suffixing DB_DATABASE with `_test_{token}` (paratest sets
# the token), e.g. amazoff_test_test_1, _test_2. Those don't exist yet
# at init time and GRANT on a pattern still lets the app user CREATE DATABASE
# for anything matching it, so this covers whatever process count a given
# run uses without listing tokens by hand.
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<-EOSQL
	CREATE DATABASE IF NOT EXISTS amazoff_test;
	GRANT ALL PRIVILEGES ON amazoff_test.* TO '$MYSQL_USER'@'%';
	GRANT ALL PRIVILEGES ON \`amazoff\_test\_test\_%\`.* TO '$MYSQL_USER'@'%';
	FLUSH PRIVILEGES;
EOSQL
