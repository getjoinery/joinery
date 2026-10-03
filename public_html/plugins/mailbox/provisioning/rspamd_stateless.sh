#!/usr/bin/env bash
#
# rspamd_stateless.sh - the ONE rspamd configuration every joinery box writes
# (spam_learning_in_core.md § One rspamd configuration). Sourced, never
# run: provision_spam_scanner.sh (a deployment's own box) and provision_relay.sh
# (a relay) both source it and write the same files from it.
#
# Version: 1.0
#
# rspamd here is a header-stamping milter and nothing else:
#   - static rules only (phishing, malformed MIME, fingerprints, URL lists,
#     auth-aware scoring), maintained upstream;
#   - add_header only, NEVER reject or greylist (the reviewable-verdict model:
#     a wrong guess costs a click, never a lost message);
#   - Bayes OFF and no autolearn, no redis, no controller the app talks to.
#     Spam learning lives in the application (SpamBayes), in Postgres, where
#     it is taught from what users mark and visible to tests.
#
# The X-Spam header NAMES are the contract InboundEmailRouter::readSpamHeader()
# parses; keep them in step with that class's SPAM_*_HEADER constants.
#
# Interface for the caller:
#   RSPAMD_STATELESS_FILES   the local.d files this configuration owns
#   rspamd_stateless_render <file>
#                            print one file's content on stdout
#
# The caller writes each file with its own write-if-changed helper and reloads
# rspamd when anything changed. A local.d file NOT in the list is never touched,
# so a hand-written override elsewhere in local.d survives.

RSPAMD_STATELESS_FILES=(
    "milter_headers.conf"
    "actions.conf"
    "classifier-bayes.conf"
    "rbl.conf"
    "worker-proxy.inc"
)

rspamd_stateless_render() {
    case "$1" in
        milter_headers.conf)
            cat <<'RSPAMDHDR'
# joinery-managed - content spam header contract (InboundEmailRouter::readSpamHeader).
extended_spam_headers = true;
use = ["spam-header", "x-spam-status", "authentication-results"];
# spam-header adds 'X-Spam: Yes' on a spam verdict; x-spam-status adds
# 'X-Spam-Status: Yes|No, score=...' on every scan. The app reads the flag and
# the score from these.
RSPAMDHDR
            ;;
        actions.conf)
            cat <<'RSPAMDACT'
# joinery-managed - header-stamping only; rejection disabled (out of scope).
reject = null;
greylist = null;
add_header = 6;
RSPAMDACT
            ;;
        classifier-bayes.conf)
            cat <<'RSPAMDBAYES'
# joinery-managed - STATELESS: Bayes off. Spam learning lives in the
# application's own corpus, taught from what users mark. rspamd's stock
# statistic.conf still declares a redis-backed classifier, so this file stays
# (rather than being deleted) to switch it off without an error on every scan.
enabled = false;
autolearn = false;
RSPAMDBAYES
            ;;
        rbl.conf)
            cat <<'RSPAMDRBL'
# joinery-managed - DNS lists that cannot answer this box.
# NiX Spam (ix.dnsbl.manitu.net) was shut down; its zone has no nameservers, so
# every query waits out the DNS retransmits (timeout 1s x 5 = ~5s) and stalls
# the scan, up to the 8s task timeout.
# SURBL and URIBL refuse queries that arrive through a shared public resolver
# (the provider's DNS this box uses): every answer is a "blocked" code, never
# a verdict. Asking them only spends lookups.
rbls {
  nixspam {
    enabled = false;
  }
  "SURBL_MULTI" {
    enabled = false;
  }
  "SURBL_HASHBL" {
    enabled = false;
  }
  "URIBL_MULTI" {
    enabled = false;
  }
}
RSPAMDRBL
            ;;
        worker-proxy.inc)
            cat <<'RSPAMDPROXY'
# joinery-managed - Postfix milter (self-scan) on 11332.
milter = yes;
timeout = 120s;
upstream "local" {
  default = yes;
  self_scan = yes;
}
bind_socket = "*:11332";
RSPAMDPROXY
            ;;
        *)
            echo "rspamd_stateless_render: unknown file $1" >&2
            return 2
            ;;
    esac
}
