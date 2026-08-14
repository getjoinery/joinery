<?php

/**
 * Turns the raw markup the Mac service captures into structured feed items.
 *
 * The browser service on the Mac does the one thing only it can — hold the
 * hand-logged-in session and scroll the virtualized feed — and ships back the
 * raw outerHTML of each post node plus a map of the images it downloaded
 * through the authenticated context. Every Facebook-specific reading decision
 * (who the author is, where the body text is, which link is canonical, which
 * images are real photos) lives here, on the Joinery side, so the fragile
 * selectors version, test, and deploy with the rest of the plugin.
 *
 * Output item shape matches what PersonaBrowserClient::get_feed() returns and
 * FetchFeedTask consumes: {dedup_key, author, message, image_alt, link, media[],
 * relation, group_name, author_link, author_verified, post_type, audience,
 * reactions, comment_count, share_count} where media[] are the service's cached
 * image filenames (served via /media). The metadata fields are read from the
 * post's header/footer markup — see the corresponding pfi_ columns in
 * PersonaFeedItem for what each means.
 */
class FacebookFeedExtractor {

    // An <img> counts as a real photo (not an avatar/icon) at or above this
    // rendered pixel width. The Mac stamps each <img> with data-nw (its runtime
    // naturalWidth), because that size is a live-DOM property that does not
    // survive serialization to HTML.
    const MIN_IMAGE_WIDTH = 300;

    /**
     * @param string[] $posts  raw outerHTML of each captured post node
     * @param array    $media  map of image src => local cached filename
     * @return array           list of normalized feed items (see class docblock)
     */
    public static function extract(array $posts, array $media): array {
        $out = [];   // dedup_key => item, so a post seen across scrolls merges

        foreach ($posts as $html) {
            if (!is_string($html) || $html === '') continue;
            $item = self::parsePost($html, $media);
            if ($item === null) continue;

            $key = $item['dedup_key'];
            if (isset($out[$key])) {
                // Same post captured again on a later scroll — union its media
                // and fill any metadata the first sighting missed.
                $out[$key]['media'] = array_values(array_unique(
                    array_merge($out[$key]['media'], $item['media'])
                ));
                if ($out[$key]['image_alt'] === '' && $item['image_alt'] !== '') {
                    $out[$key]['image_alt'] = $item['image_alt'];
                }
                foreach (['relation', 'group_name', 'author_link', 'audience'] as $k) {
                    if ($out[$key][$k] === '' && $item[$k] !== '') $out[$key][$k] = $item[$k];
                }
                foreach (['author_verified', 'reactions', 'comment_count', 'share_count'] as $k) {
                    if ($out[$key][$k] === null && $item[$k] !== null) $out[$key][$k] = $item[$k];
                }
            } else {
                $out[$key] = $item;
            }
        }

        return array_values($out);
    }

    /**
     * Pull the Stories tray out of a capture: who has an active story, the
     * link to view it, and the cached preview/avatar image filenames. The tray
     * is the horizontal bubble row Facebook renders as a feed unit; extract()
     * deliberately drops it (it is not a post), and this reads it instead.
     * Returns [] when the capture contains no tray, in tray order.
     *
     * Each story: {story_key, author, link, preview, avatar} — preview/avatar
     * are keys into the service's media map ('' if not cached).
     */
    public static function extractStories(array $posts, array $media): array {
        foreach ($posts as $html) {
            if (!is_string($html) || $html === '') continue;

            $doc = new DOMDocument();
            libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_clear_errors();
            $xp = new DOMXPath($doc);

            $anchors = $xp->query('//a[contains(@href, "/stories/")]');
            if ($anchors->length < 3) continue;   // not the tray

            $out = [];
            foreach ($anchors as $a) {
                $href = $a->getAttribute('href');
                // "Create story" and other chrome carry no numeric story id.
                if (!preg_match('#/stories/(\d+)#', $href, $m)) continue;
                $key = $m[1];
                if (isset($out[$key])) continue;

                $author = trim(preg_replace('/\s+/u', ' ', $a->textContent));
                if ($author === '') continue;

                $path = parse_url($href, PHP_URL_PATH);
                if (!is_string($path) || $path === '') continue;

                // The card holds two images: the story's cover frame (large)
                // and the author's avatar (small).
                $preview = '';
                $avatar = '';
                $preview_w = 0;
                foreach ($xp->query('.//img', $a) as $img) {
                    $src = $img->getAttribute('src');
                    if (!isset($media[$src])) continue;
                    $nw = (int)$img->getAttribute('data-nw');
                    if ($nw >= 150) {
                        if ($nw > $preview_w) { $preview = $media[$src]; $preview_w = $nw; }
                    } elseif ($nw > 0 && $avatar === '') {
                        $avatar = $media[$src];
                    }
                }

                $out[$key] = [
                    'story_key' => $key,
                    'author'    => $author,
                    'link'      => 'https://www.facebook.com' . $path,
                    'preview'   => $preview,
                    'avatar'    => $avatar,
                ];
            }
            if ($out) return array_values($out);
        }
        return [];
    }

