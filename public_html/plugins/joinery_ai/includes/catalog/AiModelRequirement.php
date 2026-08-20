<?php

/**
 * What a piece of work needs from a model, stated as floors rather than a name.
 *
 * A requirement never names a model. It says the least a model must be able to
 * do — "judge one adversarial item", "stay on my hardware", "be able to reason
 * at all" — and AiModelResolver picks the cheapest catalog model that clears
 * every floor. That is what lets a fleet-wide model change be a file edit
 * instead of a tour of production databases.
 *
 * Immutable: every wither returns a copy. A requirement is passed into
 * resolution and then thrown away, so nothing can tighten one after the gates
 * have read it.
 *
 * See specs/joinery_ai_model_capability_resolution.md §4.
 */
class AiModelRequirement {

    // --- capability tiers, weakest first ---
    const TIER_BASIC    = 'basic';
    const TIER_STANDARD = 'standard';
    const TIER_CAPABLE  = 'capable';
    const TIER_FRONTIER = 'frontier';

    /** Rung order. A request for tier N is satisfied by any model of tier >= N. */
    const TIERS = [self::TIER_BASIC, self::TIER_STANDARD, self::TIER_CAPABLE, self::TIER_FRONTIER];

    // --- trust floors ---
    /** Only endpoints whose bytes never leave hardware the operator controls. */
    const TRUST_LOCAL   = 'local';
    /** Local, plus a named vendor the operator has accepted terms with. */
    const TRUST_TRUSTED = 'trusted';
    /** Any endpoint, including a general cloud vendor. */
    const TRUST_ANY     = 'any';

    /** Trust classes an endpoint may declare, most protective first. */
    const TRUST_CLASSES = [self::TRUST_LOCAL, self::TRUST_TRUSTED, 'cloud'];

    /** What each floor accepts, as endpoint trust classes. */
    const TRUST_ACCEPTS = [
        self::TRUST_LOCAL   => [self::TRUST_LOCAL],
        self::TRUST_TRUSTED => [self::TRUST_LOCAL, self::TRUST_TRUSTED],
        self::TRUST_ANY     => [self::TRUST_LOCAL, self::TRUST_TRUSTED, 'cloud'],
    ];

    /** @var string one of TIERS */
    private $min_tier = self::TIER_STANDARD;

    /** @var bool Did anyone actually STATE this floor, or is it the platform's
     *  last-resort guess? It matters in exactly one place: whether an explicit
     *  pin is refused for sitting below it. See withFallbackMinTier(). */
    private $tier_stated = false;

    /** @var string one of TRUST_LOCAL|TRUST_TRUSTED|TRUST_ANY */
    private $trust_floor = self::TRUST_ANY;

    /** @var bool TRUE excludes any model whose catalog `thinking` is 'none'. */
    private $thinking_required = false;

    /** @var int|null nominal context window floor, in tokens; null = no floor */
    private $min_context = null;

    /** @var bool the model must be able to drive tool calls */
    private $needs_tools = false;

    /** @var bool the model must accept image blocks */
    private $needs_vision = false;

    /** @var bool the model must accept native document blocks */
    private $needs_document = false;

    /** @var string an exact catalog model id the operator pinned, or '' */
    private $pin = '';

    /** @var string 'off'|'low'|'medium'|'high' — how hard to reason, not whether it can */
    private $thinking_level = 'off';

    /** @var string one of AiModelResolver::POLICY_* — how to choose among survivors */
    private $policy = 'prefer_local';

    /** @var string short human phrase naming what is asking, for refusal messages */
    private $purpose = 'this work';

    /**
     * A requirement with the platform's fallback floors. Callers narrow it with
     * the withers; nothing is required up front, because "no opinion" is the
     * common case and must stay the easy one.
     */
    public static function make(): self {
        return new self();
    }

    private function copy(): self {
        return clone $this;
    }

    // --- withers ---

