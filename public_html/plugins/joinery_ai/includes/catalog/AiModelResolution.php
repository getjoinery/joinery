<?php

/**
 * One decision about which model runs one piece of work — made once, then read
 * by everybody.
 *
 * This object is the load-bearing part of the design. The consent gates, the
 * dispatch, the cost record and the run history all read THIS, and nothing
 * re-resolves. That is not a style preference: if the gate resolved to test
 * trust and the dispatch resolved again to run, a catalog edit, a changed local
 * model list or a cleared API key landing between them would silently move the
 * work to a different endpoint than the one that was approved. Resolving once
 * and passing the result closes that gap by construction rather than by
 * discipline.
 *
 * It carries the ordered remainder of approved candidates as well as the first
 * choice. Every candidate cleared the same requirement, trust and consent
 * filters, and the list is truncated so no candidate costs more than the first
 * choice — so failing over can never turn a sleeping local host into a cloud
 * bill, and can never reach outside the approved set.
 *
 * See specs/joinery_ai_model_capability_resolution.md §5.
 */
class AiModelResolution {

    /** @var array catalog entry for the chosen model */
    private $model;

    /** @var array[] the remaining approved candidates, in selection order */
    private $candidates;

    /** @var AiModelRequirement what was asked for */
    private $requirement;

    /** @var array ['enabled'=>bool,'effort'=>'low'|'medium'|'high'|null,'level'=>string] */
    private $thinking;

    /** @var string '' when the resolution answered the requirement normally, else
     *  a plain sentence saying why what was asked for is not what is running */
    private $substitution_note;

    /** @var LlmProviderInterface|null built lazily; transport only */
    private $provider = null;

    /** @var string|null the model actually dispatched to, once a run has started */
    private $served_by = null;

    public function __construct(array $model, array $candidates, AiModelRequirement $requirement,
            array $thinking, string $substitution_note = '') {
        $this->model             = $model;
        $this->candidates        = array_values($candidates);
        $this->requirement       = $requirement;
        $this->thinking          = $thinking;
        $this->substitution_note = $substitution_note;
    }

    /**
     * A resolution around a transport that is already in hand.
     *
     * One caller: the test harness, which drives the loops with an in-memory
     * fake and has no catalog to resolve against (tests/lib/llm_fixtures.php).
     * NO production code calls this — anything that holds a real transport
     * reached it through resolve(), and dispatching around the catalog is
     * exactly what this seam must never become. It is deliberately explicit
     * rather than a quiet fallback inside resolve(), so real work can never
     * reach a model no gate classified.
     *
     * The entry defaults describe an unknown model: no cost, no attachments,
     * the weakest tier, and the least trusted class — so nothing about it reads
     * as approved for sealed content unless the caller says so.
     */
    public static function forProvider(LlmProviderInterface $provider, string $model_id,
            array $entry = []): self {
        $merged = array_merge([
            'id'          => $model_id,
            'label'       => $model_id,
            'endpoint'    => $provider->id(),
            'trust'       => 'cloud',
            'tier'        => AiModelRequirement::TIER_BASIC,
            'thinking'    => 'optional',
            'tools'       => true,
            'context'     => null,
            'attachments' => ['vision' => false, 'document' => false],
            'cost'        => [],
            'defaults'    => [],
            'retired'     => false,
        ], $entry);
        $req = AiModelRequirement::make();
        $out = new self($merged, [], $req, AiModelResolver::thinkingDirectiveFor($merged, $req));
        $out->provider = $provider;
        return $out;
    }

    // --- what was chosen ---

    public function modelId(): string      { return (string)$this->model['id']; }
    public function label(): string        { return (string)$this->model['label']; }
    public function endpointKey(): string  { return (string)$this->model['endpoint']; }
    public function trust(): string        { return (string)$this->model['trust']; }
    public function tier(): string         { return (string)$this->model['tier']; }
    public function entry(): array         { return $this->model; }
    public function requirement(): AiModelRequirement { return $this->requirement; }

    /** Does the chosen model run on hardware the operator controls? */
    public function isLocal(): bool {
        return $this->trust() === AiModelRequirement::TRUST_LOCAL;
    }

    /** Attachment capabilities of the chosen model, as ['vision'=>bool,'document'=>bool]. */
    public function attachments(): array {
        return [
            'vision'   => (bool)($this->model['attachments']['vision'] ?? false),
            'document' => (bool)($this->model['attachments']['document'] ?? false),
        ];
    }

    /** The concrete thinking directive: whether reasoning is on, and at what
     *  effort. Computed from the catalog entry, the requirement and the level,
     *  so providers translate a decision instead of re-deciding from a table of
     *  model names. */
    public function thinkingDirective(): array { return $this->thinking; }

