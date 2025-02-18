#!/usr/bin/env bash
#Version 1.01

if [ "$1" == "" ]
then
echo "You must pass the database to backup as the first argument."
exit 1
fi

now=$(date +"%m_%d_%Y")

echo 'Enter postgres password:'
pg_dump -U postgres -W ${1}  > ${1}-${now}.sql
echo 'Backup of ' $1 'complete.'
exit 1
