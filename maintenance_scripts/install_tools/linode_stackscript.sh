#!/usr/bin/env bash
#VERSION 1.0 - First-boot install driver for the Linode StackScript path.
#
# linode_stackscript.sh — turn a blank Linode into a running Joinery site.
#
# The StackScript hosted at Linode is a thin wrapper: it declares the deploy
# form's fields, exports them, fetches the release archive, and hands off to
# this script inside it. All of the actual logic lives here, in the repo, for
# two reasons. It ships with every release, so an instance created tomorrow
# runs what was published this morning with nothing to update on the Linode
# side. And once this becomes a Marketplace listing the wrapper lives in
# Akamai's repository, where every edit is a pull request — a wrapper that
# contains only a handoff never needs one.
#
# Nothing here is Linode-specific except the optional DNS record creation, so
# the same script drives any provider that can run a script at first boot.
#
# Inputs, all as environment variables (the wrapper exports them from the
# deploy form):
#
#   JOINERY_ADMIN_PASSWORD  required — the password the owner chose. Passed
#                           through to _site_init.sh, which uses it instead of
#                           generating one and writes no credentials file. The
#                           account still has to change it at first sign-in: a
#                           deploy-form value reaches the instance as an
#                           environment variable and can land in cloud-init
#                           logs on the box.
#   JOINERY_ADMIN_EMAIL     required — the admin account's address, so the
#                           account is recoverable by email from the start.
#   JOINERY_DOMAIN          optional — blank means the site comes up on the
#                           instance's IP, which install.sh detects on its own.
#   JOINERY_SSH_KEY         optional — placed in root's authorized_keys before
#                           server setup, which then mirrors it to user1 with
#                           sudo and hardens root login off. Blank leaves root
#                           access exactly as the provider configured it.
#   JOINERY_LINODE_TOKEN    optional — a Linode API token, used once to create
#                           the A record so the first certificate attempt
#                           succeeds instead of waiting on the retry timer.
#                           Never written to disk, never printed.
#   JOINERY_INSTALL_BUNDLE  optional — plugin bundle name, default personal.
#
# Failure is loud and immediate. A half-installed box that looks alive is worse
# than one that stopped and said why: the whole run is in the deployment log at
# /var/log/stackscript.log, and the remedy is to destroy the instance and
# redeploy with the offending field corrected.
#
# There is no OS check here. install.sh server hard-fails off Ubuntu 24.04 a
# few lines down, and a second copy of that check would be a second place to
# update when a newer LTS is supported.

set -euo pipefail
set +H

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

say()  { echo ""; echo "=== $* ==="; }
fail() { echo ""; echo "ERROR: $*" >&2; echo "Install stopped. Nothing further will run." >&2; exit 1; }

# ---------------------------------------------------------------------------
# Preflight
# ---------------------------------------------------------------------------

[ "$(id -u)" -eq 0 ] || fail "This script must run as root."

ADMIN_PASSWORD="${JOINERY_ADMIN_PASSWORD:-}"
ADMIN_EMAIL="${JOINERY_ADMIN_EMAIL:-}"
DOMAIN="${JOINERY_DOMAIN:-}"
SSH_KEY="${JOINERY_SSH_KEY:-}"
LINODE_TOKEN="${JOINERY_LINODE_TOKEN:-}"
BUNDLE="${JOINERY_INSTALL_BUNDLE:-personal}"

[ -n "$ADMIN_PASSWORD" ] || fail "No admin password was supplied. This field is required on the deploy form."
[ -n "$ADMIN_EMAIL" ]    || fail "No admin email was supplied. This field is required on the deploy form."

case "$ADMIN_EMAIL" in
    *@*.*) ;;
    *) fail "'$ADMIN_EMAIL' does not look like an email address." ;;
esac

[ -f "$SCRIPT_DIR/install.sh" ] || fail "install.sh is not next to this script ($SCRIPT_DIR). The release archive may be incomplete."

# Trim a trailing dot and a leading www. — people paste both, and neither is
# what the site should be installed as.
DOMAIN="${DOMAIN%.}"
DOMAIN="${DOMAIN#www.}"

# ---------------------------------------------------------------------------
# Derive the site name
# ---------------------------------------------------------------------------
#
# It names the web root and the Postgres database and is invisible to the
# deployer, so it is derived rather than asked. Postgres wants something that
# starts with a letter and holds no punctuation.

if [ -n "$DOMAIN" ]; then
    SITENAME=$(echo "$DOMAIN" | cut -d. -f1 | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9')
else
    SITENAME="joinery$(hostname | tr -cd 'a-z0-9' | tail -c 8)"
fi
case "$SITENAME" in
    ''|[0-9]*) SITENAME="joinery${SITENAME}" ;;
esac

say "Installing Joinery as $SITENAME"
if [ -n "$DOMAIN" ]; then
    echo "Domain: $DOMAIN"
else
    echo "Domain: none — the site comes up on this instance's IP address"
fi
echo "Admin:  $ADMIN_EMAIL"
echo "Bundle: $BUNDLE"

# ---------------------------------------------------------------------------
# SSH key
# ---------------------------------------------------------------------------
#
# Placed before server setup, because derive_ssh_access reads it: with a key
# here, install.sh mirrors it to user1 with passwordless sudo and then disables
# root SSH login. Without one it leaves root login alone, so omitting the field
# cannot lock anybody out.

