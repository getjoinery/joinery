<?php
/**
 * MailGatewayPublisher - cross-compiles the mail gateway into the mail_apps
 * plugin artifact at publish time.
 *
 * A server has no compiler: install_mail_gateway.sh installs a PREBUILT gateway
 * from provisioning/bin/mail-gateway-<uname -m> and installs nothing without
 * one. The publish is the only place that has the source and knows a release is
 * being cut, so the plugin archive carries the binaries the way the mailbox
 * archive carries the relay sealer's.
 *
 * Listed in GoBinaryPublisher::PLUGIN_PUBLISHERS, so publish builds it before
 * the plugin archives, the release statement records each binary as
 * mail-gateway/<uname -m>, and the verifier rebuilds it from the commit. The
 * mechanics are GoBinaryPublisher's; this class names the program.
 *
 * @version 1.0
 */

class MailGatewayPublisher extends GoBinaryPublisher {

	const SOURCE_SUBDIR = 'public_html/plugins/mail_apps/provisioning/mail-gateway';
	const BIN_SUBDIR    = 'public_html/plugins/mail_apps/provisioning/bin';
	const BINARY        = 'mail-gateway';
	const LABEL         = 'Mail gateway';
	const CACHE_ROOT    = '/var/tmp/joinery-mail-gateway-build';
}
?>
