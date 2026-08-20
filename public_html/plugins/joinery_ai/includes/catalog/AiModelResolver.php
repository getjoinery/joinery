<?php

/**
 * Picks the model. One function, one answer, one time.
 *
 * Given a requirement — floors, never a name — this filters the shipped catalog
 * to what this install can actually reach and what clears every floor, orders
 * the survivors by the site's selection policy, and returns an immutable
 * AiModelResolution carrying the choice plus the approved alternatives.
 *
 * Two rules shape everything here:
 *
 *   Prefer local, always, unless something says otherwise. Using someone
 *   else's hardware is a decision someone had to make and can be pointed at.
 *
 *   Fail closed. An unparseable catalog, an unknown pin, an id in no endpoint,
 *   or a floor nothing clears is a refusal naming the gap — never a
 *   fall-through to whatever happens to be available.
 *
 * See specs/joinery_ai_model_capability_resolution.md §5.
 */
class AiModelResolver {

    /** Lowest trust class that clears the floor first, then cheapest. The
     *  platform's standing posture. */
    const POLICY_PREFER_LOCAL = 'prefer_local';
    /** Lowest estimated cost per Mtok, ignoring trust beyond the floor. */
    const POLICY_CHEAPEST     = 'cheapest';
    /** Highest tier that clears the floor, then cheapest. */
    const POLICY_BEST         = 'best';

    const POLICIES = [self::POLICY_PREFER_LOCAL, self::POLICY_CHEAPEST, self::POLICY_BEST];

    /** How many approved alternatives a resolution carries. Enough for a
     *  sleeping model to degrade to a sibling; short enough that a run cannot
     *  spend its wall clock walking a list. */
    const MAX_CANDIDATES = 3;

    /**
     * Resolve a requirement to a model.
     *
     * @throws LlmProviderException naming the gap and the fix, when nothing
     *         available clears the floors
     */
    public static function resolve(AiModelRequirement $req): AiModelResolution {
        try {
            $catalog = AiEndpointRegistry::catalog();
        } catch (AiCatalogException $e) {
            // A catalog that will not parse cannot be reasoned about, so
            // nothing runs. Silently falling back would put work on a model no
            // gate has classified.
            throw new LlmProviderException($e->getMessage());
        }

        // --- a pin is a decision, and it is still checked ---
        $pin = $req->pin();
        $substitution = '';
        if ($pin !== '') {
            $entry = $catalog[$pin] ?? null;
            if ($entry !== null && empty($entry['retired'])) {
                // A pin is checked against every floor SOMEBODY STATED, but not
                // against the platform's last-resort tier guess — the pin is the
                // most specific source in the chain, and refusing it over an
                // assumption nobody made would invert that.
                $gap = self::firstUnmetFloor($entry, $req, $req->tierWasStated());
                if ($gap === null) {
                    return new AiModelResolution($entry, self::alternatives($entry, $catalog, $req),
                        $req, self::thinkingDirectiveFor($entry, $req));
                }
                // The world changed under a saved row: a release raised the
                // floor, or the catalog re-graded the model down. Fail closed —
                // this is a mistake to fix, not an availability fact to route
                // around.
                throw new LlmProviderException(
                    'This is pinned to ' . (string)$entry['label'] . ', which cannot do what '
                    . $req->purpose() . ' needs (' . $req->describe() . '): ' . $gap . ' '
                    . 'Either pin a model that can, or clear the pin and let the requirement choose.');
            }
            // Unavailable, not wrong: the endpoint has no key today, the model
            // is retired, or the host stopped serving it. The requirement is
            // still enough to run on, so fall through — and say so.
            $substitution = AiEndpointRegistry::isDeclared($pin)
                ? 'The pinned model "' . $pin . '" is not available on this install right now, so '
                  . 'the requirement chose instead.'
                : 'The pinned model "' . $pin . '" is not in the model catalog, so the requirement '
                  . 'chose instead.';
        }

        $eligible = self::eligible($catalog, $req, true);

        // Nothing clears the capability floor — but if nobody STATED that floor,
        // refusing would be the platform vetoing the work on its own assumption.
        // An agent-mode recipe assumes `frontier` because the model drives a tool
        // loop; on a box whose largest model grades `capable` that assumption
        // would make agent recipes impossible to create at all, which is not what
        // a default is for. So an unstated floor relaxes to the most capable
        // model that meets every REAL constraint — trust, tools, thinking,
        // context, all of which someone did state or the work genuinely needs —
        // and says so, rather than failing.
        $relaxed = false;
        if (!$eligible && !$req->tierWasStated()) {
            $eligible = self::eligible($catalog, $req, false);
            $relaxed = true;
        }
        if (!$eligible) {
            throw new LlmProviderException(self::refusal($req, $catalog));
        }

        $ordered = self::order($eligible, $req->policy(), $relaxed);
        $first = array_shift($ordered);
        if ($relaxed) {
            $note = 'Nothing here is graded ' . $req->minTier() . ', which is what this kind of work '
                  . 'usually wants, so it will run on ' . (string)$first['label'] . ' — the most '
                  . 'capable model the selection policy offers. Serve a larger model, or set the '
                  . 'minimum yourself if that is not good enough.';
            $substitution = $substitution === '' ? $note : $substitution . ' ' . $note;
        }
        return new AiModelResolution($first, self::truncateByCost($first, $ordered), $req,
            self::thinkingDirectiveFor($first, $req), $substitution);
    }

