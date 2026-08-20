<?php

/** A catalog that will not parse is a refusal, never a fall-through. */
class AiCatalogException extends Exception {}

/**
 * The shipped model catalog, read at runtime.
 *
 * Two files back this: ai_endpoints.json says where a request may be sent and
 * what each endpoint serves, and ai_model_reference.json grades whatever the
 * operator happens to be serving on their own hardware. Neither is seeded into
 * the database, which is the entire point — editing one and publishing is how
 * a model change reaches every node, with no production row to edit.
 *
 * The registry answers three kinds of question:
 *   - which endpoints this install can actually reach (a key is set, the host
 *     serves something)
 *   - every catalog model available right now, each carrying its tier, trust,
 *     cost and mechanical facts
 *   - what one model id is, without guessing from its shape
 *
 * A model id belongs to exactly one endpoint, so routing and classification are
 * the same lookup and cannot disagree. That property is what the sealed-mail
 * gate rests on.
 *
 * See specs/joinery_ai_model_capability_resolution.md §1, §3a, §3b.
 */
class AiEndpointRegistry {

    const ENDPOINTS_FILE = 'plugins/joinery_ai/ai_endpoints.json';
    const REFERENCE_FILE = 'plugins/joinery_ai/ai_model_reference.json';

    /** Mechanical facts assumed for a local host that cannot be probed.
     *  Deliberately permissive on tools: a host that cannot drive them fails at
     *  run time, which is better than making every operator fill in a fact
     *  table. Context is deliberately absent — see §3b, an unknown context
     *  fails a min_context requirement closed rather than guessing a number. */
    const UNPROBED_DEFAULTS = [
        'thinking'    => 'optional',
        'tools'       => true,
        'context'     => null,
        'attachments' => ['vision' => false, 'document' => false],
    ];

    /** @var array{0:?string,1:?string} Absolute paths overriding the two shipped
     *  files, or nulls for the shipped ones. Set by the catalog schema gate,
     *  which must be able to check a candidate file BEFORE it ships, and by
     *  tests, which need every cell of the resolution matrix populated on a box
     *  that serves one model. */
    private static $file_override = [null, null];

    /** @var array|null parsed endpoints file */
    private static $endpoints = null;

    /** @var array|null parsed reference file */
    private static $reference = null;

    /** @var array<string,array> per-request probe memo, keyed endpoint|model */
    private static $probe_cache = [];

    /** @var array<string,array> per-request memo of each endpoint's model set */
    private static $models_cache = [];

    /** @var array<string,?int> per-request memo of live context windows, keyed
     *  endpoint|model. advisories() and the runners both ask; without the memo
     *  each ask is a fresh HTTP round trip. */
    private static $live_ctx_cache = [];

    /** @var bool Set once a probe cannot reach its host. A sleeping local box
     *  must cost one timeout on the resolve path, not one per model — and the
     *  answer for every remaining model is the same anyway. */
    private static $probe_down = false;

    /** @var array|null per-request memo of the assembled catalog */
    private static $catalog_cache = null;

    // ================= file access =================

    /**
     * Point the registry at a different pair of catalog files, and return the
     * previous pair so a caller can put it back.
     *
     * Deliberately explicit and narrow: the schema gate checks a candidate
     * catalog before it ships, and a test needs a catalog with more shapes in
     * it than any one install serves. Nothing on the request path calls this.
     */
    public static function useCatalogFiles(?string $endpoints_path, ?string $reference_path): array {
        $prev = self::$file_override;
        self::$file_override = [$endpoints_path, $reference_path];
        self::clearCache();
        return $prev;
    }

    /** @throws AiCatalogException when the file is missing or unparseable */
    private static function readJson(string $relative): array {
        $slot = ($relative === self::ENDPOINTS_FILE) ? 0 : 1;
        $path = self::$file_override[$slot] ?? PathHelper::getIncludePath($relative);
        if (!is_file($path)) {
            throw new AiCatalogException("The AI model catalog file $relative is missing from this install.");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new AiCatalogException("The AI model catalog file $relative could not be read.");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new AiCatalogException("The AI model catalog file $relative is not valid JSON: "
                . json_last_error_msg());
        }
        return $data;
    }

    /** Every declared endpoint, in file order, whether or not it is configured. */
    public static function endpoints(): array {
        if (self::$endpoints === null) {
            $data = self::readJson(self::ENDPOINTS_FILE);
            $out = [];
            foreach ($data['endpoints'] ?? [] as $e) {
                $key = trim((string)($e['key'] ?? ''));
                if ($key === '') continue;
                $out[$key] = $e;
            }
            if (!$out) {
                throw new AiCatalogException('The AI endpoint catalog declares no endpoints.');
            }
            self::$endpoints = $out;
        }
        return self::$endpoints;
    }

