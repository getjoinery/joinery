<?php
/**
 * OutboundTransferNotice — the admin-header notice when this server has sent
 * more this month than the figure its owner set (OutboundTransferMeter;
 * specs/node_outbound_and_transfer.md WP1). Superadmins only: they are the
 * ones who can look at the provider's account. Reads the stored month; never
 * probes. Silent until the figure is passed, and on a site the operator hosts.
 *
 * @version 1.0
 */
class OutboundTransferNotice {

	public static function render(): string {
		if ((int)($_SESSION['permission'] ?? 0) < 10 || !OutboundTransferMeter::over()) {
			return '';
		}
		return self::html(OutboundTransferMeter::month());
	}

	/** The notice for a month. Public and pure so the wording can be tested. */
	public static function html(array $month): string {
		return '<style>.jy-outbound-notice{margin:0 0 1rem;padding:.75rem 1rem;border:1px solid #fcd34d;border-radius:6px;'
			. 'background:#fffbeb;color:#78350f;font-size:.95rem}.jy-outbound-notice a{color:inherit;text-decoration:underline}</style>'
			. '<div class="jy-outbound-notice" role="status">'
			. htmlspecialchars(OutboundTransferMeter::sentence($month), ENT_QUOTES, 'UTF-8')
			. ' <a href="/admin/admin_settings">Change the figure</a></div>';
	}
}
