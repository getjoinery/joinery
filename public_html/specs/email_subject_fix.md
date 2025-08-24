# Email Subject Column Implementation

**Status:** ✅ PLANNED  
**Priority:** HIGH  
**Estimated Time:** 4-6 hours  

## Overview

Remove the inline `subject:` metadata parsing from email templates and add a dedicated `emt_subject` column to the database. This simplifies template processing and makes subjects more explicit and manageable.

## Current State

### Current Subject Processing
Email templates currently extract subject lines from template content using this pattern:

**Location:** `/includes/EmailTemplate.php:264-268`
```php
// Extract subject if present
if ($html_lines && stripos(trim($html_lines[0]), 'subject:') === 0) {
    $this->email_subject = substr(trim($html_lines[0]), 8);
    $html = implode("\n", array_slice($html_lines, 1));
    $this->email_has_content = true;
}
```

### Current Template Format
```
subject:Welcome to the System
<h1>Welcome *user->usr_first_name*!</h1>
<p>Your account has been created.</p>
```

### Problems with Current Approach
1. **Subject mixed with content** - Subject line is embedded in template body
2. **Manual parsing required** - Complex string processing to extract subject
3. **Easy to break** - Template formatting errors can break subject extraction
4. **No validation** - No way to enforce subject presence
5. **Not obvious to editors** - Subject line isn't clearly separated from content

## Proposed Solution

### New Database Schema
Add `emt_subject` column to `emt_email_templates` table:

```sql
ALTER TABLE emt_email_templates 
ADD COLUMN emt_subject VARCHAR(255);
```

### New Template Format
**Database fields:**
- `emt_subject`: "Welcome to the System"
- `emt_body`: `<h1>Welcome *user->usr_first_name*!</h1><p>Your account has been created.</p>`

### New Admin Interface
Add subject field to template editing form with:
- Required field validation (JavaScript + server-side)
- Character count indicator
- Clear separation from template body

## Implementation Plan

### Phase 1: Database Migration (30 minutes)

#### Migration Script
**Location:** `/migrations/migrations.php`

```php
$migration = array();
$migration['database_version'] = '0.XX';
$migration['test'] = "SELECT COUNT(*) as count FROM information_schema.columns 
                      WHERE table_name = 'emt_email_templates' 
                      AND column_name = 'emt_subject'";
$migration['migration_sql'] = "
    -- Add subject column to email templates
    ALTER TABLE emt_email_templates 
    ADD COLUMN IF NOT EXISTS emt_subject VARCHAR(255);
    
    -- Extract existing subject lines from template bodies
    UPDATE emt_email_templates 
    SET emt_subject = CASE 
        WHEN emt_body ~ '^subject:\\s*.*\\n'
        THEN TRIM(SUBSTRING(emt_body FROM '^subject:\\s*(.*)\\n'))
        ELSE 'Default Subject - Please Update'
    END
    WHERE emt_subject IS NULL;
    
    -- Remove subject lines from template bodies
    UPDATE emt_email_templates 
    SET emt_body = CASE 
        WHEN emt_body ~ '^subject:\\s*.*\\n'
        THEN REGEXP_REPLACE(emt_body, '^subject:\\s*.*\\n', '', 'i')
        ELSE emt_body
    END;
    
    -- Set subject as required (after data migration)
    ALTER TABLE emt_email_templates 
    ALTER COLUMN emt_subject SET NOT NULL;
";
$migration['migration_file'] = NULL;
$migrations[] = $migration;
```

#### Update Data Class
**Location:** `/data/email_templates_class.php`

Add to `$fields` array:
```php
'emt_subject' => 'Email subject line',
```

Add to `$field_specifications` array:
```php
'emt_subject' => array('type'=>'varchar(255)', 'is_nullable'=>false),
```

Add to `$required_fields` array:
```php
public static $required_fields = array(
    'emt_name',
    'emt_subject'  // Add this line
);
```

### Phase 2: Admin Interface Updates (90 minutes)

#### Update Email Template Edit Form
**Location:** `/adm/admin_email_template_edit.php`