    /** One endpoint definition, or null. */
    public static function endpoint(string $key): ?array {
        return self::endpoints()[$key] ?? null;
    }

    /** The advisory grading file. An unreadable one is not fatal — the size
     *  ladder still grades — but a corrupt one is, because a silently empty
     *  reference would re-grade a measured model by accident. */
    private static function reference(): array {
        if (self::$reference === null) {
            self::$reference = self::readJson(self::REFERENCE_FILE);
        }
        return self::$reference;
    }

    /** Drop every memo. For tests, and for code that changes a setting and
     *  then re-reads the catalog in the same request. */
    public static function clearCache(): void {
        self::$endpoints = null;
        self::$reference = null;
        self::$probe_cache = [];
        self::$models_cache = [];
        self::$live_ctx_cache = [];
        self::$probe_down = false;
        self::$catalog_cache = null;
    }

    // ================= availability =================

    /**
     * Can this install reach $endpoint right now?
     *
     * An endpoint is available when its key setting is non-empty (or it needs
     * no key), it has not been switched off, and it serves at least one model.
     * Clearing an API key is therefore the supported way to take an endpoint
     * out of resolution — which is why there is no separate disable knob.
     */
    public static function isAvailable(string $key): bool {
        $e = self::endpoint($key);
        if ($e === null) return false;
        $settings = Globalvars::get_instance();

        $key_setting = $e['api_key_setting'] ?? null;
        if ($key_setting !== null && (string)$settings->get_setting((string)$key_setting) === '') {
            return false;
        }
        $enabled_setting = $e['enabled_setting'] ?? null;
        if ($enabled_setting !== null && (string)$settings->get_setting((string)$enabled_setting) !== '1') {
            return false;
        }
        return count(self::modelsFor($key)) > 0;
    }

    /** The base URL for an endpoint: a literal, or whatever setting it names. */
    public static function baseUrl(string $key): string {
        $e = self::endpoint($key);
        if ($e === null) return '';
        if (isset($e['base_url'])) return (string)$e['base_url'];
        $setting = (string)($e['base_url_setting'] ?? '');
        return $setting === '' ? '' : (string)Globalvars::get_instance()->get_setting($setting);
    }

    /** The API key for an endpoint, or '' when it needs none. */
    public static function apiKey(string $key): string {
        $e = self::endpoint($key);
        if ($e === null) return '';
        $setting = $e['api_key_setting'] ?? null;
        return $setting === null ? '' : (string)Globalvars::get_instance()->get_setting((string)$setting);
    }

    // ================= the catalog =================

    /**
     * Every model this install could send to right now, as
     *   [model_id => catalog entry]
     * where a catalog entry always carries: id, label, endpoint, trust, tier,
     * thinking, tools, context, attachments, cost, defaults, retired.
     *
     * Order is endpoint order then model order, which is what makes tie-breaks
     * deterministic.
     */
    public static function catalog(): array {
        if (self::$catalog_cache !== null) return self::$catalog_cache;
        $out = [];
        foreach (self::endpoints() as $key => $_e) {
            if (!self::isAvailable($key)) continue;
            foreach (self::modelsFor($key) as $id => $entry) {
                // First endpoint to claim an id keeps it. A duplicate across
                // endpoints is a catalog defect the schema gate catches; here
                // it must not become two routes for one name.
                if (!isset($out[$id])) $out[$id] = $entry;
            }
        }
        return self::$catalog_cache = $out;
    }

    /**
     * One model id's catalog entry, or null when no endpoint serves it.
     *
     * Null is a refusal, not a licence to guess. Every gate treats an unknown
     * id as unusable rather than assuming it is local — the failure mode the
     * old ^claude regex had, where an unrecognised name defaulted to "local"
     * and so to "safe".
     */
    public static function model(string $id): ?array {
        $id = trim($id);
        if ($id === '') return null;
        return self::catalog()[$id] ?? null;
    }

    /** Does an id name a model some endpoint declares, configured or not? Used
     *  to tell "your pin is unavailable today" from "your pin is nonsense". */
    public static function isDeclared(string $id): bool {
        $id = trim($id);
        if ($id === '') return false;
        foreach (self::endpoints() as $key => $_e) {
            if (isset(self::modelsFor($key)[$id])) return true;
        }
        return false;
    }