    /**
     * Resolve, or return null instead of throwing. For read-only surfaces — an
     * edit page rendering "right now this runs on…" must not 500 because no
     * model is configured yet.
     */
    public static function tryResolve(AiModelRequirement $req, ?string &$error = null): ?AiModelResolution {
        try {
            $error = null;
            return self::resolve($req);
        } catch (Throwable $e) {
            $error = $e->getMessage();
            return null;
        }
    }

    /** Catalog models that are usable and clear every floor. $check_tier off
     *  drops only the capability rung — every other constraint still applies. */
    private static function eligible(array $catalog, AiModelRequirement $req, bool $check_tier): array {
        $out = [];
        foreach ($catalog as $entry) {
            if (!empty($entry['retired'])) continue;
            if (self::firstUnmetFloor($entry, $req, $check_tier) !== null) continue;
            $out[] = $entry;
        }
        return $out;
    }

    // ================= filtering =================

    /**
     * The first floor $entry fails, as a plain sentence, or null when it clears
     * every one. Returning the reason rather than a boolean is what lets a
     * refusal name the gap instead of saying "no model available".
     */
    private static function firstUnmetFloor(array $entry, AiModelRequirement $req,
            bool $check_tier = true): ?string {
        if (!AiModelRequirement::trustSatisfies($req->trustFloor(), (string)$entry['trust'])) {
            return 'it runs on a ' . (string)$entry['trust'] . ' endpoint.';
        }
        if ($check_tier
                && AiModelRequirement::tierRank($entry['tier']) < AiModelRequirement::tierRank($req->minTier())) {
            return 'it is graded ' . (string)$entry['tier'] . ', below ' . $req->minTier() . '.';
        }
        if ($req->thinkingRequired() && (string)$entry['thinking'] === 'none') {
            return 'it cannot reason.';
        }
        if ($req->needsTools() && empty($entry['tools'])) {
            return 'it cannot drive tools.';
        }
        if ($req->needsVision() && empty($entry['attachments']['vision'])) {
            return 'it cannot read images.';
        }
        if ($req->needsDocument() && empty($entry['attachments']['document'])) {
            return 'it cannot read documents.';
        }
        if ($req->minContext() !== null) {
            $ctx = $entry['context'];
            if ($ctx === null) {
                // An unknown context fails CLOSED, naming the silence rather
                // than guessing a number that would let an oversized digest
                // through.
                return 'its host does not report a context window, and ' . $req->purpose()
                     . ' needs at least ' . number_format($req->minContext()) . ' tokens.';
            }
            if ((int)$ctx < $req->minContext()) {
                return 'its context window is ' . number_format((int)$ctx) . ' tokens, below the '
                     . number_format($req->minContext()) . ' needed.';
            }
        }
        return null;
    }