    /** Parse one post's markup into an item, or null if it carries nothing. */
    private static function parsePost(string $html, array $media): ?array {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $head    = self::headerMeta($xp);
        $author  = $head['author'] !== '' ? $head['author'] : self::author($xp);
        $message = self::message($xp, $author);
        [$id, $link] = self::identity($xp);
        [$image_alt, $media_files] = self::images($xp, $media);

        // The Reels/Stories trays are feed units but not posts — drop them.
        if (self::isTray($xp, $author)) {
            return null;
        }

        // Nothing worth storing: no author, no real body, no images.
        if ($author === '' && mb_strlen($message) < 20 && count($media_files) === 0) {
            return null;
        }

        $dedup_key = $id !== '' ? $id : ('h:' . $author . '|' . mb_substr($message, 0, 80));

        if (strncmp($id, 'reel:', 5) === 0)        { $post_type = 'reel'; }
        elseif (strncmp($id, 'video:', 6) === 0)   { $post_type = 'video'; }
        elseif (count($media_files) > 0 || strncmp($id, 'photo:', 6) === 0) { $post_type = 'photo'; }
        else                                       { $post_type = 'text'; }

        return array_merge([
            'dedup_key' => $dedup_key,
            'author'    => $author,
            'message'   => $message,
            'image_alt' => $image_alt,
            'link'      => $link,
            'media'     => $media_files,
            'post_type' => $post_type,
            'audience'  => self::audience($xp),
        ], $head['meta'], self::engagement($xp));
    }

    /**
     * Read the post's own header (the first h2–h4 block): who the author is,
     * their profile/page link, whether the post came from a group, and the
     * relationship marker. A "Follow" button beside the name means the account
     * does not follow this creator (the algorithm injected the post); a "Join"
     * button means a group the account is not in. No marker = the post came
     * from the account's own network. Scoped strictly to the header so a body
     * that merely says "follow me" can never classify the post.
     */
    private static function headerMeta(DOMXPath $xp): array {
        $none = ['author' => '', 'meta' => [
            'relation' => '', 'group_name' => '', 'author_link' => '', 'author_verified' => null,
        ]];
        $h = $xp->query('//*[self::h2 or self::h3 or self::h4]')->item(0);
        if (!$h) return $none;

        $htext = trim(preg_replace('/\s+/u', ' ', $h->textContent));
        $verified = mb_strpos($htext, 'Verified account') !== false;

        // Follow/Join marker: a link or span inside the header whose exact
        // text is the button word.
        $marker = '';
        foreach ($xp->query('.//a|.//span', $h) as $n) {
            $t = trim($n->textContent);
            if ($t === 'Follow' || $t === 'Join') { $marker = $t; break; }
        }

        $first = $xp->query('.//a[@href]', $h)->item(0);
        $first_href = $first ? $first->getAttribute('href') : '';
        $first_text = $first ? trim(preg_replace('/\s+/u', ' ', $first->textContent)) : '';

        $author = $first_text;
        $author_link = $first_href !== '' ? self::canonicalAuthorLink($first_href) : '';
        $group_name = '';
        if (strpos($first_href, '/groups/') !== false) {
            // Group post: the header names the group; the person who posted is
            // the /groups/{gid}/user/{uid}/ link that follows it.
            $group_name = $first_text;
            $relation = ($marker === 'Join') ? 'group_suggested' : 'group';
            foreach ($xp->query('//a[contains(@href, "/user/")]') as $a) {
                if (strpos($a->getAttribute('href'), '/groups/') === false) continue;
                $t = trim(preg_replace('/\s+/u', ' ', $a->textContent));
                if ($t === '') continue;
                $author = $t;
                $author_link = self::canonicalAuthorLink($a->getAttribute('href'));
                break;
            }
        } else {
            $relation = ($marker === 'Follow') ? 'suggested' : 'network';
        }

        return ['author' => $author, 'meta' => [
            'relation'        => $relation,
            'group_name'      => $group_name,
            'author_link'     => $author_link,
            'author_verified' => $verified,
        ]];
    }

