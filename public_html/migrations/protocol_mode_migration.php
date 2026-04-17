<?php
// Protocol Mode Migration
// Migrates force_https setting to protocol_mode setting

function protocol_mode_migration() {
    require_once(__DIR__ . '/../includes/DbConnector.php');

    $dbhelper = DbConnector::get_instance();
    $dblink = $dbhelper->get_db_link();

    try {
        // Check if force_https exists and get its value
        $force_https_query = "SELECT stg_value, stg_setting_id FROM stg_settings WHERE stg_name = 'force_https'";
        $force_https_result = $dblink->query($force_https_query);
        $force_https_row = $force_https_result->fetch(PDO::FETCH_ASSOC);
        
        // Check for multiple force_https records
        $count_query = "SELECT COUNT(*) as count FROM stg_settings WHERE stg_name = 'force_https'";
        $count_result = $dblink->query($count_query);
        $count_row = $count_result->fetch(PDO::FETCH_ASSOC);
        echo "Found " . $count_row['count'] . " force_https record(s)\n";
        
        if ($force_https_row && $force_https_row['stg_value'] == '1') {
            $default_value = 'https_redirect';
            echo "Migrating force_https=1 to protocol_mode='https_redirect'\n";
        } else {
            $default_value = 'auto';
            echo "Setting default protocol_mode='auto'\n";
        }
        
        // Insert the new protocol_mode setting (only if it doesn't exist)
        $check_sql = "SELECT COUNT(*) as count FROM stg_settings WHERE stg_name = 'protocol_mode'";
        $check_result = $dblink->query($check_sql);
        $check_row = $check_result->fetch(PDO::FETCH_ASSOC);
        
        if ($check_row['count'] == 0) {
            $sql = "INSERT INTO stg_settings (stg_name, stg_value, stg_create_time, stg_update_time, stg_usr_user_id, stg_group_name) 
                    VALUES (:stg_name, :stg_value, now(), now(), 1, 'general')";
            $stmt = $dblink->prepare($sql);
            $stmt->execute([
                ':stg_name' => 'protocol_mode',
                ':stg_value' => $default_value
            ]);
            echo "Added protocol_mode setting with value '$default_value'\n";
        } else {
            echo "protocol_mode setting already exists\n";
        }
        
        // Remove old force_https setting if it exists
        if ($force_https_row) {
            echo "Found force_https setting with value: " . $force_https_row['stg_value'] . "\n";
            $delete_sql = "DELETE FROM stg_settings WHERE stg_name = 'force_https'";
            $affected_rows = $dblink->exec($delete_sql);
            echo "DELETE executed, affected rows: $affected_rows\n";
            
            // Verify deletion
            $verify_sql = "SELECT COUNT(*) as count FROM stg_settings WHERE stg_name = 'force_https'";
            $verify_result = $dblink->query($verify_sql);
            $verify_row = $verify_result->fetch(PDO::FETCH_ASSOC);
            echo "Verification: force_https count after deletion: " . $verify_row['count'] . "\n";
            
            if ($verify_row['count'] == 0) {
                echo "Successfully removed old force_https setting\n";
            } else {
                echo "WARNING: force_https setting still exists after deletion attempt\n";
            }
        } else {
            echo "No force_https setting found to remove\n";
        }
        
        return true;
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        return false;
    }
}
?>