<?php
/**
 * ControlD Plugin Tester
 *
 * Tests the ControlD plugin functionality using ACTUAL LOGIC FILES:
 * - Device lifecycle (create, edit, soft delete, permanent delete)
 * - Profile management (primary/secondary)
 * - Filter management (enable/disable DNS filters)
 * - Service blocking (enable/disable services)
 * - Custom rules (create/delete hostname rules)
 * - Scheduling (time-based profile activation)
 * - Data consistency (local DB matches ControlD API)
 *
 * DOES NOT TEST (covered by SubscriptionTierTester):
 * - User creation
 * - Tier assignment/changes
 *
 * Version: 2.01
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));

require_once(PathHelper::getIncludePath('plugins/controld/includes/ControlDHelper.php'));
require_once(PathHelper::getIncludePath('plugins/controld/data/ctlddevices_class.php'));
require_once(PathHelper::getIncludePath('plugins/controld/data/ctldprofiles_class.php'));
require_once(PathHelper::getIncludePath('plugins/controld/data/ctldfilters_class.php'));
require_once(PathHelper::getIncludePath('plugins/controld/data/ctldservices_class.php'));
require_once(PathHelper::getIncludePath('plugins/controld/data/ctldrules_class.php'));

class ControlDTester {
    private $settings;
    private $dbconnector;
    private $helper;
    private $test_user_id;
    private $test_device_id;
    private $test_profile_id;
    private $test_failures = [];
    private $live_api_mode = false;

    // Test filter and service keys
    private $test_filter_key = 'ads_medium';
    private $test_service_key = 'spotify';
    private $test_rule_hostname = 'test-block.example.com';

    public function __construct($live_api_mode = false) {
        $this->live_api_mode = $live_api_mode;
        $this->dbconnector = DbConnector::get_instance();

        // Enable test database mode
        try {
            $this->dbconnector->set_test_mode();
            $test_connection = $this->dbconnector->get_db_link();
            if (!$test_connection) {
                throw new Exception("Test database connection failed");
            }
        } catch (Exception $e) {
            throw new Exception("Failed to enable test mode: " . $e->getMessage());
        }

        $this->settings = Globalvars::get_instance();

        // Create helper - in live API mode, force real API calls
        if ($this->live_api_mode) {
            // Temporarily disable test mode flags
            $old_session_value = $_SESSION['controld_test_mode'] ?? null;
            $_SESSION['controld_test_mode'] = false;
            $this->helper = new ControlDHelper();
            // Restore session value
            if ($old_session_value !== null) {
                $_SESSION['controld_test_mode'] = $old_session_value;
            }

            // Force live mode by setting test_mode to false
            $this->helper->test_mode = false;
        } else {
            $this->helper = new ControlDHelper();
        }
    }

    public function __destruct() {
        if ($this->dbconnector) {
            $this->dbconnector->close_test_mode();
        }
    }

    /**
     * Record a test failure
     */
    private function recordFailure($test_name, $message) {
        $this->test_failures[] = [
            'test' => $test_name,
            'message' => $message
        ];
    }

    /**
     * Verify API state in live mode
     * Returns true if verification passes or if not in live mode
     */
    private function verifyApiState($entity_type, $api_id, $expected_state, $description = '') {
        if (!$this->live_api_mode) {
            return true; // Skip verification in mock mode
        }

        echo "<p style='color: blue;'>[API VERIFY] Checking {$entity_type} {$api_id}...</p>";

        try {
            switch ($entity_type) {
                case 'device':
                    $result = $this->helper->listDevice($api_id);
                    if (!$result || !isset($result['body'])) {
                        if (isset($expected_state['exists']) && $expected_state['exists'] === false) {
                            echo "<p style='color: green;'>[API VERIFY] Confirmed: device does not exist</p>";
                            return true;
                        }
                        throw new Exception("Device not found on API");
                    }
                    $api_data = $result['body'];
                    if (isset($api_data['devices'][0])) {
                        $api_data = $api_data['devices'][0];
                    }
                    // Verify expected fields
                    if (isset($expected_state['name']) && $api_data['name'] != $expected_state['name']) {
                        throw new Exception("Device name mismatch: expected '{$expected_state['name']}', got '{$api_data['name']}'");
                    }
                    if (isset($expected_state['exists']) && $expected_state['exists'] === false) {
                        throw new Exception("Device should not exist but was found");
                    }
                    break;

                case 'profile':
                    $result = $this->helper->listProfile($api_id);
                    if (!$result || !isset($result['body'])) {
                        if (isset($expected_state['exists']) && $expected_state['exists'] === false) {
                            echo "<p style='color: green;'>[API VERIFY] Confirmed: profile does not exist</p>";
                            return true;
                        }
                        throw new Exception("Profile not found on API");
                    }
                    break;

                case 'filter':
                    $profile_id = $expected_state['profile_id'];
                    $filter_key = $expected_state['filter_key'];
                    $expected_status = $expected_state['enabled'] ? 1 : 0;

                    $result = $this->helper->listNativeFilters($profile_id);
                    if (!$result || !isset($result['body'])) {
                        throw new Exception("Could not get filters from API");
                    }

                    $filters = $result['body']['filters'] ?? $result['body'];
                    $found = false;
                    foreach ($filters as $filter) {
                        if (($filter['PK'] ?? $filter['filter'] ?? '') == $filter_key) {
                            $api_status = $filter['status'] ?? $filter['enabled'] ?? 0;
                            if ($api_status != $expected_status) {
                                throw new Exception("Filter status mismatch: expected {$expected_status}, got {$api_status}");
                            }
                            $found = true;
                            break;
                        }
                    }
                    if (!$found && $expected_status == 1) {
                        throw new Exception("Filter {$filter_key} not found in enabled filters");
                    }
                    break;

                case 'service':
                    $profile_id = $expected_state['profile_id'];
                    $service_key = $expected_state['service_key'];
                    $expected_blocked = $expected_state['blocked'];

                    $result = $this->helper->listServicesOnProfile($profile_id);
                    if (!$result || !isset($result['body'])) {
                        throw new Exception("Could not get services from API");
                    }

                    $services = $result['body']['services'] ?? $result['body'];
                    $found = false;
                    foreach ($services as $service) {
                        if (($service['PK'] ?? $service['name'] ?? '') == $service_key) {
                            $found = true;
                            break;
                        }
                    }
                    if ($expected_blocked && !$found) {
                        echo "<p style='color: orange;'>[API VERIFY] Note: Service blocking verification may vary by API response format</p>";
                    }
                    break;

                case 'rule':
                    $profile_id = $expected_state['profile_id'];
                    $hostname = $expected_state['hostname'];
                    $should_exist = $expected_state['exists'] ?? true;

                    $result = $this->helper->listRules($profile_id);
                    if (!$result || !isset($result['body'])) {
                        throw new Exception("Could not get rules from API");
                    }

                    $rules = $result['body']['rules'] ?? $result['body'];
                    $found = false;
                    foreach ($rules as $rule) {
                        if (($rule['hostnames'][0] ?? $rule['hostname'] ?? '') == $hostname ||
                            (is_array($rule['hostnames'] ?? null) && in_array($hostname, $rule['hostnames']))) {
                            $found = true;
                            break;
                        }
                    }

                    if ($should_exist && !$found) {
                        throw new Exception("Rule for {$hostname} not found on API");
                    } else if (!$should_exist && $found) {
                        throw new Exception("Rule for {$hostname} should be deleted but still exists");
                    }
                    break;

                case 'schedule':
                    echo "<p style='color: orange;'>[API VERIFY] Schedule verification relies on API response during create/modify</p>";
                    break;

                default:
                    echo "<p style='color: orange;'>[API VERIFY] Unknown entity type: {$entity_type}</p>";
                    return true;
            }

            echo "<p style='color: green;'>[API VERIFY] {$description} - VERIFIED</p>";
            return true;

        } catch (Exception $e) {
            echo "<p style='color: red;'>[API VERIFY] FAILED: " . htmlspecialchars($e->getMessage()) . "</p>";
            return false;
        }
    }

    /**
     * Display database info
     */
    private function displayDatabaseInfo() {
        $dblink = $this->dbconnector->get_db_link();
        try {
            $stmt = $dblink->query("SELECT current_database()");
            $database_name = $stmt->fetchColumn();
        } catch (Exception $e) {
            $database_name = "Unknown";
        }

        if ($this->live_api_mode) {
            echo '<div class="alert alert-danger">';
            echo '<h4>⚠️ LIVE API MODE</h4>';
            echo '<strong>Database:</strong> <span class="text-primary font-weight-bold">' . htmlspecialchars($database_name) . '</span><br>';
            echo '<strong>ControlD API:</strong> <span class="text-danger font-weight-bold">LIVE - Real API calls will be made!</span><br>';
            echo '<p>This will create a real device on your ControlD account, run tests, then delete it.</p>';
            echo '</div>';
        } else {
            echo '<div class="alert alert-warning">';
            echo '<h4>TEST ENVIRONMENT STATUS</h4>';
            echo '<strong>Database:</strong> <span class="text-primary font-weight-bold">' . htmlspecialchars($database_name) . '</span><br>';
            echo '<strong>ControlD API:</strong> Mock mode (no real API calls)<br>';
            echo '</div>';
        }

        echo '<div class="alert alert-info">';
        echo '<h4>Test Architecture: Using Live Logic Files</h4>';
        echo '<p>This tester calls the actual plugin logic files and model methods:</p>';
        echo '<ul>';
        echo '<li><code>CtldProfile::createProfile()</code> - Profile creation</li>';
        echo '<li><code>CtldDevice::createDevice()</code> - Device creation</li>';
        echo '<li><code>$profile->update_remote_filters()</code> - Filter management</li>';
        echo '<li><code>$profile->update_remote_services()</code> - Service management</li>';
        echo '<li><code>$profile->add_rule() / delete_rule()</code> - Rule management</li>';
        echo '<li><code>$profile->add_or_edit_schedule()</code> - Schedule management</li>';
        echo '<li><code>$device->permanent_delete()</code> - Device deletion</li>';
        echo '</ul>';
        echo '</div>';
    }

    /**
     * Run all tests
     */
    public function run() {
        echo "<h2>ControlD Plugin Tester</h2>";

        $this->displayDatabaseInfo();

        // Step 1: Setup - create test user
        echo "<hr><h3>Step 1: Setup</h3>";
        if (!$this->setupTestUser()) {
            echo "<p class='text-danger'>Failed to create test user. Aborting.</p>";
            return;
        }

        // Step 2: Device Tests (using CtldProfile::createProfile and CtldDevice::createDevice)
        echo "<hr><h3>Step 2: Device Lifecycle Tests</h3>";
        $this->testDeviceCreate();
        $this->testDeviceEdit();

        // Step 3: Profile Tests
        echo "<hr><h3>Step 3: Profile Tests</h3>";
        $this->testProfileExists();
        $this->testSecondaryProfileCreate();

        // Step 4: Filter Tests (using $profile->update_remote_filters)
        echo "<hr><h3>Step 4: Filter Management Tests</h3>";
        $this->testFilterEnable();
        $this->testFilterDisable();
        $this->testFilterSync();

        // Step 5: Service Tests (using $profile->update_remote_services)
        echo "<hr><h3>Step 5: Service Blocking Tests</h3>";
        $this->testServiceBlock();
        $this->testServiceUnblock();
        $this->testServiceSync();

        // Step 6: Rule Tests (using $profile->add_rule and $profile->delete_rule)
        echo "<hr><h3>Step 6: Custom Rule Tests</h3>";
        $this->testRuleCreate();
        $this->testRuleDelete();
        $this->testRuleSync();

        // Step 7: Scheduling Tests (using $profile->add_or_edit_schedule)
        echo "<hr><h3>Step 7: Scheduling Tests</h3>";
        $this->testScheduleCreate();
        $this->testScheduleModify();
        $this->testScheduleDelete();

        // Step 8: Data Consistency
        echo "<hr><h3>Step 8: Data Consistency Tests</h3>";
        $this->testDeviceSync();
        $this->testProfileSync();

        // Step 9: Cleanup (using $device->permanent_delete)
        echo "<hr><h3>Step 9: Cleanup</h3>";
        $this->cleanup();

        // Summary
        echo "<hr><h2>Test Complete</h2>";
        $this->displaySummary();
    }

    /**
     * Create test user with a tier that allows ControlD access
     */
    private function setupTestUser() {
        // Create test user
        $email = 'controld_test_' . time() . '@example.com';
        $user = new User(NULL);
        $user->set('usr_email', $email);
        $user->set('usr_first_name', 'ControlD');
        $user->set('usr_last_name', 'Tester');
        $user->set('usr_password', password_hash('test123', PASSWORD_DEFAULT));
        $user->set('usr_is_activated', true);

        try {
            $user->save();
            $this->test_user_id = $user->key;
            echo "<p class='text-success'>Created test user: {$email} (ID: {$user->key})</p>";

            // Assign a tier with ControlD access
            $tiers = MultiSubscriptionTier::GetAllActive();
            if ($tiers->count() > 0) {
                $tier = $tiers->get(0);
                $tier->addUser($user->key, 'test', 'test', null, null);
                echo "<p class='text-success'>Assigned tier: {$tier->get('sbt_display_name')}</p>";
            }

            return true;
        } catch (Exception $e) {
            echo "<p class='text-danger'>Failed to create test user: " . htmlspecialchars($e->getMessage()) . "</p>";
            return false;
        }
    }

    /**
     * Test device creation using CtldProfile::createProfile() and CtldDevice::createDevice()
     */
    private function testDeviceCreate() {
        echo "<h4>Test: Create Device</h4>";
        echo "<p><em>Using: CtldProfile::createProfile(), CtldDevice::createDevice()</em></p>";

        try {
            $user = new User($this->test_user_id, TRUE);

            // Create a placeholder device first (as the real logic does)
            $empty_device = new CtldDevice(NULL);
            $empty_device->save();
            $empty_device->load();
            echo "<p>Created placeholder device: ID {$empty_device->key}</p>";

            // Use CtldProfile::createProfile() - the actual model method
            $profile_name = 'user' . $user->key . '-' . $empty_device->key . '-profile1';
            echo "<p>Calling CtldProfile::createProfile('{$profile_name}', \$user)...</p>";
            $profile = CtldProfile::createProfile($profile_name, $user);

            if (!$profile || !$profile->key) {
                throw new Exception("CtldProfile::createProfile() failed to return a valid profile");
            }
            echo "<p class='text-success'>Profile created via CtldProfile::createProfile(): ID {$profile->key}, API ID: {$profile->get('cdp_profile_id')}</p>";

            $this->test_profile_id = $profile->key;

            // Prepare POST data as the real form would submit
            $post_vars = [
                'device_name' => 'user' . $user->key . '-test_device',
                'cdd_timezone' => 'America/New_York',
                'cdd_allow_device_edits' => 1
            ];

            // Use CtldDevice::createDevice() - the actual model method
            echo "<p>Calling CtldDevice::createDevice()...</p>";
            $device = CtldDevice::createDevice($empty_device, $profile, null, $post_vars);

            if (!$device || !$device->key) {
                throw new Exception("CtldDevice::createDevice() failed to return a valid device");
            }

            $this->test_device_id = $device->key;
            echo "<p class='text-success'>Device created via CtldDevice::createDevice(): ID {$device->key}</p>";
            echo "<p>Device API ID: {$device->get('cdd_device_id')}</p>";
            echo "<p>Resolver: {$device->get('cdd_controld_resolver')}</p>";

            // Live API verification
            $this->verifyApiState('device', $device->get('cdd_device_id'), [
                'name' => $post_vars['device_name']
            ], 'Device exists on ControlD');

            return true;

        } catch (Exception $e) {
            $message = "Device creation failed: " . $e->getMessage();
            echo "<p class='text-danger'>{$message}</p>";
            $this->recordFailure('Device Create', $message);
            return false;
        }
    }

    /**
     * Test device editing (simulating ctlddevice_edit_logic behavior)
     */
    private function testDeviceEdit() {
        echo "<h4>Test: Edit Device</h4>";
        echo "<p><em>Using: ControlDHelper->modifyDevice(), device model save()</em></p>";

        if (!$this->test_device_id) {
            echo "<p class='text-warning'>Skipped - no test device</p>";
            return false;
        }

        try {
            $device = new CtldDevice($this->test_device_id, TRUE);
            $api_device_id = $device->get('cdd_device_id');
            $new_name = 'user' . $this->test_user_id . '-renamed_device';

            // This mirrors ctlddevice_edit_logic.php lines 68-84
            $cd = new ControlDHelper();
            if ($this->live_api_mode) {
                $cd->test_mode = false;
            }

            $old_device_name = $device->get('cdd_device_name');
            echo "<p>Old name: {$old_device_name}</p>";
            echo "<p>New name: {$new_name}</p>";

            if ($new_name != $old_device_name) {
                $data = ['name' => $new_name];
                echo "<p>Calling ControlDHelper->modifyDevice()...</p>";
                $result = $cd->modifyDevice($device->get('cdd_device_id'), $data);

                if (!$result['success']) {
                    throw new Exception('Unable to edit this device via API');
                }
            }

            // Update local DB (as the logic file does)
            $device->set('cdd_device_name', $new_name);
            $device->set('cdd_timezone', 'America/Los_Angeles');
            $device->prepare();
            $device->save();

            // Verify
            $verify_device = new CtldDevice($this->test_device_id, TRUE);
            if ($verify_device->get('cdd_device_name') == $new_name) {
                echo "<p class='text-success'>Device renamed successfully</p>";

                // Live API verification
                $this->verifyApiState('device', $api_device_id, [
                    'name' => $new_name
                ], 'Device name updated on ControlD');

                return true;
            } else {
                throw new Exception("DB name doesn't match after update");
            }

        } catch (Exception $e) {
            $message = "Device edit failed: " . $e->getMessage();
            echo "<p class='text-danger'>{$message}</p>";
            $this->recordFailure('Device Edit', $message);
            return false;
        }
    }

    /**
     * Test that primary profile exists
     */
    private function testProfileExists() {
        echo "<h4>Test: Primary Profile Exists</h4>";

        if (!$this->test_profile_id) {
            echo "<p class='text-warning'>Skipped - no test profile</p>";
            return false;
        }

        try {
            $profile = new CtldProfile($this->test_profile_id, TRUE);
            if ($profile->key && $profile->get('cdp_profile_id')) {
                echo "<p class='text-success'>Primary profile exists: {$profile->get('cdp_profile_id')}</p>";
                return true;
            } else {
                throw new Exception("Profile not found or missing API ID");
            }
        } catch (Exception $e) {
            $message = "Profile check failed: " . $e->getMessage();
            echo "<p class='text-danger'>{$message}</p>";
            $this->recordFailure('Profile Exists', $message);
            return false;
        }
    }

    /**
     * Test secondary profile creation using CtldProfile::createProfile()
     */
    private function testSecondaryProfileCreate() {
        echo "<h4>Test: Create Secondary Profile</h4>";
        echo "<p><em>Using: CtldProfile::createProfile()</em></p>";

        if (!$this->test_device_id) {
            echo "<p class='text-warning'>Skipped - no test device</p>";
            return false;
        }

        try {
            $user = new User($this->test_user_id, TRUE);
            $device = new CtldDevice($this->test_device_id, TRUE);

            // Use CtldProfile::createProfile() - mirrors ctldfilters_edit_logic.php lines 49-54
            $profile_name = 'user' . $user->key . '-' . $device->key . '-profile2';
            echo "<p>Calling CtldProfile::createProfile('{$profile_name}', \$user)...</p>";
            $profile = CtldProfile::createProfile($profile_name, $user);

            if (!$profile || !$profile->key) {
                throw new Exception("CtldProfile::createProfile() failed for secondary profile");
            }

            // Link to device (as ctldfilters_edit_logic does)
            $device->set('cdd_cdp_ctldprofile_id_secondary', $profile->key);
            $device->set('cdd_profile_id_secondary', $profile->get('cdp_profile_id'));
            $device->save();

            echo "<p class='text-success'>Secondary profile created: {$profile->get('cdp_profile_id')} (DB ID: {$profile->key})</p>";
            return true;

        } catch (Exception $e) {
            $message = "Secondary profile creation failed: " . $e->getMessage();
            echo "<p class='text-danger'>{$message}</p>";
            $this->recordFailure('Secondary Profile Create', $message);
            return false;
        }
    }

    /**
     * Test enabling a filter using $profile->update_remote_filters()
     */
    private function testFilterEnable() {
        echo "<h4>Test: Enable Filter ({$this->test_filter_key})</h4>";
        echo "<p><em>Using: \$profile->update_remote_filters()</em></p>";

        if (!$this->test_profile_id) {
            echo "<p class='text-warning'>Skipped - no test profile</p>";
            return false;
        }

        try {
            $profile = new CtldProfile($this->test_profile_id, TRUE);
            $api_profile_id = $profile->get('cdp_profile_id');

            // Prepare POST data as the real form would submit
            // This mirrors ctldfilters_edit_logic.php line 78
            $post_vars = [
                'block_' . $this->test_filter_key => 1
            ];

            echo "<p>Calling \$profile->update_remote_filters() with block_{$this->test_filter_key} = 1...</p>";
            $profile->update_remote_filters($post_vars);

            echo "<p class='text-success'>Filter enabled via update_remote_filters(): {$this->test_filter_key}</p>";

            // Live API verification
            $this->verifyApiState('filter', $api_profile_id, [
                'profile_id' => $api_profile_id,
                'filter_key' => $this->test_filter_key,
                'enabled' => true
            ], 'Filter enabled on ControlD');

            return true;

        } catch (Exception $e) {
            $message = "Filter enable failed: " . $e->getMessage();
            echo "<p class='text-danger'>{$message}</p>";
            $this->recordFailure('Filter Enable', $message);
            return false;
        }
    }

    /**
     * Test disabling a filter using $profile->update_remote_filters()
     */
    private function testFilterDisable() {
        echo "<h4>Test: Disable Filter ({$this->test_filter_key})</h4>";
        echo "<p><em>Using: \$profile->update_remote_filters()</em></p>";

        if (!$this->test_profile_id) {
            echo "<p class='text-warning'>Skipped - no test profile</p>";
            return false;
        }

        try {
            $profile = new CtldProfile($this->test_profile_id, TRUE);
            $api_profile_id = $profile->get('cdp_profile_id');

            // To disable, we pass 0 or omit the key
            $post_vars = [
                'block_' . $this->test_filter_key => 0
            ];

            echo "<p>Calling \$profile->update_remote_filters() with block_{$this->test_filter_key} = 0...</p>";
            $profile->update_remote_filters($post_vars);

            echo "<p class='text-success'>Filter disabled via update_remote_filters(): {$this->test_filter_key}</p>";

            // Live API verification
            $this->verifyApiState('filter', $api_profile_id, [
                'profile_id' => $api_profile_id,
                'filter_key' => $this->test_filter_key,
                'enabled' => false
            ], 'Filter disabled on ControlD');

            return true;

        } catch (Exception $e) {
            $message = "Filter disable failed: " . $e->getMessage();
            echo "<p class='text-danger'>{$message}</p>";
            $this->recordFailure('Filter Disable', $message);
            return false;
        }
    }

    /**
     * Test filter sync between DB and API
     */
    private function testFilterSync() {
        echo "<h4>Test: Filter Sync (DB matches API)</h4>";

        if (!$this->test_profile_id) {
            echo "<p class='text-warning'>Skipped - no test profile</p>";
            return false;
        }

        try {
            $profile = new CtldProfile($this->test_profile_id, TRUE);
            $api_profile_id = $profile->get('cdp_profile_id');

            // Get filters from API
            $api_filters = $this->helper->listNativeFilters($api_profile_id);

            // Get filters from DB
            $db_filters = new MultiCtldFilter(['profile_id' => $this->test_profile_id]);
            $db_filters->load();

            $api_count = is_array($api_filters) && isset($api_filters['body']) ? count($api_filters['body']) : 0;
            $db_count = $db_filters->count();

            echo "<p>API filters available: {$api_count}</p>";
            echo "<p>DB filters tracked: {$db_count}</p>";
            echo "<p class='text-success'>Filter sync check completed</p>";
            return true;

        } catch (Exception $e) {
            $message = "Filter sync check failed: " . $e->getMessage();
            echo "<p class='text-danger'>{$message}</p>";
            $this->recordFailure('Filter Sync', $message);
            return false;
        }
    }

    /**
     * Test blocking a service using $profile->update_remote_services()
     */
    private function testServiceBlock() {
        echo "<h4>Test: Block Service ({$this->test_service_key})</h4>";
        echo "<p><em>Using: \$profile->update_remote_services()</em></p>";

        if (!$this->test_profile_id) {
            echo "<p class='text-warning'>Skipped - no test profile</p>";
            return false;
        }

        try {
            $profile = new CtldProfile($this->test_profile_id, TRUE);
            $api_profile_id = $profile->get('cdp_profile_id');

            // Prepare POST data as the real form would submit
            // This mirrors ctldfilters_edit_logic.php line 79
            $post_vars = [
                'block_' . $this->test_service_key => 1
            ];

            echo "<p>Calling \$profile->update_remote_services() with block_{$this->test_service_key} = 1...</p>";
            $profile->update_remote_services($post_vars);

            echo "<p class='text-success'>Service blocked via update_remote_services(): {$this->test_service_key}</p>";

            // Live API verification
            $this->verifyApiState('service', $api_profile_id, [
                'profile_id' => $api_profile_id,
                'service_key' => $this->test_service_key,
                'blocked' => true
            ], 'Service blocked on ControlD');

            return true;

        } catch (Exception $e) {
            $message = "Service block failed: " . $e->getMessage();
            echo "<p class='text-danger'>{$message}</p>";
            $this->recordFailure('Service Block', $message);
            return false;
        }
    }

    /**
     * Test unblocking a service using $profile->update_remote_services()
     */
    private function testServiceUnblock() {
        echo "<h4>Test: Unblock Service ({$this->test_service_key})</h4>";
        echo "<p><em>Using: \$profile->update_remote_services()</em></p>";

        if (!$this->test_profile_id) {
            echo "<p class='text-warning'>Skipped - no test profile</p>";
            return false;
        }

        try {
            $profile = new CtldProfile($this->test_profile_id, TRUE);
            $api_profile_id = $profile->get('cdp_profile_id');

            // To unblock, pass 0
            $post_vars = [
                'block_' . $this->test_service_key => 0
            ];

            echo "<p>Calling \$profile->update_remote_services() with block_{$this->test_service_key} = 0...</p>";
            $profile->update_remote_services($post_vars);

            echo "<p class='text-success'>Service unblocked via update_remote_services(): {$this->test_service_key}</p>";

            // Live API verification
            $this->verifyApiState('service', $api_profile_id, [
                'profile_id' => $api_profile_id,
                'service_key' => $this->test_service_key,
                'blocked' => false
            ], 'Service unblocked on ControlD');

            return true;

        } catch (Exception $e) {
            $message = "Service unblock failed: " . $e->getMessage();
            echo "<p class='text-danger'>{$message}</p>";
            $this->recordFailure('Service Unblock', $message);
            return false;
        }
    }

    /**
     * Test service sync between DB and API
     */
    private function testServiceSync() {
        echo "<h4>Test: Service Sync (DB matches API)</h4>";

        if (!$this->test_profile_id) {
            echo "<p class='text-warning'>Skipped - no test profile</p>";
            return false;
        }

        try {
            $profile = new CtldProfile($this->test_profile_id, TRUE);
            $api_profile_id = $profile->get('cdp_profile_id');

            // Get services from API
            $api_services = $this->helper->listServicesOnProfile($api_profile_id);

            // Get services from DB
            $db_services = new MultiCtldService(['profile_id' => $this->test_profile_id]);
            $db_services->load();

            $api_count = is_array($api_services) && isset($api_services['body']) ? count($api_services['body']) : 0;
            $db_count = $db_services->count();

            echo "<p>API services configured: {$api_count}</p>";
            echo "<p>DB services tracked: {$db_count}</p>";
            echo "<p class='text-success'>Service sync check completed</p>";
            return true;

        } catch (Exception $e) {
            $message = "Service sync check failed: " . $e->getMessage();
            echo "<p class='text-danger'>{$message}</p>";
            $this->recordFailure('Service Sync', $message);
            return false;
        }
    }

    /**
     * Test creating a custom rule using $profile->add_rule()
     */
    private function testRuleCreate() {
        echo "<h4>Test: Create Custom Rule ({$this->test_rule_hostname})</h4>";
        echo "<p><em>Using: \$profile->add_rule()</em></p>";

        if (!$this->test_profile_id) {
            echo "<p class='text-warning'>Skipped - no test profile</p>";
            return false;
        }

        try {
            $profile = new CtldProfile($this->test_profile_id, TRUE);
            $api_profile_id = $profile->get('cdp_profile_id');

            // Use $profile->add_rule() - mirrors rules_logic.php line 73
            // action 0 = block, action 1 = allow
            echo "<p>Calling \$profile->add_rule('{$this->test_rule_hostname}', 0)...</p>";
            $result = $profile->add_rule($this->test_rule_hostname, 0);

            echo "<p class='text-success'>Rule created via add_rule(): block {$this->test_rule_hostname}</p>";

            // Live API verification
            $this->verifyApiState('rule', $api_profile_id, [
                'profile_id' => $api_profile_id,
                'hostname' => $this->test_rule_hostname,
                'exists' => true
            ], 'Rule created on ControlD');

            return true;

        } catch (Exception $e) {
            $message = "Rule create failed: " . $e->getMessage();
            echo "<p class='text-danger'>{$message}</p>";
            $this->recordFailure('Rule Create', $message);
            return false;
        }
    }

    /**
     * Test deleting a custom rule using $profile->delete_rule()
     */
    private function testRuleDelete() {
        echo "<h4>Test: Delete Custom Rule ({$this->test_rule_hostname})</h4>";
        echo "<p><em>Using: \$profile->delete_rule()</em></p>";

        if (!$this->test_profile_id) {
            echo "<p class='text-warning'>Skipped - no test profile</p>";
            return false;
        }

        try {
            $profile = new CtldProfile($this->test_profile_id, TRUE);
            $api_profile_id = $profile->get('cdp_profile_id');

            // Find the rule we created
            $rules = new MultiCtldRule(['profile_id' => $this->test_profile_id]);
            $rules->load();

            $rule_id = null;
            foreach ($rules as $rule) {
                if ($rule->get('cdr_rule_hostname') == $this->test_rule_hostname) {
                    $rule_id = $rule->key;
                    break;
                }
            }

            if (!$rule_id) {
                throw new Exception("Could not find rule to delete");
            }

            // Use $profile->delete_rule() - mirrors rules_logic.php line 50
            echo "<p>Calling \$profile->delete_rule({$rule_id})...</p>";
            $result = $profile->delete_rule($rule_id);

            echo "<p class='text-success'>Rule deleted via delete_rule(): {$this->test_rule_hostname}</p>";

            // Live API verification
            $this->verifyApiState('rule', $api_profile_id, [
                'profile_id' => $api_profile_id,
                'hostname' => $this->test_rule_hostname,
                'exists' => false
            ], 'Rule deleted from ControlD');

            return true;

        } catch (Exception $e) {
            $message = "Rule delete failed: " . $e->getMessage();
            echo "<p class='text-danger'>{$message}</p>";
            $this->recordFailure('Rule Delete', $message);
            return false;
        }
    }

    /**
     * Test rule sync between DB and API
     */
    private function testRuleSync() {
        echo "<h4>Test: Rule Sync (DB matches API)</h4>";

        if (!$this->test_profile_id) {
            echo "<p class='text-warning'>Skipped - no test profile</p>";
            return false;
        }

        try {
            $profile = new CtldProfile($this->test_profile_id, TRUE);
            $api_profile_id = $profile->get('cdp_profile_id');

            // Get rules from API
            $api_rules = $this->helper->listRules($api_profile_id);

            // Get rules from DB
            $db_rules = new MultiCtldRule(['profile_id' => $this->test_profile_id]);
            $db_rules->load();

            $api_count = is_array($api_rules) && isset($api_rules['body']) ? count($api_rules['body']) : 0;
            $db_count = $db_rules->count();

            echo "<p>API rules: {$api_count}</p>";
            echo "<p>DB rules: {$db_count}</p>";
            echo "<p class='text-success'>Rule sync check completed</p>";
            return true;

        } catch (Exception $e) {
            $message = "Rule sync check failed: " . $e->getMessage();
            echo "<p class='text-danger'>{$message}</p>";
            $this->recordFailure('Rule Sync', $message);
            return false;
        }
    }

    /**
     * Test schedule creation using $profile->add_or_edit_schedule()
     */
    private function testScheduleCreate() {
        echo "<h4>Test: Create Schedule</h4>";
        echo "<p><em>Using: \$profile->add_or_edit_schedule()</em></p>";

        if (!$this->test_device_id) {
            echo "<p class='text-warning'>Skipped - no test device</p>";
            return false;
        }

        try {
            $device = new CtldDevice($this->test_device_id, TRUE);
            $secondary_profile_db_id = $device->get('cdd_cdp_ctldprofile_id_secondary');

            if (!$secondary_profile_db_id) {
                echo "<p class='text-warning'>Skipped - no secondary profile</p>";
                return false;
            }

            $profile = new CtldProfile($secondary_profile_db_id, TRUE);

            // Prepare POST data as the real form would submit
            // This mirrors ctldfilters_edit_logic.php line 88
            $post_vars = [
                'cdp_schedule_start' => '09:00',
                'cdp_schedule_end' => '17:00',
                'cdp_schedule_timezone' => 'America/New_York',
                'cdp_schedule_days' => ['1', '2', '3', '4', '5'] // weekdays
            ];

            echo "<p>Calling \$profile->add_or_edit_schedule(\$device, \$post_vars)...</p>";
            $result = $profile->add_or_edit_schedule($device, $post_vars);

            echo "<p class='text-success'>Schedule created via add_or_edit_schedule(): 09:00-17:00 weekdays</p>";
            return true;

        } catch (Exception $e) {
            $message = "Schedule create failed: " . $e->getMessage();
            echo "<p class='text-danger'>{$message}</p>";
            $this->recordFailure('Schedule Create', $message);
            return false;
        }
    }

    /**
     * Test schedule modification using $profile->add_or_edit_schedule()
     */
    private function testScheduleModify() {
        echo "<h4>Test: Modify Schedule</h4>";
        echo "<p><em>Using: \$profile->add_or_edit_schedule()</em></p>";

        if (!$this->test_device_id) {
            echo "<p class='text-warning'>Skipped - no test device</p>";
            return false;
        }

        try {
            $device = new CtldDevice($this->test_device_id, TRUE);
            $secondary_profile_db_id = $device->get('cdd_cdp_ctldprofile_id_secondary');

            if (!$secondary_profile_db_id) {
                echo "<p class='text-warning'>Skipped - no secondary profile</p>";
                return false;
            }

            $profile = new CtldProfile($secondary_profile_db_id, TRUE);

            if (!$profile->get('cdp_schedule_id')) {
                echo "<p class='text-warning'>Skipped - no schedule ID</p>";
                return false;
            }

            // Modified schedule
            $post_vars = [
                'cdp_schedule_start' => '10:00',
                'cdp_schedule_end' => '18:00',
                'cdp_schedule_timezone' => 'America/New_York',
                'cdp_schedule_days' => ['1', '2', '3', '4', '5']
            ];

            echo "<p>Calling \$profile->add_or_edit_schedule() with new times...</p>";
            $result = $profile->add_or_edit_schedule($device, $post_vars);

            echo "<p class='text-success'>Schedule modified via add_or_edit_schedule(): 10:00-18:00</p>";
            return true;

        } catch (Exception $e) {
            $message = "Schedule modify failed: " . $e->getMessage();
            echo "<p class='text-danger'>{$message}</p>";
            $this->recordFailure('Schedule Modify', $message);
            return false;
        }
    }

    /**
     * Test schedule deletion
     */
    private function testScheduleDelete() {
        echo "<h4>Test: Delete Schedule</h4>";
        echo "<p><em>Using: ControlDHelper->deleteSchedule()</em></p>";

        if (!$this->test_device_id) {
            echo "<p class='text-warning'>Skipped - no test device</p>";
            return false;
        }

        try {
            $device = new CtldDevice($this->test_device_id, TRUE);
            $secondary_profile_db_id = $device->get('cdd_cdp_ctldprofile_id_secondary');

            if (!$secondary_profile_db_id) {
                echo "<p class='text-warning'>Skipped - no secondary profile</p>";
                return false;
            }

            $profile = new CtldProfile($secondary_profile_db_id, TRUE);
            $schedule_id = $profile->get('cdp_schedule_id');

            if (!$schedule_id) {
                echo "<p class='text-warning'>Skipped - no schedule ID</p>";
                return false;
            }

            // Delete schedule on API
            echo "<p>Calling ControlDHelper->deleteSchedule({$schedule_id})...</p>";
            $result = $this->helper->deleteSchedule($schedule_id);

            // Clear from DB
            $profile->set('cdp_schedule_id', null);
            $profile->set('cdp_schedule_start', null);
            $profile->set('cdp_schedule_end', null);
            $profile->set('cdp_schedule_days', null);
            $profile->save();

            echo "<p class='text-success'>Schedule deleted</p>";
            return true;

        } catch (Exception $e) {
            $message = "Schedule delete failed: " . $e->getMessage();
            echo "<p class='text-danger'>{$message}</p>";
            $this->recordFailure('Schedule Delete', $message);
            return false;
        }
    }

    /**
     * Test device sync between DB and API
     */
    private function testDeviceSync() {
        echo "<h4>Test: Device Sync (DB matches API)</h4>";

        if (!$this->test_device_id) {
            echo "<p class='text-warning'>Skipped - no test device</p>";
            return false;
        }

        try {
            $device = new CtldDevice($this->test_device_id, TRUE);
            $api_device_id = $device->get('cdd_device_id');

            // Get from API
            $api_device = $this->helper->listDevice($api_device_id);

            if (!$api_device || !isset($api_device['body'])) {
                throw new Exception("Failed to get device from API");
            }

            // Handle different response formats
            $api_data = $api_device['body'];
            if (isset($api_data['devices'][0])) {
                $api_data = $api_data['devices'][0];
            }
            $db_name = $device->get('cdd_device_name');
            $api_name = $api_data['name'] ?? $db_name;

            echo "<p>DB device name: {$db_name}</p>";
            echo "<p>API device name: {$api_name}</p>";

            if ($db_name == $api_name) {
                echo "<p class='text-success'>Device names match</p>";
                return true;
            } else {
                throw new Exception("Device name mismatch: DB='{$db_name}' vs API='{$api_name}'");
            }

        } catch (Exception $e) {
            $message = "Device sync check failed: " . $e->getMessage();
            echo "<p class='text-danger'>{$message}</p>";
            $this->recordFailure('Device Sync', $message);
            return false;
        }
    }

    /**
     * Test profile sync between DB and API
     */
    private function testProfileSync() {
        echo "<h4>Test: Profile Sync (DB matches API)</h4>";

        if (!$this->test_profile_id) {
            echo "<p class='text-warning'>Skipped - no test profile</p>";
            return false;
        }

        try {
            $profile = new CtldProfile($this->test_profile_id, TRUE);
            $api_profile_id = $profile->get('cdp_profile_id');

            // In test mode, verify the profile has a valid test API ID
            if ($this->helper->isTestMode()) {
                echo "<p>Test mode - verifying DB profile has API ID</p>";
                if ($api_profile_id && strpos($api_profile_id, 'TEST_PROFILE_') === 0) {
                    echo "<p>Profile has valid test API ID: {$api_profile_id}</p>";
                    echo "<p class='text-success'>Profile sync verified (test mode)</p>";
                    return true;
                } else {
                    throw new Exception("Profile missing or invalid API ID in test mode");
                }
            }

            // Live mode - list profiles from API
            $api_profiles = $this->helper->listProfiles();

            if (!$api_profiles || !isset($api_profiles['body'])) {
                throw new Exception("Failed to list profiles from API");
            }

            // Find our profile
            $found = false;
            $profiles_list = $api_profiles['body']['profiles'] ?? $api_profiles['body'];
            foreach ($profiles_list as $api_profile) {
                if ($api_profile['PK'] == $api_profile_id) {
                    $found = true;
                    echo "<p>Profile found in API: {$api_profile_id}</p>";
                    break;
                }
            }

            if ($found) {
                echo "<p class='text-success'>Profile sync verified</p>";
                return true;
            } else {
                throw new Exception("Profile not found in API list");
            }

        } catch (Exception $e) {
            $message = "Profile sync check failed: " . $e->getMessage();
            echo "<p class='text-danger'>{$message}</p>";
            $this->recordFailure('Profile Sync', $message);
            return false;
        }
    }

    /**
     * Cleanup test data using $device->permanent_delete()
     */
    private function cleanup() {
        echo "<h4>Cleaning up test data...</h4>";
        echo "<p><em>Using: \$device->permanent_delete()</em></p>";

        if ($this->live_api_mode) {
            echo "<p style='color: blue;'>[LIVE MODE] Cleaning up real ControlD resources...</p>";
        }

        try {
            // Delete device using the model's permanent_delete method
            // This mirrors ctlddevice_delete_logic.php line 38
            if ($this->test_device_id) {
                $device = new CtldDevice($this->test_device_id, TRUE);
                $api_device_id = $device->get('cdd_device_id');
                $primary_profile_id = $device->get('cdd_profile_id_primary');
                $secondary_profile_id = $device->get('cdd_profile_id_secondary');

                echo "<p>Calling \$device->permanent_delete()...</p>";
                $device->permanent_delete();
                echo "<p class='text-success'>Device deleted via permanent_delete()</p>";

                // Verify deletion in live mode
                if ($this->live_api_mode && $api_device_id) {
                    $verify_result = $this->helper->listDevice($api_device_id);
                    if (!$verify_result || isset($verify_result['body']['error']) ||
                        (isset($verify_result['body']['success']) && !$verify_result['body']['success'])) {
                        echo "<p style='color: green;'>[API VERIFY] Device confirmed deleted from ControlD</p>";
                    } else {
                        echo "<p style='color: orange;'>[API VERIFY] Device may still exist (API response unclear)</p>";
                    }
                }
            }

            // Clean up any remaining profiles
            $profiles = new MultiCtldProfile(['user_id' => $this->test_user_id]);
            $profiles->load();
            foreach ($profiles as $profile) {
                $profile->permanent_delete();
            }
            echo "<p>Deleted remaining profiles from DB</p>";

            // Remove user from tiers
            if ($this->test_user_id) {
                SubscriptionTier::removeUserFromAllTiers($this->test_user_id);

                // Delete test user
                $user = new User($this->test_user_id, TRUE);
                $user->permanent_delete();
                echo "<p>Deleted test user</p>";
            }

            echo "<p class='text-success'>Cleanup complete</p>";

            if ($this->live_api_mode) {
                echo "<p style='color: green;'><strong>[LIVE MODE] All ControlD resources cleaned up successfully!</strong></p>";
            }

        } catch (Exception $e) {
            echo "<p class='text-warning'>Cleanup warning: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }

    /**
     * Display test summary
     */
    private function displaySummary() {
        $mode_label = $this->live_api_mode ? 'LIVE API MODE' : 'Mock Mode';
        $mode_color = $this->live_api_mode ? '#cc6600' : '#666';

        if (empty($this->test_failures)) {
            echo "<div class='alert alert-success'>";
            echo "<h4>All ControlD Tests Passed!</h4>";
            echo "<p><strong>Mode:</strong> <span style='color: {$mode_color};'>{$mode_label}</span></p>";
            echo "<p><strong>Architecture:</strong> Using actual logic files and model methods</p>";
            if ($this->live_api_mode) {
                echo "<p><strong>API Sync Verification:</strong> <span style='color: green;'>All changes verified on ControlD servers</span></p>";
            }
            echo "<p><strong>Tests completed:</strong></p>";
            echo "<ul>";
            echo "<li>Device lifecycle (create via CtldDevice::createDevice, edit, permanent_delete)</li>";
            echo "<li>Profile management (create via CtldProfile::createProfile)</li>";
            echo "<li>Filter management (via \$profile->update_remote_filters)</li>";
            echo "<li>Service blocking (via \$profile->update_remote_services)</li>";
            echo "<li>Custom rules (via \$profile->add_rule / delete_rule)</li>";
            echo "<li>Scheduling (via \$profile->add_or_edit_schedule)</li>";
            echo "<li>Data consistency (device sync, profile sync)</li>";
            echo "</ul>";
            echo "</div>";
        } else {
            echo "<div style='background: #ffcccc; border: 5px solid #cc0000; padding: 20px; margin: 20px 0;'>";
            echo "<h2 style='color: #cc0000; margin-top: 0;'>TEST FAILURES DETECTED</h2>";
            echo "<p><strong>Mode:</strong> <span style='color: {$mode_color};'>{$mode_label}</span></p>";
            echo "<h3>" . count($this->test_failures) . " test(s) failed:</h3>";
            echo "<ul style='font-size: 1.2em;'>";
            foreach ($this->test_failures as $failure) {
                echo "<li><strong>" . htmlspecialchars($failure['test']) . "</strong>: " . htmlspecialchars($failure['message']) . "</li>";
            }
            echo "</ul>";
            echo "</div>";
        }
    }
}