    /**
     * The trust class of the endpoint serving $id, or null when no endpoint
     * declares it.
     *
     * Answered from the DECLARATION, not from availability: a Claude model is a
     * cloud model whether or not this install holds an Anthropic key, and a gate
     * asking "where would this go?" needs that answer even for an endpoint it
     * cannot currently reach. Availability is a separate question, and the
     * resolver asks it separately.
     *
     * Null for an id nothing declares — which every caller reads as "not local",
     * the safe direction. It is what makes a Fortress chat refuse a model
     * nothing classifies rather than assume the best of it.
     */
    public static function trustForModel(string $id): ?string {
        $id = trim($id);
        if ($id === '') return null;
        $m = self::model($id);
        if ($m !== null) return (string)$m['trust'];
        foreach (self::endpoints() as $key => $endpoint) {
            if (isset(self::modelsFor($key)[$id])) return (string)$endpoint['trust'];
        }
        return null;
    }

    /**
     * The models one endpoint serves, whether or not the endpoint is
     * configured. A fixed `models` list is taken as declared; a
     * `models_setting` endpoint reads the operator's own list and grades each
     * entry from the reference file.
     */
    public static function modelsFor(string $key): array {
        if (array_key_exists($key, self::$models_cache)) return self::$models_cache[$key];
        return self::$models_cache[$key] = self::buildModelsFor($key);
    }

    private static function buildModelsFor(string $key): array {
        $e = self::endpoint($key);
        if ($e === null) return [];

        if (isset($e['models']) && is_array($e['models'])) {
            $out = [];
            foreach ($e['models'] as $m) {
                $id = trim((string)($m['id'] ?? ''));
                if ($id === '') continue;
                $out[$id] = self::normalizeDeclared($m, $e);
            }
            return $out;
        }

        $setting = (string)($e['models_setting'] ?? '');
        if ($setting === '') return [];
        $raw = (string)Globalvars::get_instance()->get_setting($setting);
        $out = [];
        foreach (array_map('trim', explode(',', $raw)) as $id) {
            if ($id === '') continue;
            $out[$id] = self::gradeHostModel($id, $e);
        }
        return $out;
    }

    /** A declared catalog entry, filled out with its endpoint's facts. */
    private static function normalizeDeclared(array $m, array $e): array {
        return [
            'id'          => (string)$m['id'],
            'label'       => (string)($m['label'] ?? $m['id']),
            'endpoint'    => (string)$e['key'],
            'dialect'     => (string)($e['dialect'] ?? 'openai'),
            'trust'       => (string)($e['trust'] ?? 'cloud'),
            'tier'        => (string)($m['tier'] ?? AiModelRequirement::TIER_BASIC),
            'thinking'    => (string)($m['thinking'] ?? 'optional'),
            'tools'       => (bool)($m['tools'] ?? true),
            'context'     => isset($m['context']) ? (int)$m['context'] : null,
            'attachments' => [
                'vision'   => (bool)($m['attachments']['vision'] ?? false),
                'document' => (bool)($m['attachments']['document'] ?? false),
            ],
            'cost'        => isset($m['cost']) && is_array($m['cost']) ? $m['cost'] : [],
            'defaults'    => isset($m['defaults']) && is_array($m['defaults']) ? $m['defaults'] : [],
            'retired'     => (bool)($m['retired'] ?? false),
            'basis'       => 'declared',
        ];
    }

    /**
     * Grade a model the operator serves themselves.
     *
     * Tier is the only fact this file owns — it is a judgement about judgement,
     * and no probe can measure it. The mechanical facts come from the host when
     * the endpoint declares a probe, because the host knows them better than
     * any shipped file: swap in a vision model and the answer is simply right,
     * with no publish.
     *
     * Precedence per fact: a named reference entry that carries it (for a host
     * that mis-reports), then the probe, then the stated defaults.
     */
    private static function gradeHostModel(string $id, array $e): array {
        $ref   = self::referenceEntryFor($id);
        $probe = self::probeFacts((string)$e['key'], $id);

        $pick = function (string $field) use ($ref, $probe) {
            if ($ref !== null && array_key_exists($field, $ref)) return $ref[$field];
            if ($probe !== null && array_key_exists($field, $probe)) return $probe[$field];
            return self::UNPROBED_DEFAULTS[$field];
        };

        $attachments = $pick('attachments');
        return [
            'id'          => $id,
            'label'       => $id,
            'endpoint'    => (string)$e['key'],
            'dialect'     => (string)($e['dialect'] ?? 'openai'),
            // Same fail-safe default as a declared endpoint: an endpoint that
            // forgot to state its trust class is treated as the least trusted,
            // never the most. The shipped local endpoint states 'local'.
            'trust'       => (string)($e['trust'] ?? 'cloud'),
            'tier'        => $ref['tier'] ?? self::tierFromLadder($id),
            'thinking'    => (string)$pick('thinking'),
            'tools'       => (bool)$pick('tools'),
            'context'     => $pick('context') === null ? null : (int)$pick('context'),
            'attachments' => [
                'vision'   => (bool)($attachments['vision'] ?? false),
                'document' => (bool)($attachments['document'] ?? false),
            ],
            // Local inference is free. An absent cost is what lets the
            // cost-nonincreasing failover rule keep a sleeping local host from
            // ever becoming a cloud bill.
            'cost'        => [],
            'defaults'    => (array)($ref['defaults'] ?? []),
            'retired'     => false,
            'basis'       => (string)($ref['basis'] ?? 'ladder'),
            'evidence'    => (string)($ref['evidence'] ?? ''),
        ];
    }