    /** '' when nothing surprising happened; otherwise one sentence an operator
     *  can act on ("your pinned model is unavailable, so …"). */
    public function substitutionNote(): string { return $this->substitution_note; }

    /** Anything worth saying on the edit page about the pairing — currently the
     *  one residual thinking mismatch the design chose to surface rather than
     *  legislate. */
    public function advisories(): array {
        $out = [];
        if ($this->substitution_note !== '') $out[] = $this->substitution_note;
        if ((string)$this->model['thinking'] === 'none'
                && $this->requirement->thinkingLevel() !== 'off') {
            $out[] = $this->label() . ' cannot reason — the thinking level is ignored.';
        }
        if ((string)$this->model['thinking'] === 'always'
                && $this->requirement->thinkingLevel() === 'off') {
            $out[] = $this->label() . ' always reasons — "off" runs it at its lowest effort.';
        }
        $usable = $this->usableContext();
        $nominal = $this->model['context'] === null ? null : (int)$this->model['context'];
        if ($usable !== null && $nominal !== null && $usable < $nominal / 2) {
            $out[] = 'This model is rated for ' . number_format($nominal) . ' tokens of context but the '
                   . 'host is serving it with ' . number_format($usable) . '.';
        }
        return $out;
    }

    // --- context ---

    /**
     * How much room the work actually got: the smaller of the catalog's nominal
     * window and whatever the host is serving right now. Both numbers are
     * wanted and the operative one is the minimum — a model rated at 256k being
     * served with a 24k window breaks turns, and only the host knows.
     *
     * Null when neither number is available, which a caller reads as "size
     * conservatively", never as "unlimited".
     */
    public function usableContext(): ?int {
        $nominal = $this->model['context'] === null ? null : (int)$this->model['context'];
        try {
            $live = AiEndpointRegistry::liveContextWindow($this->endpointKey(), $this->modelId());
        } catch (Throwable $e) {
            $live = null;
        }
        if ($nominal === null) return $live;
        if ($live === null) return $nominal;
        return min($nominal, $live);
    }

    // --- controls ---

    /**
     * A sampling control resolved for this model: the caller's own value, else
     * the catalog model's default, else the plugin setting, else null.
     *
     * One global temperature for every model is a Claude-shaped number handed
     * to qwen; a default that ships beside the model is the right settings for
     * that model rather than one number for all of them.
     */
    public function control(string $name, $row_value, $setting_value) {
        if ($row_value !== null && $row_value !== '') return $row_value;
        $catalog = $this->model['defaults'][$name] ?? null;
        if ($catalog !== null && $catalog !== '') return $catalog;
        return ($setting_value === null || $setting_value === '') ? null : $setting_value;
    }

    /** The per-call output ceiling for this model, from its catalog default. */
    public function maxOutputTokens(int $floor): int {
        $v = (int)($this->model['defaults']['max_output_tokens'] ?? 0);
        return $v > 0 ? $v : $floor;
    }

    // --- cost ---

    /**
     * USD for a usage block against any catalog model id — the form the run
     * finaliser needs, because it costs what ACTUALLY served the run rather
     * than what the recipe pinned.
     *
     * The usage SHAPE follows the endpoint's wire dialect, which the catalog
     * entry states — keyed on that rather than sniffing which rates the entry
     * happens to declare, so a catalog author forgetting a cache rate mislays
     * a rate instead of silently switching accounting models.
     */
    public static function costFor(string $model_id, array $usage): float {
        $entry = AiEndpointRegistry::model($model_id);
        $rates = $entry === null ? [] : (array)$entry['cost'];
        if (!$rates) return 0.0;

        $input  = (int)($usage['input_tokens'] ?? 0);
        $output = (int)($usage['output_tokens'] ?? 0);
        $write  = (int)($usage['cache_creation_input_tokens'] ?? 0);
        $read   = (int)($usage['cache_read_input_tokens'] ?? 0);

        if ((string)($entry['dialect'] ?? 'openai') === 'anthropic') {
            // Anthropic-style usage: cached tokens are reported OUTSIDE
            // input_tokens, each with its own rate.
            return ($input * (float)$rates['input']
                  + $output * (float)$rates['output']
                  + $write  * (float)($rates['cache_write'] ?? $rates['input'])
                  + $read   * (float)($rates['cache_read'] ?? $rates['input'])) / 1000000.0;
        }
        // OpenAI-style usage: cached tokens are counted INSIDE input_tokens and
        // bill at half the input rate.
        $uncached = max(0, $input - $read);
        return ($uncached * (float)$rates['input']
              + $read     * (float)$rates['input'] * 0.5
              + $output   * (float)$rates['output']) / 1000000.0;
    }

