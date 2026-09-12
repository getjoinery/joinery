<?php
/** @joinery-test
 * name: persona_sender_rules
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * The owner's two standing instructions about feed creators, and how they
 * interact with the repeat-advertiser rule.
 *
 *  - Allowed senders are never auto-blocked, whatever their ad tally says.
 *  - Allowing a blocked sender lifts the block; blocking by hand lifts an allow.
 *    A sender is on at most one list.
 *  - Unblocking lifts the block and nothing more: the repeat-advertiser rule
 *    can block them again. Allow is the durable choice.
 *  - Every match is case-insensitive, because captures vary a name's casing.
 *  - Events are spelled out as not-advertisements in the judging prompt.
 *
 * Run: php plugins/persona_browser/tests/sender_rules_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();

require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_feed_items_class.php'));
require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_blocked_senders_class.php'));
require_once(PathHelper::getIncludePath('plugins/persona_browser/data/persona_allowed_senders_class.php'));

$owner = PersonaFeedItem::OWNER_INSTANCE;
$persona = 'test-persona-' . substr(md5((string)microtime(true)), 0, 8);

harness_defer(function () use ($persona) {
    $db = DbConnector::get_instance()->get_db_link();
    foreach (['pbs_persona_blocked_senders' => 'pbs_persona', 'pas_persona_allowed_senders' => 'pas_persona'] as $t => $c) {
        $q = $db->prepare("DELETE FROM {$t} WHERE {$c} = ?");
        $q->execute([$persona]);
    }
});

section('An allowed sender is never auto-blocked');
PersonaAllowedSender::allow($owner, $persona, 'Dance Philly', 'runs the socials');
check(PersonaAllowedSender::is_allowed($owner, $persona, 'dance philly'), 'allow is recorded, matched case-insensitively');
check(PersonaBlockedSender::auto_block($owner, $persona, 'DANCE PHILLY', '9 posts judged ads') === false, 'auto_block declines an allowed sender');
check(!isset(PersonaBlockedSender::blocked_author_set($owner, $persona)['dance philly']), 'no block row was written');

section('Allowing a blocked sender lifts the block');
check(PersonaBlockedSender::auto_block($owner, $persona, 'Some Shop', '5 posts judged ads') === true, 'auto_block blocks an ordinary sender');
check(isset(PersonaBlockedSender::blocked_author_set($owner, $persona)['some shop']), 'the block is live');
PersonaAllowedSender::allow($owner, $persona, 'some shop');
check(!isset(PersonaBlockedSender::blocked_author_set($owner, $persona)['some shop']), 'allow lifted the block');
check(isset(PersonaAllowedSender::allowed_author_set($owner, $persona)['some shop']), 'and the sender is allowed');
check(PersonaBlockedSender::auto_block($owner, $persona, 'Some Shop', '6 posts judged ads') === false, 'auto_block still declines afterwards');

section('Blocking by hand lifts an allow');
PersonaBlockedSender::block($owner, $persona, 'Some Shop');
check(!isset(PersonaAllowedSender::allowed_author_set($owner, $persona)['some shop']), 'the allow is gone');
check(isset(PersonaBlockedSender::blocked_author_set($owner, $persona)['some shop']), 'the block is back');
$rows = new MultiPersonaBlockedSender(['owner_user_id' => $owner, 'persona' => $persona, 'deleted' => false]);
$manual = null;
foreach ($rows as $r) { if (mb_strtolower($r->get('pbs_author')) === 'some shop') $manual = $r; }
check($manual !== null && $manual->get('pbs_source') === 'manual', 'the revived row is owned by the owner (source=manual)');

section('Unblocking is not an allow: the rule can block again');
PersonaBlockedSender::unblock($owner, $persona, 'some SHOP');
check(!isset(PersonaBlockedSender::blocked_author_set($owner, $persona)['some shop']), 'unblock lifted the block');
check(PersonaBlockedSender::auto_block($owner, $persona, 'Some Shop', '7 posts judged ads') === true, 'auto_block may block them again');
$count = count(new MultiPersonaBlockedSender(['owner_user_id' => $owner, 'persona' => $persona]));
check($count === 1, 'one row per sender across block/unblock/re-block (revived, not duplicated)', "rows: {$count}");

section('Removing an allow makes an ordinary sender again');
PersonaAllowedSender::disallow($owner, $persona, 'DANCE philly');
check(!PersonaAllowedSender::is_allowed($owner, $persona, 'Dance Philly'), 'disallow removed the allow');
check(PersonaBlockedSender::auto_block($owner, $persona, 'Dance Philly', '9 posts judged ads') === true, 'auto_block now applies');
PersonaAllowedSender::allow($owner, $persona, 'Dance Philly');
check(PersonaAllowedSender::is_allowed($owner, $persona, 'Dance Philly'), 're-allow revives the same row');
$dp = 0;
foreach (new MultiPersonaAllowedSender(['owner_user_id' => $owner, 'persona' => $persona]) as $r) {
    if (mb_strtolower($r->get('pas_author')) === 'dance philly') $dp++;
}
check($dp === 1, 'still one allow row for the sender (revived, not duplicated)', "rows: {$dp}");

section('Events are spelled out as not-advertisements');
// Pipeline jobs are not autoloaded classes; the job needs joinery_ai's interface.
if (interface_exists('PipelineJobInterface')) {
    require_once(PathHelper::getIncludePath('plugins/persona_browser/pipeline_jobs/MarkAdvertisementsJob.php'));
    $prompt = (new MarkAdvertisementsJob())->defaultPrompt();
    check(strpos($prompt, 'EVENTS ARE NEVER ADVERTISEMENTS') !== false, 'the prompt carries the events rule');
    check(strpos($prompt, 'ticket') !== false, 'ticketed events are covered explicitly');
} else {
    check(true, 'joinery_ai inactive here — prompt check skipped');
}

harness_finish();
