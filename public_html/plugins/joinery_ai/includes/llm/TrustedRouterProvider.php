<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/OpenAiCompatibleProvider.php'));

use GuzzleHttp\Client;

/**
 * The hosted endpoint: AI credit a site's hosting includes, served through
 * TrustedRouter.
 *
 * TrustedRouter is an OpenAI-compatible router, so this rides
 * OpenAiCompatibleProvider's wire translation. What it adds is one rule:
 * every request carries "provider": {"zdr": true}, which tells the router to
 * use only routes that retain nothing. A model with no such route is refused
 * rather than sent somewhere that keeps the text — which is what lets the
 * endpoint be graded `trusted` in ai_endpoints.json.
 *
 * The key is the site's own capped key, written by utils/hosted_ai_settings.php
 * from the hosting operator's management node. When the cap is reached the
 * router answers 402; LlmProviderException reads that as api_billing and, by
 * this provider's label, words it as the site's AI credit being used up.
 *
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

    /** Zero-retention routing on every request, never optional. */
    protected function applyProviderFields(array &$request): void {
        $provider = isset($request['provider']) && is_array($request['provider']) ? $request['provider'] : [];
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