    /** Does this resolution cost money to run? Used to keep a failover from
     *  turning a free choice into a paid one. */
    public function isPaid(): bool {
        return !empty($this->model['cost']);
    }

    // --- dispatch ---

    /**
     * The transport for the chosen model. Built from the endpoint definition,
     * not from the model's shape, so what receives the request is provably the
     * endpoint the gates approved.
     */
    public function provider(): LlmProviderInterface {
        if ($this->provider === null) {
            $this->provider = LlmProviderFactory::forEndpoint($this->endpointKey());
        }
        return $this->provider;
    }

    /** The approved alternatives, first to last. Every one cleared the same
     *  filters and none costs more than the first choice. */
    public function candidates(): array { return $this->candidates; }

    /**
     * Send one request, failing over inside the approved set if the first
     * choice will not answer.
     *
     * Two rules, and both matter:
     *
     * BEFORE THE FIRST TOKEN, a transport failure — connection refused, a model
     * that would not load — advances to the next approved candidate. Every
     * candidate cleared the same requirement, trust and consent filters at
     * resolve time and none costs more than the first choice, so failing over
     * can never reach outside what was approved and can never turn a sleeping
     * local host into a cloud bill. On this fleet that buys something real: the
     * Studio's memory pressure can fail the 35B's load while the 9B still
     * serves, and the hour's work degrades to a measured sibling instead of
     * failing.
     *
     * AFTER THE FIRST TOKEN, a dying stream is a FAILED RUN, not a retry. A
     * second dispatch would double-spend and re-fire whatever side effects the
     * first pass already caused.
     *
     * The choice moves within this object rather than producing a new one, so
     * "one resolution per run" stays literally true and the gates' approval
     * still covers whatever ends up serving the request.
     */
    public function send(array $params, callable $onTextDelta, ?callable $shouldAbort = null): array {
        while (true) {
            $emitted = false;
            $sink = function (string $delta) use (&$emitted, $onTextDelta): void {
                $emitted = true;
                $onTextDelta($delta);
            };
            $params['model'] = $this->modelId();
            try {
                $response = $this->provider()->createMessageStreamed($params, $sink, $shouldAbort);
                $this->served_by = $this->modelId();
                return $response;
            } catch (LlmProviderException $e) {
                $next = $emitted ? null : $this->advance($e);
                if ($next === null) {
                    $this->served_by = $this->modelId();
                    throw $e;
                }
                error_log('[joinery_ai] ' . $this->modelId() . ' did not answer (' . $e->getMessage()
                    . '); falling over to an approved candidate.');
            }
        }
    }

    /**
     * Move to the next approved candidate, or return null when there is none —
     * or when the failure was not the kind a different model would fix.
     *
     * Only a transport-level failure earns a failover — the host is
     * unreachable, the server erred, or the model never started responding
     * (a cold load that could not fit). An authentication, quota or validation
     * error would fail identically on the sibling and turn one clear message
     * into a slow cascade of the same one.
     */
    const FAILOVER_CODES = ['api_network_error', 'api_server_error', 'api_no_response'];

    private function advance(LlmProviderException $e): ?array {
        if (!$this->candidates) return null;
        if (!in_array(LlmProviderException::classify($e), self::FAILOVER_CODES, true)) return null;

        $next = array_shift($this->candidates);
        $note = 'Fell back to ' . (string)$next['label'] . ' — ' . $this->label() . ' did not answer.';
        $this->substitution_note = $this->substitution_note === ''
            ? $note : $this->substitution_note . ' ' . $note;
        $this->model = $next;
        $this->provider = null;
        $this->thinking = AiModelResolver::thinkingDirectiveFor($next, $this->requirement);
        return $next;
    }

    /** Record what actually served the run, which is what history stores. */
    public function noteServedBy(string $model_id): void { $this->served_by = $model_id; }

    /** The model that actually served the run — the first choice unless a
     *  failover happened. */
    public function servedBy(): string { return $this->served_by ?? $this->modelId(); }

    /** One line for a UI: "Qwen 3.6 35B (local · capable)". */
    public function summary(): string {
        $endpoint = AiEndpointRegistry::endpoint($this->endpointKey());
        $where = $endpoint === null ? $this->endpointKey() : (string)$endpoint['label'];
        return $this->label() . ' (' . $where . ' · ' . $this->trust() . ' · ' . $this->tier() . ')';
    }
}
