<?php
/**
 * CurrencyHelper
 *
 * The platform currency-symbol map. Given a currency code (the value stored in
 * the `site_currency` setting, an ISO code such as `usd`/`eur`), it returns the
 * display symbol used throughout price rendering.
 *
 * This is a core helper: core analytics (attribution revenue) and the store both
 * consume it. It carries no dependency on any model or setting, and degrades to
 * the dollar sign when the code is unknown or unset — so a store-less install,
 * which has no `site_currency` setting at all, still renders sensibly.
 *
 * @version 1.0.0
 */
class CurrencyHelper {

    /** @var array<string,string> lowercase ISO code => HTML symbol */
    private static $symbols = array(
        'usd' => '$',
        'eur' => '&euro;',
    );

    /**
     * Return the display symbol for a currency code. Case-insensitive; unknown or
     * empty codes fall back to '$'.
     */
    public static function symbol($code): string {
        $key = strtolower((string)$code);
        return self::$symbols[$key] ?? '$';
    }

    /**
     * The full code => symbol map (lowercase keys).
     *
     * @return array<string,string>
     */
    public static function all(): array {
        return self::$symbols;
    }
}
