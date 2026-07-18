<?php
/** @joinery-test
 * name: event_capacity
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Event capacity — whether a full event can still sell seats.
 *
 * evt_max_signups decides whether the event page renders a Register button, but
 * the button is not the only way to reach checkout: a product URL reaches it
 * directly, and fulfillment (Event::add_registrant) runs at charge time with no
 * capacity check of its own. Enforcement therefore has to live before the
 * charge, which is what FulfillmentProvider::checkAvailability() is for — a
 * full event refuses the purchase rather than taking money for a seat that
 * does not exist.
 *
 * The seam is deliberately the store's, not the event plugin's: anything with a
 * finite supply answers the same question at the same point in checkout. This
 * suite drives EventRegistrationFulfillment's answer directly, which is where
 * the arithmetic lives, and separately pins the two behaviours around it that
 * are easy to get wrong — expired registrations releasing their seat, and
 * add_registrant remaining unguarded so nobody mistakes it for the gate.
 *
 * Sections: the availability contract; the capacity arithmetic; seat release;
 * and the boundary between checkout enforcement and fulfillment.
 *
 * Run: php plugins/event_manager/tests/event_capacity_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_registrants_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/includes/FulfillmentRegistry.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
require_once(PathHelper::getIncludePath('plugins/event_manager/includes/fulfillment_providers/EventRegistrationFulfillment.php'));

/** An event with $max seats (0 = uncapped), registered for teardown. */
function ec_make_event($name, $max) {
	$event = new Event(NULL);
	$event->set('evt_name', 'HarnessTest ' . $name . ' ' . bin2hex(random_bytes(3)));
	$event->set('evt_start_time', gmdate('Y-m-d H:i:s', time() + 86400));
	$event->set('evt_end_time', gmdate('Y-m-d H:i:s', time() + 90000));
	$event->set('evt_status', Event::STATUS_ACTIVE);
	$event->set('evt_visibility', Event::VISIBILITY_PUBLIC);
	$event->set('evt_max_signups', (int)$max);
	$event->save();
	$event->load();
	harness_register_row('evt_events', 'evt_event_id', $event->key);
	return $event;
}

/** Seat $user on $event, optionally already expired. Registered for teardown. */
function ec_seat($event, $user, $expires = null) {
	$reg = new EventRegistrant(NULL);
	$reg->set('evr_evt_event_id', $event->key);
	$reg->set('evr_usr_user_id', $user->key);
	if ($expires !== null) {
		$reg->set('evr_expires_time', $expires);
	}
	$reg->save();
	$reg->load();
	harness_register_row('evr_event_registrants', 'evr_event_registrant_id', $reg->key);
	return $reg;
}

/** A product wired to the event fulfillment provider. */
function ec_make_product($event_id) {
	$slug = bin2hex(random_bytes(3));
	$product = new Product(NULL);
	$product->set('pro_name', 'HarnessTest Ticket ' . $slug);
	$product->set('pro_link', 'harnesstest-ticket-' . $slug);
	$product->set('pro_is_active', true);
	$product->set('pro_fulfillment_provider', 'event_registration');
	$product->set('pro_fulfillment_ref', (int)$event_id);
	$product->save();
	$product->load();
	harness_register_row('pro_products', 'pro_product_id', $product->key);
	return $product;
}

$provider = new EventRegistrationFulfillment();

// ---------------------------------------------------------------------------
section('The availability contract');

// Availability is asked of every fulfillment provider, not just this one — the
// store cannot know which kinds of thing run out.
foreach (FulfillmentRegistry::all() as $registered) {
	check(method_exists($registered, 'checkAvailability'),
		'provider ' . $registered->key() . ' answers checkAvailability',
		get_class($registered));
}

check($provider->key() === 'event_registration',
	'the event provider is registered under its stable key',
	'key: ' . $provider->key());

// ---------------------------------------------------------------------------
section('Capacity arithmetic');

$uncapped = ec_make_event('Uncapped', 0);
$product_uncapped = ec_make_product($uncapped->key);
$u1 = make_user('EvCapU1');
ec_seat($uncapped, $u1);

check($provider->checkAvailability($product_uncapped, $uncapped->key, 1) === null,
	'an event with no seat limit is always available');
check($provider->checkAvailability($product_uncapped, $uncapped->key, 500) === null,
	'an uncapped event accepts any quantity');

$capped = ec_make_event('Capped', 3);
$product_capped = ec_make_product($capped->key);

check($provider->checkAvailability($product_capped, $capped->key, 1) === null,
	'an empty capped event has room');
