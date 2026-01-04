# Docker Setup Test Issues Report

**Test Date:** 2026-01-04
**Test Server:** 23.239.11.53 (Fresh Ubuntu 24.04 installation)
**Archive Used:** joinery-2-20.tar.gz
**Dockerfile:** Dockerfile.template v1.0

## Summary

The Docker installation was successful after manual intervention for three issues. The site is fully functional at http://23.239.11.53:8080/.

## Issues Encountered

### Issue 1: Composer Dependencies Not Installed

**Severity:** High
**Impact:** Site shows "Vendor autoload not found" error

**Symptoms:**
- Container starts successfully
- Database loads correctly
- Apache starts
- Site returns 500 error or blank page
- Error log shows: `composer autoload.php not found at: ../vendor/autoload.php`

**Root Cause:**
Bug in `ComposerValidator.php` at line 359. The `installIfNeeded()` method has a list of "fixable" errors that trigger automatic composer install:

```php
// Line 359 in includes/ComposerValidator.php
$installFixableErrors = ['composer.lock not found', 'Missing required packages', 'Vendor directory mismatch'];
```

When the vendor directory doesn't exist (fresh Docker install), `validateAutoloadExists()` returns this error:
```
Composer autoload.php not found at: ../vendor/autoload.php
```

This error is **NOT** in the `$installFixableErrors` list, so `installIfNeeded()` returns `false` without attempting to run `composer install`.

**Manual Fix Applied:**
```bash
docker exec dockertest bash -c "cd /var/www/html/dockertest/public_html && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev"
docker exec dockertest chown -R www-data:www-data /var/www/html/dockertest/vendor
```

**Code Fix Required:**
In `/includes/ComposerValidator.php`, line 359, add `'autoload.php not found'` to the fixable errors list:

```php
// BEFORE (line 359):
$installFixableErrors = ['composer.lock not found', 'Missing required packages', 'Vendor directory mismatch'];

// AFTER:
$installFixableErrors = ['composer.lock not found', 'Missing required packages', 'Vendor directory mismatch', 'autoload.php not found'];
```

**Full context of the fix** (lines 358-370 in `includes/ComposerValidator.php`):

```php
public function installIfNeeded() {
    // Run validation first
    if ($this->validate()) {
        return true; // Already valid, no install needed
    }

    // Check if the errors are composer-install-fixable
    // FIX: Added 'autoload.php not found' to handle fresh installs where vendor doesn't exist
    $installFixableErrors = ['composer.lock not found', 'Missing required packages', 'Vendor directory mismatch', 'autoload.php not found'];
    $canFix = false;

    foreach ($this->errors as $error) {
        foreach ($installFixableErrors as $fixableError) {
            if (strpos($error, $fixableError) !== false) {
                $canFix = true;
                break 2;
            }
        }
    }
    // ... rest of method
}
```

This fix ensures that when the vendor directory is missing entirely (as in a fresh Docker deployment), the validator recognizes this as a fixable condition and proceeds to run `composer install`.

---

### Issue 2: Apache Default Site Not Disabled

**Severity:** Medium
**Impact:** Site shows Apache default "It works!" page instead of Joinery

**Symptoms:**
- Container starts successfully
- Curl to localhost:8080 returns HTTP 200
- Browser shows "Apache2 Ubuntu Default Page: It works"
- Joinery site is not served

**Root Cause:**
The fix for this issue was already added to `new_account.sh` at line 325:

```bash
# Line 325 in new_account.sh
a2dissite 000-default.conf 2>/dev/null || true
```

**However, this line never executes** because of Issue 1. Here's the execution flow:

1. Container starts, `new_account.sh` runs
2. Lines 295-302: Composer install is attempted
3. **Composer install fails** (Issue 1 - missing error in fixable list)
4. **Script exits at line 301** with `exit 1`
5. Line 325 (`a2dissite 000-default.conf`) is **never reached**

