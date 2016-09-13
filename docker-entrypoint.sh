#!/bin/bash
# FOSSology docker-entrypoint script
# Copyright Siemens AG 2016, fabio.huser@siemens.com
#
# Copying and distribution of this file, with or without modification,
# are permitted in any medium without royalty provided the copyright
# notice and this notice are preserved.  This file is offered as-is,
# without any warranty.
#
# Description: startup helper script for the FOSSology Docker container

#
# used environmental variables:
#    FOSSOLOGY_DB_HOST
#    FOSSOLOGY_DB_NAME (defaults to fossology)
#    FOSSOLOGY_DB_USER (defaults to fossy)
#    FOSSOLOGY_DB_PASSWORD (defaults to fossy)
#    FOSSOLOGY_SCHEDULER_HOST (defaults to localhost)

set -ex

if [ ! "$FOSSOLOGY_DB_HOST" ]; then
    echo "no host specified in the variable \$FOSSOLOGY_DB_HOST"
    exit 1
fi
db_host="$FOSSOLOGY_DB_HOST"
db_name="${FOSSOLOGY_DB_NAME:-fossology}"
db_user="${FOSSOLOGY_DB_USER:-fossy}"
db_password="${FOSSOLOGY_DB_PASSWORD:-fossy}"

################################################################################
# wait for external DB
testForPostgres(){
    PGPASSWORD=$db_password psql -h "$db_host" "$db_name" "$db_user" -c '\l' >/dev/null
    return $?
}
until testForPostgres; do
    >&2 echo "Postgres is unavailable - sleeping"
    sleep 1
done

################################################################################
# Write configuration
cat <<EOM > /usr/local/etc/fossology/Db.conf
dbname=$db_name;
host=$db_host;
user=$db_user;
password=$db_password;
EOM

sed -i 's/address = .*/address = '"${FOSSOLOGY_SCHEDULER_HOST:-localhost}"'/' \
    /usr/local/etc/fossology/fossology.conf

################################################################################
exec "$@"
