<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/MarketDataProviderInterface.php'));
require_once(PathHelper::getComposerAutoloadPath());

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Finnhub.io free-tier market data provider.
 *
 * Free tier (as of 2026-04): 60 calls/minute, no API key restrictions on
 * basic endpoints. Sign up at finnhub.io/dashboard for a key.
 *
 * Endpoints used:
 *   GET /quote                — real-time quote
 *   GET /stock/profile2       — company profile
 *   GET /stock/metric         — key fundamentals (basic-financials free)
 *   GET /company-news         — news within a date range
 */
class FinnhubProvider implements MarketDataProviderInterface {

    const BASE = 'https://finnhub.io/api/v1';

    /** @var string */
    private $api_key;

    /** @var Client */
    private $http;

    public function __construct(string $api_key, ?Client $http = null) {
        if ($api_key === '') {
            throw new Exception('Finnhub API key is empty (joinery_ai_market_data_api_key).');
        }
        $this->api_key = $api_key;
        $this->http = $http ?: new Client(['timeout' => 15, 'connect_timeout' => 5]);
    }

    public function quote(string $symbol): array {
        $symbol = strtoupper(trim($symbol));
        $data = $this->get('/quote', ['symbol' => $symbol]);

        // Finnhub uses single-letter keys; map to descriptive names.
        return [
            'symbol'         => $symbol,
            'price'          => self::nullableFloat($data['c']  ?? null),
            'change'         => self::nullableFloat($data['d']  ?? null),
            'percent_change' => self::nullableFloat($data['dp'] ?? null),
            'high'           => self::nullableFloat($data['h']  ?? null),
            'low'            => self::nullableFloat($data['l']  ?? null),
            'open'           => self::nullableFloat($data['o']  ?? null),
            'prev_close'     => self::nullableFloat($data['pc'] ?? null),
        ];
    }

    public function fundamentals(string $symbol): array {
        $symbol = strtoupper(trim($symbol));

        $profile = $this->get('/stock/profile2', ['symbol' => $symbol]);
        $metrics = $this->get('/stock/metric', ['symbol' => $symbol, 'metric' => 'all']);

        $m = $metrics['metric'] ?? [];
        return [
            'symbol'          => $symbol,
            'name'            => $profile['name']        ?? null,
            'industry'        => $profile['finnhubIndustry'] ?? null,
            'market_cap'      => self::nullableFloat($profile['marketCapitalization'] ?? null),
            'pe_ratio'        => self::nullableFloat($m['peBasicExclExtraTTM'] ?? $m['peNormalizedAnnual'] ?? null),
            'beta'            => self::nullableFloat($m['beta'] ?? null),
            '52w_high'        => self::nullableFloat($m['52WeekHigh'] ?? null),
            '52w_low'         => self::nullableFloat($m['52WeekLow'] ?? null),
            'dividend_yield'  => self::nullableFloat($m['dividendYieldIndicatedAnnual'] ?? null),
            'profile_url'     => $profile['weburl'] ?? null,
        ];
    }

    public function news(string $symbol, int $days = 7, int $max = 10): array {
        $symbol = strtoupper(trim($symbol));
        $to   = gmdate('Y-m-d');
        $from = gmdate('Y-m-d', time() - ($days * 86400));

        $data = $this->get('/company-news', [
            'symbol' => $symbol, 'from' => $from, 'to' => $to,
        ]);
        if (!is_array($data)) return [];

        $items = [];
        foreach (array_slice($data, 0, $max) as $row) {
            $items[] = [
                'headline'     => $row['headline'] ?? '',
                'source'       => $row['source']   ?? '',
                'url'          => $row['url']      ?? '',
                'published_at' => isset($row['datetime'])
                                  ? gmdate('Y-m-d\TH:i:s\Z', (int)$row['datetime'])
                                  : null,
                'summary'      => $row['summary']  ?? '',
            ];
        }
        return $items;
    }

    private function get(string $path, array $query): array {
        $query['token'] = $this->api_key;
        try {
            $response = $this->http->get(self::BASE . $path, ['query' => $query]);
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
            throw new Exception("Finnhub HTTP error on $path: " . $e->getMessage()
                                . ($body ? ' — ' . substr($body, 0, 200) : ''));
        }
        $body = (string)$response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new Exception("Finnhub returned non-JSON on $path");
        }
        return $decoded;
    }

    private static function nullableFloat($v) {
        if ($v === null || $v === '' || !is_numeric($v)) return null;
        return (float)$v;
    }

}
