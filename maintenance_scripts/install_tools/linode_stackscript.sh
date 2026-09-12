#!/usr/bin/env bash
#VERSION 1.8 - Nothing here sets a service up any more: the DNS token, the
#               sending key and the bucket are handed to install.sh in the
#               environment, and _site_init.sh does the work for every install
#               path alike. The closing summary reads the outcomes _site_init.sh
#               recorded (config/install_services.txt).
#VERSION 1.7 - Two services the deploy form may name are set up during the
#               install and found done in the setup wizard: a sending key
#               configures email (provider, From address, the owner's mailbox,
#               the domain registered at the provider, the mail records
#               published through the kept DNS token, the provider asked to
#               verify) and a bucket becomes the scheduled backup target after
#               a connection test. Both optional; both leave the wizard to ask
#               when absent or when they fail, and the closing summary says
#               which. The recovery key and the delivery proof remain the
#               owner's, in the wizard.
#VERSION 1.6 - The DNS step's outcome is a fact the rest of the install reads,
#               not a line that scrolls past. A refusal that waiting will not
#               change (a zone another account holds, a token without the
#               scope) is reported as DNS setup FAILED in the closing summary
#               and handed to install.sh, which says the same instead of
#               "point it here whenever you are ready".
#VERSION 1.5 - An existing A record is updated, not duplicated: a second one
#               round-robins the domain between the old server and this one. A
#               zone Linode refuses to create says what is actually wrong -- it
#               exists but this token cannot see it -- rather than leaving the
#               deployer to guess why the domain still points at its old
#               address. Zone and record are found by exact-match query, and
#               every id is read only from an object that matches on name. A
#               name that already round-robins is reduced to the one record
#               just repointed.
#VERSION 1.4 - The deploy-form password is the one the owner keeps; no forced change.
#VERSION 1.3 - First-boot install driver for the Linode StackScript path.
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
#                           owner chose it, so it is the password they keep:
#                           no change is forced at first sign-in.
#   JOINERY_ADMIN_EMAIL     required — the admin account's address, so the
#                           account is recoverable by email from the start.
#   JOINERY_DOMAIN          optional — blank means the site comes up on the
#                           instance's IP, which install.sh detects on its own.
#   JOINERY_SSH_KEY         optional — placed in root's authorized_keys before
#                           server setup, which then mirrors it to user1 with
#                           sudo and hardens root login off. Blank leaves root
#                           access exactly as the provider configured it.
#   JOINERY_LINODE_TOKEN    optional — a Linode API token with the Domains
#                           Read/Write scope, used to create the zone (when
#                           the account holds none) and the A record, so the
#                           first certificate attempt succeeds instead of
#                           waiting on the retry timer. Never printed. A token
#                           that proved usable is then sealed into the site
#                           (utils/install_dns_credential.php) for the setup
#                           wizard, whose email step uses it once to add the
#                           mail records and deletes it.
#   JOINERY_INSTALL_BUNDLE  optional — plugin bundle name, default personal.
#   JOINERY_MAIL_API_KEY    optional — an API key at the sending provider.
#                           With it, email is set up during the install
#                           (utils/install_mail_provider.php): the From
#                           address is derived from the admin address on the
#                           site's domain, the owner's mailbox is provisioned
#                           for it, the domain is registered at the provider,
#                           and its mail records are published through the
#                           kept DNS token. The wizard then opens on the
#                           delivery proof, or on a DNS wait. Never printed.
#   JOINERY_MAIL_PROVIDER   optional — which provider the key belongs to
#                           (default smtp2go, the one the quickstart uses).
#   JOINERY_BACKUP_BUCKET   optional — a bucket for backups. With the two keys
#   JOINERY_BACKUP_KEY_ID     below it becomes the scheduled backup target
#   JOINERY_BACKUP_KEY        after a connection test
#                           (utils/install_backup_target.php). The recovery
#                           key is a secret shown once to a human and stays
#                           the wizard's. The secret key is never printed.
#   JOINERY_BACKUP_PROVIDER optional — b2 (default), s3 or linode.
#   JOINERY_BACKUP_REGION   optional — region for s3 and linode buckets.
#
# Neither service is a condition of the install. A key the provider rejects
# or a bucket that cannot be reached is reported in the closing summary and
# left for the setup wizard to ask for again; the site is installed either
# way. All of it is done by _site_init.sh, which takes the same inputs from
# any install path; this script only passes them on, in the environment.
#
# Failure is loud and immediate. A half-installed box that looks alive is worse
# than one that stopped and said why: the whole run is in the deployment log at
# /var/log/stackscript.log, and the remedy is to destroy the instance and
# redeploy with the offending field corrected.
#
# There is no OS check here. install.sh server hard-fails off the supported
# Ubuntu LTS releases a few lines down, and a second copy of that check would be
# a second place to update when the supported set changes. Nothing here names a
# release or a PHP version for the same reason — install.sh detects the PHP the
# release ships and derives every package, service and config path from it.

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
TOKEN_USABLE=false
# How the DNS step ended, for the closing summary and for install.sh's own.
# One of: skipped: ..., written: ..., failed: ... — "failed" is reserved for
# a refusal that waiting will not change, which is what makes it a failure
# rather than a record still propagating.
DNS_OUTCOME="skipped: no Linode token was supplied"
BUNDLE="${JOINERY_INSTALL_BUNDLE:-personal}"
MAIL_API_KEY="${JOINERY_MAIL_API_KEY:-}"
MAIL_PROVIDER="${JOINERY_MAIL_PROVIDER:-smtp2go}"
BACKUP_BUCKET="${JOINERY_BACKUP_BUCKET:-}"
BACKUP_KEY_ID="${JOINERY_BACKUP_KEY_ID:-}"
BACKUP_KEY="${JOINERY_BACKUP_KEY:-}"
BACKUP_PROVIDER="${JOINERY_BACKUP_PROVIDER:-b2}"
BACKUP_REGION="${JOINERY_BACKUP_REGION:-}"
# How each optional service ended, for the closing summary. One of
# skipped: ..., done: ..., failed: ... — "failed" means the wizard will ask
# for it again, and the summary says so.
MAIL_OUTCOME="skipped: no sending key was supplied"
BACKUP_OUTCOME="skipped: no backup bucket was supplied"

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
if [ -n "$MAIL_API_KEY" ]; then
    echo "Email:  set up during install ($MAIL_PROVIDER key supplied)"
