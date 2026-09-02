<?php
/**
 * UserRecipientProvider — core recipient-group provider for a single person.
 *
 * A single person is an audience of one. Naming a user as a recipient group is
 * what lets a sender's own copy, an event leader's copy and a one-person send
 * all sit on the same Email row as its other audiences, with no second code
 * path. options() is empty on purpose: the campaign page's picker does not
 * offer it, because a campaign to one person is typed into the recipients box,
 * not picked from a dropdown.
 *
 * @version 1.0.0
 */
class UserRecipientProvider implements RecipientGroupProvider {

    public function key(): string {
        return 'user';
    }

    public function label(): string {
        return 'A single user';
    }

    public function options(): array {
        return array();
    }

    public function resolve(int $reference_id): array {
        if ($reference_id <= 0) {
            return array();
        }
        return array($reference_id);
    }

    public function reference_label(int $reference_id): string {
        try {
            $user = new User($reference_id, TRUE);
        } catch (\Throwable $e) {
            return 'User #' . $reference_id;
        }
        if (!$user->key) {
            return 'User #' . $reference_id;
        }
        return $user->display_name();
    }
}