    // ================= grading =================

    /** The first reference entry whose glob matches $id, or null. */
    public static function referenceEntryFor(string $id): ?array {
        try {
            $ref = self::reference();
        } catch (AiCatalogException $e) {
            return null;
        }
        foreach ($ref['models'] ?? [] as $entry) {
            $match = (string)($entry['match'] ?? '');
            if ($match === '') continue;
            if (fnmatch($match, $id, FNM_CASEFOLD)) return $entry;
        }
        return null;
    }

    /**
     * Grade an unlisted model from the parameter count in its tag.
     *
     * Most Ollama tags announce their own size (:9b, :4b, :35b-a3b). Where a
     * tag carries more than one count the LARGEST wins — 35b-a3b is a 35B model
     * with 3B active per token, and grading it as a 3B would be wrong in the
     * direction that matters. Only a tag with nothing readable falls to basic,
     * which is the one genuinely unknown case rather than a punishment for
     * being unlisted.
     */
    public static function tierFromLadder(string $id): string {
        $params = self::paramsFromTag($id);
        if ($params === null) return AiModelRequirement::TIER_BASIC;
        try {
            $ladder = self::reference()['ladder'] ?? [];
        } catch (AiCatalogException $e) {
            $ladder = [];
        }
        foreach ($ladder as $rung) {
            $max = $rung['max_params_b'] ?? null;
            if ($max === null || $params <= (float)$max) {
                return (string)($rung['tier'] ?? AiModelRequirement::TIER_BASIC);
            }
        }
        return AiModelRequirement::TIER_BASIC;
    }

    /** Billions of parameters announced by a model tag, or null. Takes the
     *  largest count present. An NxMb mixture tag (mixtral:8x7b) announces
     *  N experts of M billion, so its total is the product. */
    public static function paramsFromTag(string $id): ?float {
        $id = strtolower($id);
        $best = null;
        if (preg_match_all('/(?<![a-z0-9.])(\d+)x(\d+(?:\.\d+)?)\s*b(?![a-z0-9])/', $id, $moe)) {
            foreach ($moe[1] as $i => $n) {
                $v = (float)$n * (float)$moe[2][$i];
                if ($best === null || $v > $best) $best = $v;
            }
        }
        if (preg_match_all('/(?<![a-z0-9.])(\d+(?:\.\d+)?)\s*b(?![a-z0-9])/', $id, $m)) {
            foreach ($m[1] as $n) {
                $v = (float)$n;
                if ($best === null || $v > $best) $best = $v;
            }
        }
        return $best;
    }

    // ================= probing =================

