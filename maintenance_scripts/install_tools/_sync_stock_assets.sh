#!/bin/bash
# _sync_stock_assets.sh
# Sync missing stock themes and plugins from the upgrade server.
# Runs at container startup (CMD) after update_database, before Apache starts.
# Always exits 0 — a failed sync is non-fatal; Apache starts regardless.
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

# Download all is_stock items of a given type that are missing from the filesystem.
# Args: TYPE (plugins|themes)  TARGET_DIR  URL_TYPE_SUFFIX (&type=plugin or "")
sync_items() {
    local type="$1"
    local target_dir="$2"
    local url_suffix="$3"

    local list_json
    list_json=$(curl -sf --max-time 20 \
        "${UPGRADE_SOURCE}/utils/publish_theme?list=${type}" 2>/dev/null)
    if [ -z "$list_json" ]; then
        echo "[sync] Could not fetch ${type} list from ${UPGRADE_SOURCE} — skipping"
        return
    fi

    while IFS= read -r name; do
        # Skip non-stock items
        if ! echo "$list_json" | grep -A10 "\"name\".*\"${name}\"" | grep -q '"is_stock"[[:space:]]*:[[:space:]]*true'; then
            continue
        fi

        if [ -d "${target_dir}/${name}" ]; then
            continue  # already present
        fi

        echo "[sync] Downloading missing ${type%s}: ${name}"
        if curl -sfL --max-time 120 \
            "${UPGRADE_SOURCE}/utils/publish_theme?download=${name}${url_suffix}" \
            | tar xz -C "${target_dir}" 2>/dev/null \
            && [ -d "${target_dir}/${name}" ]; then
            echo "[sync] Installed ${name}"
        else
            echo "[sync] Failed to install ${name}"
        fi
    done < <(echo "$list_json" | grep -oP '"name"\s*:\s*"\K[^"]+')
}

sync_items "plugins" "${PUBLIC_HTML}/plugins" "&type=plugin"
sync_items "themes"  "${PUBLIC_HTML}/theme"   ""

echo "[sync] Stock asset sync complete"
exit 0