check($provider->checkAvailability($product_capped, $capped->key, 3) === null,
	'a quantity exactly filling the event is allowed');

$over = $provider->checkAvailability($product_capped, $capped->key, 4);
check($over !== null, 'a quantity exceeding the limit is refused',
	'answer: ' . var_export($over, true));
check(is_string($over) && stripos($over, 'left') !== false,
	'the refusal tells the buyer how many places remain',
	'answer: ' . var_export($over, true));

// Fill it one seat at a time and watch the boundary move.
$s1 = make_user('EvCapS1');
$s2 = make_user('EvCapS2');
$s3 = make_user('EvCapS3');

ec_seat($capped, $s1);
check($provider->checkAvailability($product_capped, $capped->key, 2) === null,
	'with one of three seats taken, two more are available');
check($provider->checkAvailability($product_capped, $capped->key, 3) !== null,
	'with one of three seats taken, three more are refused');

ec_seat($capped, $s2);
ec_seat($capped, $s3);

$full = $provider->checkAvailability($product_capped, $capped->key, 1);
check($full !== null, 'a full event refuses a single seat',
	'answer: ' . var_export($full, true));
check(is_string($full) && stripos($full, 'full') !== false,
	'the refusal says the event is full',
	'answer: ' . var_export($full, true));
check(is_string($full) && strpos($full, $capped->get('evt_name')) !== false,
	'the refusal names the event, so a multi-line cart says which one',
	'answer: ' . var_export($full, true));

// ---------------------------------------------------------------------------
section('Seat release');

// An expired registration no longer holds a seat — the same rule the event page
// counts by. Otherwise a recurring/subscription event silently fills forever.
$expiring = ec_make_event('Expiring', 2);
$product_expiring = ec_make_product($expiring->key);
$e1 = make_user('EvCapE1');
$e2 = make_user('EvCapE2');

ec_seat($expiring, $e1);
ec_seat($expiring, $e2);
check($provider->checkAvailability($product_expiring, $expiring->key, 1) !== null,
	'two live registrations fill a two-seat event');

$db = DbConnector::get_instance()->get_db_link();
$q = $db->prepare("UPDATE evr_event_registrants SET evr_expires_time = NOW() - INTERVAL '1 day'
	WHERE evr_evt_event_id = ? AND evr_usr_user_id = ?");
$q->execute(array($expiring->key, $e1->key));

check($provider->checkAvailability($product_expiring, $expiring->key, 1) === null,
	'an expired registration releases its seat');
check($provider->checkAvailability($product_expiring, $expiring->key, 2) !== null,
	'only the expired seat is released, not both');

// ---------------------------------------------------------------------------
section('Enforcement boundary');

// A bundle seats a group whose membership resolves at fulfillment, so its size
// is unknown before the charge and it is deliberately not capacity checked.
check($provider->checkAvailability($product_capped, 0, 1) === null,
	'a bundle reference is not capacity checked');
check($provider->checkAvailability($product_capped, -1, 1) === null,
	'a negative reference is not capacity checked');

// A reference pointing at nothing must not take checkout down.
check($provider->checkAvailability($product_capped, 999999999, 1) === null,
	'a reference to a missing event does not refuse the purchase');

// add_registrant is NOT the gate and must not be mistaken for one: it runs after
// payment, where refusing would strand a charged buyer. Capacity is enforced
// before the charge; this pins that division so a later change to one is a
// deliberate change to the other.
$before = (new MultiEventRegistrant(array('event_id' => $capped->key, 'expired' => false)))->count_all();
$overflow_user = make_user('EvCapOverflow');
$overflow = $capped->add_registrant($overflow_user->key);
if ($overflow && $overflow->key) {
	harness_register_row('evr_event_registrants', 'evr_event_registrant_id', $overflow->key);
}
$after = (new MultiEventRegistrant(array('event_id' => $capped->key, 'expired' => false)))->count_all();

check($overflow instanceof EventRegistrant && $overflow->key,
	'add_registrant seats a registrant past capacity — it is not the gate');
check((int)$after === (int)$before + 1,
	'the overflow registration really landed',
	"before: $before after: $after");
check($provider->checkAvailability($product_capped, $capped->key, 1) !== null,
	'the event still reports itself full to the pre-charge check');

// The same person twice is one seat, not two.
$repeat = $capped->add_registrant($overflow_user->key);
$after_repeat = (new MultiEventRegistrant(array('event_id' => $capped->key, 'expired' => false)))->count_all();
check((int)$after_repeat === (int)$after,
	'registering the same user again does not consume a second seat',
	"after: $after after_repeat: $after_repeat");

harness_finish();