fi
if [ -n "$BACKUP_BUCKET" ]; then
    echo "Backup: $BACKUP_PROVIDER bucket $BACKUP_BUCKET"
fi

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
        DNS_OUTCOME="failed: could not determine this instance's public IP"
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

        # Ask Linode for this one zone by name rather than scanning the whole
        # account. X-Filter matches server-side, so the reply holds that zone or
        # nothing and the id comes out of a single object — no pattern matching
        # against a list, and no page_size ceiling to fall off when an account
        # holds more zones than one page returns. Listing is also the scope
        # check: a token without Domains Read/Write gets a 401 here, which is a
        # different problem from "no zone yet" and is reported as one.
        zone_lookup() {
            LOOKUP_CODE=$(curl -s -o /tmp/joinery_dns_zones.json -w '%{http_code}' --max-time 15 \
                -H "Authorization: Bearer ${LINODE_TOKEN}" \
                -H "X-Filter: {\"domain\": \"${ZONE}\"}" \
                "https://api.linode.com/v4/domains" 2>/dev/null || echo 000)
            # The id is read only out of an object that names this zone.
            # X-Filter is the optimisation; this grep is the correctness check.
            # A filter Linode ignores or widens would otherwise hand back the
            # first zone in the account, and the A record below would be written
            # into somebody else's domain.
            ZONE_ID=$(grep -o "{[^{]*\"domain\": *\"${ZONE}\"[^}]*}" /tmp/joinery_dns_zones.json 2>/dev/null \
                | grep -o '"id": *[0-9]*' | head -1 | grep -o '[0-9]*' || true)
        }

        zone_lookup
        if [ "$LOOKUP_CODE" != "200" ]; then
            echo "Linode returned HTTP $LOOKUP_CODE listing zones — the token probably lacks the Domains Read/Write scope. Skipping DNS creation."
            DNS_OUTCOME="failed: Linode returned HTTP $LOOKUP_CODE listing zones; the token probably lacks the Domains Read/Write scope"
            echo "Point $DOMAIN at $PUBLIC_IP yourself; the certificate follows automatically."
            DOMAIN_ID=""
        else
            TOKEN_USABLE=true
            DOMAIN_ID="$ZONE_ID"
        fi
        LIST_CODE="$LOOKUP_CODE"
        # No zone means the deployer pointed the nameservers at Linode and
        # nothing else, which is exactly the quickstart's path. Create it,
        # so that pointing the nameservers here is the only DNS errand left.
        if [ "$LIST_CODE" = "200" ] && [ -z "$DOMAIN_ID" ]; then
            echo "No zone for '$ZONE' in this Linode account — creating one."
            CREATE_CODE=$(curl -s -o /tmp/joinery_dns_zone.json -w '%{http_code}' --max-time 15 \
                -X POST \
                -H "Authorization: Bearer ${LINODE_TOKEN}" \
                -H "Content-Type: application/json" \
                -d "{\"domain\":\"${ZONE}\",\"type\":\"master\",\"soa_email\":\"${ADMIN_EMAIL}\"}" \
                "https://api.linode.com/v4/domains" 2>/dev/null || echo 000)
            if [ "$CREATE_CODE" = "200" ]; then
                DOMAIN_ID=$(grep -o '"id": *[0-9]*' /tmp/joinery_dns_zone.json 2>/dev/null | head -1 | grep -o '[0-9]*' || true)
                echo "Zone created: $ZONE"
            else
                # Linode's zones are unique across the whole platform, so a 400
                # here is nearly always "already exists" — the zone is real and
                # this token cannot see it. Asking again would return exactly
                # what the lookup just returned, so name the cause instead.
                echo "Linode returned HTTP $CREATE_CODE creating the zone — it most likely exists already but is not visible to this token (another account, or a restricted user with no access to it)."
                DNS_OUTCOME="failed: the zone for $ZONE is not visible to this token (held by another Linode account, or by a restricted user with no access to it)"
                echo "Point $DOMAIN at $PUBLIC_IP wherever $ZONE is managed; the certificate follows automatically."
            fi
        fi
        if [ -n "$DOMAIN_ID" ]; then
            # An A record for this name may already exist and point elsewhere.
            # Adding a second one makes the zone round-robin between the old
            # address and this one, so the site answers for some visitors and
            # not others — worse than either address on its own. Update the
            # record in place when it is there; create one only when it is not.
            curl -s -o /tmp/joinery_dns_records.json --max-time 15 \
                -H "Authorization: Bearer ${LINODE_TOKEN}" \
                -H "X-Filter: {\"type\": \"A\", \"name\": \"${RECORD}\"}" \
                "https://api.linode.com/v4/domains/${DOMAIN_ID}/records" >/dev/null 2>&1 || true
            # Same guard as the zone: the id is taken only from an object
            # that is itself an A record with this exact name. Trusting the
            # filter alone would let a PUT repoint whatever record happened to
            # come back first — an NS or MX record aimed at the web server.
            RECORD_IDS=$(grep -o "{[^{]*\"type\": *\"A\"[^}]*}" /tmp/joinery_dns_records.json 2>/dev/null \
                | grep "\"name\": *\"${RECORD}\"" \
                | grep -o '"id": *[0-9]*' | grep -o '[0-9]*' || true)
            RECORD_ID=$(printf '%s\n' "$RECORD_IDS" | head -1)
            if [ -n "$RECORD_ID" ]; then
                DID="updated"; DOING="updating"
                HTTP_CODE=$(curl -s -o /tmp/joinery_dns_result.json -w '%{http_code}' --max-time 15 \
                    -X PUT \
                    -H "Authorization: Bearer ${LINODE_TOKEN}" \
                    -H "Content-Type: application/json" \
                    -d "{\"target\":\"${PUBLIC_IP}\",\"ttl_sec\":300}" \
                    "https://api.linode.com/v4/domains/${DOMAIN_ID}/records/${RECORD_ID}" 2>/dev/null || echo 000)
            else
                DID="created"; DOING="creating"
                HTTP_CODE=$(curl -s -o /tmp/joinery_dns_result.json -w '%{http_code}' --max-time 15 \
                    -X POST \
                    -H "Authorization: Bearer ${LINODE_TOKEN}" \
                    -H "Content-Type: application/json" \
                    -d "{\"type\":\"A\",\"name\":\"${RECORD}\",\"target\":\"${PUBLIC_IP}\",\"ttl_sec\":300}" \
                    "https://api.linode.com/v4/domains/${DOMAIN_ID}/records" 2>/dev/null || echo 000)
            fi
            if [ "$HTTP_CODE" = "200" ]; then
                echo "A record $DID: $DOMAIN -> $PUBLIC_IP"
                DNS_OUTCOME="written: $DOMAIN -> $PUBLIC_IP ($DID)"
                # A name that already round-robined keeps only the record just
                # repointed. Every leftover still names the old server, so the
                # domain would answer from both and the site would load for
                # some visitors and not others -- the same split this step
                # exists to prevent, arrived at from the other direction.
                # Read the status, not curl's exit code: without -f curl is
                # perfectly happy to return 0 on a 401 or a 404, and the loop
                # would report a zone reduced to one record while it still
                # round-robins. A refusal is named, with the id, so it can be
                # removed by hand.
                for extra_id in $(printf '%s\n' "$RECORD_IDS" | tail -n +2); do
                    DEL_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 \
                        -X DELETE \
                        -H "Authorization: Bearer ${LINODE_TOKEN}" \
                        "https://api.linode.com/v4/domains/${DOMAIN_ID}/records/${extra_id}" 2>/dev/null || echo 000)
                    if [ "$DEL_CODE" = "200" ]; then
                        echo "Removed a duplicate A record that still pointed at the old server."
                    else
                        echo "Linode returned HTTP $DEL_CODE deleting duplicate A record $extra_id — it is still there, and $DOMAIN will answer from two servers until it is removed."
                    fi
                done
                sleep 20
            else
                echo "Linode returned HTTP $HTTP_CODE $DOING the record — continuing without it."
                DNS_OUTCOME="failed: Linode returned HTTP $HTTP_CODE $DOING the A record"
            fi
        fi
        rm -f /tmp/joinery_dns_zones.json /tmp/joinery_dns_zone.json /tmp/joinery_dns_records.json /tmp/joinery_dns_result.json
    fi
