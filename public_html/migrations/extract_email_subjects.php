<?php
/**
 * Migration to extract subject lines from email template bodies
 * and populate the new emt_subject column
 */

function extract_email_subjects() {
    $dbconnector = DbConnector::get_instance();
    $dblink = $dbconnector->get_db_link();
    
    try {
        // Get all email templates that don't have subjects set
        $sql = "SELECT emt_email_template_id, emt_name, emt_body 
                FROM emt_email_templates 
                WHERE emt_subject IS NULL OR emt_subject = ''";
        $q = $dblink->prepare($sql);
        $q->execute();
        $templates = $q->fetchAll(PDO::FETCH_ASSOC);
        
        $updated_count = 0;
        $skipped_count = 0;
        
        foreach ($templates as $template) {
            $template_id = $template['emt_email_template_id'];
            $template_name = $template['emt_name'];
            $body = $template['emt_body'];
            
            // Extract subject line if it exists
            $extracted_subject = null;
            $updated_body = $body;
            
            // Split body into lines
            $lines = preg_split('/[\r\n]/', $body, null, PREG_SPLIT_NO_EMPTY);
            
            if (!empty($lines)) {
                $first_line = trim($lines[0]);
                
                // Check if first line starts with "subject:"
                if (stripos($first_line, 'subject:') === 0) {
                    // Extract the subject (remove "subject:" prefix)
                    $extracted_subject = trim(substr($first_line, 8));
                    
                    // Remove the subject line from the body
                    $updated_body = implode("\n", array_slice($lines, 1));
                    
                    // Update the template with extracted subject and cleaned body
                    $update_sql = "UPDATE emt_email_templates 
                                   SET emt_subject = :subject, emt_body = :body 
                                   WHERE emt_email_template_id = :id";
                    $update_q = $dblink->prepare($update_sql);
                    $update_q->execute([
                        ':subject' => $extracted_subject,
                        ':body' => $updated_body,
                        ':id' => $template_id
                    ]);
                    
                    $updated_count++;
                    echo "✓ Updated template '{$template_name}' with subject: '{$extracted_subject}'\n";
                } else {
                    // No subject line found, set a default subject
                    $default_subject = "Email from " . ucwords(str_replace('_', ' ', $template_name));
                    
                    $update_sql = "UPDATE emt_email_templates 
                                   SET emt_subject = :subject 
                                   WHERE emt_email_template_id = :id";
                    $update_q = $dblink->prepare($update_sql);
                    $update_q->execute([
                        ':subject' => $default_subject,
                        ':id' => $template_id
                    ]);
                    
                    $skipped_count++;
                    echo "- Set default subject for template '{$template_name}': '{$default_subject}'\n";
                }
            } else {
                // Empty body, set basic default
                $default_subject = "Email Template";
                
                $update_sql = "UPDATE emt_email_templates 
                               SET emt_subject = :subject 
                               WHERE emt_email_template_id = :id";
                $update_q = $dblink->prepare($update_sql);
                $update_q->execute([
                    ':subject' => $default_subject,
                    ':id' => $template_id
                ]);
                
                $skipped_count++;
                echo "- Set basic default subject for empty template '{$template_name}'\n";
            }
        }
        
        echo "\nMigration completed successfully!\n";
        echo "Templates with extracted subjects: {$updated_count}\n";
        echo "Templates with default subjects: {$skipped_count}\n";
        echo "Total templates processed: " . ($updated_count + $skipped_count) . "\n";
        
        return true;
        
    } catch (Exception $e) {
        echo "ERROR: Migration failed - " . $e->getMessage() . "\n";
        return false;
    }
}
?>