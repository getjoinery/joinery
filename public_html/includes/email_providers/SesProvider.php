<?php
/**
 * SesProvider - Amazon SES (Simple Email Service) email service provider
 *
 * Implements EmailServiceProvider using aws/aws-sdk-php's SESv2 client.
 * Batch sending is per-recipient since SES has no native non-templated bulk API.
 */

require_once(PathHelper::getComposerAutoloadPath());

use Aws\SesV2\SesV2Client;
use Aws\Exception\AwsException;

class SesProvider implements EmailServiceProvider {

    public static function getKey(): string {
        return 'ses';
    }

    public static function getLabel(): string {
        return 'Amazon SES';
    }

    public static function getSettingsFields(): array {
        return [
            [
                'key' => 'ses_access_key_id',
                'label' => 'AWS Access Key ID',
                'type' => 'text',
                'helptext' => 'Leave blank to use IAM role / instance credentials (EC2, ECS, Lambda).',
            ],
            [
                'key' => 'ses_secret_access_key',
                'label' => 'AWS Secret Access Key',
                'type' => 'password',
                'helptext' => 'Leave blank when using IAM role credentials.',
            ],
            [
                'key' => 'ses_region',
                'label' => 'AWS Region',
                'type' => 'dropdown',
                'options' => [
                    'us-east-1' => 'us-east-1 (N. Virginia)',
                    'us-east-2' => 'us-east-2 (Ohio)',
                    'us-west-1' => 'us-west-1 (N. California)',
                    'us-west-2' => 'us-west-2 (Oregon)',
                    'eu-west-1' => 'eu-west-1 (Ireland)',
                    'eu-west-2' => 'eu-west-2 (London)',
                    'eu-central-1' => 'eu-central-1 (Frankfurt)',
                    'ap-southeast-1' => 'ap-southeast-1 (Singapore)',
                    'ap-southeast-2' => 'ap-southeast-2 (Sydney)',
                    'ap-northeast-1' => 'ap-northeast-1 (Tokyo)',
                ],
            ],
            [
                'key' => 'ses_configuration_set',
                'label' => 'Configuration Set (optional)',
                'type' => 'text',
                'helptext' => 'Name of an SES Configuration Set for engagement tracking, custom event publishing, or IP pool selection. Leave blank to send without one.',
            ],
            [
                'key' => 'ses_verified_domain',
                'label' => 'Verified Sender Domain',
                'type' => 'text',
                'helptext' => 'For display only — SES validates the From at send time. Must be verified in this AWS region.',
            ],
        ];
    }