    /**
     * Mechanical facts a probing host reports for one model, or null.
     *
     * The endpoint declares whether it can answer (`probe: "ollama"`), so this
     * is a stated capability rather than the method_exists() duck-typing it
     * replaces. Bounded timeouts and a catch-all: a host that is asleep or slow
     * costs a fraction of a second and yields the stated defaults, never an
     * exception on the resolve path.
     */
    private static function probeFacts(string $endpoint_key, string $model): ?array {
        $e = self::endpoint($endpoint_key);
        if ($e === null || (string)($e['probe'] ?? '') !== 'ollama') return null;

        if (self::$probe_down) return null;
        $cache_key = $endpoint_key . '|' . $model;
        if (array_key_exists($cache_key, self::$probe_cache)) return self::$probe_cache[$cache_key];
        self::$probe_cache[$cache_key] = null;   // memoize the failure too

        $base = self::baseUrl($endpoint_key);
        if ($base === '') return null;
        $root = preg_replace('#/v1/?$#', '', rtrim($base, '/'));

        try {
            require_once(PathHelper::getComposerAutoloadPath());
            $http = new GuzzleHttp\Client(['timeout' => 3, 'connect_timeout' => 2]);
            $res = $http->post($root . '/api/show', [
                'json'        => ['model' => $model],
                'http_errors' => false,
            ]);
            $data = json_decode((string)$res->getBody(), true);
            if (!is_array($data)) return null;

            $caps = array_map('strval', (array)($data['capabilities'] ?? []));
            $facts = [];
            if ($caps) {
                $facts['tools']    = in_array('tools', $caps, true);
                $facts['thinking'] = in_array('thinking', $caps, true) ? 'optional' : 'none';
                $facts['attachments'] = [
                    'vision'   => in_array('vision', $caps, true),
                    // Native document blocks are not part of the OpenAI wire
                    // shape, so a local host never accepts one.
                    'document' => false,
                ];
            }
            $ctx = self::contextFromShow($data);
            if ($ctx !== null) $facts['context'] = $ctx;

            return self::$probe_cache[$cache_key] = ($facts ?: null);
        } catch (GuzzleHttp\Exception\ConnectException $ex) {
            self::$probe_down = true;
            return null;
        } catch (Throwable $ex) {
            return null;
        }
    }

    /** The declared context length out of an /api/show payload, whatever the
     *  architecture calls it (llama.context_length, qwen3.context_length, …). */
    private static function contextFromShow(array $data): ?int {
        $info = $data['model_info'] ?? null;
        if (is_array($info)) {
            foreach ($info as $k => $v) {
                if (substr((string)$k, -15) === '.context_length' && (int)$v > 0) return (int)$v;
            }
        }
        if ((int)($data['context_length'] ?? 0) > 0) return (int)$data['context_length'];
        return null;
    }

    /**
     * The window the host is ACTUALLY serving right now, which is not the same
     * question as the nominal one above: a model rated at 256k can be served
     * with a 24k window by a Modelfile num_ctx or a server-global default, and
     * the runner has to honour the smaller number. Only answerable while the
     * model is loaded. Best-effort; null means "the host did not say".
     */
    public static function liveContextWindow(string $endpoint_key, string $model): ?int {
        $e = self::endpoint($endpoint_key);
        if ($e === null || (string)($e['probe'] ?? '') !== 'ollama') return null;

        // Same discipline as probeFacts(): a sleeping host costs one timeout per
        // request, not one per ask — the breaker is shared and the answer is
        // memoized (failures included).
        if (self::$probe_down) return null;
        $cache_key = $endpoint_key . '|' . $model;
        if (array_key_exists($cache_key, self::$live_ctx_cache)) return self::$live_ctx_cache[$cache_key];
        self::$live_ctx_cache[$cache_key] = null;

        $base = self::baseUrl($endpoint_key);
        if ($base === '') return null;
        $root = preg_replace('#/v1/?$#', '', rtrim($base, '/'));

        try {
            require_once(PathHelper::getComposerAutoloadPath());
            $http = new GuzzleHttp\Client(['timeout' => 2, 'connect_timeout' => 1]);
            $res = $http->get($root . '/api/ps', ['http_errors' => false]);
            $data = json_decode((string)$res->getBody(), true);
            if (!is_array($data) || empty($data['models'])) return null;
            foreach ($data['models'] as $m) {
                if (($m['name'] ?? $m['model'] ?? '') === $model) {
                    $w = (int)($m['context_length'] ?? 0);
                    return self::$live_ctx_cache[$cache_key] = ($w > 0 ? $w : null);
                }
            }
            // A sole loaded model whose reported name is a tag-variant of the
            // asked-for id (qwen3.5:9b vs qwen3.5:9b-nvfp4) is still the one.
            // The variant check matters: without it, asking about the 35B while
            // only the 9B is loaded would report the 9B's window as the 35B's.
            if (count($data['models']) === 1) {
                $loaded = strtolower((string)($data['models'][0]['name'] ?? $data['models'][0]['model'] ?? ''));
                $asked  = strtolower($model);
                $loaded_base = explode(':', $loaded)[0];
                $asked_base  = explode(':', $asked)[0];
                if ($loaded_base === $asked_base
                        && (strpos($loaded, $asked) === 0 || strpos($asked, $loaded) === 0)) {
                    $w = (int)($data['models'][0]['context_length'] ?? 0);
                    return self::$live_ctx_cache[$cache_key] = ($w > 0 ? $w : null);
                }
            }
            return null;
        } catch (GuzzleHttp\Exception\ConnectException $ex) {
            self::$probe_down = true;
            return null;
        } catch (Throwable $ex) {
            return null;
        }
    }
}
