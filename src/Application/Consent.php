<?php

declare(strict_types=1);

namespace Wmos\Application;

use Wmos\Infrastructure\{Database, Audit, Secrets};

final class Consent
{
    public function __construct(private Database $database, private Audit $audit, private Secrets $secrets)
    {
    }

    private $challengeSender = null;
    public function setChallengeSender(callable $sender): void
    {
        $this->challengeSender = $sender;
    }

    public function grant(string $profileUuid, string $purpose, string $channel, string $source, string $policy, array $evidence, string $operationKey): array
    {
        $this->scope($purpose, $channel);
        if ($policy === '' || strlen($policy) > 64 || $source === '' || strlen($source) > 64 || !$evidence || strlen(json_encode($evidence, JSON_THROW_ON_ERROR)) > 4096) {
            throw new \InvalidArgumentException('Consent requires bounded source, policy and evidence.');
        }
        return $this->database->transaction(function () use ($profileUuid, $purpose, $channel, $source, $policy, $evidence, $operationKey): array {
            $profile = $this->lockedProfile($profileUuid);
            if ($profile['state'] !== 'active') {
                throw new \RuntimeException('Contact is unavailable.');
            }
            $requestKey = hash('sha256', 'consent.grant:' . $operationKey);
            $old = $this->database->find('consents', 'request_key', $requestKey);
            if ($old) {
                if ((int) $old['profile_id'] !== (int) $profile['id'] || $old['purpose'] !== $purpose || $old['channel'] !== $channel || $old['status'] !== 'granted') {
                    throw new \RuntimeException('Consent operation key conflict.');
                }
                return $this->safe($old);
            }
            // Resubscription can lift its own withdrawal; complaints/manual do-not-contact remain authoritative.
            $this->database->db()->query($this->database->db()->prepare('DELETE FROM ' . $this->database->table('suppressions') . ' WHERE profile_id=%d AND purpose=%s AND channel=%s AND reason=%s', $profile['id'], $purpose, $channel, 'withdrawal'));
            $row = $this->database->insert('consents', ['profile_id' => $profile['id'], 'purpose' => $purpose, 'channel' => $channel, 'status' => 'granted', 'source' => $source, 'policy_version' => $policy, 'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'request_key' => $requestKey, 'effective_at' => Database::now()]);
            $this->audit->record('consent.granted', $profileUuid, ['purpose' => $purpose, 'channel' => $channel, 'source' => $source]);
            return $this->safe($row);
        });
    }

    public function withdraw(string $profileUuid, string $purpose, string $channel, string $operationKey): array
    {
        $this->scope($purpose, $channel, true);
        return $this->database->transaction(function () use ($profileUuid, $purpose, $channel, $operationKey): array {
            $profile = $this->lockedProfile($profileUuid);
            $key = hash('sha256', 'consent.withdraw:' . $operationKey);
            $old = $this->database->find('consents', 'request_key', $key);
            if ($old) {
                if ((int) $old['profile_id'] !== (int) $profile['id'] || $old['purpose'] !== $purpose || $old['channel'] !== $channel) {
                    throw new \RuntimeException('Consent operation key conflict.');
                }
                return $this->safe($old);
            }
            $row = $this->database->insert('consents', ['profile_id' => $profile['id'], 'purpose' => $purpose, 'channel' => $channel, 'status' => 'withdrawn', 'source' => 'preference', 'policy_version' => 'withdrawal', 'evidence' => '{}', 'request_key' => $key, 'effective_at' => Database::now()]);
            $this->suppressLocked($profile, $purpose, $channel, 'withdrawal');
            $this->cancelMessages($profile['id'], $purpose, $channel);
            $this->audit->record('consent.withdrawn', $profileUuid, ['purpose' => $purpose, 'channel' => $channel]);
            return $this->safe($row);
        });
    }

    public function suppress(string $profileUuid, string $purpose, string $channel, string $reason): void
    {
        $this->scope($purpose, $channel, true);
        if (!in_array($reason, ['bounce', 'complaint', 'withdrawal', 'manual', 'erasure', 'provider_optout'], true)) {
            throw new \InvalidArgumentException('Invalid suppression reason.');
        }
        $this->database->transaction(function () use ($profileUuid, $purpose, $channel, $reason): void {
            $profile = $this->lockedProfile($profileUuid);
            $this->suppressLocked($profile, $purpose, $channel, $reason);
            $this->cancelMessages($profile['id'], $purpose, $channel);
            $this->audit->record('consent.suppressed', $profileUuid, ['purpose' => $purpose, 'channel' => $channel, 'reason' => $reason]);
        });
    }

    public function allowed(string $profileUuid, string $purpose, string $channel): bool
    {
        $this->scope($purpose, $channel);
        $profile = $this->database->get('profiles', $profileUuid);
        if (!$profile || $profile['state'] !== 'active') {
            return false;
        }
        $db = $this->database->db();
        $identities = $db->get_col($db->prepare('SELECT value_hash FROM ' . $this->database->table('identities') . ' WHERE profile_id=%d', $profile['id']));
        $hashes = array_values(array_filter(array_merge([$profile['email_hash']], $identities), 'is_string'));
        $identitySql = $hashes ? ' OR identity_hash IN (' . implode(',', array_fill(0, count($hashes), '%s')) . ')' : '';
        $args = array_merge([$profile['id']], $hashes, [$channel, $purpose]);
        $blocked = $db->get_var($db->prepare('SELECT id FROM ' . $this->database->table('suppressions') . ' WHERE (profile_id=%d' . $identitySql . ") AND channel IN (%s,'*') AND purpose IN (%s,'*') LIMIT 1", ...$args));
        if ($blocked) {
            return false;
        }
        $head = $db->get_row($db->prepare('SELECT status FROM ' . $this->database->table('consents') . ' WHERE profile_id=%d AND purpose=%s AND channel=%s ORDER BY id DESC LIMIT 1', $profile['id'], $purpose, $channel), ARRAY_A);
        return $head && $head['status'] === 'granted';
    }

    public function history(string $profileUuid): array
    {
        $profile = $this->database->get('profiles', $profileUuid);
        if (!$profile) {
            throw new \RuntimeException('Contact not found.');
        }
        return array_map(fn(array $row): array => $this->safe($row), $this->database->list('consents', ['profile_id' => $profile['id']], 100));
    }

    public function requestDoubleOptIn(string $profileUuid, string $purpose, string $channel, string $policy, array $evidence, ?string $operationKey = null): array
    {
        $this->scope($purpose, $channel);
        if ($channel !== 'email' || $policy === '' || strlen($policy) > 64 || strlen(json_encode($evidence, JSON_THROW_ON_ERROR)) > 4096) {
            throw new \InvalidArgumentException('Double opt-in requires an email policy and bounded evidence.');
        }
        $profile = $this->database->get('profiles', $profileUuid);
        if (!$profile || $profile['state'] !== 'active') {
            throw new \RuntimeException('Contact unavailable.');
        }
        if (!$this->challengeSender) {
            throw new \RuntimeException('Confirmation sender is unavailable.');
        }
        return $this->database->transaction(function () use ($profile, $purpose, $channel, $policy, $evidence, $operationKey): array {
            $locked = $this->lockedProfile($profile['uuid']);
            if ($locked['state'] !== 'active') {
                throw new \RuntimeException('Contact unavailable.');
            }
            $profile = $this->database->get('profiles', $profile['uuid']);
            $challengeUuid = Database::uuid();
            if ($operationKey !== null) {
                $hash = hash('sha256', 'doi:' . $profile['uuid'] . ':' . $purpose . ':' . $channel . ':' . $operationKey);
                $challengeUuid = substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
                $old = $this->database->get('consent_tokens', $challengeUuid);
                if ($old) {
                    return ['uuid' => $old['uuid'],'expires_at' => $old['expires_at']];
                }
            }
            $token = bin2hex(random_bytes(32));
            $email = $this->secrets->decrypt($profile['email_cipher'], 'contact.email');
            $evidence['_challenge_identity_hash'] = bin2hex($this->secrets->hash($email, 'identity.email.store'));
            if (strlen(json_encode($evidence, JSON_THROW_ON_ERROR)) > 4096) {
                throw new \InvalidArgumentException('Confirmation evidence exceeds limits.');
            }
            $row = $this->database->insert('consent_tokens', ['uuid' => $challengeUuid, 'profile_id' => $profile['id'], 'purpose' => $purpose, 'channel' => $channel, 'token_hash' => hash('sha256', $token), 'policy_version' => $policy, 'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400), 'consumed_at' => null]);
            ($this->challengeSender)($profile['uuid'], $token, $row['uuid'], $row['expires_at']);
            return ['uuid' => $row['uuid'], 'expires_at' => $row['expires_at']];
        });
    }

    public function confirm(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new \InvalidArgumentException('Invalid confirmation token.');
        }
        return $this->database->transaction(function () use ($token): array {
            $row = $this->database->find('consent_tokens', 'token_hash', hash('sha256', $token));
            if (!$row || $row['expires_at'] <= Database::now()) {
                throw new \RuntimeException('Confirmation expired or invalid.');
            }
            $profile = $this->database->db()->get_row($this->database->db()->prepare('SELECT id,uuid,state,email_hash FROM ' . $this->database->table('profiles') . ' WHERE id=%d FOR UPDATE', $row['profile_id']), ARRAY_A);
            if (!$profile || $profile['state'] !== 'active') {
                throw new \RuntimeException('Contact unavailable.');
            }
            $evidence = json_decode($row['evidence'], true, 32, JSON_THROW_ON_ERROR);
            $identityHash = $evidence['_challenge_identity_hash'] ?? null;
            if (!is_string($identityHash) || !preg_match('/^[a-f0-9]{64}$/D', $identityHash)) {
                throw new \RuntimeException('Confirmation destination evidence unavailable. Request a new challenge.');
            }
            $identity = $this->database->db()->get_row($this->database->db()->prepare('SELECT uuid,verified_at FROM ' . $this->database->table('identities') . ' WHERE profile_id=%d AND kind=%s AND namespace=%s AND value_hash=%s FOR UPDATE', $profile['id'], 'email', 'store', hex2bin($identityHash)), ARRAY_A);
            if (!$identity) {
                throw new \RuntimeException('Confirmation destination no longer belongs to this contact.');
            }
            $result = $this->grant($profile['uuid'], $row['purpose'], $row['channel'], 'double_opt_in', $row['policy_version'], array_merge($evidence, ['challenge' => $row['uuid']]), 'confirm:' . $row['uuid']);
            if (!$identity['verified_at']) {
                $this->database->update('identities', $identity['uuid'], ['verified_at' => Database::now()]);
            }
            if (!$row['consumed_at']) {
                $this->database->update('consent_tokens', $row['uuid'], ['consumed_at' => Database::now()]);
            }
            return $result;
        });
    }

    private function suppressLocked(array $profile, string $purpose, string $channel, string $reason): void
    {
        $hashes = $this->database->db()->get_col($this->database->db()->prepare('SELECT value_hash FROM ' . $this->database->table('identities') . ' WHERE profile_id=%d', $profile['id']));
        $hashes[] = $profile['email_hash'];
        foreach (array_values(array_unique(array_filter($hashes, 'is_string'))) as $hash) {
            $scope = hash('sha256', bin2hex($hash) . ':' . $purpose . ':' . $channel . ':' . $reason);
            if (!$this->database->find('suppressions', 'scope_key', $scope)) {
                $this->database->insert('suppressions', ['profile_id' => $profile['id'], 'identity_hash' => $hash, 'purpose' => $purpose, 'channel' => $channel, 'reason' => $reason, 'scope_key' => $scope]);
            }
        }
    }

    private function cancelMessages(int|string $profileId, string $purpose, string $channel): void
    {
        $where = 'profile_id=%d';
        $args = [$profileId];
        if ($purpose !== '*') {
            $where .= ' AND purpose=%s';
            $args[] = $purpose;
        }
        if ($channel !== '*') {
            $where .= ' AND channel=%s';
            $args[] = $channel;
        }
        $this->database->db()->query($this->database->db()->prepare('UPDATE ' . $this->database->table('messages') . " SET state='blocked',last_error='consent_blocked',row_version=row_version+1 WHERE " . $where . " AND state IN ('planned','queued','held')", ...$args));
    }

    private function lockedProfile(string $uuid): array
    {
        $profile = $this->database->db()->get_row($this->database->db()->prepare('SELECT id,uuid,state,email_hash FROM ' . $this->database->table('profiles') . ' WHERE uuid=%s FOR UPDATE', $uuid), ARRAY_A);
        if (!$profile) {
            throw new \RuntimeException('Contact not found.');
        }
        return $profile;
    }

    private function scope(string $purpose, string $channel, bool $wildcard = false): void
    {
        if (($wildcard && $purpose === '*') || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $purpose)) {
            if (in_array($channel, ['email', 'sms', 'whatsapp', 'telegram', 'push', 'social', 'ads', 'webhook', 'analytics', 'personalization', 'profiling'], true) || ($wildcard && $channel === '*')) {
                return;
            }
        }
        throw new \InvalidArgumentException('Invalid consent purpose/channel.');
    }

    private function safe(array $row): array
    {
        return array_intersect_key($row, array_flip(['uuid', 'purpose', 'channel', 'status', 'source', 'policy_version', 'effective_at', 'created_at']));
    }
}
