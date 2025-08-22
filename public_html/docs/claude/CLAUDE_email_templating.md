# Email Templating System Documentation

## EmailTemplate Class Architecture

### Constructor and Initialization

The EmailTemplate constructor follows this signature:
```php
function __construct($inner_template, $recipient_user=NULL, $outer_template=NULL, $footer=NULL)
```

**Parameters:**
- `$inner_template`: Name of the inner template (stored in database)
- `$recipient_user`: Optional User object for recipient-specific template variables
- `$outer_template`: Optional outer template override (defaults to 'default_outer_template')
- `$footer`: Optional footer template override (defaults to 'default_footer')

**Initialization Process:**
1. Sets default tracking parameters (utm_source, utm_medium, etc.)
2. Loads default outer template if not provided
3. Loads default footer if not provided
4. Loads the inner template from database
5. Sets up initial template values array
6. Configures recipient information if user provided

### Template Storage and Loading

**Templates are stored in the database** in the `emt_email_templates` table with these key fields:
- `emt_name`: Template name (used for lookup)
- `emt_type`: Template type (outer=1, inner=2, footer=3)
- `emt_body`: Template content with variables and conditionals
- `emt_create_time`, `emt_update_time`, `emt_delete_time`: Audit fields

**Template Loading Process:**
1. Constructor receives template name (e.g., 'activation_content')
2. Uses `MultiEmailTemplateStore` to query database by name:
   ```php
   $templates = new MultiEmailTemplateStore(array('email_template_name'=>$inner_template));
   ```
3. Loads template body from `emt_body` field
4. Throws `EmailTemplateError` if template not found

**Three Template Layers:**
- **Inner Template**: Main content template (contains actual email content)
- **Outer Template**: Layout wrapper (contains `*!**mail_body**!*` placeholder)
- **Footer Template**: Footer content (appended to inner template)

## Variable Replacement System

### Variable Syntax

Variables use **asterisk syntax**: `*variable_name*`

**Examples:**
- `*recipient->usr_first_name*` - Access user's first name
- `*web_dir*` - Website directory URL
- `*template_name*` - Current template name
- `*email_vars*` - UTM tracking parameters

**Pipe Qualifiers** (for formatting):
- `*created_date|Y-m-d*` - Format DateTime object
- `*description|nl2br*` - Convert newlines to <br> tags
- `*timestamp|Y-m-d H:i|America/New_York*` - Format with timezone

### Variable Processing Flow

1. **Template Assembly**: Inner template + footer content concatenated
2. **Conditional Processing**: `{conditionals}` are evaluated first
3. **Variable Replacement**: `*variables*` are processed using regex:
   ```php
   '/\*([^\*\| ]+(?:\|[^\*]+)?)\*/'
   ```
4. **Outer Template Integration**: Result inserted into `*!**mail_body**!*` placeholder
5. **Subject Extraction**: If first line starts with "Subject:", it's extracted as email subject

### Built-in Template Variables

**Always Available:**
- `template_name`: Current template name
- `web_dir`: Absolute URL to website root
- `email_vars`: UTM tracking query string
- `recipient`: User object data (if user provided to constructor)

**Example Template Variables Array:**
```php
$this->template_values = array(
    'template_name' => 'activation_content',
    'web_dir' => 'https://example.com/',
    'email_vars' => 'utm_source=email&utm_medium=email&utm_content=email',
    'recipient' => $user->export_as_array() // If user provided
);
```

## Conditional System

### Basic Conditionals

**Existence Check:**
```
{variable_name}
Content shows if variable exists and is truthy
{end}
```

**Negation:**
```
{~variable_name}
Content shows if variable doesn't exist or is falsy
{end}
```

### Comparison Operators

**Supported Operators:**
- `==` (equals)
- `!=` or `<>` (not equals)
- `>` (greater than)
- `>=` (greater than or equal)
- `<` (less than)
- `<=` (less than or equal)
- `%%` (modulo)
- `&` or `includes` (bitwise AND)

**Examples:**
```
{recipient->usr_permission_level >= 5}
<p>Admin content here</p>
{end}

{resend == true}
<p>This is a resend of your activation email.</p>
{end}
```

### Variable Operations

**Inside conditionals, you can set variables:**
```
{condition}
[counter=1]
[email_type="activation"]
[priority setbit 2]
Content here
{end}
```

