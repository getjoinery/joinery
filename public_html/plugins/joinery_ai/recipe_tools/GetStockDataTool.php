<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/MarketDataProviderInterface.php'));

/**
 * Pull quote + fundamentals + recent news for a stock ticker via the
 * configured market-data provider. The provider is selected by the
 * joinery_ai_market_data_provider setting (default: finnhub).
 *
 * No "top movers" call here on free-tier providers — the LLM should pair
 * web_search ("biggest stock gainers today") with this tool's per-ticker
 * deep-dive to build the stock-research recipe.
 */
class GetStockDataTool implements RecipeToolInterface {

    public static function name(): string {
        return 'get_stock_data';
    }

    public static function description(): string {
        return 'Get current price, fundamentals (P/E, beta, market cap, '
             . '52-week range, dividend yield), and recent news headlines '
             . 'for a single stock ticker. Use after web_search has '
             . 'identified tickers worth investigating. Choose what to '
             . 'request via the include parameter to avoid wasting tokens.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'symbol' => [
                    'type' => 'string',
                    'description' => 'Stock ticker symbol (e.g. NVDA, AAPL, BRK.B). Case-insensitive.',
                ],
                'include' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ['quote', 'fundamentals', 'news']],
                    'description' => 'Which sections to include. Default is all three.',
                ],
                'news_days' => [
                    'type' => 'integer',
                    'description' => 'News lookback window in days (1-30, default 7).',
                    'minimum' => 1,
                    'maximum' => 30,
                ],
            ],
            'required' => ['symbol'],
        ];
    }

    public function execute(array $input, RecipeRunContext $ctx) {
        $symbol = trim((string)($input['symbol'] ?? ''));
        if ($symbol === '') {
            return ['content' => 'get_stock_data error: symbol is required.', 'is_error' => true];
        }

        $include = $input['include'] ?? ['quote', 'fundamentals', 'news'];
        if (!is_array($include) || empty($include)) {
            $include = ['quote', 'fundamentals', 'news'];
        }
        $news_days = max(1, min(30, (int)($input['news_days'] ?? 7)));

        try {
            $provider = self::buildProvider();
        } catch (Exception $e) {
            return ['content' => 'get_stock_data not configured: ' . $e->getMessage(), 'is_error' => true];
        }

        $sections = [];
        try {
            if (in_array('quote', $include, true)) {
                $sections[] = self::formatQuote($provider->quote($symbol));
            }
            if (in_array('fundamentals', $include, true)) {
                $sections[] = self::formatFundamentals($provider->fundamentals($symbol));
            }
            if (in_array('news', $include, true)) {
                $sections[] = self::formatNews($provider->news($symbol, $news_days, 8), $news_days);
            }
        } catch (Exception $e) {
            return ['content' => 'get_stock_data error: ' . $e->getMessage(), 'is_error' => true];
        }

        return implode("\n\n", $sections);
    }

    private static function buildProvider(): MarketDataProviderInterface {
        $settings = Globalvars::get_instance();
        $provider_name = $settings->get_setting('joinery_ai_market_data_provider') ?: 'finnhub';
        $api_key = $settings->get_setting('joinery_ai_market_data_api_key');

        switch ($provider_name) {
            case 'finnhub':
                require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/market_data/FinnhubProvider.php'));
                return new FinnhubProvider((string)$api_key);
            default:
                throw new Exception("Unknown market-data provider '$provider_name'.");
        }
    }

    private static function formatQuote(array $q): string {
        $sym = $q['symbol'];
        $price = self::fmt($q['price']);
        $change = self::fmt($q['change']);
        $pct = self::fmt($q['percent_change']);
        $sign = ($q['percent_change'] !== null && $q['percent_change'] >= 0) ? '+' : '';
        return "## $sym quote\n"
             . "- Price: \$$price\n"
             . "- Day change: $sign$change ($sign$pct%)\n"
             . "- Day range: \$" . self::fmt($q['low']) . ' – $' . self::fmt($q['high']) . "\n"
             . "- Open: \$" . self::fmt($q['open']) . " / Prev close: \$" . self::fmt($q['prev_close']);
    }

    private static function formatFundamentals(array $f): string {
        $lines = ["## " . ($f['name'] ?? $f['symbol']) . " fundamentals"];
        if ($f['industry'])     $lines[] = '- Industry: ' . $f['industry'];
        if ($f['market_cap'])   $lines[] = '- Market cap: $' . number_format($f['market_cap'], 0) . 'M';
        if ($f['pe_ratio'])     $lines[] = '- P/E: ' . self::fmt($f['pe_ratio']);
        if ($f['beta'])         $lines[] = '- Beta: ' . self::fmt($f['beta']);
        if ($f['52w_high'] && $f['52w_low']) {
            $lines[] = '- 52-week range: $' . self::fmt($f['52w_low']) . ' – $' . self::fmt($f['52w_high']);
        }
        if ($f['dividend_yield']) $lines[] = '- Dividend yield: ' . self::fmt($f['dividend_yield']) . '%';
        if ($f['profile_url'])   $lines[] = '- Company site: ' . $f['profile_url'];
        return implode("\n", $lines);
    }

    private static function formatNews(array $items, int $days): string {
        if (empty($items)) {
            return "## Recent news\nNo news in the last $days days.";
        }
        $lines = ["## Recent news (last $days days)"];
        foreach ($items as $i => $item) {
            $n = $i + 1;
            $hdr = $item['headline'];
            $src = $item['source']  ? ' — ' . $item['source']  : '';
            $when = $item['published_at'] ? ' (' . substr($item['published_at'], 0, 10) . ')' : '';
            $lines[] = "$n. **$hdr**$src$when";
            if (!empty($item['url']))     $lines[] = "   $item[url]";
            if (!empty($item['summary'])) {
                $sum = trim($item['summary']);
                if (mb_strlen($sum) > 240) $sum = mb_substr($sum, 0, 240) . '…';
                $lines[] = "   $sum";
            }
        }
        return implode("\n", $lines);
    }

    private static function fmt($v): string {
        if ($v === null) return 'n/a';
        return number_format((float)$v, 2);
    }

}
