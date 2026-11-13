/*
 * /utils/email_setup_check: the Check Domain button shows that it is working
 * (utils/email_setup_check.php).
 *
 * @version 1.0
 */
// Form submission with loading state
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('domain_check_form');
    const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
    const checkbox = form ? form.querySelector('input[name="complete"]') : null;

    if (form && submitBtn) {
        form.addEventListener('submit', function(e) {
            // Determine scan type
            const isComprehensive = checkbox && checkbox.checked;
            const scanType = isComprehensive ? 'Comprehensive' : 'Quick';
            const estimatedTime = isComprehensive ? '30-60 seconds' : '5-10 seconds';

            // Disable the submit button
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking...';

            // Show loading status
            const loadingHtml = `
                <div id="loading-status" class="alert alert-primary mt-3">
                    <div class="d-flex align-items-center">
                        <div class="spinner-border spinner-border-sm me-3" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <div>
                            <strong>Running ${scanType} Domain Authentication Check...</strong><br>
                            <small class="text-muted">Estimated time: ${estimatedTime}. Please wait while we analyze DNS records.</small>
                        </div>
                    </div>
                </div>
            `;

            // Insert loading status after the form
            form.insertAdjacentHTML('afterend', loadingHtml);

            // Scroll to loading indicator
            document.getElementById('loading-status').scrollIntoView({ behavior: 'smooth' });
        });
    }
});
