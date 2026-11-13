/*
 * /admin/admin_email_template_edit: a character count under the subject.
 *
 * @version 1.0
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var subjectField = document.getElementById('emt_subject');
        if (!subjectField) return;

        // Add counter display after the subject field
        var counterHtml = '<div class="field-help"><span id="subject-char-count">0/255</span> characters</div>';
        subjectField.insertAdjacentHTML('afterend', counterHtml);

        var counterElement = document.getElementById('subject-char-count');

        // Update counter on input
        subjectField.addEventListener('input', function() {
            var length = this.value.length;
            counterElement.textContent = length + '/255';

            if (length > 255) {
                this.classList.add('error');
                counterElement.classList.add('error');
            } else {
                this.classList.remove('error');
                counterElement.classList.remove('error');
            }
        });

        // Initialize counter
        subjectField.dispatchEvent(new Event('input'));
    });
})();
