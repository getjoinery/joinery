/*
 * The persona feed (views/profile/feed.php). #persona-feed-config: { review }.
 *
 * @version 1.0
 */
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

    var reviewMode = !!(((window.joineryPageData && joineryPageData('persona-feed-config')) || {}).review);

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
        if (!article) return;

        // A story card's only action is its X.
        if (article.dataset.storyId) {
            if (e.target.closest('.pb-hide-story-btn')) {
                callAction('feed_hide_story', { story_id: parseInt(article.dataset.storyId, 10) }).then(function () {
                    removePost(article);
                }).catch(function (err) { alert(err.message); });
            }
            return;
        }
        if (!article.dataset.itemId) return;
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