Add subject field after name field:

```php
// Add subject field
$form_writer->field_text(
    'emt_subject',
    'Subject Line',
    'Email subject line (required)',
    $email_template->get('emt_subject'),
    array(
        'required' => true,
        'maxlength' => 255,
        'placeholder' => 'Enter email subject line'
    )
);
```

#### JavaScript Validation
Add to form validation:

```javascript
// Subject line validation
if (!$('#emt_subject').val().trim()) {
    validation_errors.push('Subject line is required');
    $('#emt_subject').addClass('error');
} else if ($('#emt_subject').val().length > 255) {
    validation_errors.push('Subject line must be 255 characters or less');
    $('#emt_subject').addClass('error');
} else {
    $('#emt_subject').removeClass('error');
}

// Character counter for subject
$('#emt_subject').on('input', function() {
    const length = $(this).val().length;
    $('#subject-char-count').text(length + '/255');
    
    if (length > 255) {
        $(this).addClass('error');
        $('#subject-char-count').addClass('error');
    } else {
        $(this).removeClass('error');
        $('#subject-char-count').removeClass('error');
    }
});
```

#### Add Character Counter HTML
```html
<div class="field-help">
    <span id="subject-char-count">0/255</span> characters
</div>
```

### Phase 3: Template Processing Updates (60 minutes)

#### Remove Subject Extraction Logic
**Location:** `/includes/EmailTemplate.php`

**REMOVE lines 264-268:**
```php
// Extract subject if present
if ($html_lines && stripos(trim($html_lines[0]), 'subject:') === 0) {
    $this->email_subject = substr(trim($html_lines[0]), 8);
    $html = implode("\n", array_slice($html_lines, 1));
    $this->email_has_content = true;
}
```

#### Update Constructor to Load Subject
**Location:** `/includes/EmailTemplate.php:100-105`

Modify template loading to include subject:

```php
if ($count) {
    $this_template = $templates->get(0);
    $this->inner_template = $this_template->get('emt_body');
    $this->email_subject = $this_template->get('emt_subject'); // Add this line
} else {
    throw new EmailTemplateError('We could not find the template ' . $inner_template);
}
```

#### Server-Side Validation
**Location:** `/data/email_templates_class.php`

Server-side validation is automatically handled by adding `'emt_subject'` to the `$required_fields` array. The SystemBase class will enforce this requirement during `save()` operations.

### Phase 4: Admin Interface Listing Updates (30 minutes)

#### Update Email Template List View
**Location:** `/adm/admin_email_templates.php`

Add subject column to template listing table:

```php
// Add to table headers
<th>Subject</th>

// Add to table rows
<td><?= htmlspecialchars($template->get('emt_subject')) ?></td>
```

### Phase 5: Testing & Validation (60 minutes)

#### Create Test Template
**Location:** `/tests/email/fixtures/create_subject_test_template.php`

```php
<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');
PathHelper::requireOnce('includes/Globalvars.php');
PathHelper::requireOnce('data/email_templates_class.php');

try {
    // Create test template with subject
    $testTemplate = new EmailTemplateStore(NULL);
    $testTemplate->set('emt_name', 'subject_validation_test');
    $testTemplate->set('emt_type', 2); // Inner template
    $testTemplate->set('emt_subject', 'Test Subject - ID *test_id*');
    $testTemplate->set('emt_body', '<h1>Test Email</h1><p>Test ID: *test_id*</p>');
    $testTemplate->save();
    
    echo "Subject test template created successfully.\n";
    
} catch (Exception $e) {
    echo "Error creating subject test template: " . $e->getMessage() . "\n";
    exit(1);
}
?>
```

#### Add Validation Tests
**Location:** `/tests/email/suites/TemplateTests.php`

