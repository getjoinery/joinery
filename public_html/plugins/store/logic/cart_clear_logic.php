<?php
/**
 * @version 1.1 - only a POST clears the cart
 */
function cart_clear_logic(array $input): LogicResult{
	require_once(PathHelper::getIncludePath('plugins/store/includes/ShoppingCart.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	
	$page_vars = array();

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('products_active')){
		return LogicResult::error('This feature is turned off');
	}
	
	// Clearing is an action, so only a POST does it. A GET (an old link, a
	// prefetch, another site's image tag) changes nothing and goes to the cart.
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
		$cart = ShoppingCart::current();
		$cart->clear_cart();
	}
	
	return LogicResult::render($page_vars);
}

function cart_clear_logic_descriptor(): array {
	return [
		'auth' => ['min_user_permission' => 0],
		'description'      => 'Clear all items from the current user\'s cart.',
		'requires_session' => true,
		'mutates'          => true,
		'ai_agent'         => 'confirm',
		'input'            => [
		],
	];
}
?>
