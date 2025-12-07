<?php
/**
 * Test Database Management
 *
 * Admin page to manage the test database:
 * - Detect schema differences between live and test databases
 * - Copy live database to test database
 *
 * Version: 1.00
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$session = SessionControl::get_instance();
$session->check_permission(10); // Superadmin only

$settings = Globalvars::get_instance();
$page = new AdminPage();

// Get database names from config
$live_db = $settings->get_setting('dbname');
$test_db = $settings->get_setting('dbname_test');
$db_user = $settings->get_setting('dbusername');

// Handle actions
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$message = '';
$message_type = '';

if ($action === 'copy_live_to_test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Safety check: ensure test_db is different from live_db and contains "test"
    if ($test_db === $live_db) {
        $message = 'SAFETY BLOCK: Test database name is the same as live database. Aborting.';
        $message_type = 'danger';
    } elseif (strpos($test_db, 'test') === false && strpos($test_db, '_test') === false) {
        $message = 'SAFETY BLOCK: Test database name does not contain "test". Aborting.';
        $message_type = 'danger';
    } else {
        try {
            $result = copyLiveToTest($live_db, $test_db, $db_user);
            $message = $result['message'];
            $message_type = $result['success'] ? 'success' : 'danger';
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Get schema comparison
$schema_diff = compareSchemas($live_db, $test_db, $db_user);

/**
 * Compare schemas between two databases
 */
function compareSchemas($live_db, $test_db, $db_user) {
    $diff = [
        'live_only_tables' => [],
        'test_only_tables' => [],
        'column_differences' => [],
        'is_in_sync' => true
    ];

    try {
        // Get live database tables and columns
        $live_schema = getDatabaseSchema($live_db, $db_user);
        $test_schema = getDatabaseSchema($test_db, $db_user);

        if ($live_schema === false || $test_schema === false) {
            $diff['error'] = 'Could not connect to one or both databases';
            $diff['is_in_sync'] = false;
            return $diff;
        }

        // Find tables only in live
        $diff['live_only_tables'] = array_diff(array_keys($live_schema), array_keys($test_schema));

        // Find tables only in test
        $diff['test_only_tables'] = array_diff(array_keys($test_schema), array_keys($live_schema));

        // Compare columns in shared tables
        $shared_tables = array_intersect(array_keys($live_schema), array_keys($test_schema));
        foreach ($shared_tables as $table) {
            $live_columns = $live_schema[$table];
            $test_columns = $test_schema[$table];

            $live_only_cols = array_diff($live_columns, $test_columns);
            $test_only_cols = array_diff($test_columns, $live_columns);

            if (!empty($live_only_cols) || !empty($test_only_cols)) {
                $diff['column_differences'][$table] = [
                    'live_only' => $live_only_cols,
                    'test_only' => $test_only_cols
                ];
            }
        }

        // Determine if in sync
        if (!empty($diff['live_only_tables']) ||
            !empty($diff['test_only_tables']) ||
            !empty($diff['column_differences'])) {
            $diff['is_in_sync'] = false;
        }

    } catch (Exception $e) {
        $diff['error'] = $e->getMessage();
        $diff['is_in_sync'] = false;
    }

    return $diff;
}

/**
 * Get schema (tables and columns) for a database
 */
