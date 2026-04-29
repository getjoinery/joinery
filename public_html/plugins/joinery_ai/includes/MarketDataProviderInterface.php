<?php
/**
 * Pluggable market-data provider contract.
 *
 * v1 ships one implementation (FinnhubProvider). The interface exists from
 * day one so a Polygon or Alpha Vantage backend can drop in alongside without
 * touching the calling tool — the recipe runner picks a provider via the
 * joinery_ai_market_data_provider setting.
 *
 * Method shapes are deliberately simple: each returns an associative array
 * of provider-normalized fields the calling tool formats for the LLM.
 */
interface MarketDataProviderInterface {

    /**
     * Current price + day change for a ticker.
     * Returns: ['symbol', 'price', 'change', 'percent_change', 'high', 'low', 'open', 'prev_close']
     * Throws on transport / 4xx / unknown-ticker.
     */
    public function quote(string $symbol): array;

    /**
     * Key fundamentals + company profile.
     * Returns: ['symbol', 'name', 'industry', 'market_cap', 'pe_ratio', 'beta',
     *           '52w_high', '52w_low', 'dividend_yield', 'profile_url']
     * Missing fields are returned as null. Throws on transport / 4xx.
     */
    public function fundamentals(string $symbol): array;

    /**
     * Recent news headlines for a ticker.
     * Returns array of: ['headline', 'source', 'url', 'published_at', 'summary']
     * Limited to $max items. $days is the lookback window in days.
     */
    public function news(string $symbol, int $days = 7, int $max = 10): array;

}
