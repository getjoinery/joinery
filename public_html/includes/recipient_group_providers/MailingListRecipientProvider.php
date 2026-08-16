<?php
/**
 * MailingListRecipientProvider — core recipient-group provider for mailing lists.
 *
 * Targets a bulk email at the people who signed up for one mailing list. This
 * is the send side of /lists and /list/{slug}: what a visitor subscribes to
 * there is what an admin picks here. Registered by core.
 */
require_once(PathHelper::getIncludePath('includes/RecipientGroupProviderRegistry.php'));

class MailingListRecipientProvider implements RecipientGroupProvider {

    public function key(): string {
        return 'mailing_list';
    }

    public function label(): string {
        return 'Mailing list subscribers';
    }

    /**
     * Every live list, hidden ones included — a list nobody can subscribe to
     * from the site is still one an admin may need to write to.
     */
    public function options(): array {
        require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
        $lists = new MultiMailingList(
            array('deleted' => false),
            array('name' => 'ASC'),
            NULL,
            NULL
        );
        $lists->load();
        return $lists->get_dropdown_array();
    }

    public function resolve(int $reference_id): array {
        require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
        try {
            $list = new MailingList($reference_id, TRUE);
        } catch (\Throwable $e) {
            return array();
        }
        if (!$list->key || $list->get('mlt_delete_time')) {
            return array();
        }
        $user_ids = array();
        foreach ($list->get_subscribed_users('array') as $user_id) {
            $user_ids[] = (int)$user_id;
        }
        return $user_ids;
    }

    public function reference_label(int $reference_id): string {
        require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));
        try {
            $list = new MailingList($reference_id, TRUE);
        } catch (\Throwable $e) {
            return 'Mailing list #' . $reference_id;
        }
        if (!$list->key) {
            return 'Mailing list #' . $reference_id;
        }
        return $list->get('mlt_name');
    }
}
