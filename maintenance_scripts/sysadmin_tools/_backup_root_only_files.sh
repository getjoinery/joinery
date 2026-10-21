# _backup_root_only_files.sh - sourced, never run: the config/ files readable by root only, on purpose.
# Version: 1.0.0
#
# The signing keys on a publishing box, because the web user must never be able
# to sign code (agent_signing_key, specs/agent_local_queue_retirement.md, "G1 -
# decided") or a release statement (release_statement_key,
# release_transparency); and the first-login password _site_init.sh leaves for
# the operator (admin_credentials.txt, 600 root:root, which stays on the node).
#
# An unprivileged backup - the web user's own, a container site's - cannot carry
# them, and that is not the silent partial backup the doctrine forbids: each is
# one named file, left out on purpose and said out loud, and the root-run
# manager backup of the same site carries it. Any other unreadable file still
# fails the run. backup_files.sh and backup_project.sh both read this one list.

ROOT_ONLY_FILES=(agent_signing_key release_statement_key admin_credentials.txt)
