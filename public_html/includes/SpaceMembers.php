<?php
/**
 * SpaceMembers — answers SpaceAccess's `invite` rule for a consumer whose spaces
 * keep their own member list (a chat channel's invited people). A space model
 * names its implementation in a static $space_members property.
 *
 * @version 1.0.0
 */

interface SpaceMembers {
	/** Is this user a member of this space (the `invite` rule)? */
	public static function isMember(SystemBase $space, int $user_id): bool;
}
