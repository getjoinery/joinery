#!/bin/bash
#
# _site_start.sh - a site container's start command (specs/site_container_engine.md D6).
#
# Version: 1.0 - the start command Dockerfile.template carried inline as a shell-form CMD,
#                moved here unchanged so the image runs it in exec form: Podman's builder
#                stores a shell-form CMD for /bin/sh whatever SHELL says, and this command
#                is bash. Copied into the image at build (/usr/local/sbin/joinery-site-start),
#                so it is the image's, as the inline command was, not the scripts volume's.
#
# On first run _site_init.sh creates the database and configures the site. Every
# variable is quoted, so a password with special characters survives; set +H
# keeps a ! in one from being taken for history.

set +H && \
    echo "Container starting: SITENAME=${SITENAME}, DOMAIN=${DOMAIN_NAME}" && \
    if [ -z "${SITENAME}" ]; then echo "FATAL: SITENAME is empty"; exit 1; fi && \
    if [ -z "${POSTGRES_PASSWORD}" ]; then echo "FATAL: POSTGRES_PASSWORD is empty"; exit 1; fi && \
    if [ ! -f "/var/www/html/${SITENAME}/public_html/VERSION" ]; then \
        echo "FATAL: no site code at /var/www/html/${SITENAME}/public_html"; \
        echo "       The code volume is mounted but empty. Seed it from a release"; \
        echo "       archive, or reinstall this site with install.sh."; \
        exit 1; \
    fi && \
    PG_IMAGE_MAJOR="$(ls -1 /usr/lib/postgresql 2>/dev/null | sort -n | tail -1)" && \
    PG_DATA_MAJORS="$(for v in /var/lib/postgresql/*/main/PG_VERSION; do [ -f "$v" ] && tr -cd '0-9' < "$v" && echo; done | sort -n | tr '\n' ' ')" && \
    if [ -n "${PG_DATA_MAJORS// /}" ] && [[ " ${PG_DATA_MAJORS}" != *" ${PG_IMAGE_MAJOR} "* ]]; then \
        echo "FATAL: the database on this volume is PostgreSQL ${PG_DATA_MAJORS% } and this image carries ${PG_IMAGE_MAJOR}."; \
        echo "       PostgreSQL was not started and the data is untouched. Move it with"; \
        echo "       maintenance_scripts/sysadmin_tools/rebase_site_container.sh on the host."; \
        exit 1; \
    fi && \
    PG_OWNER="$(id -u postgres):$(id -g postgres)" && \
    if [ "$(stat -c %u:%g /var/lib/postgresql)" != "${PG_OWNER}" ]; then \
        echo "The database volume belongs to another image's postgres user; handing it to this image's (${PG_OWNER})"; \
        chown -R postgres:postgres /var/lib/postgresql; \
    fi && \
    if [ "$(stat -c %g /var/log/postgresql)" != "$(id -g postgres)" ]; then \
        chown root:postgres /var/log/postgresql && chmod 1775 /var/log/postgresql && \
        find /var/log/postgresql -maxdepth 1 -type f -name '*.log' -exec chown postgres:adm {} +; \
    fi && \
    (bash "/var/www/html/${SITENAME}/maintenance_scripts/sysadmin_tools/tune_postgres_memory.sh" --no-restart --container || true) && \
    service postgresql start && \
    sleep 3 && \
    export PGPASSWORD="${POSTGRES_PASSWORD}" && \
    PG_CONF="$(ls -1d /etc/postgresql/*/main/pg_hba.conf 2>/dev/null | sort -V | tail -1)" && \
    if [ -z "${PG_CONF}" ]; then echo "FATAL: no pg_hba.conf under /etc/postgresql"; exit 1; fi && \
    if [ ! -f "/var/www/html/${SITENAME}/config/Globalvars_site.php" ]; then \
        PG_LOCAL_METHOD="$(awk '$1=="local" && $2=="all" && $3=="postgres" {print $4; exit}' "$PG_CONF")" && \
        if [ -z "${PG_LOCAL_METHOD}" ] || [ "${PG_LOCAL_METHOD}" = "trust" ]; then \
            echo "FATAL: no method to restore on the local postgres line in ${PG_CONF}"; exit 1; \
        fi && \
        sed -i -E 's/^(local[[:space:]]+all[[:space:]]+postgres[[:space:]]+)[A-Za-z0-9-]+/\1trust/' "$PG_CONF" && \
        service postgresql reload && \
        PW_SQL="${POSTGRES_PASSWORD//"'"/"''"}" && \
        printf "ALTER USER postgres PASSWORD '%s';\n" "$PW_SQL" | su -c "psql -q -v ON_ERROR_STOP=1" postgres && \
        sed -i -E "s/^(local[[:space:]]+all[[:space:]]+postgres[[:space:]]+)[A-Za-z0-9-]+/\1${PG_LOCAL_METHOD}/" "$PG_CONF" && \
        if grep -qE '^local[[:space:]]+all[[:space:]]+postgres[[:space:]]+trust' "$PG_CONF"; then \
            echo "FATAL: local postgres left on trust in ${PG_CONF}"; exit 1; \
        fi && \
        service postgresql reload; \
    fi && \
    ([ -f "/var/www/html/${SITENAME}/config/Globalvars_site.php" ] || \
        (cd "/var/www/html/${SITENAME}/maintenance_scripts/install_tools" && \
        JOINERY_DB_PASSWORD="${POSTGRES_PASSWORD}" ./_site_init.sh "${SITENAME}" "" "${DOMAIN_NAME}" --docker-mode)) && \
    chown -R root:root "/var/www/html/${SITENAME}/public_html" "/var/www/html/${SITENAME}/maintenance_scripts" && \
    ([ -d "/var/www/html/${SITENAME}/vendor" ] && chown -R root:root "/var/www/html/${SITENAME}/vendor" || true) && \
    chown root:root "/var/www/html/${SITENAME}" && chmod 755 "/var/www/html/${SITENAME}" && \
    ([ -f "/var/www/html/${SITENAME}/config/Globalvars_site.php" ] && \
        chown root:www-data "/var/www/html/${SITENAME}/config/Globalvars_site.php" && \
        chmod 640 "/var/www/html/${SITENAME}/config/Globalvars_site.php" || true) && \
    find "/var/www/html/${SITENAME}/public_html" "/var/www/html/${SITENAME}/maintenance_scripts" -type d -exec chmod 755 {} + && \
    find "/var/www/html/${SITENAME}/public_html" "/var/www/html/${SITENAME}/maintenance_scripts" -type f -name '*.sh' -exec chmod 755 {} + && \
    find "/var/www/html/${SITENAME}/public_html" "/var/www/html/${SITENAME}/maintenance_scripts" -type f -not -name '*.sh' -exec chmod 644 {} + && \
    bash "/var/www/html/${SITENAME}/maintenance_scripts/install_tools/_install_declared_dependencies.sh" "/var/www/html/${SITENAME}/public_html" || true && \
    php "/var/www/html/${SITENAME}/public_html/utils/update_database.php" 2>/dev/null || true && \
    bash "/var/www/html/${SITENAME}/maintenance_scripts/install_tools/_reconcile_upgradable_assets.sh" || true && \
    mkdir -p "/var/www/html/${SITENAME}/cache/static_pages" && \
    chown -R www-data:www-data "/var/www/html/${SITENAME}/cache" && \
    echo "* * * * * www-data php /var/www/html/${SITENAME}/public_html/utils/process_scheduled_tasks.php >> /var/www/html/${SITENAME}/logs/cron_scheduled_tasks.log 2>&1" > "/etc/cron.d/joinery-${SITENAME}" && \
    chmod 644 "/etc/cron.d/joinery-${SITENAME}" && \
    echo "" >> "/etc/cron.d/joinery-${SITENAME}" && \
    echo "0 2 * * * root /usr/sbin/logrotate /etc/logrotate.d/joinery-${SITENAME} > /dev/null 2>&1" > /etc/cron.d/logrotate-joinery && \
    chmod 644 /etc/cron.d/logrotate-joinery && \
    echo "" >> /etc/cron.d/logrotate-joinery && \
    sed "s|{{SITE_ROOT}}|/var/www/html/${SITENAME}|g" "/var/www/html/${SITENAME}/maintenance_scripts/install_tools/logrotate_joinery.conf" > "/etc/logrotate.d/joinery-${SITENAME}" && \
    chmod 644 "/etc/logrotate.d/joinery-${SITENAME}" && \
    service cron start && \
    FPM_SERVICE="$(basename "$(ls -1 /etc/init.d/php*-fpm 2>/dev/null | sort -V | tail -1)")" && \
    if [ -z "${FPM_SERVICE}" ] || [ "${FPM_SERVICE}" = "." ]; then echo "FATAL: no php-fpm init script under /etc/init.d"; exit 1; fi && \
    (bash "/var/www/html/${SITENAME}/maintenance_scripts/sysadmin_tools/tune_php_fpm.sh" --no-restart --container || true) && \
    service "${FPM_SERVICE}" start && \
    bash "/var/www/html/${SITENAME}/maintenance_scripts/install_tools/_plugin_installers_start.sh" "${SITENAME}" || true && \
    SUPERVISOR="/var/www/html/${SITENAME}/maintenance_scripts/install_tools/_site_supervisor.sh" && \
    if [ -f "${SUPERVISOR}" ]; then exec bash "${SUPERVISOR}" "${SITENAME}"; else exec apache2ctl -D FOREGROUND; fi
