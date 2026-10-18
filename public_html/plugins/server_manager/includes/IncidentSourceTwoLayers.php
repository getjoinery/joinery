<?php
/**
 * IncidentSourceTwoLayers - plane:two_layers: a machine's data sits under two
 * layers of encryption, ours and its provider's (specs/site_copy_final_test.md
 * E4). One layer is the rule: a server we make is born with ours and the
 * provider's off, and a machine whose provider's layer is on is moved by a
 * site copy onto one that is, never encrypted where it stands. Only a
 * management node keeps both, until it is replaced, since it never copies
 * itself; it raises nothing.
 *
 * Read from stored facts (DataRootLayers): the provider's layer is recorded
 * when a server is made, and from the provider's listing by the daily machine
 * transfer watch. Clears when the machine has one.
 *
 * @version 1.0
 */
class IncidentSourceTwoLayers implements IncidentSource {

	const NAME = 'plane:two_layers';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (trim((string)$node->get('mgn_container_name')) !== '' || DataRootEncryptionOffer::may_keep_provider_layer($node)) {
			return null;
		}
		$layers = DataRootLayers::of($node);
		if ($layers['count'] !== 2) {
			return null;
		}
		return array(
			'title'    => 'Two layers of encryption',
			'severity' => IncidentRecord::SEVERITY_WARNING,
			'detail'   => array(
				'Layers' => $layers['line'],
				'Why'    => 'Its data root is encrypted by us and its provider\'s own disk encryption is on as well. One layer is the rule: '
					. 'the second costs a key to keep and explains nothing.',
				'Fix'    => 'Copy the site onto a new server from its Copy tab: it is born encrypted by us with the provider\'s layer off. '
					. 'Keep the switch-over, then delete this server at its provider.',
			),
		);
	}

	public function cleared_text(ManagedNode $node): string {
		return 'It has one layer of encryption now.';
	}
}
?>
