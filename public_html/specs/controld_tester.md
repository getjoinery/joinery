# ControlD Plugin Tester Specification

> **STATUS: SPECIFICATION**
>
> This spec defines a comprehensive test suite for the ControlD plugin features,
> excluding user creation and tier changes (covered by SubscriptionTierTester).

## Overview

The ControlD plugin provides DNS filtering and content blocking integration. This tester
will verify that local database operations mirror the ControlD API state correctly.

## Location

`/var/www/html/joinerytest/public_html/plugins/controld/tests/ControlDTester.php`

## Test Scope

### In Scope
- Device lifecycle (create, edit, soft delete, permanent delete)
- Profile management (primary/secondary profiles)
- Filter management (enable/disable DNS filters)
- Service blocking (enable/disable service categories)
- Custom rules (create/delete hostname rules)
- Scheduling (secondary profile time-based activation)
- Data consistency (local DB matches ControlD API state)

### Out of Scope (covered by SubscriptionTierTester)
- User creation
- Tier assignment/changes
- Tier-based feature limits (max_devices, advanced_filters, custom_rules)

## Test Categories

### 1. Device Lifecycle Tests

| Test | Description | Validation |
|------|-------------|------------|
| Create device | Create device with valid data | Device exists in DB and ControlD API |
| Device has UUID | ControlD assigns UUID on creation | `ctd_controld_device_id` is set |
| Device has resolver | Device gets unique resolver IP | `ctd_resolver` is set |
| Device has PIN | Deactivation PIN generated | `ctd_deactivation_pin` is set |
| Edit device name | Change device name | Name updated in DB and API |
| Edit device timezone | Change device timezone | Timezone updated in DB and API |
| Soft delete device | Set delete_time, mark inactive | Device inactive on API, delete_time set |
| Permanent delete | Remove device completely | Device gone from DB and API |

### 2. Profile Management Tests

| Test | Description | Validation |
|------|-------------|------------|
| Primary profile created | New device gets primary profile | Profile exists with `ctp_is_primary = true` |
| Primary profile has API ID | ControlD assigns profile ID | `ctp_controld_profile_id` is set |
| Secondary profile creation | Create secondary for scheduling | Profile exists with `ctp_is_primary = false` |
| Cannot delete primary | Attempt to delete primary fails | Error returned, profile still exists |
| Delete secondary | Delete secondary profile | Profile removed from DB and API |
| Cascade on device delete | Device deletion removes profiles | All profiles deleted |

### 3. Filter Management Tests

| Test | Description | Validation |
|------|-------------|------------|
| Enable ad filter | Enable ads_medium filter | Filter active in DB and API |
| Disable ad filter | Disable ads_medium filter | Filter inactive in DB and API |
| Enable malware filter | Enable malware filter | Filter active in DB and API |
| Multiple filters | Enable several filters at once | All filters reflect correctly |
| Filter state sync | Compare DB state to API state | States match |

### 4. Service Blocking Tests

| Test | Description | Validation |
|------|-------------|------------|
| Block service | Block spotify service | Service blocked in DB and API |
| Unblock service | Unblock spotify service | Service unblocked in DB and API |
| Multiple services | Block/unblock several services | All services reflect correctly |
| Service state sync | Compare DB state to API state | States match |

### 5. Custom Rules Tests

| Test | Description | Validation |
|------|-------------|------------|
| Create block rule | Block example.com | Rule exists in DB and API |
| Create allow rule | Allow example.org | Rule exists with action=1 |
| Delete rule | Remove a rule | Rule gone from DB and API |
| Invalid hostname | Create rule with invalid hostname | Error returned |
| Rule state sync | Compare DB rules to API rules | Rules match |

### 6. Scheduling Tests

| Test | Description | Validation |
|------|-------------|------------|
| Create schedule | Set schedule on secondary profile | Schedule exists in DB and API |
| Schedule time calculation | Check time remaining | Correct hours/minutes returned |
| Active profile check | Get active profile based on time | Correct profile returned |
| Overnight schedule | Schedule 22:00-04:00 | Works across midnight |
| Edit schedule | Change schedule times | Updated in DB and API |
| Delete schedule | Remove schedule | Schedule removed |

### 7. Data Consistency Tests

| Test | Description | Validation |
|------|-------------|------------|
| DB matches API devices | List devices from both | Same devices, same states |
| DB matches API profiles | List profiles from both | Same profiles, same states |
| DB matches API filters | List filters from both | Same filter states |
| DB matches API services | List services from both | Same service states |
| DB matches API rules | List rules from both | Same rules |

## Implementation Approach

### Test Mode

Use ControlD's test/sandbox mode if available, otherwise use live API with test user.

```php
class ControlDTester {
    private $test_user_id;
    private $test_device_id;
    private $helper;
    private $test_failures = [];

    public function __construct() {
        // Enable test database
        $dbconnector = DbConnector::get_instance();
        $dbconnector->set_test_mode();

        // Initialize ControlD helper
        $this->helper = new ControlDHelper();
    }
}
```

### Test Flow

1. **Setup**
   - Create test user with appropriate tier
   - Store original settings

2. **Device Tests**
   - Create device, verify DB and API
   - Edit device, verify changes
   - Test soft delete
   - Test permanent delete

3. **Profile Tests**
   - Verify primary profile exists
   - Create secondary profile
   - Test deletion rules

4. **Filter/Service/Rule Tests**
   - Enable/disable various filters
   - Block/unblock services
   - Create/delete rules
   - Verify sync after each operation

5. **Scheduling Tests**
   - Create schedules
   - Test time calculations
   - Test active profile logic

6. **Consistency Tests**
   - Compare all DB state to API state
   - Report any discrepancies

7. **Cleanup**
   - Delete test devices
   - Delete test user
   - Restore settings

### API Sync Verification

For each operation, verify both sides match:

```php
private function verifyDeviceSync($device_id) {
    // Get from database
    $db_device = new CtldDevice($device_id, TRUE);

    // Get from ControlD API
    $api_device = $this->helper->listDevice($db_device->get('ctd_controld_device_id'));

    // Compare key fields
    $this->assertEqual($db_device->get('ctd_name'), $api_device['name']);
    $this->assertEqual($db_device->get('ctd_is_active'), $api_device['status'] == 1);
    // ... etc
}
```

## Output Format

Similar to SubscriptionTierTester - HTML output with:
- Step headers
- Success/failure indicators
- Debug information
- Summary of failures at end

## Files Involved

- `/plugins/controld/tests/ControlDTester.php` - Main tester class
- `/plugins/controld/tests/run.php` - Test runner script
- `/plugins/controld/includes/ControlDHelper.php` - API helper (read-only)
- `/plugins/controld/data/*_class.php` - Data models (read-only)
- `/plugins/controld/logic/*_logic.php` - Logic files (tested via tester)

## Success Criteria

All tests pass with:
- Local DB operations succeed
- ControlD API operations succeed
- DB state matches API state after each operation
- No orphaned data after cleanup

## Notes

- Tests make real API calls to ControlD
- Test data should be cleaned up even if tests fail
- Consider rate limiting on ControlD API
- May need API credentials configured for test environment
