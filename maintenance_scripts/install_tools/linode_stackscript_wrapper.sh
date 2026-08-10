#!/bin/bash
#VERSION 1.2
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
# Target Images: linode/ubuntu26.04 and linode/ubuntu24.04 (declared in the
# StackScript settings, not here). At least one is required and the deploy form
# offers only what is listed, so an incompatible image cannot be selected.
# install.sh hard-fails on anything else regardless. 26.04 is the release the
# platform is built against (PHP 8.5, PostgreSQL 18); 24.04 stays listed so a
# deployer who has a reason to match an existing 24.04 box still can. The order
# they are listed in is not ours to choose - the platform stores the set sorted,
# so which one the deploy form offers first is its decision, and both have to
# work.

# <UDF name="JOINERY_ADMIN_EMAIL" label="Admin email address" example="you@example.com" />
# <UDF name="JOINERY_ADMIN_PASSWORD" label="Admin password" example="Choose a strong password" />
# <UDF name="JOINERY_DOMAIN" label="Site domain" default="" example="example.com" optional="true" />
# <UDF name="JOINERY_SSH_KEY" label="SSH public key for this server" default="" optional="true" />
# <UDF name="JOINERY_LINODE_TOKEN_PASSWORD" label="Linode API token (only if your DNS is at Linode)" default="" optional="true" />

set -euo pipefail

exec > >(tee -a /var/log/stackscript.log) 2>&1

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

# The UDF is named ..._PASSWORD so Linode masks it; the handoff script knows it
# by its real name. This rename is the one piece of translation the wrapper does.
export JOINERY_LINODE_TOKEN="${JOINERY_LINODE_TOKEN_PASSWORD:-}"
unset JOINERY_LINODE_TOKEN_PASSWORD

chmod +x "$HANDOFF"
exec bash "$HANDOFF"