    /** A floor somebody stated — an operator's override, a job's declaration, a
     *  shipped recipe's declaration. A pin below one of these is a mistake and
     *  is refused.
     *
     *  An INVALID non-empty value throws rather than being ignored. Ignoring it
     *  would silently demote a stated floor to the unstated fallback — a typo in
     *  a job's minTier() would quietly stop vetoing pins, which is exactly the
     *  fail-open this design exists to end. '' and null mean "no opinion". */
    public function withMinTier(?string $tier): self {
        $tier = strtolower(trim((string)$tier));
        if ($tier === '') return $this;
        if (!in_array($tier, self::TIERS, true)) {
            throw new InvalidArgumentException(
                '"' . $tier . '" is not a capability tier (' . implode(', ', self::TIERS) . ').');
        }
        $c = $this->copy(); $c->min_tier = $tier; $c->tier_stated = true; return $c;
    }

    /**
     * The platform's last-resort floor, used when nothing in the chain had an
     * opinion.
     *
     * It still filters — an unstated recipe has to land somewhere sensible — but
     * it never VETOES an explicit pin. A fallback exists to give a recipe that
     * said nothing something reasonable; treating it as grounds to refuse the
     * one thing the operator did say would invert the resolution chain, where
     * the pin is the most specific source of all. A floor that matters —
     * "this job reads attacker-controlled mail and needs a capable model" — is
     * always stated by the code that knows, and that one does veto a pin.
     */
    public function withFallbackMinTier(?string $tier): self {
        $tier = strtolower(trim((string)$tier));
        if ($tier === '') return $this;
        if (!in_array($tier, self::TIERS, true)) {
            throw new InvalidArgumentException(
                '"' . $tier . '" is not a capability tier (' . implode(', ', self::TIERS) . ').');
        }
        $c = $this->copy(); $c->min_tier = $tier; $c->tier_stated = false; return $c;
    }

    /** Whether the capability floor was stated by someone, rather than assumed. */
    public function tierWasStated(): bool { return $this->tier_stated; }

    /** An invalid non-empty floor throws — ignoring it would leave the floor at
     *  the default ANY, failing OPEN on the one field where that ships sealed
     *  content to a vendor. '' and null mean "no opinion". */
    public function withTrustFloor(?string $floor): self {
        $floor = strtolower(trim((string)$floor));
        if ($floor === '') return $this;
        if (!isset(self::TRUST_ACCEPTS[$floor])) {
            throw new InvalidArgumentException(
                '"' . $floor . '" is not a trust floor (' . implode(', ', array_keys(self::TRUST_ACCEPTS)) . ').');
        }
        $c = $this->copy(); $c->trust_floor = $floor; return $c;
    }

    /**
     * Narrow the trust floor to the stricter of the current one and $floor.
     *
     * The one-way-tightening rule in code: a domain's consent, a chat level and
     * a recipe's own floor all push in the same direction, and no caller can
     * loosen what an earlier one tightened.
     */
    public function tightenTrustFloor(?string $floor): self {
        $floor = strtolower(trim((string)$floor));
        if ($floor === '') return $this;
        if (!isset(self::TRUST_ACCEPTS[$floor])) {
            throw new InvalidArgumentException(
                '"' . $floor . '" is not a trust floor (' . implode(', ', array_keys(self::TRUST_ACCEPTS)) . ').');
        }
        $rank = [self::TRUST_LOCAL => 0, self::TRUST_TRUSTED => 1, self::TRUST_ANY => 2];
        return $rank[$floor] < $rank[$this->trust_floor] ? $this->withTrustFloor($floor) : $this;
    }

    public function withThinkingRequired(bool $required): self {
        $c = $this->copy(); $c->thinking_required = $required; return $c;
    }

    public function withMinContext(?int $tokens): self {
        $c = $this->copy(); $c->min_context = ($tokens !== null && $tokens > 0) ? $tokens : null; return $c;
    }

