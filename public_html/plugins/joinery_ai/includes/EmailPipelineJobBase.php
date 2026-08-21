<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/PipelineJobInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/AreaScopedJobInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/EmailJobCandidates.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxAliasConfig.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/EmailSecurityDigest.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/EmailAttachmentDigest.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/data/aip_recipe_item_log_class.php'));

/**
 * Shared spine of the three email pipeline jobs (triage, security scan,
 * schedule): the `mailbox_aliases` list binding, its validation, the
 * per-subset scheduling posture, candidate selection across the union, and
 * the mail-reader AI panel's bind/unbind contract. Each concrete job supplies
 * only what genuinely differs — its identity, verdict contract, prompt, and
 * what it does with a verdict (specs/implemented/ai_recipes_multi_mailbox_and_ai_panel.md).
 *
 * Lives in includes/, NOT pipeline_jobs/ — PipelineJobRegistry instantiates
 * every class it discovers there, and an abstract class cannot be.
 *
 * @version 1.1
 */
abstract class EmailPipelineJobBase implements PipelineJobInterface, AreaScopedJobInterface {

    /** The mailbox-list field's label, in the job's own words. */
    abstract protected function mailboxFieldLabel(): string;

    /** The mailbox-list field's help text, in the job's own words. */
    abstract protected function mailboxFieldHelp(): string;

    /** Mail is a stream of arrivals, so these jobs offer the option to run on
     *  one — the wording an operator would use for their own inbox. */
    public function arrivalLabel(): ?string { return 'As mail arrives'; }

    /** Whether nextItem() appends the attachment digest to the security
     *  digest. The scan job judges the message envelope and body alone. */
    protected function includeAttachmentDigest(): bool {
        return true;
    }

    public function configDescriptor(): array {
        return ['input' => [
            'mailbox_aliases' => MailboxAliasConfig::descriptorListField(
                $this->mailboxFieldLabel(), $this->mailboxFieldHelp()),
        ]];
    }

    /**
     * One rule for every listed address: it must resolve to a real, enabled,
     * store-capable mailbox, the recipe's owner must hold an explicit grant on
     * it (the same access check the Mailbox Reader itself enforces, so a
     * recipe can never read mail its owner couldn't already see), and a sealed
     * domain must have consented to AI reading its mail. Every address on the
     * list was chosen by name, so refusing by name is always right. An empty
     * list is legal — the recipe simply covers nothing.
     */
    public function validateConfig(array $config, Recipe $recipe): void {
        $owner_id = (int)$recipe->get('rcp_owner_user_id');
        foreach (MailboxAliasConfig::listedAddresses($config) as $address) {
            MailboxAliasConfig::validateOwnerGrant($address, $owner_id);
            EmailJobCandidates::assertAiProcessingAllowed($address);
        }
    }

    /** Email is attacker-controlled text — the recipe carries
     *  rcp_allow_tainted_writes per the pipeline's taint posture. */
    public function untrustedDigest(): bool {
        return true;
    }

    /**
     * Mail on a sealed domain can only be read inside the owner's unlock
     * window, so that subset of the binding never runs from cron
     * (specs/in_window_deferred_work.md). The standard addresses on the same
     * list need no window and keep running on the schedule, unattended.
     */
    public function requiresVaultScope(array $config): ?string {
        return EmailJobCandidates::requiredVaultScope($config);
    }