elif [ -z "$DOMAIN" ]; then
    DNS_OUTCOME="skipped: no domain was supplied"
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
# The DNS step's outcome, so the closing summary install.sh prints tells the
# truth about it: a failed step is not "point it here whenever you are ready".
export JOINERY_DNS_OUTCOME="$DNS_OUTCOME"

# The three optional services, handed to _site_init.sh through the
# environment like everything else it reads. It seals the token for the
# wizard's one publish of the mail records, sets email up from the sending
# key (publishing those records through the token in the same pass), and
# points backups at the bucket — the same inputs and the same work on every
# install path, so nothing here does any of it.
#
# The token proved usable above, and the setup wizard needs it once more, a
# few minutes from now, for the mail records. Kept rather than pasted twice:
# it is used once and deleted. Only a token of the shape Linode issues is
# placed inside the JSON, since the value is interpolated into it.
if [ "$TOKEN_USABLE" = true ]; then
    case "$LINODE_TOKEN" in
        *[!A-Za-z0-9_-]*) TOKEN_USABLE=false ;;
    esac
fi
if [ "$TOKEN_USABLE" = true ]; then
    export JOINERY_DNS_CREDENTIAL="{\"driver\":\"linode\",\"credential\":{\"access_token\":\"${LINODE_TOKEN}\"}}"
