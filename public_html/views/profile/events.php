<?php
/**
 * Full event history sub-page.
 *
 * @version 2.0
 */

require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getThemeFilePath('events_profile_logic.php', 'logic'));

$page_vars = process_logic(events_profile_logic($_GET, $_POST));

$page = new PublicPage();
$page->public_header([
	'title' => 'My Events',
]);

$status_filter = $page_vars['status_filter'];
$session = $page_vars['session'];
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">

        <div class="jy-page-header">
            <div class="jy-page-header-bar">
                <h1>My Events</h1>
                <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                    <ol>
                        <li><a href="/">Home</a></li>
                        <li><a href="/profile">Dashboard</a></li>
                        <li class="active">Events</li>
                    </ol>
                </nav>
            </div>
        </div>

        <nav class="tabs" aria-label="Status filter">
            <a class="tab<?php echo $status_filter == 'all' ? ' active' : ''; ?>" href="/profile/events">All</a>
            <a class="tab<?php echo $status_filter == 'active' ? ' active' : ''; ?>" href="/profile/events?status=active">Active</a>
            <a class="tab<?php echo $status_filter == 'completed' ? ' active' : ''; ?>" href="/profile/events?status=completed">Completed</a>
            <a class="tab<?php echo $status_filter == 'expired' ? ' active' : ''; ?>" href="/profile/events?status=expired">Expired</a>
            <a class="tab<?php echo $status_filter == 'canceled' ? ' active' : ''; ?>" href="/profile/events?status=canceled">Canceled</a>
        </nav>

        <div class="card">
            <div class="card-header">
                <h6 style="margin: 0;">Events &amp; Courses</h6>
            </div>
            <div class="card-body">
                <?php if (empty($page_vars['event_registrations'])): ?>
                    <p class="muted" style="margin: 0;">
                        <?php echo $status_filter == 'all' ? 'You have no event registrations.' : 'No ' . htmlspecialchars($status_filter) . ' events found.'; ?>
                    </p>
                <?php else: ?>
                    <?php foreach ($page_vars['event_registrations'] as $i => $event): ?>
                    <div style="<?php echo $i > 0 ? 'border-top: 1px solid var(--jy-color-border);' : ''; ?> padding: var(--jy-space-3) 0; display: flex; justify-content: space-between; align-items: flex-start; gap: var(--jy-space-4); flex-wrap: wrap;">
                        <div style="flex: 1; min-width: 0;">
                            <h6 style="margin: 0 0 var(--jy-space-1);">
                                <a href="<?php echo htmlspecialchars($event['event_link']); ?>"><?php echo htmlspecialchars($event['event_name']); ?></a>
                            </h6>
                            <?php if ($event['event_time']): ?>
                            <p class="muted text-sm" style="margin: 0;"><?php echo $event['event_time']; ?></p>
                            <?php endif; ?>
                        </div>
                        <div style="flex-shrink: 0; display: flex; align-items: center; gap: var(--jy-space-2);">
                            <?php
                            $badge_class = 'badge-success';
                            if ($event['event_status'] == 'Expired' || $event['event_status'] == 'Canceled' || $event['event_status'] == 'Completed') {
                                $badge_class = 'badge-muted';
                            }
                            $badge_text = $event['event_status'];
                            if ($event['event_status'] == 'Active' && $event['event_expires']) {
                                $badge_text = 'Expires ' . htmlspecialchars($event['event_expires']);
                            }
                            ?>
                            <span class="badge <?php echo $badge_class; ?>"><?php echo $badge_text; ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if ($page_vars['num_events'] > 0): ?>
            <div class="card-footer muted text-sm" style="display: flex; justify-content: space-between; align-items: center;">
                <span><?php echo $page_vars['num_events']; ?> event<?php echo $page_vars['num_events'] != 1 ? 's' : ''; ?></span>
                <?php
                $pager = $page_vars['pager'];
                if ($pager->num_records() > $pager->num_per_page()):
                ?>
                <div class="pagination">
                    <?php if ($pager->is_valid_page('-1')): ?>
                        <a href="<?php echo htmlspecialchars($pager->get_url('-1')); ?>" class="page-link">&laquo;</a>
                    <?php else: ?>
                        <span class="page-link disabled">&laquo;</span>
                    <?php endif; ?>
                    <?php for ($p = 1; $p <= $pager->total_pages(); $p++): ?>
                        <?php if ($p == $pager->current_page()): ?>
                            <span class="page-link active"><?php echo $p; ?></span>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars($pager->get_url($p)); ?>" class="page-link"><?php echo $p; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($pager->is_valid_page('+1')): ?>
                        <a href="<?php echo htmlspecialchars($pager->get_url('+1')); ?>" class="page-link">&raquo;</a>
                    <?php else: ?>
                        <span class="page-link disabled">&raquo;</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>
</section>
</div>
<?php
$page->public_footer(['track' => TRUE]);
?>
