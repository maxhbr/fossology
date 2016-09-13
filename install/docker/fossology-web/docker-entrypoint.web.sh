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
#    FOSSOLOGY_DB_NAME
#    FOSSOLOGY_DB_USER
#    FOSSOLOGY_DB_PASSWORD
#    FOSSOLOGY_SCHEDULER_HOST

set -ex

echo "call parent entrypoint"
/fossology/docker-entrypoint.sh

echo "Starnting apache..."
exec /usr/sbin/apache2ctl -D FOREGROUND