fi
unset LINODE_TOKEN
LINODE_TOKEN=""
MAIL_API_KEY_SUPPLIED=""
if [ -n "$MAIL_API_KEY" ]; then
    MAIL_API_KEY_SUPPLIED=1
    export JOINERY_MAIL_API_KEY="$MAIL_API_KEY"
    export JOINERY_MAIL_PROVIDER="$MAIL_PROVIDER"
fi
if [ -n "$BACKUP_BUCKET" ]; then
    export JOINERY_BACKUP_BUCKET="$BACKUP_BUCKET"
    export JOINERY_BACKUP_KEY_ID="$BACKUP_KEY_ID"
    export JOINERY_BACKUP_KEY="$BACKUP_KEY"
    export JOINERY_BACKUP_PROVIDER="$BACKUP_PROVIDER"
    export JOINERY_BACKUP_REGION="$BACKUP_REGION"
fi

SITE_ARGS=(-y site --bare-metal "$SITENAME" -)
if [ -n "$DOMAIN" ]; then
    SITE_ARGS+=("$DOMAIN")
fi

"$SCRIPT_DIR/install.sh" "${SITE_ARGS[@]}" || fail "Site creation failed. See the output above."

unset JOINERY_ADMIN_PASSWORD
ADMIN_PASSWORD=""

