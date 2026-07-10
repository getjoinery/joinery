<?php
/**
 * ProfileDashboardRegistry + value objects
 *
 * The member profile page and the native-app dashboard summary show sections
 * like recent orders, active subscriptions, upcoming events, and pending
 * surveys. Which sections exist depends on which plugins are active, so each
 * contributor registers a provider that, given the user, returns a section (or
 * null to contribute nothing this request). The web profile view and the
 * dashboard API both iterate the registry; core keeps building the user card,
 * conversations, mailing lists and notifications directly.
 *
 * @version 1.0.0
 */

/** A stat card: a single headline number for a section. */
class ProfileDashboardStat {
    public string $key;
    public string $label;
    public int $count;
    public ?string $link;

    public function __construct(string $key, string $label, int $count, ?string $link = null) {
        $this->key   = $key;
        $this->label = $label;
        $this->count = $count;
        $this->link  = $link;
    }
}

/** One row within a section. `data` is the raw native-app payload for this item. */
class ProfileDashboardItem {
    public array $data;
    public string $title;
    public ?string $subtitle;
    public ?string $meta;   // may contain safe HTML
    public ?string $badge;
    public ?string $url;

    public function __construct(array $data, string $title, ?string $subtitle = null, ?string $meta = null, ?string $badge = null, ?string $url = null) {
        $this->data     = $data;
        $this->title    = $title;
        $this->subtitle = $subtitle;
        $this->meta     = $meta;
        $this->badge    = $badge;
        $this->url      = $url;
    }
}

/** A dashboard section: a titled group of items with an optional stat + "view all" link. */
class ProfileDashboardSection {
    public string $id;
    public string $title;
    public ?string $view_all_url;
    public ?ProfileDashboardStat $stat;
    /** @var ProfileDashboardItem[] */
    public array $items;

    public function __construct(string $id, string $title, ?string $view_all_url = null, ?ProfileDashboardStat $stat = null, array $items = []) {
        $this->id           = $id;
        $this->title        = $title;
        $this->view_all_url = $view_all_url;
        $this->stat         = $stat;
        $this->items        = $items;
    }
}

class ProfileDashboardRegistry {

    /** @var array<string,callable> id => function(User $user): ?ProfileDashboardSection */
    private static $providers = [];

    /** Register a section provider. Idempotent (last-wins by id). */
    public static function register(string $id, callable $provider): void {
        self::$providers[$id] = $provider;
    }

    /**
     * All non-null sections for a user, in registration order.
     *
     * @return ProfileDashboardSection[]
     */
    public static function sections(User $user): array {
        $out = [];
        foreach (self::$providers as $provider) {
            $section = $provider($user);
            if ($section !== null) {
                $out[] = $section;
            }
        }
        return $out;
    }

    /** Clear the registry (tests only). */
    public static function resetCache(): void {
        self::$providers = [];
    }
}
