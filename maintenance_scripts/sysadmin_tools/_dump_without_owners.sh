#!/usr/bin/env bash
# _dump_without_owners.sh - Take the ownership statements out of a plain-SQL
# dump, in place (mail_access_hardening WP4).
#
# VERSION: 1.0
#
# Usage: _dump_without_owners.sh DUMP.sql        (prints the number of lines removed)
#
# A dump written by pg_dump says `ALTER ... OWNER TO role;` on every object. A
# role that is not the superuser and not a member of that role cannot run
# those lines, so a restore by the site's own role (the backup verifier's
# rehearsal) would stop at the first one under ON_ERROR_STOP. Without them the
# connecting role owns what it creates, which is what `pg_restore --no-owner`
# does for a custom-format dump; the restore then runs the ownership block
# (_db_ownership_sql.sh) for the site's role when there is one.
#
# Only statements are touched: the data inside a COPY block is passed through
# untouched however much it looks like SQL, because a row's text can read like
# a statement.

set -euo pipefail

DUMP="${1:-}"
if [[ -z "$DUMP" || ! -f "$DUMP" ]]; then
    echo "usage: _dump_without_owners.sh DUMP.sql" >&2
    exit 1
fi

TMP="${DUMP}.noowner.$$"
trap 'rm -f "$TMP"' EXIT

LC_ALL=C awk -v counter_file="${TMP}.count" '
    copy { print; if ($0 == "\\.") copy = 0; next }
    /^COPY .* FROM stdin;$/ { copy = 1; print; next }
    /^ALTER [A-Z ]+ .* OWNER TO .*;$/ { removed++; next }
    { print }
    END { print removed + 0 > counter_file }
' "$DUMP" > "$TMP"

REMOVED="$(cat "${TMP}.count" 2>/dev/null || echo 0)"
rm -f "${TMP}.count"
mv "$TMP" "$DUMP"
trap - EXIT
echo "$REMOVED"