    public static function validateConfiguration(): array {
        $settings = Globalvars::get_instance();
        $errors = [];

        // Either explicit static credentials, or rely on role credentials (both blank is OK).
        $access_key = $settings->get_setting('ses_access_key_id');
        $secret_key = $settings->get_setting('ses_secret_access_key');

        if (!empty($access_key) && empty($secret_key)) {
            $errors[] = 'SES access key ID set but secret access key missing';
        }
        if (empty($access_key) && !empty($secret_key)) {
            $errors[] = 'SES secret access key set but access key ID missing';
        }
        if (empty($settings->get_setting('ses_region'))) {
            $errors[] = 'SES region not configured';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Live API validation: GetAccount returns sending enablement and quota.
     */
    public static function validateApiConnection(): array {
        $settings = Globalvars::get_instance();
        $region = $settings->get_setting('ses_region') ?: 'us-east-1';
        $access_key = $settings->get_setting('ses_access_key_id');
        $secret_key = $settings->get_setting('ses_secret_access_key');
        $configured_domain = $settings->get_setting('ses_verified_domain');

        try {
            $client = self::buildClient($region, $access_key, $secret_key);
            $result = $client->getAccount();

            $details = [
                'Region' => $region,
            ];
            if (!empty($access_key)) {
                $details['Credentials'] = 'Static (Access Key)';
            } else {
                $details['Credentials'] = 'Instance / IAM Role';
            }

            $production = $result->get('ProductionAccessEnabled');
            $details['Production Access'] = $production ? 'Yes' : 'No (Sandbox)';

            $sending = $result->get('SendingEnabled');
            $details['Sending Enabled'] = $sending ? 'Yes' : 'No';

            $quota = $result->get('SendQuota');
            if (is_array($quota)) {
                if (isset($quota['Max24HourSend'], $quota['SentLast24Hours'])) {
                    $details['24h Quota'] = intval($quota['SentLast24Hours']) . ' / ' . intval($quota['Max24HourSend']);
                }
                if (isset($quota['MaxSendRate'])) {
                    $details['Max Send Rate'] = $quota['MaxSendRate'] . '/sec';
                }
            }

            if (!empty($configured_domain)) {
                $details['Verified Domain'] = $configured_domain;
            }

            return [
                'success' => true,
                'label' => 'API Connection Valid',
                'details' => $details,
                'error' => null,
            ];
        } catch (AwsException $e) {
            $code = $e->getAwsErrorCode();
            $details = ['Region' => $region];

            if ($code === 'InvalidClientTokenId' || $code === 'SignatureDoesNotMatch') {
                $error = 'Invalid AWS credentials. Check Access Key ID and Secret Access Key.';
                $label = 'Credentials Rejected';
            } elseif ($code === 'AccessDenied' || $code === 'AccessDeniedException') {
                $error = 'IAM permissions insufficient — need ses:GetAccount and ses:SendEmail.';
                $label = 'Access Denied';
            } elseif (strpos((string) $code, 'Endpoint') !== false) {
                $error = 'Region not reachable — check ses_region.';
                $label = 'Endpoint Unreachable';
            } else {
                $error = $e->getAwsErrorMessage() ?: $e->getMessage();
                $label = 'API Connection Failed';
            }

            return [
                'success' => false,
                'label' => $label,
                'details' => $details,
                'error' => $error,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'label' => 'API Connection Failed',
                'details' => ['Region' => $region],
                'error' => $e->getMessage(),
            ];
        }
    }

    public function send(EmailMessage $message): bool {
        $settings = Globalvars::get_instance();

        try {
            $client = self::buildClient(
                $settings->get_setting('ses_region') ?: 'us-east-1',
                $settings->get_setting('ses_access_key_id'),
                $settings->get_setting('ses_secret_access_key')
            );

            $params = $this->buildSendParams($message, $settings);
            $params['Destination'] = $this->buildDestination(
                $message->getRecipients(),
                $message->getCc(),
                $message->getBcc()
            );

            $client->sendEmail($params);
            return true;
        } catch (AwsException $e) {
            error_log('[SesProvider] Send failed: ' . ($e->getAwsErrorMessage() ?: $e->getMessage()));
            return false;
        } catch (\Exception $e) {
            error_log('[SesProvider] Send failed: ' . $e->getMessage());
            return false;
        }
    }

    public function sendBatch(EmailMessage $message, array $recipients): array {
        $settings = Globalvars::get_instance();
        $failed = [];

        try {
            $client = self::buildClient(
                $settings->get_setting('ses_region') ?: 'us-east-1',
                $settings->get_setting('ses_access_key_id'),
                $settings->get_setting('ses_secret_access_key')
            );

            $base_params = $this->buildSendParams($message, $settings);

            foreach ($recipients as $email) {
                $params = $base_params;
                $params['Destination'] = ['ToAddresses' => [$email]];

                try {
                    $client->sendEmail($params);
                } catch (AwsException $e) {
                    error_log('[SesProvider] Batch send failed for ' . $email . ': '
                        . ($e->getAwsErrorMessage() ?: $e->getMessage()));
                    $failed[] = $email;
                }
            }
        } catch (\Exception $e) {
            error_log('[SesProvider] Batch setup failed: ' . $e->getMessage());
            $failed = $recipients;
        }

        return [
            'success' => empty($failed),
            'failed_recipients' => $failed,
        ];
    }

    /**
     * Build the SES SendEmail params shared between send() and sendBatch().
     * Caller fills in 'Destination'.
     */
    private function buildSendParams(EmailMessage $message, Globalvars $settings): array {
        $from = $message->getFromName()
            ? $message->getFromName() . ' <' . $message->getFrom() . '>'
            : $message->getFrom();

        $body = [];
        if ($message->getHtmlBody()) {
            $body['Html'] = ['Data' => $message->getHtmlBody(), 'Charset' => 'UTF-8'];
        }
        if ($message->getTextBody()) {
            $body['Text'] = ['Data' => $message->getTextBody(), 'Charset' => 'UTF-8'];
        }

        $params = [
            'FromEmailAddress' => $from,
            'Content' => [
                'Simple' => [
                    'Subject' => ['Data' => $message->getSubject(), 'Charset' => 'UTF-8'],
                    'Body' => $body,
                ],
            ],
        ];

        if ($replyTo = $message->getReplyTo()) {
            $params['ReplyToAddresses'] = [$replyTo];
        }

        $config_set = $settings->get_setting('ses_configuration_set');
        if (!empty($config_set)) {
            $params['ConfigurationSetName'] = $config_set;
        }

        return $params;
    }

    /**
     * Convert EmailMessage recipient arrays into SES Destination shape.
     */
    private function buildDestination(array $recipients, array $cc, array $bcc): array {
        $destination = [];

        if (!empty($recipients)) {
            $destination['ToAddresses'] = array_map(
                fn($r) => !empty($r['name']) ? $r['name'] . ' <' . $r['email'] . '>' : $r['email'],
                $recipients
            );
        }
        if (!empty($cc)) {
            $destination['CcAddresses'] = array_map(
                fn($r) => !empty($r['name']) ? $r['name'] . ' <' . $r['email'] . '>' : $r['email'],
                $cc
            );
        }
        if (!empty($bcc)) {
            $destination['BccAddresses'] = array_map(
                fn($r) => !empty($r['name']) ? $r['name'] . ' <' . $r['email'] . '>' : $r['email'],
                $bcc
            );
        }

        return $destination;
    }

    /**
     * Build the SES v2 client. If credentials are blank, the SDK auto-discovers
     * via the standard AWS provider chain (env vars, instance metadata, ~/.aws/credentials).
     */
    private static function buildClient(string $region, ?string $access_key, ?string $secret_key): SesV2Client {
        $config = [
            'version' => '2019-09-27',
            'region' => $region,
        ];

        if (!empty($access_key) && !empty($secret_key)) {
            $config['credentials'] = [
                'key' => $access_key,
                'secret' => $secret_key,
            ];
        }

        return new SesV2Client($config);
    }
}
