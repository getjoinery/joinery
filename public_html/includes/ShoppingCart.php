<?php

require_once($_SERVER['DOCUMENT_ROOT'].'/data/products_class.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/data/product_groups_class.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/data/users_class.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/data/orders_class.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/data/order_items_class.php');
require_once($_SERVER['DOCUMENT_ROOT'].'/data/event_registrants_class.php');

class ShoppingCartException extends Exception {}

class ShoppingCart {
	public $items;
	public $billing_user;
	public $last_receipt;

	public function __construct() {
		$this->items = array();
		$this->extras = array();
		$this->item_id = 0;
	}

	private function get_next_item_id() {
		return $this->item_id++;
	}

	public function set_extra_info($data) {
		$this->extras = $data;
	}

	public function clear_extra_info() {
		$this->extras = array();
	}

	public function get_extra_info() {
		return $this->extras;
	}

	public function add_item($product, $form_data) {
		// First lets validate we can add this item to the cart!
		$current_count = 0;
		if ($product->get('pro_max_purchase_count')) {
			// Check to make sure we haven't gone over this item's maximum purchase count
			foreach($this->get_items() as $item) {
				list ($unused_id, $item_quantity, $item_product) = $item;
				if ($item_product->key == $product->key) {
					$current_count += $item_quantity;
				}
			}

			if ($current_count >= $product->get('pro_max_purchase_count')) {
				throw new ShoppingCartException(
					'Sorry, you can not add this item to you cart more than ' . $product->get('pro_max_purchase_count') 
					. (($product->get('pro_max_purchase_count') == 1) ? ' time' : ' times') . '.  <a href="/cart">
					View your current shopping cart</a> for more details.');
			}
		}

		if ($product->get('pro_prg_product_group_id')) {
			$product_group = new ProductGroup($product->get('pro_prg_product_group_id'), TRUE);
			$current_count = 0;
			if ($product_group->get('prg_max_items')) {
				foreach($this->get_items() as $item) {
					list ($unused_id, $item_quantity, $item_product) = $item;
					if ($item_product->get('pro_prg_product_group_id') == $product->get('pro_prg_product_group_id')) {
						$current_count += $item_quantity;
					}
				}
			}

			if ($current_count >= $product_group->get('prg_max_items')) {
				throw new ShoppingCartException($product_group->get('prg_error'));
			}
		}


		$this->items[] = array(1,	$product,	$form_data);
	}

	public function count_items() {
		return count($this->items);
	}

	public function get_items() {
		$item_array = array();
		foreach($this->items as $key => $cart_item) {
			$item_array[] = array($key, $cart_item[0], $cart_item[1]);
		}
		return $item_array;
	}
	
	public function get_items_generic() {
		$item_array = array();
		foreach($this->items as $key => $cart_item) {
			list($quantity, $product, $data) = $cart_item;
			$item_array[] = array($key, $quantity, (array)$product, (array)$data);
		}
		return $item_array;
	}	

	public function get_detailed_items() {
		$detailed_items = array();
		foreach ($this->items as $key => $cart_item) {
			list($quantity, $product, $data) = $cart_item; 
			$product_version = $product->get_product_version($data);
			if ($product_version !== NULL) {
				$name = $product->get('pro_name') . ' - ' . $product_version->prv_version_name;
			} else {
				$name = $product->get('pro_name');
			}
			
			$price = $product->get_price($product_version, $data);

			$detailed_items[] = array(
				'id' => $key,
				'name' => $name,
				'price' => $price,
				'quantity' => $quantity,
				'total' => $quantity * $price,
				'recurring' => $product->get('pro_recurring'),
			);
		}
		return $detailed_items;
	}

	public function get_total() {
		$total_price = 0;
		foreach($this->get_detailed_items() as $cart_item) {
			$total_price += $cart_item['total'];
		}
		return $total_price;
	}

	public function remove_item($item_id) {
		foreach($this->items as $key => $cart_item) {
			if ($key === $item_id) {
				unset($this->items[$key]);
				return TRUE;
			}
		}
		return FALSE;
	}

	public function get_hash() {
		// Return a hash of this shopping cart, so between pages we can compare and make
		// sure the contents of the cart haven't been changed
		$hash_string = '';
		foreach($this->items as $cart_item) {
			$hash_string .= serialize($cart_item);
		}
		return md5($hash_string);
	}

	public function clear_cart() {
		$this->items = array();
		$this->item_id = 0;
	}

}

?>
