<?php
/**
 * PostfixProvider - Self-hosted inbound transport.
 *
 * Inbound-only provider. The plugin's Postfix instance accepts mail via
 * MX and pipes it to utils/inbound_email_handler.php, which delegates to
 * PostfixProvider::handleInbound() to read the stdin payload and the
 * envelope recipient. Setup checks and DNS records are sourced from the
 * existing InboundEmailSetupCheck engine.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/InboundEmailProvider.php'));

class PostfixProvider implements InboundEmailProvider {

    public static function getKey(): string {
        return 'postfix';
    }

    public static function getLabel(): string {
        return 'Postfix (self-hosted)';
    }

    public static function getSpfMechanism(string $domain): string
    {
        // No SPF mechanism: local sendmail egresses from this server itself,
        // covered (colocated only) by the server's own ip4 term.
        return '';
    }

    public static function getInboundSettingsFields(): array {
        return [];
    }

    public static function getSetupChecks(?string $domain = null): array {
        require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
        $checker = new InboundEmailSetupCheck();
        // Postfix's catalogue is the host + mail-host + per-domain DNS layers
        // — exactly what runDomainChecks plus the host/mailhost block produces.
        $results = [];
        foreach ($checker->checkHostLayer() as $r)     { $results[] = $r; }
        foreach ($checker->checkMailHostLayer() as $r) { $results[] = $r; }
        if ($domain) {
            foreach ($checker->runDomainChecks($domain) as $r) { $results[] = $r; }
        }
        return $results;
    }

    public static function getDnsRecords(string $domain): array {
        $settings = Globalvars::get_instance();
        $mail_hostname = trim((string)$settings->get_setting('mailbox_mail_hostname'));
        $public_ip = trim((string)$settings->get_setting('mailbox_public_ip'));

        if ($mail_hostname === '') {
            $mail_hostname = 'mail.' . $domain;
        }
        $ip_value = $public_ip !== '' ? $public_ip : 'YOUR_SERVER_IP';

        $records = [
            [
                'type' => 'MX',
                'name' => $domain,
                'value' => '10 ' . $mail_hostname,
                'note' => 'Routes inbound mail for ' . $domain . ' to this server.',
            ],
            [
                'type' => 'TXT',
                'name' => $domain,
                'value' => 'v=spf1 ip4:' . $ip_value . ' -all',
                'note' => 'SPF — authorizes this server as a sender for ' . $domain . '.',
            ],
        ];

        // DKIM record from local opendkim key, if present.
        $keyfile = '/etc/opendkim/keys/' . $domain . '/mail.txt';
        if (is_readable($keyfile)) {
            $raw = @file_get_contents($keyfile);
            if ($raw !== false) {
                $value = '';
                if (preg_match_all('/"([^"]*)"/', $raw, $m) && !empty($m[1])) {
                    $value = trim(implode('', $m[1]));
                } else {
                    $value = trim($raw);
                }
                if ($value !== '') {
                    $records[] = [
                        'type' => 'TXT',
                        'name' => 'mail._domainkey.' . $domain,
                        'value' => $value,
                        'note' => 'DKIM — published key matches the local signing key.',
                    ];
                }
            }
        }

        $records[] = [
            'type' => 'TXT',
            'name' => '_dmarc.' . $domain,
            'value' => 'v=DMARC1; p=none; rua=mailto:postmaster@' . $domain,
            'note' => 'DMARC — recommended once SPF and DKIM are in place.',
        ];

        return $records;
    }

    public static function isWebhook(): bool {
        return false;
    }

    /**
     * Read raw MIME from $raw_body and envelope recipient from $post['recipient'].
     * Returns null if recipient is missing or body is empty.
     */
    public function handleInbound(array $post, string $raw_body): ?array {
        $recipient = isset($post['recipient']) ? trim((string)$post['recipient']) : '';
        if ($recipient === '') {
            return null;
        }
        if ($raw_body === '') {
            return null;
        }
        return [
            'raw_mime' => $raw_body,
            'recipient' => $recipient,
        ];
    }
}