    // ================= ordering =================

    /** Deterministic order for the survivors. A recipe's behaviour must not
     *  drift between runs, so every comparison ends in catalog order. */
    private static function order(array $models, string $policy, bool $want_best = false): array {
        $index = [];
        foreach (array_keys(AiEndpointRegistry::catalog()) as $i => $id) $index[$id] = $i;

        $trust_rank = [AiModelRequirement::TRUST_LOCAL => 0, AiModelRequirement::TRUST_TRUSTED => 1, 'cloud' => 2];

        usort($models, function (array $a, array $b) use ($policy, $index, $trust_rank, $want_best) {
            if ($policy === self::POLICY_BEST) {
                $t = AiModelRequirement::tierRank($b['tier']) <=> AiModelRequirement::tierRank($a['tier']);
                if ($t !== 0) return $t;
            } elseif ($policy !== self::POLICY_CHEAPEST) {
                // prefer_local, the default: the lowest trust class that clears
                // the floor wins outright. Cloud is reached only when the
                // operator's own hardware cannot meet the requirement.
                $t = ($trust_rank[$a['trust']] ?? 2) <=> ($trust_rank[$b['trust']] ?? 2);
                if ($t !== 0) return $t;
            }
            // Nothing met the assumed floor, so the tie-break inverts: take the
            // MOST capable available rather than the least, since "least that
            // clears the floor" is meaningless when none of them do.
            if ($want_best) {
                $t = AiModelRequirement::tierRank($b['tier']) <=> AiModelRequirement::tierRank($a['tier']);
                if ($t !== 0) return $t;
            }
            $c = self::rateOf($a) <=> self::rateOf($b);
            if ($c !== 0) return $c;
            if ($policy !== self::POLICY_BEST && !$want_best) {
                // Among equally-priced survivors, take the LEAST capable one
                // that still clears the floor. On a local box every model is
                // free, so dollars separate nothing — but running a 35B to
                // answer "is this an advertisement" spends minutes of GPU per
                // item that a 9B clearing the same floor would not. A floor
                // exists to stop work reaching a model that cannot do it, not
                // to reserve work for the biggest one available.
                $t = AiModelRequirement::tierRank($a['tier']) <=> AiModelRequirement::tierRank($b['tier']);
                if ($t !== 0) return $t;
            }
            return ($index[$a['id']] ?? PHP_INT_MAX) <=> ($index[$b['id']] ?? PHP_INT_MAX);
        });
        return $models;
    }

    /** A single comparable price for a model: input plus output per Mtok. Free
     *  (a local model, which declares no cost) sorts first. */
    public static function rateOf(array $entry): float {
        $cost = (array)($entry['cost'] ?? []);
        if (!$cost) return 0.0;
        return (float)($cost['input'] ?? 0) + (float)($cost['output'] ?? 0);
    }

    /**
     * The approved fallbacks, cut off where they would start costing more than
     * the first choice.
     *
     * This is the whole of the "failover never spends money" rule. A timeout
     * must never be the reason a bill appears, so the candidate list is fixed
     * at resolve time and can only contain models at or below the first
     * choice's price. A free first choice can therefore only fail over to
     * another free one — which on this fleet means an hour's capable work
     * degrades from a 35B to a measured 9B when the big one will not load,
     * instead of quietly moving to a vendor.
     */
    private static function truncateByCost(array $first, array $rest): array {
        $ceiling = self::rateOf($first);
        $out = [];
        foreach ($rest as $c) {
            if (self::rateOf($c) > $ceiling) break;
            $out[] = $c;
            if (count($out) >= self::MAX_CANDIDATES) break;
        }
        return $out;
    }

