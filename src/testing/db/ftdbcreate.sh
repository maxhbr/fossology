#!/bin/bash
# FOSSology dbcreate script
# Copyright (C) 2008 Hewlett-Packard Development Company, L.P.
#
# This script checks to see if the the fossology db exists and if not
# then creates it.
#
# @verion "$Id$"

echo "*** Setting up the FOSSology database ***"

# At some point this is where we could dynamically set the db password


if [ -n "$1" ]; then
  dbname=$1
else
  echo "Error! No DataBase Name supplied"
  exit 1
fi

# first check that postgres is running
su postgres -c 'echo \\q|psql' 2>/dev/null
if [ $? != 0 ]; then
   echo "ERROR: postgresql isn't running"
   exit 2
fi

# then check to see if the db already exists
su postgres -c 'psql -l' 2>/dev/null |grep -q $dbname
if [ $? = 0 ]; then
   echo "NOTE: $dbname database already exists, not creating"
   echo "*** Checking for plpgsql support ***"
   su postgres -c "createlang -l $dbname" 2>/dev/null |grep -q plpgsql
   if [ $? = 0 ]; then
      echo "NOTE: plpgsql already exists in $dbname database, good"
   else
      echo "NOTE: plpgsql doesn't exist, adding"
      su postgres -c "createlang plpgsql $dbname" 2>/dev/null
      if [ $? != 0 ]; then
         echo "ERROR: failed to add plpgsql to $dbname database"
      fi
   fi
else
   echo "sysconfdir from env is -> $SYSCONFDIR"
   echo "*** Initializing database ***"
   if [ -z $TESTROOT ]; then
      #TESTROOT=`pwd`;
      TESTROOT=$(mktemp -d)
      chmod 755 $TESTROOT
   fi
   echo "testroot is->$TESTROOT"
# change the name of the db in the sql file if a name was passed in
# or use the default name.
   didSed=
   if [ "$dbname" != "fosstest" ]; then
      fossSql=$TESTROOT/$$db.sql
      sed '1,$s/fosstest/'"$dbname"'/' < fosstestinit.sql > $fossSql
      didSed=1
      chmod 644 $fossSql
   else
      fossSql='fosstestinit.sql'
   fi

   echo "DB: before su to postgres"
   su postgres -c "psql < $fossSql" 2>/dev/null
   if [ $? != 0 ] ; then
      echo "ERROR: Database failed during configuration."
      clean up
      if [ -n "$didSed" ]; then
         rm $fossSql
         rmdir $TESTROOT
      fi
      exit 3
   fi
   #clean up
   if [ -n "$didSed" ]; then
     rm $fossSql
     rmdir $TESTROOT
   fi
fi