```php
public function testSubjectValidation() {
    echo "Testing subject validation...\n";
    
    try {
        // Test template with missing subject should throw exception
        $template = EmailTemplate::CreateLegacyTemplate('subject_validation_test', null);
        $template->fill_template(['test_id' => '12345']);
        
        // Should have subject from database
        assert($template->getSubject() === 'Test Subject - ID 12345');
        echo "✅ Subject loaded correctly from database\n";
        
    } catch (Exception $e) {
        echo "❌ Subject validation test failed: " . $e->getMessage() . "\n";
        return false;
    }
    
    return true;
}

public function testMissingSubjectException() {
    echo "Testing missing subject exception...\n";
    
    try {
        // Create template without subject (should fail validation)
        $template = new EmailTemplateStore(NULL);
        $template->set('emt_name', 'no_subject_test');
        $template->set('emt_type', 2);
        $template->set('emt_body', '<p>No subject template</p>');
        // Deliberately not setting emt_subject
        $template->save(); // This should fail due to NOT NULL constraint
        
        echo "❌ Template without subject was allowed - validation failed\n";
        return false;
        
    } catch (Exception $e) {
        echo "✅ Missing subject properly rejected: " . $e->getMessage() . "\n";
        return true;
    }
}
```

#### Syntax Validation
Run PHP syntax check on all modified files:

```bash
php -l /path/to/data/email_templates_class.php
php -l /path/to/includes/EmailTemplate.php
php -l /path/to/adm/admin_email_template_edit.php
php -l /path/to/adm/admin_email_templates.php
```

## Files Modified

### Database
- `/migrations/migrations.php` - Add emt_subject column and migrate data
- Database schema: `emt_email_templates` table

### Data Layer
- `/data/email_templates_class.php` - Add subject field specifications

### Core Processing
- `/includes/EmailTemplate.php` - Remove subject parsing, add validation

### Admin Interface
- `/adm/admin_email_template_edit.php` - Add subject field with validation
- `/adm/admin_email_templates.php` - Show subject in listing

### Testing
- `/tests/email/fixtures/create_subject_test_template.php` - New test fixture
- `/tests/email/suites/TemplateTests.php` - Add subject validation tests

## Success Criteria

- [ ] Database migration adds `emt_subject` column successfully
- [ ] Existing templates have subjects extracted and migrated
- [ ] Admin interface requires subject field
- [ ] JavaScript validation prevents empty subjects
- [ ] Server-side validation enforces required subject
- [ ] EmailTemplate throws exception for missing subjects
- [ ] Template listing shows subject column
- [ ] All existing email functionality continues to work
- [ ] All PHP files pass syntax validation
- [ ] Test suite passes with new subject validation tests

## Backward Compatibility

### Breaking Changes
- ❌ Old templates with `subject:` lines in body will have subjects extracted to database field
- ❌ Templates created without subjects will be rejected
- ✅ All existing EmailTemplate methods continue to work
- ✅ Template processing API remains the same

### Migration Safety
- Migration extracts existing subjects before removing from body
- Default subject added for templates without subjects
- Database constraint added AFTER data migration

## Risk Assessment

**Low Risk Changes:**
- Database column addition
- Admin form field addition
- Template listing updates

**Medium Risk Changes:**
- Subject extraction logic removal (core functionality)
- Database migration (existing data modification)

**Mitigation:**
- Backup database before migration
- Test migration on copy of production data
- Maintain backward compatibility in EmailTemplate API
- Comprehensive testing suite

## Future Enhancements

### Phase 2 Possibilities (Not Included)
- Subject line templates with variables (already supported)
- Subject line A/B testing
- Multi-language subject support
- Subject line character optimization suggestions
- Integration with email marketing best practices

## Implementation Notes

### Character Limits
- Subject limited to 255 characters (email standard recommendation: 50-60 characters)
- Consider adding warning for subjects over 60 characters

### Variable Processing
- Subjects support same variable substitution as body content
- Example: `emt_subject = "Welcome *user->usr_first_name*!"`

### Error Handling
- Clear error messages for missing subjects
- Validation occurs both client-side and server-side
- Database constraints prevent invalid data

### Testing Strategy
- Test existing templates continue to work
- Test new templates require subjects
- Test subject variable substitution
- Test admin interface validation
- Test database migration with various subject formats