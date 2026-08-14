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
 * FetchFeedTask consumes: {dedup_key, author, message, image_alt, link, media[]}
 * where media[] are the service's cached image filenames (served via /media).
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
                // Same post captured again on a later scroll — union its media.
                $out[$key]['media'] = array_values(array_unique(
                    array_merge($out[$key]['media'], $item['media'])
                ));
                if ($out[$key]['image_alt'] === '' && $item['image_alt'] !== '') {
                    $out[$key]['image_alt'] = $item['image_alt'];
                }
            } else {
                $out[$key] = $item;
            }
        }

        return array_values($out);
    }

    /** Parse one post's markup into an item, or null if it carries nothing. */
    private static function parsePost(string $html, array $media): ?array {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $author  = self::author($xp);
        $message = self::message($xp, $author);
        [$id, $link] = self::identity($xp);
        [$image_alt, $media_files] = self::images($xp, $media);

        // Nothing worth storing: no author, no real body, no images.
        if ($author === '' && mb_strlen($message) < 20 && count($media_files) === 0) {
            return null;
        }

        $dedup_key = $id !== '' ? $id : ('h:' . $author . '|' . mb_substr($message, 0, 80));

        return [
            'dedup_key' => $dedup_key,
            'author'    => $author,
            'message'   => $message,
            'image_alt' => $image_alt,
            'link'      => $link,
            'media'     => $media_files,
        ];
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