unset JOINERY_MAIL_API_KEY JOINERY_BACKUP_KEY JOINERY_DNS_CREDENTIAL
MAIL_API_KEY=""
BACKUP_KEY=""

# What _site_init.sh did with the services it was handed, from the file it
# records them in; the summary below reads these.
SERVICES_FILE="/var/www/html/${SITENAME}/config/install_services.txt"
if [ -f "$SERVICES_FILE" ]; then
    MAIL_RECORDED=$(sed -n 's/^mail=//p' "$SERVICES_FILE" | head -1)
    BACKUP_RECORDED=$(sed -n 's/^backup=//p' "$SERVICES_FILE" | head -1)
    [ -n "$MAIL_RECORDED" ] && MAIL_OUTCOME="$MAIL_RECORDED"
    [ -n "$BACKUP_RECORDED" ] && BACKUP_OUTCOME="$BACKUP_RECORDED"
fi
if [ -n "$MAIL_API_KEY_SUPPLIED" ] && [ -z "${MAIL_RECORDED:-}" ]; then
    MAIL_OUTCOME="failed: the install recorded no outcome for it"
fi
if [ -n "$BACKUP_BUCKET" ] && [ -z "${BACKUP_RECORDED:-}" ]; then
    BACKUP_OUTCOME="failed: the install recorded no outcome for it"
fi

say "Joinery is installed"
SITE_HOST="$DOMAIN"
if [ -z "$SITE_HOST" ]; then
    SITE_HOST=$(hostname -I | awk '{print $1}')
fi
# Protocol deliberately unstated: install.sh has just reported whether a
# certificate was issued or deferred, and repeating a guess here would
# contradict it.
case "$DNS_OUTCOME" in
    failed:*)
        echo "DNS setup failed: ${DNS_OUTCOME#failed: }"
        echo "Nothing about that changes on its own. Point $DOMAIN at $PUBLIC_IP where its DNS is"
        echo "actually managed; the certificate then follows within a few minutes."
        echo ""
        ;;
    written:*)
        echo "DNS: ${DNS_OUTCOME#written: }"
        ;;
esac
echo "Sign in at: ${SITE_HOST}/login"
echo "Email:      $ADMIN_EMAIL"
echo "Password:   the one you entered on the deploy form"
echo ""
case "$MAIL_OUTCOME" in
    done:*)
        echo "Email:   ${MAIL_OUTCOME#done: }"
        echo "         The setup wizard will ask you to confirm a test message arrived."
        ;;
    failed:*)
        echo "Email:   NOT set up — ${MAIL_OUTCOME#failed: }"
        echo "         The setup wizard will ask for the key again."
        ;;
esac
case "$BACKUP_OUTCOME" in
    done:*)
        echo "Backups: ${BACKUP_OUTCOME#done: }"
        echo "         The setup wizard will ask you to create the recovery key that turns them on."
        ;;
    failed:*)
        echo "Backups: bucket NOT set — ${BACKUP_OUTCOME#failed: }"
        echo "         The setup wizard will ask for the bucket again."
        ;;
esac
case "$MAIL_OUTCOME" in
    done:*) ;;
    *)
        echo ""
        echo "First, set up email — password reset needs it, and a new site has no"
        echo "mail provider yet. Linode blocks outbound port 25, so a mail server on this"
        echo "instance will not deliver; name a provider under Settings, Email."
        ;;
esac
exit 0
