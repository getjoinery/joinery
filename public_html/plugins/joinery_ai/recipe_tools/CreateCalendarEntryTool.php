<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/QueueableToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ProposedActionFacts.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarEntryImporter.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

/**
 * Put an entry on the acting user's own calendar — through the one
 * owner-fixed door every AI-originated entry takes, CalendarEntryImporter
 * (specs/joinery_ai_calendar_ai_surface.md).
 *
 * Queueable, and that is the point of it: the email schedule job PROPOSES an
 * entry it found in a stranger's mail by enqueueing this call for the owner
 * (specs/security_inventory.md S17), and the card the owner approves is
 * rendered from these literal arguments. The entry is created only when they
 * approve, and then exactly as the importer always creates one: tentative,
 * on their own calendar, deduped on its provenance so a second proposal for
 * the same message updates the same entry.
 *
 * The subject is always the acting user. Nothing in the input can aim the
 * entry at anyone else's calendar.
 *
 * @version 1.1
 * @changelog 1.1 - the card shows the time in the owner's zone with the
 *   email's own clock alongside, and names the source email (subject, sender,
 *   mailbox) instead of its row id
 */
class CreateCalendarEntryTool implements RecipeToolInterface, QueueableToolInterface {

    /**
     * The card, from the literal arguments, for the owner who will read it:
     *
     *   Add to your calendar: Joinery // Akamai
     *   Mon, Sep 14 · 1:30–2:00 PM EDT (12:30–1:00 PM CDT)
     *   From the email “Joinery // Akamai” sent by Akamai <x@akamai.com> to info@getjoinery.com
     *
     * The time is shown in the OWNER's timezone — that is what their calendar
     * will show and what they will live by — with the event's own wall clock
     * in parentheses when the email named a different zone, so the card can
     * be checked against the email without arithmetic.
     *
     * The source line names the message the proposal came from, looked up by
     * id at render time — never carried in the arguments, where a model could
     * write it. It is shown only if the owner may read that mailbox; a chat
     * model naming a stranger's message id gets a line saying the email
     * cannot be opened, not its subject.
     */
    public function renderProposedAction(array $input, ?int $owner_id = null): array {
        $lines = ['Add to your calendar: ' . ProposedActionFacts::scalar($input['title'] ?? '')];
        $lines[] = self::whenLine($input, $owner_id);
        $source = self::sourceLine($input, $owner_id);
        if ($source !== null) $lines[] = $source;
        return $lines;
    }