**Operation Types:**
- `=` (assignment)
- `+=` (addition)
- `-=` (subtraction)
- `setbit` (bitwise operations)

## Subject Processing

### Subject Extraction

**How subjects work:**
1. Template is processed (conditionals + variables)
2. First line is checked with: `stripos(trim($html_lines[0]), 'subject:') === 0`
3. If found, subject is extracted: `substr(trim($html_lines[0]), 8)`
4. Subject line is removed from email body
5. `$this->email_has_content` is set to TRUE

**Example Template with Subject:**
```
Subject: Welcome to *company_name*, *recipient->usr_first_name*!

<h1>Welcome!</h1>
<p>Hello *recipient->usr_first_name*,</p>
<p>Thanks for joining us...</p>
```

### Why getEmailSubject() Returns Null

`getEmailSubject()` returns null when:
1. No subject line in template (doesn't start with "Subject:")
2. Template processing failed
3. `fill_template()` hasn't been called yet
4. Template has no content (empty body)

**The `email_has_content` flag is set to TRUE only when:**
- A subject line is found and extracted, OR
- Content exists after processing

## Template Processing Flow

### Complete Processing Sequence

1. **Initialization**
   ```php
   $email = new EmailTemplate('activation_content', $user);
   ```

2. **Template Loading**
   - Loads inner, outer, and footer templates from database
   - Sets up default template variables

3. **Fill Template**
   ```php
   $email->fill_template(array(
       'act_code' => 'ABC123',
       'resend' => false
   ));
   ```

4. **Processing Steps**
   - Merge passed values with template defaults
   - Process conditionals in inner template
   - Process conditionals in footer (if exists)
   - Concatenate inner + footer content
   - Process variable replacements (*variable*)
   - Extract subject if present
   - Insert content into outer template (`*!**mail_body**!*`)
   - Generate text version
   - Add UTM tracking to links

5. **Ready for Sending**
   ```php
   if ($email->hasContent()) {
       $email->send();
   }
   ```

## Working Examples

### Real Working Template Usage

**From Activation.php:**
```php
static function email_activate_send($user, $resend=FALSE) {
    $act_code = self::getTempCode($user->key, '30 days', Activation::EMAIL_VERIFY, NULL, $user->get('usr_email'));
    $activation_email = new EmailTemplate('activation_content', $user);
    $activation_email->fill_template(array(
        'resend' => $resend,
        'act_code' => $act_code,
    ));
    return $activation_email->send();
}
```

**Expected Template Structure:**
```
Subject: {resend}Re-{end}Activate your account

{~resend}
<h1>Welcome to Our Site!</h1>
<p>Please activate your account by clicking the link below:</p>
{end}

{resend}
<h1>Account Activation Reminder</h1>
<p>This is a reminder to activate your account:</p>
{end}

<p>Hello *recipient->usr_first_name*,</p>
<p><a href="*web_dir*activate?code=*act_code*">Click here to activate</a></p>
```

### Template Types and Their Roles

**Inner Template ("activation_content"):**
- Contains main email content
- Includes subject line
- Uses variables and conditionals
- Gets inserted into outer template

**Outer Template ("default_outer_template"):**
- Provides email layout/design
- Contains `*!**mail_body**!*` placeholder
- Usually includes header, styling, footer structure

**Footer Template ("default_footer"):**
- Appended to inner template content
- Often contains unsubscribe links, company info
- Processed for variables/conditionals like inner template

## Common Issues and Solutions

### Template Not Found Errors
- Ensure template name exists in `emt_email_templates` table
- Check `emt_name` field matches exactly (case sensitive)
- Verify template is not soft-deleted (`emt_delete_time` is NULL)

### hasContent() Returns False
- Template must have a "Subject:" line to set `email_has_content = TRUE`
- Template body cannot be empty after processing
- Variables that don't exist return NULL (not errors)

### Variable Replacement Issues
- Use asterisk syntax: `*variable*` not `{variable}`
- Array access uses arrows: `*recipient->usr_first_name*`
- Non-existent variables are replaced with empty string (NULL)

### Conditional Syntax
- Always use `{end}` to close conditionals
- Nesting is supported but must be properly balanced
- Variable operations use square brackets: `[variable=value]`

This documentation provides a complete understanding of how the EmailTemplate system works, from database storage through variable processing to final email generation.