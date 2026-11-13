/*
 * The address requirement on a product form (includes/requirements/
 * AddressRequirement.php): choosing "new" in the address list shows the
 * new-address fields.
 *
 * @version 1.0
 */
(function () {
	function start() {
		var addressSelect = document.getElementById('address');
		var newAddressBlock = document.getElementById('new_address_block');
		if (!addressSelect || !newAddressBlock) return;
		addressSelect.addEventListener('change', function () {
			newAddressBlock.style.display = this.value === 'new' ? 'block' : 'none';
		});
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
