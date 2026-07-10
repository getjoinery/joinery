<?php
/**
 * GroupRecipientProvider — core recipient-group provider for user Groups.
 *
 * Targets bulk email at the members of a core Group. Registered by core.
 */
require_once(PathHelper::getIncludePath('includes/RecipientGroupProviderRegistry.php'));

class GroupRecipientProvider implements RecipientGroupProvider {

    public function key(): string {
        return 'group';
    }

    public function label(): string {
        return 'Group members';
    }

    public function options(): array {
        require_once(PathHelper::getIncludePath('data/groups_class.php'));
        $groups = new MultiGroup(
            array('category' => 'user', 'deleted' => false),
            array('name' => 'ASC'),
            NULL,
            NULL
        );
        $groups->load();
        return $groups->get_dropdown_array();
    }

    public function resolve(int $reference_id): array {
        require_once(PathHelper::getIncludePath('data/groups_class.php'));
        try {
            $group = new Group($reference_id, TRUE);
        } catch (\Throwable $e) {
            return array();
        }
        if (!$group->key) {
            return array();
        }
        $user_ids = array();
        foreach ($group->get_member_list() as $member) {
            $user_ids[] = (int)$member->get('grm_foreign_key_id');
        }
        return $user_ids;
    }

    public function reference_label(int $reference_id): string {
        require_once(PathHelper::getIncludePath('data/groups_class.php'));
        try {
            $group = new Group($reference_id, TRUE);
        } catch (\Throwable $e) {
            return 'Group #' . $reference_id;
        }
        if (!$group->key) {
            return 'Group #' . $reference_id;
        }
        return $group->get('grp_name');
    }
}