    /**
     * "Mon, Sep 14 · 1:30–2:00 PM EDT (12:30–1:00 PM CDT)". The year appears
     * only when it is not this year; a multi-day span states both ends in
     * full; an all-day entry says so instead of a clock time.
     */
    private static function whenLine(array $input, ?int $owner_id): string {
        $start = trim((string)($input['start_local'] ?? ''));
        $end   = trim((string)($input['end_local'] ?? ''));
        $event_tz = trim((string)($input['timezone'] ?? ''));
        $valid = function (string $tz): bool {
            return $tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true);
        };
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start)) {
            // Malformed input still renders literally — the card never hides
            // what would be attempted.
            return 'When: ' . ProposedActionFacts::scalar($start)
                . ($event_tz !== '' ? ' ' . ProposedActionFacts::scalar($event_tz) : '');
        }
        if (!$valid($event_tz)) $event_tz = self::ownerTimezone($owner_id);
        if (!empty($input['all_day'])) {
            return self::dayLabel($start, $event_tz, $event_tz) . ' · all day';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $end)) $end = '';

        $owner_tz = self::ownerTimezone($owner_id);
        $line = self::spanLabel($start, $end, $event_tz, $owner_tz);
        if ($owner_tz !== $event_tz
                && LibraryFunctions::convert_time($start, $event_tz, $owner_tz, 'P')
                    !== LibraryFunctions::convert_time($start, $event_tz, $event_tz, 'P')) {
            // The email's zone keeps a different clock: show its wall time
            // too, so the card can be checked against the email as written.
            $line .= ' (' . self::spanLabel($start, $end, $event_tz, $event_tz, true) . ')';
        }
        return $line;
    }

    /** "Mon, Sep 14 · 1:30–2:00 PM EDT" in $display_tz; $compact drops the day when it is the same. */
    private static function spanLabel(string $start, string $end, string $from_tz,
            string $display_tz, bool $compact = false): string {
        $day = self::dayLabel($start, $from_tz, $display_tz);
        $clock = LibraryFunctions::convert_time($start, $from_tz, $display_tz, 'g:i A');
        $abbr  = LibraryFunctions::convert_time($start, $from_tz, $display_tz, 'T');
        if ($end === '') {
            return ($compact ? '' : $day . ' · ') . $clock . ' ' . $abbr;
        }
        $same_day = LibraryFunctions::convert_time($start, $from_tz, $display_tz, 'Y-m-d')
            === LibraryFunctions::convert_time($end, $from_tz, $display_tz, 'Y-m-d');
        if ($same_day) {
            $end_clock = LibraryFunctions::convert_time($end, $from_tz, $display_tz, 'g:i A');
            // "1:30–2:00 PM", not "1:30 PM–2:00 PM", when both ends share a meridiem.
            if (substr($clock, -3) === substr($end_clock, -3)) $clock = substr($clock, 0, -3);
            return ($compact ? '' : $day . ' · ') . $clock . '–' . $end_clock . ' ' . $abbr;
        }
        return $day . ', ' . $clock . ' – '
            . self::dayLabel($end, $from_tz, $display_tz) . ', '
            . LibraryFunctions::convert_time($end, $from_tz, $display_tz, 'g:i A') . ' ' . $abbr;
    }

    /** "Mon, Sep 14", with the year only when it is not this year. */
    private static function dayLabel(string $when, string $from_tz, string $display_tz): string {
        $this_year = LibraryFunctions::convert_time(gmdate('Y-m-d H:i:s'), 'UTC', $display_tz, 'Y');
        $year = LibraryFunctions::convert_time($when, $from_tz, $display_tz, 'Y');
        return LibraryFunctions::convert_time($when, $from_tz, $display_tz,
            $year === $this_year ? 'D, M j' : 'D, M j, Y');
    }

    /** The owner's profile timezone, else the site default — the same rule the tool contexts use. */
    private static function ownerTimezone(?int $owner_id): string {
        if ($owner_id !== null && $owner_id > 0) {
            $user = new User($owner_id, TRUE);
            $tz = (string)$user->get('usr_timezone');
            if (in_array($tz, DateTimeZone::listIdentifiers(), true)) return $tz;
        }
        $tz = (string)(Globalvars::get_instance()->get_setting('default_timezone') ?: '');
        return in_array($tz, DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC';
    }

    /**
     * Which email this came from, as the owner would recognise it: subject,
     * sender, and the mailbox it arrived in. Null when there is no source
     * (a chat proposal with no source_ref).
     */
    private static function sourceLine(array $input, ?int $owner_id): ?string {
        $ref = trim((string)($input['source_ref'] ?? ''));
        if ($ref === '') return null;
        if (!ctype_digit($ref) || !class_exists('InboundEmailMessage')) {
            return 'From: ' . ProposedActionFacts::scalar($ref);
        }
        $unreadable = 'From an email you cannot open (#' . $ref . ')';
        if ($owner_id === null || $owner_id <= 0) return $unreadable;

        // A collection lookup, so a model-named id that matches nothing is
        // simply an empty result rather than a logged missing-row error.
        $msg = null;
        foreach (new MultiInboundEmailMessage(['iem_inbound_email_message_id' => (int)$ref, 'deleted' => false]) as $row) {
            $msg = $row;
        }
        if ($msg === null) return $unreadable;
        $alias_id = (int)$msg->get('iem_iea_inbound_email_alias_id');
        $owner = new User($owner_id, TRUE);
        $viewer = MailboxViewer::forUser($owner_id, (int)$owner->get('usr_permission'));
        if ($alias_id <= 0 || !$viewer->canAccess($alias_id)) return $unreadable;

        $mailbox = '';
        $alias = new InboundEmailAlias($alias_id, TRUE);
        if ($alias->key) {
            $domain = new InboundEmailDomain((int)$alias->get('iea_ied_inbound_email_domain_id'), TRUE);
            $mailbox = (string)$alias->get('iea_alias')
                . ($domain->key ? '@' . (string)$domain->get('ied_domain') : '');
        }
        $in_mailbox = $mailbox !== '' ? ' to ' . ProposedActionFacts::scalar($mailbox) : '';

        try {
            $subject = ProposedActionFacts::scalar($msg->get('iem_subject'));
            $sender  = ProposedActionFacts::scalar($msg->get('iem_sender'));
        } catch (VaultLockedException $e) {
            // The row's own facts are open but this message is sealed and the
            // window has closed between the two reads.
            return 'From a sealed email' . $in_mailbox . ' (unlock your vault to see which)';
        }
        return 'From the email “' . $subject . '” sent by ' . $sender . $in_mailbox;
    }

    public static function name(): string {
        return 'create_calendar_entry';
    }

    public static function description(): string {
        return 'Add an entry to the owner\'s own personal calendar. It is created '
             . 'tentative; the owner confirms or removes it in the calendar. '
             . 'Give wall-clock times and an IANA timezone.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'required' => ['title', 'start_local'],
            'properties' => [
                'title'       => ['type' => 'string', 'description' => 'Event title (max 255 chars).'],
                'start_local' => ['type' => 'string', 'description' => 'Start, Y-m-d H:i:s wall clock.'],
                'end_local'   => ['type' => 'string', 'description' => 'End, Y-m-d H:i:s wall clock. Defaults to one hour after the start.'],
                'timezone'    => ['type' => 'string', 'description' => 'IANA timezone (e.g. America/New_York). Defaults to the owner\'s.'],
                'all_day'     => ['type' => 'boolean', 'description' => 'True for a date with no time (a deadline, a due date).'],
            ],
        ];
    }

    public function execute(array $input, ToolContext $ctx) {
        $title       = trim((string)($input['title'] ?? ''));
        $start_local = trim((string)($input['start_local'] ?? ''));
        $end_local   = isset($input['end_local']) && trim((string)$input['end_local']) !== ''
            ? trim((string)$input['end_local']) : null;
        $all_day     = !empty($input['all_day']);
        $tz          = trim((string)($input['timezone'] ?? ''));
        if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            $tz = $ctx->ownerTimezone();
        }
        if ($end_local === null && !$all_day && $start_local !== '') {
            $end_local = LibraryFunctions::time_shift($start_local, '1 hour', 'Y-m-d H:i:s');
        }

        // Provenance is a dedup key scoped to the acting user's own calendar.
        // The schedule job sets it to the message id so a re-judged message
        // updates its entry rather than adding a second; a model can name one
        // too, and the worst it can do with that is update its own earlier entry.
        $source_ref = isset($input['source_ref']) && trim((string)$input['source_ref']) !== ''
            ? trim((string)$input['source_ref']) : null;

        try {
            $entry = CalendarEntryImporter::upsert($ctx->actingUserId(), [
                'title'       => $title,
                'start_local' => $start_local,
                'end_local'   => $end_local,
                'timezone'    => $tz,
                'all_day'     => $all_day,
                'source'      => $source_ref !== null ? 'email' : 'assistant',
                'source_ref'  => $source_ref,
            ]);
        } catch (InvalidArgumentException $e) {
            return ['content' => 'create_calendar_entry error: ' . $e->getMessage(), 'is_error' => true];
        }

        return json_encode([
            'status'   => 'success',
            'summary'  => 'Added to the calendar as a tentative entry: ' . $title,
            'entry_id' => (int)$entry->key,
        ], JSON_UNESCAPED_SLASHES);
    }

}