    /** Normalize a header href to a stable profile/page/group-member URL. */
    private static function canonicalAuthorLink(string $href): string {
        $parts = parse_url($href);
        if ($parts === false) return '';
        $path = $parts['path'] ?? '';
        if ($path === '' || $path === '/') return '';
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'www.facebook.com');
        parse_str($parts['query'] ?? '', $q);
        if ($path === '/profile.php' && !empty($q['id'])) {
            return $origin . '/profile.php?id=' . $q['id'];
        }
        return $origin . rtrim($path, '/');
    }

    /**
     * The Reels tray and Stories tray scroll past as feed units but are
     * navigation chrome, not posts.
     */
    private static function isTray(DOMXPath $xp, string $author): bool {
        if ($author === 'Reels' || $author === 'Stories') return true;
        // The stories tray has no heading — recognize it by its pile of
        // /stories/ links.
        return $xp->query('//a[contains(@href, "/stories/")]')->length >= 3;
    }

    /** The "Shared with …" audience string ('Public', 'Friends', 'Public group'). */
    private static function audience(DOMXPath $xp): string {
        foreach ($xp->query('//span') as $n) {
            if ($n->getElementsByTagName('span')->length > 0) continue;   // leaf spans only
            $t = trim(preg_replace('/\s+/u', ' ', $n->textContent));
            if (preg_match('/^Shared with (.{1,40})$/u', $t, $m)) {
                return $m[1];
            }
        }
        return '';
    }

    /**
     * Engagement counts from the post's action bar. Each count is the number
     * span inside a role=button whose aria-label identifies the action, so a
     * number in the body text can never be mistaken for a count. NULL = the
     * markup showed no number (Facebook omits zero).
     */
    private static function engagement(DOMXPath $xp): array {
        $out = ['reactions' => null, 'comment_count' => null, 'share_count' => null];
        foreach ($xp->query('//div[@role="button"][@aria-label]') as $btn) {
            $label = $btn->getAttribute('aria-label');
            if ($label === 'Like' || $label === 'React')                   { $slot = 'reactions'; }
            elseif ($label === 'Leave a comment')                          { $slot = 'comment_count'; }
            elseif ($label === 'Share' || stripos($label, 'Send this to friends') === 0) { $slot = 'share_count'; }
            else continue;
            if ($out[$slot] !== null) continue;   // first (outermost post's) bar wins
            foreach ($xp->query('.//span', $btn) as $s) {
                if ($s->getElementsByTagName('span')->length > 0) continue;
                if (preg_match('/^([0-9][0-9.,]*)\s*([KM])?$/', trim($s->textContent), $m)) {
                    $out[$slot] = self::parseCount($m[1], $m[2] ?? '');
                    break;
                }
            }
        }
        return $out;
    }

    /** '1.2' + 'K' → 1200; '316' + '' → 316. */
    private static function parseCount(string $num, string $suffix): int {
        $n = (float)str_replace(',', '', $num);
        if ($suffix === 'K') $n *= 1000;
        if ($suffix === 'M') $n *= 1000000;
        return (int)round($n);
    }

    /** Author is the first heading link; failing that, the heading's first line. */
    private static function author(DOMXPath $xp): string {
        $a = $xp->query('//*[self::h2 or self::h3 or self::h4]//a');
        if ($a->length > 0) {
            $t = trim($a->item(0)->textContent);
            if ($t !== '') return $t;
        }
        $h = $xp->query('//*[self::h2 or self::h3 or self::h4]');
        if ($h->length > 0) {
            $line = explode("\n", trim($h->item(0)->textContent))[0];
            return trim($line);
        }
        return '';
    }

    /**
     * Body text is the longest distinct dir="auto" block that isn't just the
     * author's name. Read via textContent (not innerText): Facebook renders
     * posts with white-space:normal, so only textContent keeps the real
     * paragraph newlines instead of collapsing them into one blob.
     */
    private static function message(DOMXPath $xp, string $author): string {
        $bodies = [];
        foreach ($xp->query('//div[@dir="auto"]') as $node) {
            $t = self::dedouble(self::clean($node->textContent));
            if ($t !== '') $bodies[$t] = true;
        }
        $bodies = array_keys($bodies);
        usort($bodies, function ($x, $y) { return mb_strlen($y) - mb_strlen($x); });

        $message = $bodies[0] ?? '';
        if ($message === $author) {
            $message = '';
            foreach ($bodies as $t) { if ($t !== $author) { $message = $t; break; } }
        }
        return $message;
    }

    /**
     * Canonical id + permalink from the first stable link in the post. The id
     * prefix (reel:/video:/post:/photo:) both dedupes the post and lets the
     * feed page tell a reel from a normal post.
     */
    private static function identity(DOMXPath $xp): array {
        $hrefs = [];
        foreach ($xp->query('//a[@href]') as $a) {
            $hrefs[] = $a->getAttribute('href');
        }

        $id = '';
        foreach ($hrefs as $h) {
            if (preg_match('#/reel/(\d+)#', $h, $m))          { $id = 'reel:' . $m[1]; break; }
            if (preg_match('#/videos/(\d+)#', $h, $m))        { $id = 'video:' . $m[1]; break; }
            if (preg_match('#/posts/(\d+)#', $h, $m))         { $id = 'post:' . $m[1]; break; }
            if (preg_match('#story_fbid=(\d+)#', $h, $m))     { $id = 'post:' . $m[1]; break; }
            if (preg_match('#[?&]fbid=(\d+)#', $h, $m))       { $id = 'photo:' . $m[1]; break; }
        }

        $link = '';
        foreach ($hrefs as $h) {
            if (!preg_match('#/reel/|/videos/|/posts/|story_fbid=|[?&]fbid=|/permalink#', $h)) continue;
            $link = self::canonicalLink($h);
            break;
        }

        return [$id, $link];
    }

    /** Normalize a post URL to a stable, shareable permalink. */
    private static function canonicalLink(string $href): string {
        $parts = parse_url($href);
        if ($parts === false || empty($parts['host'])) return '';
        $scheme = $parts['scheme'] ?? 'https';
        $origin = $scheme . '://' . $parts['host'];
        $path   = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $q);

        if (preg_match('#/reel/#', $path)) {
            return $origin . $path;
        }
        if (!empty($q['fbid'])) {
            return $origin . '/photo/?fbid=' . $q['fbid'];
        }
        if (!empty($q['story_fbid'])) {
            return $origin . '/permalink.php?story_fbid=' . $q['story_fbid']
                 . (!empty($q['id']) ? '&id=' . $q['id'] : '');
        }
        return $origin . $path;
    }

    /**
     * Real photos in the post (data-nw >= threshold, http src), resolved to the
     * filenames the service already cached. Returns [image_alt, media_files].
     */
    private static function images(DOMXPath $xp, array $media): array {
        $alt = '';
        $files = [];
        foreach ($xp->query('//img') as $img) {
            $nw  = (int)$img->getAttribute('data-nw');
            $src = $img->getAttribute('src');
            if ($nw < self::MIN_IMAGE_WIDTH || strncmp($src, 'http', 4) !== 0) continue;

            $a = trim($img->getAttribute('alt'));
            if ($alt === '' && mb_strlen($a) > 12) $alt = $a;

            if (isset($media[$src]) && !in_array($media[$src], $files, true)) {
                $files[] = $media[$src];
                if (count($files) >= 4) break;
            }
        }
        return [$alt, $files];
    }

    /**
     * nbsp -> space, drop trailing per-line spaces, collapse blank-line runs,
     * strip a trailing "See more"/"See less" toggle Facebook appends inside a
     * truncated block.
     */
    private static function clean(string $s): string {
        $s = preg_replace('/\x{00A0}/u', ' ', $s);
        $s = preg_replace('/[ \t]+\n/', "\n", $s);
        $s = preg_replace('/\n{3,}/', "\n\n", $s);
        $s = preg_replace('/\s*(See more|See less)\s*$/iu', '', $s);
        return trim($s);
    }

    /**
     * Some posts carry a hidden mirror copy of the body, so textContent returns
     * the whole text verbatim twice. Collapse an exact front/back duplication.
     */
    private static function dedouble(string $s): string {
        $len = mb_strlen($s);
        if ($len <= 40) return $s;
        $h = intdiv($len, 2);
        $front = trim(mb_substr($s, 0, $h));
        $back  = trim(mb_substr($s, $h));
        return ($front !== '' && $front === $back) ? $front : $s;
    }
}