if [ -n "$SSH_KEY" ]; then
    say "Installing the supplied SSH key"
    mkdir -p /root/.ssh
    chmod 700 /root/.ssh
    touch /root/.ssh/authorized_keys
    chmod 600 /root/.ssh/authorized_keys
    if ! grep -qF "$SSH_KEY" /root/.ssh/authorized_keys 2>/dev/null; then
        printf '%s\n' "$SSH_KEY" >> /root/.ssh/authorized_keys
    fi
    echo "Key installed for root; server setup will mirror it to user1."
fi

# ---------------------------------------------------------------------------
# DNS record (optional)
# ---------------------------------------------------------------------------
#
# Only reachable when the deployer's DNS is already at Linode and they supplied
# a token. It buys a certificate on the first attempt instead of on the retry
# timer's; everything works without it.
#
# The token is used here and nowhere else. It is never written to disk and
# never printed — the deployment log is readable by the deployer, and a token
# in it would outlive whatever it was needed for.

if [ -n "$LINODE_TOKEN" ] && [ -n "$DOMAIN" ]; then
    say "Creating the DNS record at Linode"

    PUBLIC_IP=$(curl -s --max-time 10 https://api.ipify.org 2>/dev/null || true)
    if [ -z "$PUBLIC_IP" ]; then
        echo "Could not determine this instance's public IP — skipping DNS creation."
    else
        # The zone is the registrable domain; anything to the left is the record
        # name. sub.example.com is an A record 'sub' in the zone example.com;
        # example.com itself is the zone's own record, which Linode names ''.
        LABEL_COUNT=$(echo "$DOMAIN" | tr '.' '\n' | wc -l)
        if [ "$LABEL_COUNT" -gt 2 ]; then
            ZONE=$(echo "$DOMAIN" | rev | cut -d. -f1-2 | rev)
            RECORD="${DOMAIN%.$ZONE}"
        else
            ZONE="$DOMAIN"
            RECORD=""
        fi

        DOMAIN_ID=$(curl -s --max-time 15 \
            -H "Authorization: Bearer ${LINODE_TOKEN}" \
            "https://api.linode.com/v4/domains" 2>/dev/null \
            | grep -o "{[^{]*\"domain\": *\"${ZONE}\"[^}]*}" \
            | grep -o '"id": *[0-9]*' | head -1 | grep -o '[0-9]*' || true)

        if [ -z "$DOMAIN_ID" ]; then
            echo "No zone for '$ZONE' in this Linode account — skipping."
            echo "Point $DOMAIN at $PUBLIC_IP yourself; the certificate follows automatically."
        else
            HTTP_CODE=$(curl -s -o /tmp/joinery_dns_result.json -w '%{http_code}' --max-time 15 \
                -X POST \
                -H "Authorization: Bearer ${LINODE_TOKEN}" \
                -H "Content-Type: application/json" \
                -d "{\"type\":\"A\",\"name\":\"${RECORD}\",\"target\":\"${PUBLIC_IP}\",\"ttl_sec\":300}" \
                "https://api.linode.com/v4/domains/${DOMAIN_ID}/records" 2>/dev/null || echo 000)

            if [ "$HTTP_CODE" = "200" ]; then
                echo "A record created: $DOMAIN -> $PUBLIC_IP"
                # Give the record a moment to be servable before install.sh's own
                # DNS check runs. Not waited on properly: if it is not ready the
                # install continues on HTTP and the retry timer finishes the job.
                sleep 20
            else
                echo "Linode returned HTTP $HTTP_CODE creating the record — continuing without it."
            fi
            rm -f /tmp/joinery_dns_result.json
        fi
    fi
    unset LINODE_TOKEN
fi

# ---------------------------------------------------------------------------
# Install
# ---------------------------------------------------------------------------

say "Preparing the server"
"$SCRIPT_DIR/install.sh" -y server || fail "Server setup failed. See the output above."

say "Creating the site"

# The password goes in through the environment, not the command line: arguments
# are visible in ps to every user on the box for the life of the process.
export JOINERY_ADMIN_PASSWORD="$ADMIN_PASSWORD"
export JOINERY_ADMIN_EMAIL="$ADMIN_EMAIL"
export JOINERY_INSTALL_BUNDLE="$BUNDLE"

SITE_ARGS=(-y site --bare-metal "$SITENAME" -)
if [ -n "$DOMAIN" ]; then
    SITE_ARGS+=("$DOMAIN")
fi

"$SCRIPT_DIR/install.sh" "${SITE_ARGS[@]}" || fail "Site creation failed. See the output above."

unset JOINERY_ADMIN_PASSWORD
ADMIN_PASSWORD=""

say "Joinery is installed"
SITE_HOST="$DOMAIN"
if [ -z "$SITE_HOST" ]; then
    SITE_HOST=$(hostname -I | awk '{print $1}')
fi
# Protocol deliberately unstated: install.sh has just reported whether a
# certificate was issued or deferred, and repeating a guess here would
# contradict it.
echo "Sign in at: ${SITE_HOST}/login"
echo "Email:      $ADMIN_EMAIL"
echo "Password:   the one you entered on the deploy form"
echo ""
echo "You will be asked to choose a new password at first sign-in."
echo "After that, set up email — password reset needs it, and a new site has no"
echo "mail provider yet. Linode blocks outbound port 25, so a mail server on this"
echo "instance will not deliver; name a provider under Settings, Email."
exit 0
