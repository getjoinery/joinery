#!/usr/bin/env bash
# _db_ownership_sql.sh - Print the SQL that makes ROLE the owner of a database's
# public schema and every object in it (mail_access_hardening WP4).
#
# VERSION: 1.1 - the public schema itself changes hands (and the role is granted
#                USAGE, CREATE on it), and a sequence owned by a column is left
#                to follow its table (reviewer2 B1, B2)
# VERSION: 1.0
#
# Usage: _db_ownership_sql.sh ROLE        (prints SQL on stdout; connects to nothing)
#
# Run the output inside the database whose objects are to change hands, as a
# role that may give them away: the superuser, or their current owner when it
# is a member of ROLE. Covered, in this order: the public schema (a schema the
# superuser owns by name, as a dump made before the role existed leaves it,
# would otherwise refuse the role every query: "permission denied for schema
# public"); tables, partitioned and foreign tables, stand-alone sequences,
# views and materialized views; functions and procedures; the stand-alone
# types. Indexes follow their tables, and so does a sequence a column owns
# (serial, identity): ALTER SEQUENCE refuses one that is linked to a table,
# ALTER TABLE carries it, so those are left out of the sequence loop. Anything
# ROLE already owns is left alone, so running it twice changes nothing.
#
# This is deliberately NOT `REASSIGN OWNED BY postgres TO role`: that statement
# moves everything the superuser owns across the whole server, every other
# database, template1 and postgres included.
#
# Used by site_db_role.sh (a site's own database and its test database) and by
# restore_database.sh after a load (a dump made before the role existed says
# OWNER TO postgres on every object). One place, so the two cannot drift.

set -euo pipefail

ROLE="${1:-}"
if [[ ! "$ROLE" =~ ^[a-z_][a-z0-9_]{0,62}$ ]]; then
    echo "usage: _db_ownership_sql.sh ROLE   (a role name: lower-case letters, digits, underscores)" >&2
    exit 1
fi

# The role name is validated above and is the one thing substituted into the
# block; everything else is read from the catalogs as the block runs.
cat <<SQL
DO \$joinery_ownership\$
DECLARE
    r record;
BEGIN
    -- The schema first: a new owner must hold CREATE on the schema its tables
    -- live in. pg_database_owner (the default on PostgreSQL 15 and later) is
    -- whoever owns the database and follows ALTER DATABASE ... OWNER TO.
    IF EXISTS (SELECT 1 FROM pg_namespace
                WHERE nspname = 'public'
                  AND pg_get_userbyid(nspowner) NOT IN ('${ROLE}', 'pg_database_owner')) THEN
        EXECUTE format('ALTER SCHEMA public OWNER TO %I', '${ROLE}');
    END IF;
    EXECUTE format('GRANT USAGE, CREATE ON SCHEMA public TO %I', '${ROLE}');

    FOR r IN
        SELECT c.relkind, c.oid::regclass AS name
          FROM pg_class c
          JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = 'public'
           AND c.relkind IN ('r', 'p', 'S', 'v', 'm', 'f')
           AND pg_get_userbyid(c.relowner) <> '${ROLE}'
           -- a sequence a column owns follows its table
           AND NOT EXISTS (SELECT 1 FROM pg_depend d
                            WHERE d.classid = 'pg_class'::regclass AND d.objid = c.oid
                              AND d.refclassid = 'pg_class'::regclass AND d.deptype IN ('a', 'i'))
    LOOP
        CASE r.relkind
            WHEN 'S' THEN EXECUTE format('ALTER SEQUENCE %s OWNER TO %I', r.name, '${ROLE}');
            WHEN 'v' THEN EXECUTE format('ALTER VIEW %s OWNER TO %I', r.name, '${ROLE}');
            WHEN 'm' THEN EXECUTE format('ALTER MATERIALIZED VIEW %s OWNER TO %I', r.name, '${ROLE}');
            WHEN 'f' THEN EXECUTE format('ALTER FOREIGN TABLE %s OWNER TO %I', r.name, '${ROLE}');
            ELSE          EXECUTE format('ALTER TABLE %s OWNER TO %I', r.name, '${ROLE}');
        END CASE;
    END LOOP;

    FOR r IN
        SELECT p.oid::regprocedure AS name, p.prokind
          FROM pg_proc p
          JOIN pg_namespace n ON n.oid = p.pronamespace
         WHERE n.nspname = 'public'
           AND pg_get_userbyid(p.proowner) <> '${ROLE}'
    LOOP
        EXECUTE format('ALTER %s %s OWNER TO %I',
            CASE WHEN r.prokind = 'p' THEN 'PROCEDURE' WHEN r.prokind = 'a' THEN 'AGGREGATE' ELSE 'FUNCTION' END,
            r.name, '${ROLE}');
    END LOOP;

    -- Stand-alone types (enums, domains, composites): a table's own row type
    -- follows its table and is left out here.
    FOR r IN
        SELECT t.oid::regtype AS name
          FROM pg_type t
          JOIN pg_namespace n ON n.oid = t.typnamespace
         WHERE n.nspname = 'public'
           AND t.typtype IN ('e', 'd', 'c')
           AND NOT EXISTS (SELECT 1 FROM pg_class c WHERE c.reltype = t.oid)
           AND pg_get_userbyid(t.typowner) <> '${ROLE}'
    LOOP
        EXECUTE format('ALTER TYPE %s OWNER TO %I', r.name, '${ROLE}');
    END LOOP;
END
\$joinery_ownership\$;
SQL