    /** Cron can make progress whenever any listed address is standard —
     *  the sealed remainder fails closed out of the candidate set there. */
    public function hasUnsealedBinding(array $config): bool {
        foreach (MailboxAliasConfig::listedAddresses($config) as $address) {
            if (!MailboxAliasConfig::isSealedAtRest($address)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The domain's second consent: how far may its decrypted mail travel?
     *
     * A run reads the whole bound set in one pass, so the answer is the
     * STRICTEST any sealed address gives — one address that must stay on the
     * box holds the whole recipe there. An address with nothing sealed has
     * nothing to protect and contributes no constraint.
     */
    public function processingConsent(array $config): string {
        $consent = InboundEmailDomain::CONSENT_CLOUD;
        foreach (MailboxAliasConfig::listedAddresses($config) as $address) {
            if (!MailboxAliasConfig::isSealedAtRest($address)) continue;
            $consent = InboundEmailDomain::strictestConsent(
                $consent, MailboxAliasConfig::aiProcessingConsent($address));
        }
        return $consent;
    }

    /**
     * Mail is document-length and the verdict has several fields to hold at
     * once, so `standard` is the honest floor for the family. A job whose
     * judgement is adversarial — the security scan — raises it for itself.
     */
    public function minTier(): string {
        return AiModelRequirement::TIER_STANDARD;
    }

    /**
     * No floor of the job's own: what mail may reach is the domain's decision,
     * enforced by processingConsent() above, and stating a second answer here
     * would be a place for the two to disagree.
     */
    public function defaultTrustFloor(): string {
        return AiModelRequirement::TRUST_ANY;
    }

    /** Cheap existence check for the same pool nextItem() draws from,
     *  narrowed to the caller's posture subset (see PipelineJobInterface). */
    public function hasWork(array $config, Recipe $recipe, ?string $posture = null): bool {
        $owner_id = (int)$recipe->get('rcp_owner_user_id');
        return EmailJobCandidates::hasCandidate(
            EmailJobCandidates::readableAliasIds($config, $owner_id, $posture),
            (int)$recipe->key, $owner_id);
    }

    /** How far behind this recipe is, for the mailbox catch-up prompt. */
    public function countWork(array $config, Recipe $recipe, ?string $posture = null): int {
        $owner_id = (int)$recipe->get('rcp_owner_user_id');
        return EmailJobCandidates::countCandidates(
            EmailJobCandidates::readableAliasIds($config, $owner_id, $posture),
            (int)$recipe->key, $owner_id);
    }

    /**
     * Listed addresses the run cannot cover right now, one line each for the
     * run tally: an address that stopped resolving (grant revoked, mailbox
     * disabled, domain disabled) or whose domain sealed itself without the AI
     * opt-in. A sealed address merely waiting for the owner's window is NOT a
     * gap — that is its normal posture — so it is not reported.
     */
    public function coverageNotes(array $config, Recipe $recipe): array {
        $owner_id = (int)$recipe->get('rcp_owner_user_id');
        $resolved = MailboxAliasConfig::resolveBoundAliases($config, $owner_id);
        $notes = [];
        foreach (MailboxAliasConfig::listedAddresses($config) as $address) {
            if (!in_array($address, $resolved, true)) {
                $notes[] = "$address is on this recipe's list but not covered right now — "
                         . 'the mailbox is disabled, or the recipe owner no longer holds a grant on it.';
            } elseif (MailboxAliasConfig::isSealedAtRest($address)
                    && !MailboxAliasConfig::aiProcessingAllowed($address)) {
                $notes[] = "$address is on this recipe's list but its domain is now encrypted at rest "
                         . 'without the AI-processing opt-in, so this recipe cannot read it.';
            }
        }
        return $notes;
    }

    public function nextItem(array $config, Recipe $recipe, AiModelResolution $model): ?array {
        $owner_id = (int)$recipe->get('rcp_owner_user_id');
        // Selection is shared across all three email jobs so they cannot
        // drift apart — see EmailJobCandidates for the rules. Config drift
        // (an alias renamed/disabled/removed after save) resolves to a
        // smaller readable set rather than a hard failure; coverageNotes()
        // reports the gap on the run tally.
        $id = EmailJobCandidates::nextId(
            EmailJobCandidates::readableAliasIds($config, $owner_id),
            (int)$recipe->key, $owner_id);
        if ($id === null) return null;

        $msg = new InboundEmailMessage($id, TRUE);
        if (!$msg->key) return null;

        $subject = trim((string)$msg->get('iem_subject'));
        $digest = EmailSecurityDigest::build($msg);
        if ($this->includeAttachmentDigest()) {
            $attachments = EmailAttachmentDigest::build($msg);
            if ($attachments !== '') {
                $digest .= "\n\n" . $attachments;
            }
        }
        return [
            'item_key' => (string)$msg->key,
            'digest'   => $digest,
            'label'    => $subject !== '' ? $subject : '(no subject)',
        ];
    }

    /**
     * Defense in depth for every recordVerdict(): nextItem() already scoped to
     * the bound set; re-resolve here so model output can never steer the one
     * write door to a message on a mailbox the config doesn't cover right now.
     * Returns the loaded message, or null when it vanished between selection
     * and judging (nothing to record).
     */
    protected function loadJudgedMessage(string $item_key, Recipe $recipe): ?InboundEmailMessage {
        $msg = new InboundEmailMessage((int)$item_key, TRUE);
        if (!$msg->key) return null;

        $config = Recipe::decodeSourceConfig($recipe);
        $resolved = MailboxAliasConfig::resolveBoundAliases(
            $config, (int)$recipe->get('rcp_owner_user_id'));
        if (!isset($resolved[(int)$msg->get('iem_iea_inbound_email_alias_id')])) {
            throw new InvalidArgumentException(
                "Message $item_key is not on a mailbox this recipe covers.");
        }
        return $msg;
    }

    // ---- AreaScopedJobInterface: the mail reader's AI panel ----

    public function area(): string {
        return 'mailbox';
    }

    public function coversContext(array $config, array $context, Recipe $recipe): bool {
        $address = strtolower(trim((string)($context['mailbox'] ?? '')));
        return $address !== ''
            && in_array($address, MailboxAliasConfig::listedAddresses($config), true);
    }

    public function bindContext(array $config, array $context, bool $on): array {
        $address = strtolower(trim((string)($context['mailbox'] ?? '')));
        if ($address === '') {
            throw new InvalidArgumentException('No mailbox in the panel context to bind.');
        }
        $list = MailboxAliasConfig::listedAddresses($config);
        if ($on) {
            if (!in_array($address, $list, true)) {
                $list[] = $address;
            }
        } else {
            $list = array_values(array_diff($list, [$address]));
        }
        $config['mailbox_aliases'] = $list;
        return $config;
    }

    public function contextCount(array $config): int {
        return count(MailboxAliasConfig::listedAddresses($config));
    }

}
