#!/usr/bin/env bash
#
# Creates the extra databases listed in EXTRA_DATABASES and grants the
# application user full privileges on each one.
#
# The official image only creates MYSQL_DATABASE (a single schema) and grants
# MYSQL_USER privileges on that one schema. A project that keeps one schema per
# application needs the rest created explicitly, or each app fails with error
# 1044 ("Access denied for user ... to database ...").
#
# List them space-separated in EXTRA_DATABASES (see env.example). The image runs
# everything in /docker-entrypoint-initdb.d once, the first time the mysql_data
# volume is initialised. Recreating the volume
# (`docker compose down -v && docker compose up -d`) re-applies this.
set -euo pipefail

databases=( "${MYSQL_DATABASE:-app}" )
extra_databases=()
if [ -n "${EXTRA_DATABASES:-}" ]; then
    read -r -a extra_databases <<< "${EXTRA_DATABASES}"
fi
databases+=( ${extra_databases[@]+"${extra_databases[@]}"} )

for db in "${databases[@]}"; do
    [ -n "$db" ] || continue
    # Names are interpolated into SQL, so only accept plain identifiers.
    if [[ ! "$db" =~ ^[A-Za-z0-9_]+$ ]]; then
        echo "init: skipping invalid database name: $db" >&2
        continue
    fi
    mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${db}\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL
    if [ -n "${MYSQL_USER:-}" ]; then
        mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" <<SQL
GRANT ALL PRIVILEGES ON \`${db}\`.* TO '${MYSQL_USER}'@'%';
SQL
    fi
done

mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" -e 'FLUSH PRIVILEGES;'
