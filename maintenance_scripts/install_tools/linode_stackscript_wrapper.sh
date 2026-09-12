#!/bin/bash
#VERSION 1.9 - The sending key field names no provider: the installer tells
#              which provider issued the key (SMTP2GO, Mailgun and the other
#              single-key providers) and asks it.
#VERSION 1.8 - The SSH key field is gone from the form (the handoff still honours
#              JOINERY_SSH_KEY from the environment for a hand-run install), and
#              the domain field is labelled 'Your domain'. Region, plan and
#              firewall are the deployer's Create-form choices; a StackScript
#              cannot preset or hide them, and every field it declares renders.
#VERSION 1.7 - Four optional fields name two services the install sets up on
#              the deployer's behalf: a sending key (email) and a backup bucket
#              with its key pair (backups). Secrets are password-named so
#              Linode masks them; the handoff script knows them by their real
#              names, the same rename the API token already crosses through.
#VERSION 1.6 - The deployment log keeps its tail. Output went through a process
#              substitution, which nothing waits for, so the console session's
#              teardown killed tee with the closing summary still unread in the
#              pipe and every successful install looked like it stopped mid-step.
#
# THIS FILE IS NOT RUN FROM THE REPOSITORY.
#
# It is the body of the StackScript hosted at Linode - paste it into the
# StackScript editor, or, for the Marketplace listing, into the pull request
# against akamai-compute-marketplace/marketplace-apps. The copy here is the
# source of truth for what was pasted, so a change is reviewable and a
# deployment that misbehaves can be compared against what it should be.
#
# It contains no logic on purpose. Everything it could delegate, it delegates
# to linode_stackscript.sh inside the release archive, which ships with every
# publish and therefore improves without anyone touching Linode. Once this is a
# Marketplace listing, editing this file means a pull request and a review
# cycle - so the only things that belong here are the field declarations and
# the handoff, and the only change that should ever be needed is a new field.
#
# The field declarations below are a Linode platform feature: they render as a
# form on the Create page and arrive as environment variables. Three
# constraints shape them. Fields work only in bash scripts. A field is masked
# in the UI - and kept out of the deployment log - only if its name contains
# "password", which is why the API token is named the way it is. And the
# platform parses every occurrence of the opening tag anywhere in the file,
# including inside a comment like this one, so prose here must never spell it
# out - a mention with no name and label attached is rejected as a malformed
# field.
#
# A field is required when it declares no default, and optional when it
# declares one. The domain is required on purpose. A site with no domain runs
# on a bare IP: no certificate is possible, every canonical URL and link points
# at an address rather than a name, and moving to a real domain later means
# reconfiguring rather than deploying. That state is worth passing through
# during setup and not worth living in, so the form does not offer it -- and
# a deployer who reads "Your domain" with an empty box next to it cannot tell
# whether leaving it blank is allowed, which is how a placeholder that never
# resolves ends up naming somebody's site.
#
# Target Images: linode/ubuntu26.04 only (declared in the StackScript settings,
# not here). At least one is required and the deploy form offers only what is
# listed, so an incompatible image cannot be selected. install.sh hard-fails on
# anything else regardless.
#
# 24.04 was listed and then removed 2026-08-11: it is a supported install target
# but it had never been deployed through this path, and both failures found
# while gating this script were properties of the image rather than the code - a
# debconf answer corrupted at image build time, and a phased package update that
# lands on some machines and not others. Offering an image nobody has deployed
# means the first person to pick it does the testing. 26.04 is what the platform
# is built against (PHP 8.5, PostgreSQL 18) and what every gate ran on. A
# deployer who needs 24.04 can still install by hand, where they are at a
# terminal and can see what happens.

# <UDF name="JOINERY_ADMIN_EMAIL" label="Admin email address" example="you@example.com" />
# <UDF name="JOINERY_ADMIN_PASSWORD" label="Admin password" example="Choose a strong password" />
# <UDF name="JOINERY_DOMAIN" label="Your domain" example="example.com" />
# <UDF name="JOINERY_LINODE_TOKEN_PASSWORD" label="Linode API token with the Domains Read/Write scope (only if your DNS is at Linode)" default="" optional="true" />
# <UDF name="JOINERY_MAIL_API_KEY_PASSWORD" label="Email sending API key, e.g. from SMTP2GO or Mailgun (optional: sets up email during the install)" default="" optional="true" />
# <UDF name="JOINERY_BACKUP_BUCKET" label="Backblaze B2 bucket for backups (optional: sets up backups during the install)" default="" optional="true" />
# <UDF name="JOINERY_BACKUP_KEY_ID" label="Backblaze application key ID for that bucket" default="" optional="true" />
# <UDF name="JOINERY_BACKUP_KEY_PASSWORD" label="Backblaze application key for that bucket" default="" optional="true" />

set -euo pipefail

# The whole deploy prints through one real pipeline into the log, never through
# a process substitution. `exec > >(tee ...)` is not waited for: the script ends,
# the console session is stopped, getty hangs up its whole cgroup, and tee is
# killed with the last writes still sitting unread in the pipe. What dies there
# is the tail - the "Joinery is installed / sign in here" block, the one thing
# the deployer most needs - so a completed install reads in the log as one that
# stopped mid-step with no error. A pipeline IS waited for, so tee always drains
# before exit. Nothing here suspends errexit: pipefail makes the pipeline carry
# the handoff status, and errexit then exits this script with it.
run_install() {
    RELEASE_URL="https://getjoinery.com/utils/latest_release"
    WORKDIR="/opt/joinery-install"

    echo "=== Joinery first-boot install: $(date -u) ==="

    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq curl ca-certificates tar

    rm -rf "$WORKDIR"
    mkdir -p "$WORKDIR"

    echo "Fetching the current release..."
    if ! curl -sfL --max-time 300 "$RELEASE_URL" | tar xz -C "$WORKDIR"; then
        echo "ERROR: could not fetch the release archive from $RELEASE_URL" >&2
        exit 1
    fi

    HANDOFF="$WORKDIR/maintenance_scripts/install_tools/linode_stackscript.sh"
    if [ ! -f "$HANDOFF" ]; then
        echo "ERROR: the release archive does not contain linode_stackscript.sh" >&2
        exit 1
    fi

    # The UDF is named ..._PASSWORD so Linode masks it; the handoff script knows
    # it by its real name. This rename is the one translation the wrapper does.
    export JOINERY_LINODE_TOKEN="${JOINERY_LINODE_TOKEN_PASSWORD:-}"
    unset JOINERY_LINODE_TOKEN_PASSWORD
    export JOINERY_MAIL_API_KEY="${JOINERY_MAIL_API_KEY_PASSWORD:-}"
    unset JOINERY_MAIL_API_KEY_PASSWORD
    export JOINERY_BACKUP_KEY="${JOINERY_BACKUP_KEY_PASSWORD:-}"
    unset JOINERY_BACKUP_KEY_PASSWORD
    export JOINERY_BACKUP_BUCKET="${JOINERY_BACKUP_BUCKET:-}"
    export JOINERY_BACKUP_KEY_ID="${JOINERY_BACKUP_KEY_ID:-}"

    chmod +x "$HANDOFF"
    # Replaces this subshell, so the pipeline - and tee - outlive the handoff
    # and drain it to the end.
    exec bash "$HANDOFF"
}

run_install 2>&1 | tee -a /var/log/stackscript.log
