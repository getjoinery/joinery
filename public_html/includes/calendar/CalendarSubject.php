<?php
require_once(PathHelper::getIncludePath('data/users_class.php'));

/**
 * A schedulable subject — the thing a calendar and a schedule belong to.
 *
 * This is the single place in the codebase that knows subject *types* exist.
 * Everywhere else passes a CalendarSubject around and never branches on type.
 * `user` is the only implemented type; `resource`, `team`, and `venue` are
 * reserved for later and key on the same (type, id) pair, so adding one means
 * editing only this resolver and the two owner-bearing tables.
 *
 * It is a value object, not a table: owner identity is stored as
 * subject_type + subject_id columns on sch_schedules and cal_entries and
 * resolved live through here.
 */
class CalendarSubject {

    const TYPE_USER   = 'user';
    // Reserved, not yet implemented:
    const TYPE_RESOURCE = 'resource';
    const TYPE_TEAM     = 'team';
    const TYPE_VENUE    = 'venue';

    public $type;
    public $id;

    /** Lazily-resolved owner record (a User for type=user). */
    private $owner = null;
    private $resolved = false;

    public function __construct(string $type, $id) {
        $this->type = $type;
        $this->id   = (int)$id;
    }

    /** Convenience constructor for the only implemented type. */
    public static function user($user_id): CalendarSubject {
        return new CalendarSubject(self::TYPE_USER, $user_id);
    }

    /** Stable string key, e.g. "user:123" — used for caching and comparison. */
    public function getKey(): string {
        return $this->type . ':' . $this->id;
    }

    public function equals(?CalendarSubject $other): bool {
        return $other !== null && $other->getKey() === $this->getKey();
    }

    /**
     * Resolve the owner record. The ONLY method that branches on subject type.
     * Returns the underlying model (a User) or null if it cannot be resolved.
     */
    public function resolveOwner() {
        if ($this->resolved) {
            return $this->owner;
        }
        $this->resolved = true;

        switch ($this->type) {
            case self::TYPE_USER:
                $user = new User($this->id, true);
                $this->owner = $user->key ? $user : null;
                break;
            default:
                // resource / team / venue not implemented yet
                $this->owner = null;
                break;
        }
        return $this->owner;
    }

    /** Display name of the owner, or a placeholder if unresolved. */
    public function getDisplayName(): string {
        $owner = $this->resolveOwner();
        if ($owner instanceof User) {
            return $owner->display_name();
        }
        return 'Unknown';
    }

    /** IANA timezone for the owner; falls back to the platform default. */
    public function getTimezone(): string {
        $owner = $this->resolveOwner();
        if ($owner instanceof User && $owner->get('usr_timezone')) {
            return $owner->get('usr_timezone');
        }
        return 'America/New_York';
    }

    /** Avatar URL for the owner (best-effort), or the blank avatar. */
    public function getAvatarUrl(): string {
        $owner = $this->resolveOwner();
        if ($owner instanceof User) {
            return $owner->get_picture_link('avatar');
        }
        return '/assets/images/blank-avatar.png';
    }

    /** The numeric user id when this subject is a user, else null. */
    public function getUserId(): ?int {
        return $this->type === self::TYPE_USER ? $this->id : null;
    }

    /**
     * Permanently delete everything this subject owns: schedules and native
     * calendar entries (the latter cascading to their recurrence exceptions).
     *
     * The owner column is polymorphic (subject_type + subject_id), so it can't be
     * a real FK and the generic deletion cascade can't express it — a blind delete
     * by id would also hit other subject types sharing the number. This is the one
     * place that knows subject types, so subject-scoped cleanup lives here. Call it
     * from the owner's deletion path (e.g. User::permanent_delete for a user).
     */
    public function purge(): void {
        require_once(PathHelper::getIncludePath('data/schedule_class.php'));
        require_once(PathHelper::getIncludePath('data/calendar_entry_class.php'));

        $filter = ['subject_type' => $this->type, 'subject_id' => $this->id];

        $schedules = new MultiSchedule($filter);
        $schedules->load();
        foreach ($schedules as $schedule) {
            $schedule->permanent_delete();
        }

        $entries = new MultiCalendarEntry($filter);
        $entries->load();
        foreach ($entries as $entry) {
            $entry->permanent_delete();
        }
    }
}