The Dockerfile comment on line 39 states: _"Composer install and 000-default disable are handled by new_account.sh"_ - but both fail because the script exits early.

**Manual Fix Applied:**
```bash
docker exec dockertest a2dissite 000-default.conf
docker exec dockertest service apache2 reload
```

**Code Fix Required:**
This issue will be **automatically resolved** once Issue 1 is fixed. When `ComposerValidator.php` correctly recognizes "autoload.php not found" as a fixable error, `new_account.sh` will complete successfully and reach line 325.

**Alternative Fix (Defense in Depth):**
Add `a2dissite` to the Dockerfile BUILD phase (line 46) so it doesn't depend on `new_account.sh` completing:

```dockerfile
# Line 46 in Dockerfile.template - BEFORE:
    a2ensite ${SITENAME}.conf

# AFTER:
    a2dissite 000-default.conf 2>/dev/null || true && \
    a2ensite ${SITENAME}.conf
```

This ensures the default site is disabled during image build, regardless of what happens at runtime.

---

### Issue 3: PostgreSQL Password Not Set

**Severity:** Medium
**Impact:** Database authentication fails for remote connections

**Symptoms:**
- Database loads correctly
- Site works (uses local socket connection)
- External database tools cannot connect
- `psql -h localhost` fails with "password authentication failed"

**Root Cause:**
The password IS set during `server_setup.sh` (lines 307-325), but it's lost due to Docker volume mounting:

1. **During BUILD** (`RUN ./server_setup.sh`):
   - PostgreSQL installed, password set via `ALTER USER`
   - Password stored in `/var/lib/postgresql/` data files

2. **During RUN** (container start with volumes):
   - Volume `-v dockertest_postgres:/var/lib/postgresql` is mounted
   - On **first run**, the volume is **EMPTY**
   - Empty volume **overwrites** the entire data directory
   - PostgreSQL re-initializes fresh with **no password**

This is a fundamental Docker behavior - anything written during BUILD to a path that gets volume-mounted is lost when the container runs.

**Manual Fix Applied:**
```bash
# Temporarily allow trust authentication
docker exec dockertest bash -c "sed -i 's/md5$/trust/' /etc/postgresql/16/main/pg_hba.conf"
docker exec dockertest service postgresql reload

# Set the password
docker exec dockertest bash -c "psql -U postgres -c \"ALTER USER postgres PASSWORD 'DockerTest123!';\""

# Restore md5 authentication
docker exec dockertest bash -c "sed -i 's/trust$/md5/' /etc/postgresql/16/main/pg_hba.conf"
docker exec dockertest service postgresql reload
```

**Code Fix Required:**
Set the password on **first run only**. Once set, it persists in the PostgreSQL volume. Update `Dockerfile.template` CMD section (lines 51-59):

```dockerfile
# BEFORE (lines 51-59):
CMD service postgresql start && \
    sleep 3 && \
    export PGPASSWORD="${POSTGRES_PASSWORD}" && \
    ([ -f /var/www/html/${SITENAME}/config/Globalvars_site.php ] || \
        cd /var/www/html/${SITENAME}/maintenance_scripts && \
        ./new_account.sh ${SITENAME} ${DOMAIN_NAME} "*") && \
    php /var/www/html/${SITENAME}/public_html/utils/update_database.php 2>/dev/null || true && \
    apache2ctl -D FOREGROUND

# AFTER:
CMD service postgresql start && \
    sleep 3 && \
    export PGPASSWORD="${POSTGRES_PASSWORD}" && \
    PG_CONF="/etc/postgresql/16/main/pg_hba.conf" && \
    if [ ! -f /var/www/html/${SITENAME}/config/Globalvars_site.php ]; then \
        sed -i 's/local   all             postgres                                md5/local   all             postgres                                trust/' $PG_CONF && \
        service postgresql reload && \
        su -c "psql -c \"ALTER USER postgres PASSWORD '${POSTGRES_PASSWORD}';\"" postgres && \
        sed -i 's/local   all             postgres                                trust/local   all             postgres                                md5/' $PG_CONF && \
        service postgresql reload; \
    fi && \
    ([ -f /var/www/html/${SITENAME}/config/Globalvars_site.php ] || \
        cd /var/www/html/${SITENAME}/maintenance_scripts && \
        ./new_account.sh ${SITENAME} ${DOMAIN_NAME} "*") && \
    php /var/www/html/${SITENAME}/public_html/utils/update_database.php 2>/dev/null || true && \
    apache2ctl -D FOREGROUND
```