    public function withTools(bool $needed): self {
        $c = $this->copy(); $c->needs_tools = $needed; return $c;
    }

    public function withVision(bool $needed): self {
        $c = $this->copy(); $c->needs_vision = $needed; return $c;
    }

    public function withDocument(bool $needed): self {
        $c = $this->copy(); $c->needs_document = $needed; return $c;
    }

    public function withPin(?string $model_id): self {
        $c = $this->copy(); $c->pin = trim((string)$model_id); return $c;
    }

    public function withThinkingLevel(?string $level): self {
        $level = strtolower(trim((string)$level));
        $c = $this->copy();
        $c->thinking_level = in_array($level, ['off', 'low', 'medium', 'high'], true) ? $level : 'off';
        return $c;
    }

    public function withPolicy(?string $policy): self {
        $policy = strtolower(trim((string)$policy));
        if ($policy === '') return $this;
        if (!in_array($policy, AiModelResolver::POLICIES, true)) {
            throw new InvalidArgumentException(
                '"' . $policy . '" is not a selection policy (' . implode(', ', AiModelResolver::POLICIES) . ').');
        }
        $c = $this->copy(); $c->policy = $policy; return $c;
    }

    public function withPurpose(string $purpose): self {
        $c = $this->copy(); $c->purpose = $purpose !== '' ? $purpose : 'this work'; return $c;
    }

    // --- readers ---

    public function minTier(): string          { return $this->min_tier; }
    public function trustFloor(): string       { return $this->trust_floor; }
    public function thinkingRequired(): bool   { return $this->thinking_required; }
    public function minContext(): ?int         { return $this->min_context; }
    public function needsTools(): bool         { return $this->needs_tools; }
    public function needsVision(): bool        { return $this->needs_vision; }
    public function needsDocument(): bool      { return $this->needs_document; }
    public function pin(): string              { return $this->pin; }
    public function thinkingLevel(): string    { return $this->thinking_level; }
    public function policy(): string           { return $this->policy; }
    public function purpose(): string          { return $this->purpose; }

    /** Rung index of a tier name, or -1 when it is not a tier at all. */
    public static function tierRank(?string $tier): int {
        $i = array_search(strtolower(trim((string)$tier)), self::TIERS, true);
        return $i === false ? -1 : (int)$i;
    }

    /** Does an endpoint of trust class $class satisfy $floor? */
    public static function trustSatisfies(string $floor, string $class): bool {
        $accepts = self::TRUST_ACCEPTS[$floor] ?? self::TRUST_ACCEPTS[self::TRUST_ANY];
        return in_array($class, $accepts, true);
    }

    /** The stricter of two trust floors/classes, by protectiveness. */
    public static function strictestTrust(string $a, string $b): string {
        $rank = [self::TRUST_LOCAL => 0, self::TRUST_TRUSTED => 1, 'cloud' => 2, self::TRUST_ANY => 2];
        return ($rank[$a] ?? 2) <= ($rank[$b] ?? 2) ? $a : $b;
    }

    /** One line naming what was asked for, for a refusal or a UI summary. */
    public function describe(): string {
        $bits = ['a ' . $this->min_tier . ' model'];
        if ($this->trust_floor === self::TRUST_LOCAL)   $bits[] = 'that stays on your hardware';
        if ($this->trust_floor === self::TRUST_TRUSTED) $bits[] = 'on your hardware or a vendor you have accepted';
        if ($this->thinking_required)                   $bits[] = 'that can reason';
        if ($this->needs_tools)                         $bits[] = 'that can drive tools';
        if ($this->needs_vision)                        $bits[] = 'that can read images';
        if ($this->needs_document)                      $bits[] = 'that can read documents';
        if ($this->min_context !== null)                $bits[] = 'with at least ' . number_format($this->min_context) . ' tokens of context';
        return implode(' ', $bits);
    }
}
