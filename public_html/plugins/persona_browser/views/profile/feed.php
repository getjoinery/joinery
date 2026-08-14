<?php
/**
 * Persona Browser — My Feed (member)
 * URL: /profile/persona_browser/feed
 *
 * Shows stored feed posts (author, date, text, cached images, permalink).
 * New posts arrive hourly via FetchFeedTask; "Fetch now" triggers an
 * out-of-band fetch. Experimental; Facebook only.
 */
require_once(PathHelper::getThemeFilePath('PublicPage.php', 'includes'));
require_once(PathHelper::getIncludePath('plugins/persona_browser/logic/feed_logic.php'));

$page_vars = process_logic(persona_browser_feed_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new PublicPage();
$page->public_header(['title' => 'My Feed']);
?>
<div class="jy-ui">
<section class="jy-content-section">
    <div class="jy-container">

        <div class="jy-page-header">
            <div class="jy-page-header-bar">
                <h1>My Feed</h1>
                <nav class="jy-breadcrumbs" aria-label="breadcrumb">
                    <ol>
                        <li><a href="/">Home</a></li>
                        <li><a href="/profile">My Profile</a></li>
                        <li class="active">My Feed</li>
                    </ol>
                </nav>
            </div>
        </div>

        <style>
        .pb-feed { max-width: 640px; }
        .pb-toolbar { display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:1rem; }
        .pb-toolbar form { margin:0; }
        .pb-post { border:1px solid var(--jy-border, #d9dbe0); border-radius:12px; padding:1rem 1.25rem; margin-bottom:1rem; background:var(--jy-surface, #fff); }
        .pb-head { display:flex; justify-content:space-between; align-items:baseline; gap:1rem; margin-bottom:.4rem; }
        .pb-author { font-weight:600; }
        .pb-date { font-size:.8rem; color:var(--jy-muted, #6b7280); white-space:nowrap; }
        .pb-post.is-ad { opacity:.72; }
        .pb-badge-ad { display:inline-block; font-size:.7rem; font-weight:700; letter-spacing:.03em; text-transform:uppercase; color:#8a5a00; background:#ffe9b8; border:1px solid #f2d79b; border-radius:4px; padding:.05rem .35rem; margin-right:.4rem; vertical-align:middle; }
        .pb-message { white-space:pre-wrap; line-height:1.5; }
        .pb-media { margin-top:.75rem; display:flex; flex-direction:column; gap:.5rem; }
        .pb-media img { width:100%; height:auto; border-radius:8px; display:block; }
        .pb-imgnote { margin-top:.6rem; font-size:.85rem; color:var(--jy-muted, #6b7280); font-style:italic; }
        .pb-link { margin-top:.6rem; font-size:.85rem; }
        .pb-banner { border-radius:10px; padding:1rem 1.25rem; margin-bottom:1rem; }
        .pb-banner.info { background:#eef4ff; border:1px solid #c7d7fb; }
        .pb-banner.warn { background:#fff6e6; border:1px solid #f2d79b; }
        </style>

        <div class="pb-feed">

            <div class="pb-toolbar">
                <span class="jy-muted"><?php echo count($items); ?> post<?php echo count($items) === 1 ? '' : 's'; ?></span>
                <form method="post" action="/profile/persona_browser/feed">
                    <button class="jy-btn" type="submit" name="btn_fetch_now" value="1">&#8635; Refresh</button>
                </form>
            </div>

            <?php if (!empty($fetching)): ?>
                <div class="pb-banner info">Checking Facebook for new posts — this takes ~30 seconds. Hit <strong>Refresh</strong> again shortly to see them.</div>
            <?php endif; ?>

            <?php if (empty($items) && !$configured): ?>
                <div class="pb-banner warn">
                    <strong>Not set up yet.</strong> Add the service endpoint and token under
                    <a href="/admin/admin_settings">Settings</a>, then use Fetch now.
                </div>

            <?php elseif (empty($items)): ?>
                <div class="pb-banner info">No posts stored yet. Use <strong>Fetch now</strong>, or wait for the hourly pull.</div>

            <?php else: ?>
                <?php foreach ($items as $post): ?>
                <article class="pb-post<?php echo !empty($post['is_ad']) ? ' is-ad' : ''; ?>">
                    <div class="pb-head">
                        <span class="pb-author">
                            <?php if (!empty($post['is_ad'])): ?>
                                <span class="pb-badge-ad" title="<?php echo htmlspecialchars($post['ad_reason']); ?>">Ad</span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($post['author'] !== '' ? $post['author'] : 'Unknown'); ?>
                        </span>
                        <?php if (!empty($post['seen'])): ?>
                            <span class="pb-date" title="When this post was first captured"><?php echo htmlspecialchars($post['seen']); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($post['message'] !== ''): ?>
                        <div class="pb-message"><?php echo htmlspecialchars($post['message']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($post['media'])): ?>
                        <div class="pb-media">
                            <?php foreach ($post['media'] as $file): ?>
                                <img loading="lazy"
                                     alt="<?php echo htmlspecialchars($post['image_alt']); ?>"
                                     src="/profile/persona_browser/media?f=<?php echo urlencode($file); ?>">
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($post['image_alt'] !== ''): ?>
                        <div class="pb-imgnote">&#128247; <?php echo htmlspecialchars($post['image_alt']); ?></div>
                    <?php endif; ?>
                    <?php if ($post['link'] !== ''): ?>
                        <div class="pb-link">
                            <a href="<?php echo htmlspecialchars($post['link']); ?>" target="_blank" rel="noopener noreferrer">Open on Facebook &#8599;</a>
                        </div>
                    <?php endif; ?>
                </article>
                <?php endforeach; ?>
            <?php endif; ?>

        </div>

    </div>
</section>
</div>
<?php
$page->public_footer(['track' => TRUE]);
