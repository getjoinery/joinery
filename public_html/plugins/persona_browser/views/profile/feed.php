<?php
/**
 * Persona Browser — My Feed (member)
 * URL: /profile/persona_browser/feed
 *
 * Shows stored feed posts (author, date, text, cached images, permalink) and
 * the network's current Stories as cards in the same list, newest-captured
 * first. New posts arrive hourly via FetchFeedTask; "Fetch now" triggers an
 * out-of-band fetch. Experimental; Facebook only.
 *
 * For labelling ads by hand, every post card has an Ad button (the owner's
 * own verdict; a marked post leaves the page) and a "Reviewed down to here"
 * menu item, and ?review=1 lists only the posts not yet reviewed.
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
        .pb-head { display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:.4rem; }
        .pb-author { font-weight:600; }
        .pb-headside { display:flex; align-items:center; gap:.15rem; flex-shrink:0; }
        .pb-date { font-size:.8rem; color:var(--jy-muted, #6b7280); white-space:nowrap; margin-right:.35rem; }
        .pb-iconbtn { border:0; background:none; cursor:pointer; color:var(--jy-muted, #6b7280); font-size:1.1rem; line-height:1; padding:.3rem .45rem; border-radius:6px; }
        .pb-iconbtn:hover { background:var(--jy-hover, #f1f2f4); color:inherit; }
        .pb-menu { position:relative; }
        .pb-menu-pop { position:absolute; right:0; top:100%; z-index:20; min-width:11rem; background:var(--jy-surface, #fff); border:1px solid var(--jy-border, #d9dbe0); border-radius:8px; box-shadow:0 4px 16px rgba(0,0,0,.12); padding:.25rem; }
        .pb-menu-item { display:block; width:100%; text-align:left; border:0; background:none; cursor:pointer; padding:.45rem .6rem; border-radius:6px; font-size:.9rem; }
        .pb-menu-item:hover { background:var(--jy-hover, #f1f2f4); }
        .pb-post.is-ad { opacity:.72; }
        .pb-post.is-ad.is-owner-ad { opacity:1; }
        .pb-adbtn { border:1px solid var(--jy-border, #d9dbe0); background:none; cursor:pointer; color:var(--jy-muted, #6b7280); font-size:.7rem; font-weight:700; letter-spacing:.03em; text-transform:uppercase; border-radius:4px; padding:.15rem .45rem; margin-right:.2rem; }
        .pb-adbtn:hover { background:var(--jy-hover, #f1f2f4); color:inherit; }
        .pb-adbtn[aria-pressed="true"] { color:#8a5a00; background:#ffe9b8; border-color:#f2d79b; }
        .pb-reviewed { font-size:.8rem; color:#15803d; margin-right:.3rem; }
        .pb-review-end { display:flex; justify-content:center; margin:1.5rem 0; }
        .pb-badge-ad { display:inline-block; font-size:.7rem; font-weight:700; letter-spacing:.03em; text-transform:uppercase; color:#8a5a00; background:#ffe9b8; border:1px solid #f2d79b; border-radius:4px; padding:.05rem .35rem; margin-right:.4rem; vertical-align:middle; }
        /* Network identity — a subtle per-network accent + glyph so cards from
           different feeds stay tellable apart as more networks are added. */
        .pb-net-facebook { border-left:3px solid #1877f2; }
        .pb-badge-net { display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; border-radius:50%; margin-right:.45rem; vertical-align:-3px; }
        .pb-badge-net svg { width:10px; height:10px; fill:#fff; display:block; }
        .pb-net-facebook .pb-badge-net { background:#1877f2; }
        .pb-message { white-space:pre-wrap; line-height:1.5; }
        .pb-message.pb-clamped { max-height:9em; overflow:hidden; position:relative; }
        .pb-message.pb-clamped::after { content:""; position:absolute; left:0; right:0; bottom:0; height:2.5em; background:linear-gradient(to bottom, transparent, var(--jy-surface, #fff)); }
        .pb-more { display:inline-block; margin-top:.35rem; border:0; background:none; padding:0; cursor:pointer; color:var(--jy-link, #1d4ed8); font-size:.9rem; font-weight:600; }
        .pb-more:hover { text-decoration:underline; }
        .pb-media { margin-top:.75rem; display:flex; flex-direction:column; gap:.5rem; }
        .pb-media img { width:100%; height:auto; border-radius:8px; display:block; }
        .pb-imgnote { margin-top:.6rem; font-size:.85rem; color:var(--jy-muted, #6b7280); font-style:italic; }
        .pb-link { margin-top:.6rem; font-size:.85rem; }
        .pb-badge-story { display:inline-block; font-size:.7rem; font-weight:700; letter-spacing:.03em; text-transform:uppercase; color:#fff; background:#1877f2; border-radius:4px; padding:.05rem .35rem; margin-right:.4rem; vertical-align:middle; }
        .pb-story-frame { position:relative; display:block; width:min(100%, 270px); margin:.75rem auto 0; border-radius:8px; overflow:hidden; background:linear-gradient(160deg, #1877f2, #0a3d80); aspect-ratio:9/16; }
        .pb-story-frame img.pb-story-preview { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
        .pb-story-avatar { position:absolute; top:.6rem; left:.6rem; width:40px; height:40px; border-radius:50%; border:3px solid #1877f2; object-fit:cover; z-index:1; background:#fff; }
        .pb-banner { border-radius:10px; padding:1rem 1.25rem; margin-bottom:1rem; }
        .pb-banner.info { background:#eef4ff; border:1px solid #c7d7fb; }
        .pb-banner.warn { background:#fff6e6; border:1px solid #f2d79b; }
        </style>

        <div class="pb-feed">

            <div class="pb-toolbar">
                <span class="jy-muted" id="pb-count"><?php echo count($items); ?> item<?php echo count($items) === 1 ? '' : 's'; ?></span>
                <?php if ($review): ?>
                    <a href="/profile/persona_browser/feed">Back to the feed</a>
                <?php else: ?>
                    <a href="/profile/persona_browser/feed?review=1">Review for ads (<?php echo (int)$unreviewed; ?> not reviewed)</a>
                <?php endif; ?>
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

            <?php elseif (empty($items) && $review): ?>
                <div class="pb-banner info">Every post has been reviewed.</div>

            <?php elseif (empty($items)): ?>
                <div class="pb-banner info">No posts stored yet. Use <strong>Fetch now</strong>, or wait for the hourly pull.</div>

            <?php else: ?>
                <?php foreach ($items as $post): ?>
                <?php if ($post['kind'] === 'story'): ?>
                <article class="pb-post pb-net-<?php echo htmlspecialchars($post['persona']); ?> pb-story-card"
                         data-author="<?php echo htmlspecialchars($post['author']); ?>">
                    <div class="pb-head">
                        <span class="pb-author">
                            <?php if ($post['persona'] === 'facebook'): ?>
                                <span class="pb-badge-net" title="Facebook"><svg viewBox="0 0 320 512" aria-hidden="true"><path d="M80 299.3V512H196V299.3h86.5l18-97.8H196V166.9c0-51.7 20.3-71.5 72.7-71.5c16.3 0 29.4 .4 37 1.2V7.9C291.4 4 256.4 0 236.2 0C129.3 0 80 50.5 80 159.4v42.1H14v97.8H80z"/></svg></span>
                            <?php endif; ?>
                            <span class="pb-badge-story">Story</span>
                            <?php echo htmlspecialchars($post['author'] !== '' ? $post['author'] : 'Unknown'); ?>
                        </span>
                        <span class="pb-headside">
                            <?php if (!empty($post['seen'])): ?>
                                <span class="pb-date" title="When this story was first captured"><?php echo htmlspecialchars($post['seen']); ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <a class="pb-story-frame" href="<?php echo htmlspecialchars($post['link']); ?>" target="_blank" rel="noopener noreferrer"
                       title="<?php echo htmlspecialchars($post['author']); ?>'s story on Facebook">
                        <?php if ($post['preview'] !== ''): ?>
                            <img class="pb-story-preview" loading="lazy" alt=""
                                 src="/profile/persona_browser/media?f=<?php echo urlencode($post['preview']); ?>">
                        <?php endif; ?>
                        <?php if ($post['avatar'] !== ''): ?>
                            <img class="pb-story-avatar" loading="lazy" alt=""
                                 src="/profile/persona_browser/media?f=<?php echo urlencode($post['avatar']); ?>">
                        <?php endif; ?>
                    </a>
                    <?php if ($post['link'] !== ''): ?>
                        <div class="pb-link">
                            <a href="<?php echo htmlspecialchars($post['link']); ?>" target="_blank" rel="noopener noreferrer">View story on Facebook &#8599;</a>
                        </div>
                    <?php endif; ?>
                </article>
                <?php continue; ?>
                <?php endif; ?>
                <article class="pb-post pb-net-<?php echo htmlspecialchars($post['persona']); ?><?php echo !empty($post['is_ad']) ? ' is-ad' : ''; ?><?php echo $post['owner_is_ad'] ? ' is-owner-ad' : ''; ?>"
                         data-item-id="<?php echo (int)$post['id']; ?>"
                         data-author="<?php echo htmlspecialchars($post['author']); ?>">
                    <div class="pb-head">
                        <span class="pb-author">
                            <?php if ($post['persona'] === 'facebook'): ?>
                                <span class="pb-badge-net" title="Facebook"><svg viewBox="0 0 320 512" aria-hidden="true"><path d="M80 299.3V512H196V299.3h86.5l18-97.8H196V166.9c0-51.7 20.3-71.5 72.7-71.5c16.3 0 29.4 .4 37 1.2V7.9C291.4 4 256.4 0 236.2 0C129.3 0 80 50.5 80 159.4v42.1H14v97.8H80z"/></svg></span>
                            <?php endif; ?>
                            <?php if (!empty($post['is_ad']) && $post['owner_is_ad'] === null): ?>
                                <span class="pb-badge-ad" title="AI: <?php echo htmlspecialchars($post['ad_reason']); ?>">Ad</span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($post['author'] !== '' ? $post['author'] : 'Unknown'); ?>
                        </span>
                        <span class="pb-headside">
                            <?php if (!empty($post['seen'])): ?>
                                <span class="pb-date" title="When this post was first captured"><?php echo htmlspecialchars($post['seen']); ?></span>
                            <?php endif; ?>
                            <?php if ($post['owner_is_ad'] !== null): ?>
                                <span class="pb-reviewed" title="You reviewed this post">&#10003;</span>
                            <?php endif; ?>
                            <button type="button" class="pb-adbtn" aria-pressed="<?php echo $post['owner_is_ad'] ? 'true' : 'false'; ?>"
                                    title="<?php echo $post['owner_is_ad'] ? 'Marked as an ad by you (click to undo)' : 'Mark this post as an ad and remove it from the feed'; ?>">Ad</button>
                            <span class="pb-menu">
                                <button type="button" class="pb-iconbtn pb-menu-btn" aria-label="Post options" aria-haspopup="true" aria-expanded="false">&#8942;</button>
                                <div class="pb-menu-pop" hidden>
                                    <button type="button" class="pb-menu-item pb-reviewed-btn">Reviewed down to here</button>
                                    <?php if ($post['author'] !== ''): ?>
                                    <button type="button" class="pb-menu-item pb-allow-btn">Allow sender</button>
                                    <button type="button" class="pb-menu-item pb-block-btn">Block sender</button>
                                    <?php endif; ?>
                                </div>
                            </span>
                            <button type="button" class="pb-iconbtn pb-hide-btn" aria-label="Hide this post" title="Hide this post">&#10005;</button>
                        </span>
                    </div>
                    <?php if ($post['message'] !== ''): ?>
                        <div class="pb-message pb-clamped"><?php echo htmlspecialchars($post['message']); ?></div>
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
                <?php if ($review && !empty($items)): ?>
                    <div class="pb-review-end">
                        <button type="button" class="jy-btn pb-review-all-btn">All of these reviewed &mdash; show the next ones</button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

        </div>

    </div>
</section>
</div>

<script defer>
document.addEventListener('DOMContentLoaded', function () {
    var feed = document.querySelector('.pb-feed');
    if (!feed) return;
    var csrfMeta = document.querySelector('meta[name="joinery-api-csrf"]');
    var csrf = csrfMeta ? csrfMeta.content : '';

    function callAction(name, body) {
        return fetch('/api/v1/action/persona_browser/' + name, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Joinery-Csrf': csrf,
                'Idempotency-Key': (crypto.randomUUID ? crypto.randomUUID() : String(Math.random()))
            },
            body: JSON.stringify(body)
        }).then(async function (res) {
            var json = await res.json();
            if (!res.ok) throw new Error(json.error || 'Request failed.');
            return json;
        });
    }

    function closeMenus() {
        feed.querySelectorAll('.pb-menu-pop:not([hidden])').forEach(function (pop) {
            pop.hidden = true;
            var btn = pop.parentElement.querySelector('.pb-menu-btn');
            if (btn) btn.setAttribute('aria-expanded', 'false');
        });
    }

    function removePost(article) {
        article.remove();
        var count = feed.querySelectorAll('.pb-post').length;
        var label = document.getElementById('pb-count');
        if (label) label.textContent = count + ' item' + (count === 1 ? '' : 's');
    }

    // Long posts start clamped; a "Show more" toggle appears only when the
    // text actually overflows the clamp height.
    feed.querySelectorAll('.pb-message').forEach(function (msg) {
        if (msg.scrollHeight <= msg.clientHeight + 4) {
            msg.classList.remove('pb-clamped');
            return;
        }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pb-more';
        btn.textContent = 'Show more';
        btn.addEventListener('click', function () {
            var clamped = msg.classList.toggle('pb-clamped');
            btn.textContent = clamped ? 'Show more' : 'Show less';
        });
        msg.after(btn);
    });

    var reviewMode = <?php echo $review ? 'true' : 'false'; ?>;

    function addReviewedMark(card) {
        if (card.querySelector('.pb-reviewed')) return;
        var mark = document.createElement('span');
        mark.className = 'pb-reviewed';
        mark.title = 'You reviewed this post';
        mark.innerHTML = '&#10003;';
        card.querySelector('.pb-adbtn').before(mark);
    }

    // Record every post card from the top of the page down to (and including)
    // this one as reviewed. Posts marked as ads keep their mark. In review
    // mode the reviewed cards leave the page; when none are left, the next
    // batch loads.
    function reviewedDownTo(article) {
        var ids = [], cards = [];
        var all = feed.querySelectorAll('.pb-post[data-item-id]');
        for (var i = 0; i < all.length; i++) {
            ids.push(parseInt(all[i].dataset.itemId, 10));
            cards.push(all[i]);
            if (all[i] === article) break;
        }
        return callAction('feed_mark_reviewed', { item_ids: ids }).then(function () {
            cards.forEach(function (card) {
                if (reviewMode) { removePost(card); return; }
                addReviewedMark(card);
            });
            if (reviewMode && !feed.querySelector('.pb-post[data-item-id]')) window.location.reload();
        });
    }

    var reviewAll = feed.querySelector('.pb-review-all-btn');
    if (reviewAll) {
        reviewAll.addEventListener('click', function () {
            var cards = feed.querySelectorAll('.pb-post[data-item-id]');
            if (!cards.length) { window.location.reload(); return; }
            reviewAll.disabled = true;
            reviewedDownTo(cards[cards.length - 1]).catch(function (err) {
                reviewAll.disabled = false;
                alert(err.message);
            });
        });
    }

    feed.addEventListener('click', function (e) {
        var menuBtn = e.target.closest('.pb-menu-btn');
        if (menuBtn) {
            var pop = menuBtn.parentElement.querySelector('.pb-menu-pop');
            var opening = pop.hidden;
            closeMenus();
            pop.hidden = !opening;
            menuBtn.setAttribute('aria-expanded', opening ? 'true' : 'false');
            return;
        }

        var article = e.target.closest('.pb-post');
        if (!article || !article.dataset.itemId) return;   // story cards carry no actions
        var itemId = parseInt(article.dataset.itemId, 10);

        var adBtn = e.target.closest('.pb-adbtn');
        if (adBtn) {
            var marking = adBtn.getAttribute('aria-pressed') !== 'true';
            adBtn.disabled = true;
            callAction('feed_mark_ad', { item_id: itemId, is_ad: marking }).then(function () {
                // A post you mark as an ad leaves the page, as if hidden with the X.
                if (marking) { removePost(article); return; }
                adBtn.setAttribute('aria-pressed', 'false');
                article.classList.remove('is-ad', 'is-owner-ad');
                // Your mark replaces any AI badge; either way the post is reviewed.
                var badge = article.querySelector('.pb-badge-ad');
                if (badge) badge.remove();
                addReviewedMark(article);
            }).catch(function (err) { alert(err.message); })
              .finally(function () { adBtn.disabled = false; });
            return;
        }

        if (e.target.closest('.pb-reviewed-btn')) {
            closeMenus();
            reviewedDownTo(article).catch(function (err) { alert(err.message); });
            return;
        }

        if (e.target.closest('.pb-hide-btn')) {
            callAction('feed_hide_post', { item_id: itemId }).then(function () {
                removePost(article);
            }).catch(function (err) { alert(err.message); });
            return;
        }

        if (e.target.closest('.pb-block-btn')) {
            closeMenus();
            var author = article.dataset.author;
            callAction('feed_block_sender', { item_id: itemId }).then(function () {
                var key = author.trim().toLowerCase();
                feed.querySelectorAll('.pb-post').forEach(function (p) {
                    if ((p.dataset.author || '').trim().toLowerCase() === key) removePost(p);
                });
            }).catch(function (err) { alert(err.message); });
            return;
        }

        // Allowing changes nothing on screen right now — their posts are
        // already showing — so the card just says it happened. From the next
        // load they show without the AI's ad badges and never get hidden or blocked.
        if (e.target.closest('.pb-allow-btn')) {
            closeMenus();
            var allowBtn = article.querySelector('.pb-allow-btn');
            callAction('feed_allow_sender', { item_id: itemId }).then(function () {
                allowBtn.textContent = 'Allowed \u2713';
                allowBtn.disabled = true;
                var key = article.dataset.author.trim().toLowerCase();
                feed.querySelectorAll('.pb-post').forEach(function (p) {
                    if ((p.dataset.author || '').trim().toLowerCase() !== key) return;
                    if (p.classList.contains('is-owner-ad')) return;   // your own mark stands
                    p.classList.remove('is-ad');
                    var badge = p.querySelector('.pb-badge-ad');
                    if (badge) badge.remove();
                });
            }).catch(function (err) { alert(err.message); });
        }
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.pb-menu')) closeMenus();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMenus();
    });
});
</script>
<?php
$page->public_footer(['track' => TRUE]);