function getDatabaseSchema($dbname, $db_user) {
    $settings = Globalvars::get_instance();
    $password = $settings->get_setting('dbpassword');

    try {
        $pdo = new PDO(
            "pgsql:host=localhost;port=5432;dbname={$dbname}",
            $db_user,
            $password
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Get all tables
        $sql = "SELECT table_name FROM information_schema.tables
                WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
                ORDER BY table_name";
        $stmt = $pdo->query($sql);
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $schema = [];
        foreach ($tables as $table) {
            // Get columns for each table
            $sql = "SELECT column_name FROM information_schema.columns
                    WHERE table_schema = 'public' AND table_name = ?
                    ORDER BY ordinal_position";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$table]);
            $schema[$table] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        return $schema;

    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Copy live database to test database using pg_dump/psql
 */
function copyLiveToTest($live_db, $test_db, $db_user) {
    $settings = Globalvars::get_instance();
    $password = $settings->get_setting('dbpassword');

    // Set password for PostgreSQL commands
    putenv("PGPASSWORD={$password}");

    $output = [];
    $return_var = 0;

    // Step 1: Terminate connections to test database
    $terminate_sql = "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$test_db}' AND pid <> pg_backend_pid();";
    exec("psql -U {$db_user} -d postgres -c \"{$terminate_sql}\" 2>&1", $output, $return_var);

    // Step 2: Drop test database
    exec("dropdb -U {$db_user} --if-exists {$test_db} 2>&1", $output, $return_var);
    if ($return_var !== 0) {
        // Try again after a brief pause
        sleep(2);
        exec("dropdb -U {$db_user} --if-exists {$test_db} 2>&1", $output, $return_var);
    }

    // Step 3: Create empty test database
    exec("createdb -U {$db_user} {$test_db} 2>&1", $output, $return_var);
    if ($return_var !== 0) {
        putenv("PGPASSWORD");
        return [
            'success' => false,
            'message' => "Failed to create test database. Output: " . implode("\n", $output)
        ];
    }

    // Step 4: Dump live and restore to test (works even with active connections on live)
    $dump_restore_cmd = "pg_dump -U {$db_user} {$live_db} | psql -U {$db_user} -d {$test_db} 2>&1";
    exec($dump_restore_cmd, $output, $return_var);

    // Clear password from environment
    putenv("PGPASSWORD");

    if ($return_var === 0) {
        return [
            'success' => true,
            'message' => "Successfully copied '{$live_db}' to '{$test_db}'. Test database is now in sync with live."
        ];
    } else {
        return [
            'success' => false,
            'message' => "Failed to copy database. Output: " . implode("\n", $output)
        ];
    }
}

// Start output
$page->admin_header(array(
    'menu-id' => NULL,
    'page_title' => 'Test Database Management',
    'readable_title' => 'Test Database Management',
    'breadcrumbs' => array(
        'Test Database' => '',
    ),
    'session' => $session,
));
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <p class="text-muted">Manage the test database used for automated testing.</p>

            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Database Info -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-info-circle"></i> Database Configuration</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Live Database</h6>
                            <p class="mb-0"><code><?php echo htmlspecialchars($live_db); ?></code></p>
                        </div>
                        <div class="col-md-6">
                            <h6>Test Database</h6>
                            <p class="mb-0"><code><?php echo htmlspecialchars($test_db); ?></code></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Schema Comparison -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-code-compare"></i> Schema Comparison
                        <?php if ($schema_diff['is_in_sync']): ?>
                        <span class="badge bg-success ms-2">In Sync</span>
                        <?php else: ?>
                        <span class="badge bg-warning ms-2">Out of Sync</span>
                        <?php endif; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (isset($schema_diff['error'])): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($schema_diff['error']); ?>
                    </div>

                    <?php elseif ($schema_diff['is_in_sync']): ?>
                    <div class="alert alert-success mb-0">
                        <i class="fas fa-check-circle"></i>
                        Test database schema matches live database. No action needed.
                    </div>

                    <?php else: ?>

                    <?php if (!empty($schema_diff['live_only_tables'])): ?>
                    <h6 class="text-danger"><i class="fas fa-plus-circle"></i> Tables missing from test database:</h6>
                    <ul class="mb-3">
                        <?php foreach ($schema_diff['live_only_tables'] as $table): ?>
                        <li><code><?php echo htmlspecialchars($table); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>

                    <?php if (!empty($schema_diff['test_only_tables'])): ?>
                    <h6 class="text-warning"><i class="fas fa-minus-circle"></i> Tables only in test database (will be removed):</h6>
                    <ul class="mb-3">
                        <?php foreach ($schema_diff['test_only_tables'] as $table): ?>
                        <li><code><?php echo htmlspecialchars($table); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>

                    <?php if (!empty($schema_diff['column_differences'])): ?>
                    <h6 class="text-danger"><i class="fas fa-columns"></i> Column differences:</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>Table</th>
                                    <th>Missing in Test</th>
                                    <th>Extra in Test</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($schema_diff['column_differences'] as $table => $cols): ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($table); ?></code></td>
                                    <td>
                                        <?php if (!empty($cols['live_only'])): ?>
                                        <span class="text-danger">
                                            <?php echo htmlspecialchars(implode(', ', $cols['live_only'])); ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($cols['test_only'])): ?>
                                        <span class="text-warning">
                                            <?php echo htmlspecialchars(implode(', ', $cols['test_only'])); ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <?php endif; ?>
                </div>
            </div>

            <!-- Actions -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-tools"></i> Actions</h5>
                </div>
                <div class="card-body">
                    <form method="POST" onsubmit="return confirm('This will DROP the test database and replace it with a copy of the live database. All test data will be lost. Continue?');">
                        <input type="hidden" name="action" value="copy_live_to_test">

                        <div class="mb-3">
                            <h6>Copy Live Database to Test</h6>
                            <p class="text-muted">
                                This will:
                            </p>
                            <ol class="text-muted">
                                <li>Terminate all connections to the test database</li>
                                <li>Drop the test database entirely</li>
                                <li>Create a fresh copy of the live database as the test database</li>
                            </ol>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Warning:</strong> All data in the test database will be permanently deleted.
                            </div>
                        </div>

                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-copy"></i> Copy Live to Test Database
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<?php
$page->admin_footer();
?>
