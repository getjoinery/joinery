# _host_files.sh - the one definition of three host files the platform tunes,
# sourced by install.sh (first install) and host_housekeeping.sh (every
# converge, and the repair of reclaim_managed_file). Functions only; sourcing
# it runs nothing.
#
# Version: 1.0 - specs/agent_recipes_and_vocabulary.md, "Host files": the files
#                install.sh wrote once move into a re-runnable installer, so
#                install day and repair day run the same code (requirement 5).
#
# Each writer takes the file's path, so a test can point it at a fixture.

# The event MPM, sized for a low-traffic site: PHP work happens in the FPM
# pool, so Apache's threads only shuttle requests and static files.
host_files_write_mpm_event() {
    cat > "$1" << 'MPM'
# event MPM
StartServers             2
MinSpareThreads         10
MaxSpareThreads         25
ThreadsPerChild         25
MaxRequestWorkers       50
MaxConnectionsPerChild  2000
MPM
    chmod 644 "$1"
}

# The systemd journal, capped so logs cannot fill a small disk.
host_files_write_journald_limit() {
    mkdir -p "$(dirname "$1")"
    cat > "$1" << 'JOURNAL'
[Journal]
SystemMaxUse=100M
JOURNAL
    chmod 644 "$1"
}

# The platform's PHP-FPM settings, applied to a php.ini in place: upload and
# post limits, execution time, memory, UTC (every stored time is UTC; display
# conversion is per user), and the PostgreSQL extensions.
host_files_tune_php_ini() {
    local ini="$1"
    sed -i 's/upload_max_filesize = .*/upload_max_filesize = 32M/' "$ini"
    sed -i 's/post_max_size = .*/post_max_size = 32M/' "$ini"
    sed -i 's/max_execution_time = .*/max_execution_time = 300/' "$ini"
    sed -i 's/memory_limit = .*/memory_limit = 128M/' "$ini"
    sed -i 's/;date.timezone =/date.timezone = UTC/' "$ini"
    sed -i 's/^;extension=pdo_pgsql/extension=pdo_pgsql/' "$ini"
    sed -i 's/^;extension=pgsql/extension=pgsql/' "$ini"
}
