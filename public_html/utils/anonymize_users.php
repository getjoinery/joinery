<?php
/**
 * User Data Anonymization Script
 * 
 * This script anonymizes user data for testing purposes by:
 * 1. Randomly exchanging first and last names between users with userid > 40
 * 2. Modifying email addresses by adding/removing random characters before the @ sign
 * 
 * WARNING: This will permanently modify user data. Only use on test databases.
 * REQUIRES: System admin permission level (10)
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

// Require login and highest permission level
$session = SessionControl::get_instance();
$session->check_permission(10);

// Safety check - require confirmation
if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
    echo "<h2>User Data Anonymization Script</h2>";
    echo "<p><strong>WARNING:</strong> This script will permanently modify user data in your database.</p>";
    echo "<p>This should ONLY be run on test databases, never on production data.</p>";
    echo "<p>The script will:</p>";
    echo "<ul>";
    echo "<li>Randomly exchange first and last names between users with user ID > 40</li>";
    echo "<li>Modify email addresses by adding/removing random characters before the @ sign</li>";
    echo "</ul>";
    echo "<p><strong>To proceed, add ?confirm=yes to the URL</strong></p>";
    exit;
}

try {
    $dbhelper = DbConnector::get_instance();
    $dblink = $dbhelper->get_db_link();

    // Start transaction
    $dblink->beginTransaction();

    // Get all users with userid > 40
    $stmt = $dblink->prepare("SELECT usr_user_id, usr_first_name, usr_last_name, usr_email FROM usr_users WHERE usr_user_id > 40");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        echo "<p>No users found with user ID > 40</p>";
        exit;
    }

    echo "<h2>Anonymizing " . count($users) . " users...</h2>";

    // Create arrays of first names and last names to shuffle
    $first_names = array();
    $last_names = array();
    
    foreach ($users as $user) {
        $first_names[] = $user['usr_first_name'];
        $last_names[] = $user['usr_last_name'];
    }

    // Shuffle the name arrays
    shuffle($first_names);
    shuffle($last_names);

    // Function to anonymize email local part (before @)
    function anonymizeEmailLocal($email) {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email; // Invalid email format, return as-is
        }
        
        $local = $parts[0];
        $domain = $parts[1];
        
        // Random operations to modify the local part
        $operations = rand(1, 3);
        
        for ($i = 0; $i < $operations; $i++) {
            $action = rand(1, 3);
            
            switch ($action) {
                case 1: // Add random character
                    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
                    $pos = rand(0, strlen($local));
                    $char = $chars[rand(0, strlen($chars) - 1)];
                    $local = substr($local, 0, $pos) . $char . substr($local, $pos);
                    break;
                    
                case 2: // Remove random character (if length > 3)
                    if (strlen($local) > 3) {
                        $pos = rand(0, strlen($local) - 1);
                        $local = substr($local, 0, $pos) . substr($local, $pos + 1);
                    }
                    break;
                    
                case 3: // Replace random character
                    if (strlen($local) > 0) {
                        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
                        $pos = rand(0, strlen($local) - 1);
                        $char = $chars[rand(0, strlen($chars) - 1)];
                        $local[$pos] = $char;
                    }
                    break;
            }
        }
        
        // Ensure minimum length
        if (strlen($local) < 3) {
            $local .= 'usr';
        }
        
        return $local . '@' . $domain;
    }

    // Update each user with shuffled names and anonymized emails
    $update_stmt = $dblink->prepare("UPDATE usr_users SET usr_first_name = ?, usr_last_name = ?, usr_email = ? WHERE usr_user_id = ?");
    
    foreach ($users as $index => $user) {
        $new_first_name = $first_names[$index];
        $new_last_name = $last_names[$index];
        $new_email = anonymizeEmailLocal($user['usr_email']);
        
        $update_stmt->execute([$new_first_name, $new_last_name, $new_email, $user['usr_user_id']]);
        
        echo "<p>User ID {$user['usr_user_id']}: ";
        echo "{$user['usr_first_name']} {$user['usr_last_name']} ({$user['usr_email']}) → ";
        echo "{$new_first_name} {$new_last_name} ({$new_email})</p>";
    }

    // Commit transaction
    $dblink->commit();
    
    echo "<h3>✓ Anonymization completed successfully!</h3>";
    echo "<p>" . count($users) . " users have been anonymized.</p>";

} catch (Exception $e) {
    // Rollback on error
    if ($dblink->inTransaction()) {
        $dblink->rollback();
    }
    
    echo "<h3>✗ Error during anonymization:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>No changes were made to the database.</p>";
}
?>