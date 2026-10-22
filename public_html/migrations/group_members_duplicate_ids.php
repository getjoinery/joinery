<?php
/**
 * Give each group membership an id of its own.
 *
 * grm_group_members reached some databases without its primary key, and a
 * sequence that started over then handed out ids that rows already held, so
 * two memberships could share one id. Removing either from its group deleted
 * both (GroupMember::remove() deletes by id), and update_database could not
 * add the key: every run reported 'Primary key constraint skipped for
 * grm_group_members.grm_group_member_id due to duplicate values'.
 *
 * The first row of each shared id, in table order, keeps it; every other row
 * takes a fresh id from the sequence, which is first moved past the highest
 * id. Nothing stores a membership's id (no foreign key, no link that outlives
 * a page), so no reference changes meaning. The next update_database adds the
 * key. A second run changes nothing.
 */
function group_members_duplicate_ids() {
	$dblink = DbConnector::get_instance()->get_db_link();

	DatabaseUpdater::syncSequenceForward('grm_group_members_grm_group_member_id_seq',
		'grm_group_members', 'grm_group_member_id', $dblink);

	$renumbered = $dblink->exec("
		UPDATE grm_group_members g
		SET grm_group_member_id = nextval('grm_group_members_grm_group_member_id_seq')
		FROM (
			SELECT ctid AS row_ctid,
				row_number() OVER (PARTITION BY grm_group_member_id ORDER BY ctid) AS n
			FROM grm_group_members
		) d
		WHERE g.ctid = d.row_ctid AND d.n > 1
	");
	echo "Gave $renumbered group membership(s) an id of their own.\n";

	return true;
}