    /** Approved alternatives for a PINNED first choice: same filters, same
     *  cost ceiling, so a pin degrades the same way anything else does.
     *
     *  The capability rung the candidates must clear depends on who set it. A
     *  STATED floor is the contract, exactly as for an unpinned resolution.
     *  When nobody stated one, the PIN's own tier is the accepted level — the
     *  operator chose that model knowingly, so a sibling at or above it is an
     *  acceptable substitute and one below it is not. Dropping the tier check
     *  outright here (what an unstated floor used to do) handed a pinned 35B a
     *  basic 4B as its first fallback while a capable sibling sat unused. */
    private static function alternatives(array $first, array $catalog, AiModelRequirement $req): array {
        $pin_rank = AiModelRequirement::tierRank($first['tier']);
        $rest = [];
        foreach (self::eligible($catalog, $req, $req->tierWasStated()) as $entry) {
            if ((string)$entry['id'] === (string)$first['id']) continue;
            if (!$req->tierWasStated()
                    && AiModelRequirement::tierRank($entry['tier']) < $pin_rank) continue;
            $rest[] = $entry;
        }
        return self::truncateByCost($first, self::order($rest, $req->policy()));
    }

    // ================= thinking =================

    /**
     * The concrete reasoning directive for a chosen model.
     *
     * Once the catalog declares whether a model can reason, providers cannot
     * keep their own tables of model names without two catalogs disagreeing. So
     * the decision is made here and providers translate it into their own wire
     * field.
     *
     * A model that cannot reason gets reasoning off, whatever the level says —
     * the mismatch is surfaced on the edit page rather than legislated. A model
     * that always reasons treats "off" as its lowest effort, because refusing
     * it would be refusing a perfectly good model over a knob.
     */
    public static function thinkingDirectiveFor(array $entry, AiModelRequirement $req): array {
        $level = $req->thinkingLevel();
        $capability = (string)($entry['thinking'] ?? 'optional');

        if ($capability === 'none') {
            return ['enabled' => false, 'effort' => null, 'level' => 'off'];
        }
        if ($capability === 'always') {
            $effort = ($level === 'off' || $level === '') ? 'low' : $level;
            return ['enabled' => true, 'effort' => $effort, 'level' => $effort];
        }
        if ($level === 'off' || $level === '') {
            return ['enabled' => false, 'effort' => null, 'level' => 'off'];
        }
        return ['enabled' => true, 'effort' => $level, 'level' => $level];
    }

    // ================= refusals =================

    /**
     * Why nothing was chosen, and what to do about it.
     *
     * A refusal has to name the gap, because the whole cost of this design is
     * that the model is no longer a name someone typed on the recipe. "No model
     * available" would make a mis-configured install unanswerable.
     */
    private static function refusal(AiModelRequirement $req, array $catalog): string {
        if (!$catalog) {
            return 'No AI endpoint is configured on this install, so ' . $req->purpose()
                 . ' has nothing to run on. Set a local model, or add an API key on the Joinery AI '
                 . 'settings page.';
        }

        $lines = [];
        foreach ($catalog as $entry) {
            $gap = self::firstUnmetFloor($entry, $req);
            if ($gap !== null) $lines[(string)$entry['label']] = $gap;
        }

        $msg = ucfirst($req->purpose()) . ' needs ' . $req->describe() . ', and nothing configured '
             . 'here provides it.';
        if ($lines) {
            $shown = array_slice($lines, 0, 4, true);
            foreach ($shown as $label => $gap) $msg .= ' ' . $label . ': ' . $gap;
        }
        if ($req->trustFloor() === AiModelRequirement::TRUST_LOCAL) {
            $msg .= ' Serve a larger model on your local host, or lower the minimum.';
        } else {
            $msg .= ' Serve a larger model locally, add an endpoint key, or lower the minimum.';
        }
        return $msg;
    }
}
