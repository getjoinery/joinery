/*
 * /admin/admin_survey: each question's Remove form, written after the table,
 * goes into its cell (adm/admin_survey.php).
 *
 * @version 1.0
 */
document.addEventListener('DOMContentLoaded', function() {
    // Move each deferred form into its table cell placeholder
    var forms = document.querySelectorAll('form[id^="remove_form_"]');
    forms.forEach(function(form) {
        var formId = form.getAttribute('id');
        var placeholder = document.getElementById(formId);
        // The form and placeholder have the same ID, but the placeholder is a div in the table
        // and the form is output after the table. We need to find the div and replace it with the form.
        if (placeholder && placeholder.tagName === 'DIV') {
            placeholder.parentNode.replaceChild(form, placeholder);
        }
    });
});
