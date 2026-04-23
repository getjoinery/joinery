#!/bin/bash
# _sync_stock_assets.sh
# Sync missing stock themes and plugins from the upgrade server.
# Runs at container startup (CMD) after update_database, before Apache starts.
# Always exits 0 — a failed sync is non-fatal; Apache starts regardless.
#
# Only downloads items the site actually needs: stock themes/plugins that are
# registered in the site's own database (plg_plugins / thm_themes with is_stock=true)
# but missing from the filesystem. Non-stock items and items the site does not
# have registered are never downloaded — the base image ships with no site
# assets, so each per-site container pulls down exactly its own set.
#
# Requires env vars: SITENAME, POSTGRES_PASSWORD (set in Dockerfile.template)

PUBLIC_HTML="/var/www/html/${SITENAME}/public_html"

# Read upgrade_source from the site database
UPGRADE_SOURCE=$(PGPASSWORD="${POSTGRES_PASSWORD}" psql -U postgres -d "${SITENAME}" -t -A \
    -c "SELECT stg_value FROM stg_settings WHERE stg_name = 'upgrade_source'" 2>/dev/null \
    | tr -d '[:space:]')

if [ -z "$UPGRADE_SOURCE" ]; then
    echo "[sync] upgrade_source not set in database — skipping stock asset sync"
    exit 0
fi

echo "[sync] Syncing stock assets from ${UPGRADE_SOURCE}..."

# Download stock items registered in the local DB that are missing from the filesystem.
# Args: TYPE_LABEL (plugin|theme)  TARGET_DIR  SQL_QUERY  URL_TYPE_SUFFIX
sync_items() {
    local type_label="$1"
    local target_dir="$2"
    local sql_query="$3"
    local url_suffix="$4"

    local names
    names=$(PGPASSWORD="${POSTGRES_PASSWORD}" psql -U postgres -d "${SITENAME}" -t -A \
        -c "$sql_query" 2>/dev/null)

    if [ -z "$names" ]; then
        echo "[sync] No stock ${type_label}s registered in site database — nothing to fetch"
        return
    fi

    while IFS= read -r name; do
        [ -z "$name" ] && continue

        if [ -d "${target_dir}/${name}" ]; then
            continue  # already present
        fi

        echo "[sync] Downloading missing ${type_label}: ${name}"
        if curl -sfL --max-time 120 \
            "${UPGRADE_SOURCE}/utils/publish_theme?download=${name}${url_suffix}" \
            | tar xz -C "${target_dir}" 2>/dev/null \
            && [ -d "${target_dir}/${name}" ]; then
            echo "[sync] Installed ${name}"
        else
            echo "[sync] Failed to install ${name}"
        fi
    done <<< "$names"
}

sync_items "plugin" "${PUBLIC_HTML}/plugins" \
    "SELECT plg_name FROM plg_plugins WHERE plg_is_stock = true" \
    "&type=plugin"

sync_items "theme"  "${PUBLIC_HTML}/theme" \
    "SELECT thm_name FROM thm_themes WHERE thm_is_stock = true" \
    ""

echo "[sync] Stock asset sync complete"
exit 0
