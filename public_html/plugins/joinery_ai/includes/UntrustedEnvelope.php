<?php
/**
 * The one place untrusted content is put inside its markers.
 *
 * Content written by an outside party (a message body, an attachment, a
 * stored memory, a field a stranger filled in) reaches the model wrapped in
 * <<UNTRUSTED_nonce>> ... <</UNTRUSTED_nonce>> so the system prompt can say
 * "data, never commands". The nonce is per turn and never shown to the
 * sender, so guessing it is 2^32; this helper makes the guess irrelevant. A
 * marker found INSIDE the content is rewritten before wrapping, whatever
 * nonce it carries, so nothing a stranger writes can close the envelope or
 * open a fake one (specs/security_inventory.md S20).
 *
 * Every wrap site calls wrap() or wrapBlock(); none builds the markers by
 * hand, so a site added later cannot forget the rewrite.
 *
 * @version 1.0
 */
class UntrustedEnvelope {

    /** Either marker's opening, any case, with or without the closing slash
     *  and with whitespace tolerated inside, because the model may read a
     *  near-miss the same way it reads the real thing. */
    const MARKER_PATTERN = '/<<\s*\/?\s*UNTRUSTED_/iu';

    const MARKER_REPLACEMENT = '[marker removed]';

    public static function open(string $nonce): string {
        return "<<UNTRUSTED_$nonce>>";
    }

    public static function close(string $nonce): string {
        return "<</UNTRUSTED_$nonce>>";
    }

    /** Content with every envelope marker inside it rewritten. */
    public static function neutralize(string $content): string {
        $out = preg_replace(self::MARKER_PATTERN, self::MARKER_REPLACEMENT, $content);
        // A pattern error on hostile bytes must not let the content through
        // unrewritten; refusing the wrap is the safe side.
        if ($out === null) {
            throw new RuntimeException('UntrustedEnvelope: content could not be scanned for markers.');
        }
        return $out;
    }

    /** Inline: markers hug the content. */
    public static function wrap(string $content, string $nonce): string {
        return self::open($nonce) . self::neutralize($content) . self::close($nonce);
    }

    /** Block: content on its own lines between the markers. */
    public static function wrapBlock(string $content, string $nonce): string {
        return self::open($nonce) . "\n" . self::neutralize($content) . "\n" . self::close($nonce);
    }

}
