<?php

declare(strict_types=1);

namespace Wmos\Application;

use Wmos\Infrastructure\{Database, Queue, Secrets, Audit, RetryableException, AmbiguousException, DeferredException};
use Wmos\Infrastructure\Providers\{HttpProvider, WebhookVerifier};
use Wmos\Contracts\V1\Provider;

final class Messaging
{
    private array $adapters = [];

    public function __construct(private Database $database, private Queue $queue, private Contacts $contacts, private Consent $consent, private Secrets $secrets, private Audit $audit)
    {
        foreach (['resend', 'twilio', 'meta', 'telegram', 'onesignal', 'relay'] as $type) {
            $this->adapters[$type] = new HttpProvider($type);
        }
        $this->queue->register('message.dispatch', [$this, 'dispatch']);
        $this->queue->register('provider.receipt', [$this, 'processReceipt']);
        $this->queue->register('consent.confirmation', [$this, 'sendConfirmation']);
    }

    public function registerProvider(string $type, Provider $adapter): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $type) || isset($this->adapters[$type])) {
            throw new \InvalidArgumentException('Duplicate or invalid adapter key.');
        }
        $this->adapters[$type] = $adapter;
    }

    public function capabilities(string $type): array
    {
        return ($this->adapters[$type] ?? throw new \InvalidArgumentException('Unsupported provider.'))->capabilities();
    }

    public function providerList(int $limit = 25, int $after = 0): array
    {
        return array_map([$this, 'safeProvider'], $this->database->list('providers', [], $limit, $after));
    }

    public function saveProvider(string $type, string $name, array $configuration, ?string $secret = null, ?string $uuid = null, ?int $expectedVersion = null): array
    {
        $this->capabilities($type);
        if ($name === '' || strlen($name) > 191) {
            throw new \InvalidArgumentException('Provider name required.');
        }
        $allowed = ['from', 'account_sid', 'callback_url', 'api_version', 'phone_number_id', 'approved_templates', 'app_id', 'endpoint', 'account', 'enabled', 'policy_acknowledged', 'quiet_start', 'quiet_end', 'timezone', 'daily_cap'];
        if (array_diff(array_keys($configuration), $allowed) || strlen(json_encode($configuration, JSON_THROW_ON_ERROR)) > 8192) {
            throw new \InvalidArgumentException('Invalid provider configuration.');
        }
        if ($type === 'resend' && (empty($configuration['from']) || preg_match('/[\r\n]/', $configuration['from']))) {
            throw new \InvalidArgumentException('Email sender required.');
        }
        if ($type === 'twilio' && (!preg_match('/^AC[0-9a-fA-F]{32}$/D', $configuration['account_sid'] ?? '') || !preg_match('/^\+[1-9][0-9]{6,14}$/D', $configuration['from'] ?? ''))) {
            throw new \InvalidArgumentException('Twilio account SID and E.164 sender required.');
        }
        if ($type === 'meta' && (!preg_match('/^v[0-9]{1,2}\.0$/D', $configuration['api_version'] ?? '') || !ctype_digit((string) ($configuration['phone_number_id'] ?? '')) || !is_array($configuration['approved_templates'] ?? null))) {
            throw new \InvalidArgumentException('Meta API version, phone-number ID and approved template catalog required.');
        }
        if ($type === 'onesignal' && !preg_match('/^[a-f0-9-]{36}$/iD', $configuration['app_id'] ?? '')) {
            throw new \InvalidArgumentException('OneSignal App UUID required.');
        }
        if ($type === 'relay') {
            HttpProvider::approvedRelay($configuration['endpoint'] ?? '');
        }
        if (!empty($configuration['callback_url']) && !filter_var($configuration['callback_url'], FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid callback URL.');
        }
        foreach (['quiet_start', 'quiet_end'] as $hour) {
            if (isset($configuration[$hour]) && (!is_int($configuration[$hour]) || $configuration[$hour] < 0 || $configuration[$hour] > 23)) {
                throw new \InvalidArgumentException('Quiet hours must be 0 through 23.');
            }
        }
        if (isset($configuration['timezone'])) {
            new \DateTimeZone($configuration['timezone']);
        }
        if (isset($configuration['daily_cap']) && (!is_int($configuration['daily_cap']) || $configuration['daily_cap'] < 1 || $configuration['daily_cap'] > 100)) {
            throw new \InvalidArgumentException('Daily recipient cap must be 1 through 100.');
        }
        $existing = $uuid ? $this->database->get('providers', $uuid) : null;
        if ($uuid && !$existing) {
            throw new \RuntimeException('Provider not found.');
        }
        if ($existing && $existing['type'] !== $type) {
            throw new \InvalidArgumentException('Provider adapter cannot change.');
        }
        $uuid ??= Database::uuid();
        $cipher = $existing['secret'] ?? null;
        if ($secret !== null) {
            $values = str_starts_with(trim($secret), '{') ? json_decode($secret, true, 16, JSON_THROW_ON_ERROR) : ['token' => $secret];
            if (!is_array($values) || array_diff(array_keys($values), ['token', 'webhook_secret']) || empty($values['token']) || strlen($secret) > 16384) {
                throw new \InvalidArgumentException('Credential token required; only token and webhook_secret are accepted.');
            }
            foreach ($values as $value) {
                if (!is_string($value) || preg_match('/[\r\n]/', $value)) {
                    throw new \InvalidArgumentException('Invalid credential.');
                }
            }
            if ($type === 'telegram' && !preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/D', $values['token'])) {
                throw new \InvalidArgumentException('Invalid Telegram bot token.');
            }
            $cipher = $this->secrets->encrypt(json_encode($values, JSON_THROW_ON_ERROR), 'provider:' . $uuid);
        }
        $state = !empty($configuration['enabled']) && !empty($configuration['policy_acknowledged']) && $cipher !== null ? 'active' : 'disabled';
        $data = ['type' => $type, 'name' => $name, 'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR), 'secret' => $cipher, 'state' => $state];
        $row = $existing ? $this->database->update('providers', $uuid, $data, $expectedVersion) : $this->database->insert('providers', ['uuid' => $uuid] + $data);
        $this->audit->record('provider.saved', $uuid, ['type' => $type, 'credential_changed' => $secret !== null, 'state' => $state]);
        return $this->safeProvider($row);
    }

    public function plan(string $profileUuid, string $channel, string $providerUuid, array $content, string $logicalKey, string $purpose = 'marketing', ?string $definitionUuid = null, ?string $runUuid = null): array
    {
        $this->enabled($channel);
        $provider = $this->database->get('providers', $providerUuid) ?? throw new \RuntimeException('Provider not found.');
        if ($provider['state'] !== 'active' || !in_array($channel, $this->capabilities($provider['type'])['channels'], true)) {
            throw new \RuntimeException('Provider/channel is unavailable.');
        }
        $this->validateContent($channel, $content);
        $profile = $profileUuid !== '' ? $this->contacts->raw($profileUuid) : null;
        if ($profile && !$this->consent->allowed($profileUuid, $purpose, $channel)) {
            throw new \RuntimeException('Consent or suppression blocks this operation.');
        }
        if (!$profile && !in_array($channel, ['social', 'webhook'], true)) {
            throw new \RuntimeException('A contact is required for this channel.');
        }
        $digest = hash('sha256', json_encode($content, JSON_THROW_ON_ERROR));
        $key = hash('sha256', $logicalKey);
        return $this->database->transaction(function () use ($profile, $channel, $provider, $content, $digest, $key, $purpose, $definitionUuid, $runUuid): array {
            $old = $this->database->find('messages', 'logical_key', $key);
            if ($old) {
                if ($old['content_hash'] !== $digest || $old['provider_uuid'] !== $provider['uuid'] || $old['channel'] !== $channel || (int) $old['profile_id'] !== (int) ($profile['id'] ?? 0) || $old['purpose'] !== $purpose) {
                    throw new \RuntimeException('Message idempotency key conflict.');
                }
                return $this->safeMessage($old);
            }
            if ($profile) {
                $this->lockProfile($profile['id']);
                if (!$this->consent->allowed($profile['uuid'], $purpose, $channel)) {
                    throw new \RuntimeException('Consent changed.');
                }
            }
            $uuid = Database::uuid();
            $definition = $definitionUuid ? $this->database->get('definitions', $definitionUuid) : null;
            $run = $runUuid ? $this->database->get('runs', $runUuid) : null;
            if (($definitionUuid && !$definition) || ($runUuid && !$run)) {
                throw new \RuntimeException('Message owner not found.');
            }
            $content['authorization_epoch'] = (int) ($profile['erasure_epoch'] ?? 0);
            $content['definition_cancel_epoch'] = (int) ($definition['cancel_epoch'] ?? 0);
            $content['run_cancel_epoch'] = (int) ($run['cancel_epoch'] ?? 0);
            if ($channel === 'email' && $purpose !== 'subscription_confirmation') {
                $token = $this->createUnsubscribeToken($profile['uuid'], $purpose, $channel);
                $url = rest_url('wmos/v1/unsubscribe') . '?token=' . rawurlencode($token);
                $content['headers'] = ['List-Unsubscribe' => '<' . $url . '>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
                $humanUrl = add_query_arg(['wmos_preference' => 'unsubscribe','token' => $token], home_url('/'));
                $content['html'] = ($content['html'] ?? '') . '<p><a href="' . esc_url($humanUrl) . '">Unsubscribe</a></p>';
                $content['text'] = ($content['text'] ?? '') . "\nUnsubscribe: " . $humanUrl;
            }
            $row = $this->database->insert('messages', ['uuid' => $uuid, 'profile_id' => $profile['id'] ?? null, 'channel' => $channel, 'purpose' => $purpose, 'provider_uuid' => $provider['uuid'], 'state' => 'queued', 'logical_key' => $key, 'content' => $this->secrets->encrypt(json_encode($content, JSON_THROW_ON_ERROR), 'message:' . $uuid), 'content_hash' => $digest, 'definition_id' => $definition['id'] ?? null, 'run_id' => $run['id'] ?? null, 'scheduled_at' => Database::now()]);
            $this->queue->enqueue('message.dispatch', ['message_uuid' => $uuid], 'dispatch:' . $uuid);
            return $this->safeMessage($row);
        });
    }

    public function dispatch(array $job, array $payload): void
    {
        if (!get_option('wmos_active', false)) {
            throw new DeferredException('Marketing runtime is paused.', 300);
        }
        $uuid = (string) ($payload['message_uuid'] ?? '');
        $message = $this->database->get('messages', $uuid) ?? throw new \RuntimeException('Message not found.');
        if (in_array($message['state'], ['submitted', 'delivered', 'blocked', 'cancelled', 'failed', 'bounced', 'complained'], true)) {
            return;
        }
        $this->enabled($message['channel']);
        $provider = $this->database->get('providers', $message['provider_uuid']) ?? throw new \RuntimeException('Provider unavailable.');
        if ($provider['state'] !== 'active') {
            throw new \RuntimeException('Provider disabled.');
        }
        $config = json_decode($provider['configuration'], true, 32, JSON_THROW_ON_ERROR);
        $content = json_decode($this->secrets->decrypt($message['content'], 'message:' . $uuid), true, 32, JSON_THROW_ON_ERROR);
        $idempotent = (int) $this->capabilities($provider['type'])['idempotency_seconds'];
        if (in_array($message['state'], ['dispatching', 'ambiguous'], true)) {
            if ($idempotent === 0 || !$message['attempted_at'] || time() - strtotime($message['attempted_at'] . ' UTC') >= min($idempotent, 82800)) {
                throw new AmbiguousException('Prior submission outcome requires reconciliation.');
            }
        }
        if ((int) ($job['attempts'] ?? 0) > 6 || ($message['attempted_at'] && time() - strtotime($message['attempted_at'] . ' UTC') >= 86400)) {
            $this->database->update('messages', $uuid, ['state' => 'failed','last_error' => 'provider_retry_limit']);
            throw new \RuntimeException('Provider retry limit reached.');
        }
        $profile = $message['profile_id'] ? $this->database->find('profiles', 'id', (int) $message['profile_id']) : null;
        $this->quietHours($config);
        $destination = $profile ? ($message['channel'] === 'ads' ? hash('sha256', strtolower(trim($this->contacts->destination($profile, 'email')))) : $this->contacts->destination($profile, $message['channel'])) : (string) ($config['account'] ?? '');
        $allowed = $this->database->transaction(function () use ($profile, $message, $content, $config, $idempotent): bool {
            if ($profile) {
                $this->lockProfile($profile['id']);
                $current = $this->contacts->raw($profile['uuid']);
                if ((int) $current['erasure_epoch'] !== (int) $content['authorization_epoch'] || !$this->consent->allowed($profile['uuid'], $message['purpose'], $message['channel'])) {
                    $this->database->update('messages', $message['uuid'], ['state' => 'blocked', 'last_error' => 'consent_blocked']);
                    return false;
                }
                $sent = $this->database->db()->get_var($this->database->db()->prepare('SELECT COUNT(*) FROM ' . $this->database->table('messages') . ' WHERE profile_id=%d AND channel=%s AND uuid<>%s AND attempted_at>=%s', $profile['id'], $message['channel'], $message['uuid'], gmdate('Y-m-d H:i:s', time() - 86400)));
                if ((int) $sent >= (int) ($config['daily_cap'] ?? 5)) {
                    throw new DeferredException('Recipient frequency cap.', 3600);
                }
            }
            foreach (['definitions' => ['definition_id', 'definition_cancel_epoch'], 'runs' => ['run_id', 'run_cancel_epoch']] as $table => [$idField, $epochField]) {
                if (!$message[$idField]) {
                    continue;
                }
                $owner = $this->database->find($table, 'id', (int) $message[$idField]);
                if (!$owner || (int) $owner['cancel_epoch'] !== (int) $content[$epochField] || in_array($owner['state'], ['paused', 'cancelled', 'archived', 'failed'], true)) {
                    $this->database->update('messages', $message['uuid'], ['state' => 'cancelled']);
                    return false;
                }
            }
            if ($message['run_id']) {
                $run = $this->database->find('runs', 'id', (int) $message['run_id']);
                $context = json_decode($run['context'] ?: '{}', true, 32, JSON_THROW_ON_ERROR);
                $facts = $context['event_properties'] ?? $context['facts'] ?? [];
                if (isset($facts['cart_uuid'])) {
                    $cart = $this->database->get('carts', $facts['cart_uuid']);
                    if (!$cart || $cart['state'] !== 'abandoned' || (int) $cart['generation'] !== (int) ($facts['generation'] ?? -1)) {
                        $this->database->update('messages', $message['uuid'], ['state' => 'cancelled']);
                        return false;
                    }
                }
            }
            $current = $this->database->get('messages', $message['uuid']);
            if (in_array($current['state'], ['dispatching','ambiguous'], true) && $idempotent === 0) {
                throw new AmbiguousException('Concurrent or prior non-idempotent submission requires reconciliation.');
            }
            if (!in_array($current['state'], ['queued', 'held', 'dispatching', 'ambiguous'], true)) {
                return false;
            }
            $this->database->update('messages', $message['uuid'], ['state' => 'dispatching', 'attempted_at' => $current['attempted_at'] ?: Database::now()], (int) $current['row_version']);
            return true;
        });
        if (!$allowed) {
            return;
        }
        unset($content['authorization_epoch'], $content['definition_cancel_epoch'], $content['run_cancel_epoch']);
        $credentials = json_decode($this->secrets->decrypt($provider['secret'], 'provider:' . $provider['uuid']), true, 16, JSON_THROW_ON_ERROR);
        $this->enabled($message['channel']);
        if (!empty($job['uuid']) && !empty($job['lease_token'])) {
            $owner = $this->database->get('jobs', $job['uuid']);
            if (!$owner || $owner['state'] !== 'running' || !hash_equals((string)$owner['lease_token'], (string)$job['lease_token']) || $owner['lease_until'] <= Database::now()) {
                throw new AmbiguousException('Dispatch worker lease lost before network attempt.');
            }
        }
        if (($this->database->get('providers', $provider['uuid'])['state'] ?? 'disabled') !== 'active') {
            throw new \RuntimeException('Provider disabled before submission.');
        }
        $result = $this->adapters[$provider['type']]->submit($message['channel'], $destination, $content, $config, $credentials, $message['logical_key']);
        if ($result->state === 'accepted') {
            $this->database->update('messages', $uuid, ['state' => 'submitted', 'provider_ref' => $result->reference, 'accepted_at' => Database::now(), 'last_error' => null]);
            $this->audit->record('message.submitted', $uuid, ['channel' => $message['channel']]);
            return;
        }
        if ($result->state === 'retryable') {
            $this->database->update('messages', $uuid, ['state' => 'queued', 'last_error' => $result->error]);
            throw new RetryableException($result->error ?? 'Provider retry required.', max(30, $result->retryAfter));
        }
        if ($result->state === 'ambiguous') {
            $this->database->update('messages', $uuid, ['state' => 'ambiguous', 'last_error' => $result->error]);
            if ($idempotent > 0) {
                throw new RetryableException('Idempotent submission reconciliation.', 60);
            }
            throw new AmbiguousException('Provider acceptance unknown; blind retry prohibited.');
        }
        $this->database->update('messages', $uuid, ['state' => 'failed', 'last_error' => $result->error]);
    }

    /** Only a fresh, explicitly requested challenge may use this security-mail queue. */
    public function queueConfirmation(string $profileUuid, string $token, string $challengeUuid, string $expiresAt): void
    {
        $profile = $this->contacts->raw($profileUuid);
        $cipher = $this->secrets->encrypt(json_encode(['token' => $token,'expires_at' => $expiresAt,'email' => $this->contacts->decryptEmail($profile)], JSON_THROW_ON_ERROR), 'confirmation:' . $challengeUuid);
        $this->queue->enqueue('consent.confirmation', ['profile_uuid' => $profileUuid,'challenge_uuid' => $challengeUuid,'cipher' => $cipher], 'confirmation:' . $challengeUuid);
    }

    public function sendConfirmation(array $job, array $payload): void
    {
        if (!get_option('wmos_active', false)) {
            throw new DeferredException('Runtime paused.', 300);
        }
        $challenge = $this->database->get('consent_tokens', $payload['challenge_uuid']);
        $profile = $this->contacts->raw($payload['profile_uuid']);
        if (!$challenge || $challenge['consumed_at'] || $challenge['expires_at'] <= Database::now() || $profile['state'] !== 'active' || (int) $challenge['profile_id'] !== (int) $profile['id']) {
            return;
        }
        $plain = json_decode($this->secrets->decrypt($payload['cipher'], 'confirmation:' . $challenge['uuid']), true, 8, JSON_THROW_ON_ERROR);
        if (!hash_equals($challenge['token_hash'], hash('sha256', $plain['token']))) {
            throw new \RuntimeException('Confirmation challenge mismatch.');
        }
        $evidence = json_decode($challenge['evidence'], true, 32, JSON_THROW_ON_ERROR);
        $email = $plain['email'] ?? null;
        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($evidence['_challenge_identity_hash'] ?? null) || !hash_equals($evidence['_challenge_identity_hash'], bin2hex($this->secrets->hash($email, 'identity.email.store')))) {
            throw new \RuntimeException('Confirmation destination evidence mismatch.');
        }
        $url = add_query_arg(['wmos_preference' => 'confirm','token' => $plain['token']], home_url('/'));
        if (!wp_mail($email, __('Confirm your subscription', 'woocommerce-marketing-os'), __('Confirm the subscription you requested by opening this link:', 'woocommerce-marketing-os') . "\n" . $url)) {
            throw new \RuntimeException('Confirmation transport failed.');
        }
        $this->audit->record('consent.challenge_sent', $challenge['uuid'], ['transport' => 'wordpress_security_mail']);
    }

    public function callback(string $providerUuid, string $raw, array $headers, string $canonicalUrl): array
    {
        $provider = $this->database->get('providers', $providerUuid) ?? throw new \RuntimeException('Provider not found.');
        $config = json_decode($provider['configuration'], true, 32, JSON_THROW_ON_ERROR);
        if ($provider['type'] === 'twilio' && $canonicalUrl !== ($config['callback_url'] ?? '')) {
            throw new \RuntimeException('Callback URL mismatch.');
        }
        $secrets = json_decode($this->secrets->decrypt($provider['secret'], 'provider:' . $providerUuid), true, 16, JSON_THROW_ON_ERROR);
        $verified = WebhookVerifier::verify($provider['type'], $raw, $headers, $secrets, $config, $canonicalUrl);
        return $this->database->transaction(function () use ($provider, $verified, $raw): array {
            $db = $this->database->db();
            $db->get_var($db->prepare('SELECT id FROM ' . $this->database->table('providers') . ' WHERE id=%d FOR UPDATE', $provider['id']));
            $old = $db->get_var($db->prepare('SELECT id FROM ' . $this->database->table('webhook_receipts') . ' WHERE provider_id=%d AND event_key=%s', $provider['id'], $verified['key']));
            if ($old) {
                return ['accepted' => true,'duplicate' => true];
            }
            $uuid = Database::uuid();
            $this->database->insert('webhook_receipts', ['uuid' => $uuid,'provider_id' => $provider['id'],'event_key' => $verified['key'],'body_hash' => hash('sha256', $raw),'payload' => $this->secrets->encrypt(json_encode($verified['payload'], JSON_THROW_ON_ERROR), 'receipt:' . $uuid)]);
            $this->queue->enqueue('provider.receipt', ['receipt_uuid' => $uuid], 'receipt:' . $uuid);
            return ['accepted' => true,'duplicate' => false];
        });
    }

    public function processReceipt(array $job, array $payload): void
    {
        $receipt = $this->database->get('webhook_receipts', $payload['receipt_uuid']) ?? throw new \RuntimeException('Receipt not found.');
        if ($receipt['processed_at']) {
            return;
        }
        $provider = $this->database->find('providers', 'id', (int)$receipt['provider_id']) ?? throw new \RuntimeException('Provider unavailable.');
        $data = json_decode($this->secrets->decrypt($receipt['payload'], 'receipt:' . $receipt['uuid']), true, 32, JSON_THROW_ON_ERROR);
        $this->database->update('webhook_receipts', $receipt['uuid'], ['attempts' => (int)($job['attempts'] ?? 1)]);
        $stop = $provider['type'] === 'twilio' && in_array(strtoupper(trim($data['Body'] ?? '')), ['STOP','STOPALL','UNSUBSCRIBE','CANCEL','END','QUIT'], true);
        $telegramStop = $provider['type'] === 'telegram' && preg_match('/^\/stop(?:@\w+)?$/iD', trim($data['message']['text'] ?? ''));
        if ($stop || $telegramStop) {
            $kind = $stop ? 'phone' : 'telegram';
            $value = $stop ? ($data['From'] ?? '') : (string)($data['message']['chat']['id'] ?? '');
            $identity = $this->database->find('identities', 'value_hash', $this->secrets->hash($value, 'identity.' . $kind . '.store'));
            if ($identity && $identity['kind'] === $kind) {
                $profile = $this->database->find('profiles', 'id', (int)$identity['profile_id']);
                if ($profile) {
                    $this->consent->suppress($profile['uuid'], '*', $stop ? 'sms' : 'telegram', 'provider_optout');
                }
            }
        } else {
            $notices = $provider['type'] === 'meta' ? ($data['entry'][0]['changes'][0]['value']['statuses'] ?? []) : [$data];
            foreach ($notices as $notice) {
                $ref = $notice['data']['email_id'] ?? $notice['MessageSid'] ?? $notice['id'] ?? $notice['reference'] ?? null;
                $status = $notice['type'] ?? $notice['MessageStatus'] ?? $notice['status'] ?? '';
                $state = match ($status) {
                    'email.delivered','delivered'=>'delivered','email.bounced','undelivered','failed'=>'bounced','email.complained'=>'complained',default=>null
                };
                if (!$state || !is_string($ref) || strlen($ref) > 191) {
                    continue;
                }
                $db = $this->database->db();
                $message = $db->get_row($db->prepare('SELECT uuid,state,profile_id,channel FROM ' . $this->database->table('messages') . ' WHERE provider_uuid=%s AND provider_ref=%s LIMIT 1', $provider['uuid'], $ref), ARRAY_A);
                if (!$message) {
                    throw new RetryableException('Receipt awaits submission reconciliation.', 60);
                }
                if (!in_array($message['state'], ['bounced','complained'], true)) {
                    $this->database->update('messages', $message['uuid'], ['state' => $state]);
                }
                if (in_array($state, ['bounced','complained'], true) && $message['profile_id']) {
                    $profile = $this->database->find('profiles', 'id', (int)$message['profile_id']);
                    if ($profile) {
                        $this->consent->suppress($profile['uuid'], '*', $message['channel'], $state === 'bounced' ? 'bounce' : 'complaint');
                    }
                }
            }
        }
        $this->database->update('webhook_receipts', $receipt['uuid'], ['processed_at' => Database::now()]);
    }

    public function createUnsubscribeToken(string $profileUuid, string $purpose, string $channel): string
    {
        $plain = json_encode(['profile' => $profileUuid, 'purpose' => $purpose, 'channel' => $channel, 'expires' => time() + 31536000], JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($this->secrets->encrypt($plain, 'unsubscribe')), '+/', '-_'), '=');
    }

    public function unsubscribe(string $token): array
    {
        if (strlen($token) > 2048 || !preg_match('/^[A-Za-z0-9_-]+$/D', $token)) {
            throw new \InvalidArgumentException('Invalid unsubscribe token.');
        }
        $encoded = base64_decode(strtr($token, '-_', '+/'), true);
        if (!is_string($encoded)) {
            throw new \InvalidArgumentException('Invalid unsubscribe token.');
        }
        $scope = json_decode($this->secrets->decrypt($encoded, 'unsubscribe'), true, 8, JSON_THROW_ON_ERROR);
        if ((int) ($scope['expires'] ?? 0) < time()) {
            throw new \RuntimeException('Unsubscribe token expired.');
        }
        return $this->consent->withdraw($scope['profile'], $scope['purpose'], $scope['channel'], 'unsubscribe:' . hash('sha256', $token));
    }

    public function get(string $uuid): array
    {
        return $this->safeMessage($this->database->get('messages', $uuid) ?? throw new \RuntimeException('Message not found.'));
    }
    public function list(int $limit = 25, int $after = 0): array
    {
        return array_map([$this, 'safeMessage'], $this->database->list('messages', [], $limit, $after));
    }

    private function enabled(string $channel): void
    {
        $modules = (array)(get_option('wmos_settings', [])['enabled_modules'] ?? []);
        if (!get_option('wmos_active', false) || !in_array($channel, $modules, true)) {
            throw new DeferredException('Requested channel module is disabled.', 300);
        }
    }

    private function quietHours(array $config): void
    {
        if (!isset($config['quiet_start'], $config['quiet_end'])) {
            return;
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone($config['timezone'] ?? 'UTC'));
        $hour = (int) $now->format('G');
        $start = $config['quiet_start'];
        $end = $config['quiet_end'];
        if ($start === $end || ($start < $end ? $hour >= $start && $hour < $end : $hour >= $start || $hour < $end)) {
            throw new DeferredException('Quiet hours.', 3600);
        }
    }

    private function validateContent(string $channel, array &$content): void
    {
        if (array_diff(array_keys($content), ['subject', 'html', 'text', 'template', 'language', 'components', 'title', 'data']) || strlen(json_encode($content, JSON_THROW_ON_ERROR)) > 65536) {
            throw new \InvalidArgumentException('Invalid message content.');
        }
        foreach (['subject', 'html', 'text', 'template', 'language', 'title'] as $field) {
            if (isset($content[$field]) && !is_string($content[$field])) {
                throw new \InvalidArgumentException('Message fields must be strings.');
            }
        }
        if ($channel === 'email') {
            if (empty($content['subject']) || strlen($content['subject']) > 998 || preg_match('/[\r\n]/', $content['subject']) || (empty($content['html']) && empty($content['text']))) {
                throw new \InvalidArgumentException('Email subject and body required.');
            }
            $content['html'] = wp_kses_post($content['html'] ?? '');
        } elseif ($channel === 'whatsapp') {
            if (!preg_match('/^[a-z0-9_]{1,512}$/D', $content['template'] ?? '') || !preg_match('/^[a-z]{2}(?:_[A-Z]{2})?$/D', $content['language'] ?? '')) {
                throw new \InvalidArgumentException('Approved WhatsApp template and language required.');
            }
        } elseif (empty($content['text']) || strlen($content['text']) > ($channel === 'sms' ? 1600 : 4096)) {
            throw new \InvalidArgumentException('Channel text required or too long.');
        }
    }

    private function lockProfile(int|string $id): void
    {
        $this->database->db()->get_var($this->database->db()->prepare('SELECT id FROM ' . $this->database->table('profiles') . ' WHERE id=%d FOR UPDATE', $id));
    }
    private function safeProvider(array $row): array
    {
        $available = isset($this->adapters[$row['type']]);
        return ['uuid' => $row['uuid'],'type' => $row['type'],'name' => $row['name'],'state' => $available ? $row['state'] : 'unavailable','configuration' => $available ? json_decode($row['configuration'], true, 32, JSON_THROW_ON_ERROR) : [],'credential_configured' => !empty($row['secret']),'capabilities' => $available ? $this->capabilities($row['type']) : ['channels' => [],'idempotency_seconds' => 0,'templates' => false,'receipts' => false],'adapter_available' => $available,'row_version' => (int)$row['row_version']];
    }
    private function safeMessage(array $row): array
    {
        return array_intersect_key($row, array_flip(['uuid', 'channel', 'purpose', 'provider_uuid', 'state', 'last_error', 'scheduled_at', 'accepted_at', 'created_at', 'row_version']));
    }
}
