#!/usr/bin/env bash
#Version 1.01

if [ "$1" == "" ]
then
echo "Usage: ./restore_database.sh DB_NAME FILE_TO_RESTORE"
exit 1
fi

if [ "$2" == "" ]
then
echo "Usage: ./restore_database.sh DB_NAME FILE_TO_RESTORE"
exit 1
fi

echo "Enter postgres password"
if [ "$( psql -U postgres -XtAc "SELECT 1 FROM pg_database WHERE datname='$1'" )" = '1' ]
then
	now=$(date +"%m_%d_%Y")
	#BACKUP FIRST
	echo "Enter postgres password"
	pg_dump -U postgres -W ${1}  > ${1}-${now}-auto.sql
	echo 'Backup of ' $1 'complete.'
	dropdb $1 -U postgres
else
    echo "Database does not exist.  Creating a new one."
fi

echo "Enter postgres password"
createdb -T template0 $1 -U postgres
echo "Enter postgres password"
psql -U postgres -W -d $1 -f $2
echo 'Restore of ' $1 'complete.'
exit 1
