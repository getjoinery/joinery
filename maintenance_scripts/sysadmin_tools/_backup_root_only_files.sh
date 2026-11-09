# _backup_root_only_files.sh - sourced, never run: the config/ files readable by root only, on purpose.
# Version: 1.1.0 - no archive carries them, a root run's included: every archive is sealed to the
#                  site's own backup key, which the web user holds (shell_command_audit D3, B9)
#
# The signing keys on a publishing box, because the web user must never be able
# to sign code (agent_signing_key, specs/agent_local_queue_retirement.md, "G1 -
# decided") or a release statement (release_statement_key,
# release_transparency); the first-login password _site_init.sh leaves for the
# operator (admin_credentials.txt, 600 root:root, which stays on the node); and
# the database's postgres pair (db_admin.php, site_db_role.sh).
#
# No backup carries them, whoever runs it. Every archive is sealed to the
# site's own backup key (config/backup_site_key), which the web user can read,
# so a root-only file inside an archive would be a root-only file the web user
# can open. Each is left out by name, on every run; any other unreadable file
# still fails the run. A machine rebuilt from backup makes its own db_admin.php
# (site_db_role.sh); the two signing keys are kept offline by their owner.
# backup_files.sh and backup_project.sh both read this one list.

ROOT_ONLY_FILES=(agent_signing_key release_statement_key admin_credentials.txt db_admin.php)
