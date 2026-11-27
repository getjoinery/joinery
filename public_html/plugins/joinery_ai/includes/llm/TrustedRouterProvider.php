<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/OpenAiCompatibleProvider.php'));

use GuzzleHttp\Client;

/**
 * The hosted endpoint: AI credit a site's hosting includes, served through
 * TrustedRouter.
 *
 * TrustedRouter is an OpenAI-compatible router, so this rides
 * OpenAiCompatibleProvider's wire translation. What it adds is one rule:
 * every request carries the provider fields of the site's chosen privacy
 * level (ai_endpoints.json privacy_levels, setting joinery_ai_hosted_privacy):
 *
 *   zero_retention  {"zdr": true, "sort": "price"}: only routes that have
 *                   agreed to keep nothing, the cheapest first, the others
 *                   as fallbacks. Without the sort TrustedRouter's own pick
 *                   cost about six times the cheapest.
 *   confidential    {"min_privacy": "confidential"}: only sealed hardware
 *                   the host itself cannot read.
 *
 * Whatever the level says, "zdr": true is sent too, so zero retention is
 * never left off. A request no route can meet
 * is refused rather than sent somewhere that keeps the text, which is what
 * lets the endpoint be graded `trusted`.
 *
 * The key is the site's own capped key, written by utils/hosted_ai_settings.php
 * from the hosting operator's management node. When the cap is reached the
 * router answers 402; LlmProviderException reads that as api_billing and, by
 * this provider's label, words it as the site's AI credit being used up.
 *
 * @version 1.1 - the site's chosen privacy level's routing (zero retention, cheapest first; or confidential)
 * @version 1.0
 */
class TrustedRouterProvider extends OpenAiCompatibleProvider {

    const DEFAULT_BASE_URL = 'https://api.trustedrouter.com/v1';
    const DEFAULT_MODEL    = 'z-ai/glm-5.3-flash';

    /**
     * @param string      $api_key  the site's hosted AI key, sent as a Bearer token
     * @param string      $base_url defaults to the public TrustedRouter endpoint
     * @param string      $model    fallback model; each request names its own
     * @param int         $timeout  per-call read timeout (remote, fast)
     * @param Client|null $http     injectable for tests
     */
    public function __construct(string $api_key, string $base_url = self::DEFAULT_BASE_URL,
            string $model = self::DEFAULT_MODEL, int $timeout = 120, ?Client $http = null) {
        if ($api_key === '') {
            throw new LlmProviderException(
                'The hosted AI credit key is empty. Configure joinery_ai_hosted_api_key.'
            );
        }
        if ($base_url === '') $base_url = self::DEFAULT_BASE_URL;
        if ($model === '')    $model = self::DEFAULT_MODEL;
        parent::__construct($base_url, $model, $api_key, $timeout, $http);
    }

    public function id(): string {
        return 'hosted';
    }

    /** Remote cloud host — no local-style pre-flight probe. */
    public function reachabilityProbe(): ?string {
        return null;
    }

    /** A cloud API's first token is prompt; the per-call timeout governs. */
    protected function firstTokenTimeoutSeconds(): int {
        return 0;
    }

    /** The chosen privacy level's routing on every request; zero retention never optional. */
    protected function applyProviderFields(array &$request): void {
        $provider = isset($request['provider']) && is_array($request['provider']) ? $request['provider'] : [];
        $level = AiEndpointRegistry::privacyLevel('hosted');
        foreach ($level !== null ? $level['provider'] : [] as $field => $value) {
            $provider[$field] = $value;
        }
        // Confidential already requires zero retention at TrustedRouter; it is
        // sent anyway, so no level can ever leave it off.
        $provider['zdr'] = true;
        $request['provider'] = $provider;
    }

    /** The label LlmProviderException keys the hosted billing wording on. */
    protected function providerLabel(): string {
        return LlmProviderException::HOSTED_PROVIDER_LABEL;
    }

    protected function unreachableMessage(): string {
        return 'The hosted AI credit service is not reachable at ' . $this->base_url . ' (connection error)';
    }
}
