<?php
/**
 * Test Database Management
 *
 * Admin page to manage the test database:
 * - Detect schema differences between live and test databases
 * - Rebuild the test database from live (structure, or a full copy)
 *
 * Version: 2.1
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

// A test database is a development facility. On a production node it is a
// second copy of everyone's content, sitting on the same disk and swept into
// the same backups, serving suites that never run there. The `debug` setting is
// the platform's development/production discriminator (the same question
// /tests/ asks), and the refusal lives HERE, in the handler — hiding the menu
// entry is presentation, not a control.
$debug_on = (bool)$settings->get_setting('debug');

// Handle actions
$action = $_POST['action'] ?? '';
$message = '';
$message_type = '';

if ($action === 'copy_live_to_test' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$debug_on) {
        $message = 'Refused: rebuilding the test database is a development action, and the '
                 . 'debug setting is off on this site. A test database here would be a second '
                 . 'copy of live content on the same disk. Turn debug on in Settings if this '
                 . 'really is a development site.';
        $message_type = 'danger';
    } else {
        $mode = ($_POST['mode'] ?? '') === TestDatabaseHelper::MODE_FULL
            ? TestDatabaseHelper::MODE_FULL
            : TestDatabaseHelper::MODE_STRUCTURE;
        try {
            $result = TestDatabaseHelper::copy($mode);
            $message = $result['message'];
            $message_type = $result['success'] ? 'success' : 'danger';
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Get schema comparison (TestDatabaseHelper owns the comparison; it caches, and
// copy() clears that cache, so a rebuild above is reflected below.)
$schema_diff = TestDatabaseHelper::getSchemaComparison();

$live_size = TestDatabaseHelper::liveDatabaseSize();
$test_size = TestDatabaseHelper::testDatabaseSize();
$reference_tables = TestDatabaseHelper::referenceTables();

// Start output
$page->admin_header(array(
    'menu-id' => 'test-database',
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
            <p class="text-muted">Manage the test database used by the model test suites.</p>

            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="alert-close" aria-label="Close">&times;</button>
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
                            <p class="mb-0">
                                <code><?php echo htmlspecialchars($live_db); ?></code>
                                <?php if ($live_size !== false): ?>
                                <span class="text-muted">— <?php echo htmlspecialchars($live_size); ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <h6>Test Database</h6>
                            <p class="mb-0">
                                <code><?php echo htmlspecialchars($test_db); ?></code>
                                <?php if ($test_size !== false): ?>
                                <span class="text-muted">— <?php echo htmlspecialchars($test_size); ?></span>
                                <?php else: ?>
                                <span class="text-muted">— not provisioned</span>
                                <?php endif; ?>
                            </p>
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
                    <h5 class="mb-0"><i class="fas fa-tools"></i> Rebuild the test database</h5>
                </div>
                <div class="card-body">

                    <?php if (!$debug_on): ?>
                    <div class="alert alert-secondary mb-0">
                        <i class="fas fa-lock"></i>
                        Rebuilding is off because the <strong>debug</strong> setting is off, which is
                        how this platform tells a development site from a production one. A test
                        database on a production node is a second copy of live content on the same
                        disk, and the suites that use it do not run there.
                    </div>

                    <?php else: ?>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <h6>Structure only <span class="badge bg-success">recommended</span></h6>
                            <p class="text-muted mb-2">
                                Every table, column, index and foreign key from
                                <code><?php echo htmlspecialchars($live_db); ?></code>, with no content.
                                The model suites create their own rows, so this is all they need.
                            </p>
                            <p class="text-muted mb-2">
                                These tables are seeded with data, because the site cannot boot against
                                the copy without them:
                                <code><?php echo implode('</code>, <code>', array_map('htmlspecialchars', $reference_tables)); ?></code>.
                            </p>
                            <?php
                            echo AdminPage::action_button('Rebuild structure only', '/admin/admin_test_database', array(
                                'hidden' => array(
                                    'action' => 'copy_live_to_test',
                                    'mode'   => TestDatabaseHelper::MODE_STRUCTURE,
                                ),
                                'class'   => 'btn btn-primary',
                                'confirm' => 'This drops the test database and rebuilds it as an empty copy of the live schema. Continue?',
                            ));
                            ?>
                        </div>

                        <div class="col-md-6 mb-3">
                            <h6>Full copy, content and all</h6>
                            <p class="text-muted mb-2">
                                Every row of every table<?php if ($live_size !== false): ?> —
                                <strong><?php echo htmlspecialchars($live_size); ?></strong> right now<?php endif; ?>,
                                including mail, sealed content and member records, duplicated onto this
                                disk and into anything that backs it up.
                            </p>
                            <p class="text-muted mb-2">
                                Only worth it to reproduce something against real data. No test needs it.
                            </p>
                            <?php
                            echo AdminPage::action_button('Rebuild with a full copy', '/admin/admin_test_database', array(
                                'hidden' => array(
                                    'action' => 'copy_live_to_test',
                                    'mode'   => TestDatabaseHelper::MODE_FULL,
                                ),
                                'class'   => 'btn btn-danger',
                                'confirm' => 'This duplicates all live content'
                                    . ($live_size !== false ? ' (' . $live_size . ')' : '')
                                    . ' into the test database. Type COPY to confirm.',
                                'confirm_typed' => 'COPY',
                            ));
                            ?>
                        </div>
                    </div>

                    <p class="text-muted mb-0">
                        Either way the test database is dropped and replaced; anything in it is lost.
                        The rebuild restores into a staging database first, so a failed restore leaves
                        the existing copy untouched.
                    </p>
                    <?php endif; ?>

                </div>
            </div>

        </div>
    </div>
</div>

<?php
$page->admin_footer();
?>