**Explanation of the fix:**
1. Start PostgreSQL and wait for it to be ready
2. Check if this is first run (config file doesn't exist)
3. **First run only:** Set password using trust auth temporarily, then restore md5
4. Password is stored in PostgreSQL data directory (persistent volume)
5. **On restart:** Password already exists in volume, no action needed

---

### Issue 4: Dockerfile.template Not Included in Archive

**Severity:** Medium
**Impact:** Users must manually obtain the Dockerfile to deploy with Docker

**Symptoms:**
- Extract `joinery-X-Y.tar.gz`
- No `Dockerfile` or `Dockerfile.template` in the archive
- Must manually copy from source server to proceed with Docker deployment

**Root Cause:**
The `publish_upgrade.php` script (lines 196-210) has an explicit list of maintenance files to include in the archive:

```php
$maintenance_files = [
    'server_setup.sh',
    'deploy.sh',
    'backup_database.sh',
    'restore_database.sh',
    'restore_project.sh',
    'copy_database.sh',
    'new_account.sh',
    'remove_account.sh',
    'fix_permissions.sh',
    'fix_postgres_auth.sh',
    'Globalvars_site_default.php',
    'default_virtualhost.conf',
    'virtualhost_update_script.sh'
];
```

`Dockerfile.template` is **not in this list**.

**Code Fix Required:**
In `/utils/publish_upgrade.php`, add `Dockerfile.template` to the `$maintenance_files` array at line 209:

```php
// BEFORE (lines 208-210):
    'default_virtualhost.conf',
    'virtualhost_update_script.sh'
];

// AFTER:
    'default_virtualhost.conf',
    'virtualhost_update_script.sh',
    'Dockerfile.template'
];
```

---

## Environment Details

### Server Specifications
- OS: Ubuntu 24.04.3 LTS
- Kernel: 6.8.0-71-generic
- Docker: 29.1.3

### Container Specifications
- Base Image: ubuntu:24.04
- Image Size: 2.68GB
- Services: Apache 2.4, PHP 8.3, PostgreSQL 16

### Successful Configuration
After manual fixes, the following works correctly:
- Home page loads at http://23.239.11.53:8080/
- Login page accessible at /login
- Database contains admin user: admin@example.com (permission level 10)
- All 10 persistent volumes mounted correctly
- PostgreSQL accessible via port 9080

## Test Results Summary

| Test | Status | Notes |
|------|--------|-------|
| Docker Installation | PASS | v29.1.3 installed successfully |
| Image Build | PASS | 2.68GB image built in ~5 minutes |
| Container Start | PASS | Container runs with all volumes |
| PostgreSQL Service | PASS | Starts automatically |
| Apache Service | PASS | Starts automatically |
| Database Restore | PASS | joinery-install.sql.gz loaded |
| Composer Install | FAIL* | Required manual intervention |
| Site Serving | FAIL* | Required disabling default site |
| Database Auth | FAIL* | Required manual password set |
| Site Functionality | PASS | After manual fixes |

*Issues documented above; site works after manual fixes.

## Recommendations

1. **Short-term:** Document the three manual steps in the installation guide
2. **Long-term:** Update the maintenance scripts to handle these cases automatically
3. **Testing:** Add automated tests to verify these steps complete successfully
