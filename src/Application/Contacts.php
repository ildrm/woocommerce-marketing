<?php

declare(strict_types=1);

namespace Wmos\Application;

use Wmos\Infrastructure\{Database, Secrets, Audit};

final class Contacts
{
    public function __construct(private Database $database, private Secrets $secrets, private Audit $audit)
    {
    }

    public function create(string $email, ?int $userId = null, bool $verified = false): array
    {
        $email = $this->normalize('email', $email);
        $hash = $this->secrets->hash($email, 'email');
        return $this->database->transaction(function () use ($email, $hash, $userId, $verified): array {
            $existing = $this->database->find('profiles', 'email_hash', $hash);
            if ($existing && $userId !== null && !empty($existing['user_id']) && (int) $existing['user_id'] !== $userId) {
                throw new \RuntimeException('Email belongs to another verified account.');
            }
            // A staff merge may move the account to a survivor with a different primary email.
            // Reuse its established principal without claiming or merging the supplied address.
            $principal = $userId !== null ? $this->database->find('profiles', 'user_id', $userId) : null;
            if ($principal) {
                if ($principal['state'] !== 'active') {
                    throw new \RuntimeException('Contact is unavailable for enrollment.');
                }
                return $this->safe($principal);
            }
            if ($existing) {
                if ($existing['state'] !== 'active') {
                    throw new \RuntimeException('Contact is unavailable for enrollment.');
                }
                return $this->safe($existing);
            }
            $row = $this->database->insert('profiles', ['email_hash' => $hash, 'email_cipher' => $this->secrets->encrypt($email, 'contact.email'), 'user_id' => $userId, 'state' => 'active', 'tags' => '[]', 'attributes' => '{}', 'currency' => '---']);
            $this->database->insert('identities', ['profile_id' => $row['id'], 'kind' => 'email', 'namespace' => 'store', 'value_hash' => $this->secrets->hash($email, 'identity.email.store'), 'value_cipher' => $this->secrets->encrypt($email, 'identity.email.store'), 'verified_at' => $verified ? Database::now() : null]);
            $this->audit->record('contact.created', $row['uuid'], ['verified' => $verified]);
            return $this->safe($row);
        });
    }

    public function raw(string $uuid): array
    {
        $row = $this->database->get('profiles', $uuid);
        if (!$row) {
            throw new \RuntimeException('Contact not found.');
        }
        return $row;
    }

    public function get(string $uuid): array
    {
        return $this->safe($this->raw($uuid));
    }

    public function list(int $limit = 25, int $after = 0): array
    {
        return array_map(fn(array $row): array => $this->safe($row), $this->database->list('profiles', ['state' => 'active'], $limit, $after));
    }

    public function update(string $uuid, array $attributes, array $tags, int $revision): array
    {
        if (count($attributes) > 50 || count($tags) > 50 || strlen(json_encode($attributes, JSON_THROW_ON_ERROR)) > 4096) {
            throw new \InvalidArgumentException('Contact attributes exceed limits.');
        }
        foreach ($attributes as $key => $value) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', (string) $key) || (!is_scalar($value) && $value !== null)) {
                throw new \InvalidArgumentException('Attributes must be named scalar values.');
            }
            if (preg_match('/password|secret|card|health|religion|national_id/i', (string) $key)) {
                throw new \InvalidArgumentException('Sensitive attributes are unsupported.');
            }
        }
        foreach ($tags as $tag) {
            if (!is_string($tag) || strlen($tag) > 64 || preg_match('/[<>\x00-\x1F]/', $tag)) {
                throw new \InvalidArgumentException('Invalid tag.');
            }
        }
        if ($this->raw($uuid)['state'] !== 'active') {
            throw new \RuntimeException('Contact is not active.');
        }
        $row = $this->database->update('profiles', $uuid, ['attributes' => json_encode($attributes, JSON_THROW_ON_ERROR), 'tags' => json_encode(array_values(array_unique($tags)), JSON_THROW_ON_ERROR)], $revision);
        $this->audit->record('contact.updated', $uuid, ['fields' => array_keys($attributes)]);
        return $this->safe($row);
    }

    public function addIdentity(string $uuid, string $kind, string $value, string $namespace = 'store', bool $verified = false): array
    {
        $value = $this->normalize($kind, $value);
        if (!preg_match('/^[a-z0-9_.-]{1,100}$/D', $namespace)) {
            throw new \InvalidArgumentException('Invalid identity namespace.');
        }
        $profile = $this->raw($uuid);
        return $this->database->transaction(function () use ($profile, $kind, $value, $namespace, $verified): array {
            $this->lock($profile['id']);
            if ($this->raw($profile['uuid'])['state'] !== 'active') {
                throw new \RuntimeException('Contact is unavailable.');
            }
            $hash = $this->secrets->hash($value, 'identity.' . $kind . '.' . $namespace);
            $rows = $this->database->db()->get_results($this->database->db()->prepare('SELECT id,uuid,profile_id,kind,namespace,value_cipher,verified_at FROM ' . $this->database->table('identities') . ' WHERE kind=%s AND namespace=%s AND value_hash=%s', $kind, $namespace, $hash), ARRAY_A);
            if ($rows) {
                if ((int) $rows[0]['profile_id'] !== (int) $profile['id']) {
                    throw new \RuntimeException('Identity is already linked to another contact; no automatic merge was performed.');
                }
                if ($verified && !$rows[0]['verified_at']) {
                    $this->database->update('identities', $rows[0]['uuid'], ['verified_at' => Database::now()]);
                }
                return ['uuid' => $rows[0]['uuid'], 'kind' => $kind, 'verified' => $verified || !empty($rows[0]['verified_at'])];
            }
            $row = $this->database->insert('identities', ['profile_id' => $profile['id'], 'kind' => $kind, 'namespace' => $namespace, 'value_hash' => $hash, 'value_cipher' => $this->secrets->encrypt($value, 'identity.' . $kind . '.' . $namespace), 'verified_at' => $verified ? Database::now() : null]);
            $this->audit->record('identity.linked', $profile['uuid'], ['kind' => $kind, 'verified' => $verified]);
            return ['uuid' => $row['uuid'], 'kind' => $kind, 'verified' => $verified];
        });
    }

    public function destination(array $profile, string $channel): string
    {
        $kind = match ($channel) {
            'email' => 'email', 'sms', 'whatsapp' => 'phone', 'telegram' => 'telegram', 'push' => 'push', default => throw new \InvalidArgumentException('Unsupported personal channel.')
        };
        $row = $this->database->db()->get_row($this->database->db()->prepare('SELECT id,uuid,profile_id,kind,namespace,value_cipher,verified_at FROM ' . $this->database->table('identities') . ' WHERE profile_id=%d AND kind=%s AND verified_at IS NOT NULL ORDER BY id ASC LIMIT 1', $profile['id'], $kind), ARRAY_A);
        if (!$row) {
            throw new \RuntimeException('A verified channel identity is required.');
        }
        return $this->secrets->decrypt($row['value_cipher'], 'identity.' . $row['kind'] . '.' . $row['namespace']);
    }

    /** Staff records the verified ownership evidence; monetary and consent history stays on its original subject. */
    public function merge(string $sourceUuid, string $targetUuid, string $reason, array $proof, int $sourceRevision, int $targetRevision): array
    {
        if ($sourceUuid === $targetUuid || trim($reason) === '' || strlen($reason) > 191 || empty($proof['verification_reference']) || strlen(json_encode($proof, JSON_THROW_ON_ERROR)) > 4096) {
            throw new \InvalidArgumentException('Distinct contacts, reason and recorded ownership proof are required.');
        }
        return $this->database->transaction(function () use ($sourceUuid, $targetUuid, $reason, $proof, $sourceRevision, $targetRevision): array {
            $source = $this->raw($sourceUuid);
            $target = $this->raw($targetUuid);
            $ids = [(int)$source['id'],(int)$target['id']];
            sort($ids);
            foreach ($ids as $id) {
                $this->lock($id);
            }
            $source = $this->raw($sourceUuid);
            $target = $this->raw($targetUuid);
            if ($source['state'] !== 'active' || $target['state'] !== 'active') {
                throw new \RuntimeException('Only active contacts can be merged.');
            }
            if ((int)$source['row_version'] !== $sourceRevision || (int)$target['row_version'] !== $targetRevision) {
                throw new \Wmos\Infrastructure\ConflictException('Contact revision changed.');
            }
            if ($source['user_id'] && $target['user_id']) {
                throw new \RuntimeException('Separate registered accounts require account ownership resolution before merge.');
            }
            $identities = $this->database->list('identities', ['profile_id' => $source['id']], 1000);
            if (count($identities) === 1000) {
                throw new \RuntimeException('Identity limit requires review.');
            }
            $uuid = Database::uuid();
            $snapshot = ['source' => array_intersect_key($source, array_flip(['email_hash','email_cipher','user_id','tags','attributes'])),'target_user_id' => $target['user_id'],'identities' => array_map(fn(array $r): array=>['uuid' => $r['uuid'],'verified_at' => $r['verified_at']], $identities)];
            // Binary hashes are encoded before encrypting the JSON recovery snapshot.
            $snapshot['source']['email_hash'] = isset($source['email_hash']) ? base64_encode($source['email_hash']) : null;
            $merge = $this->database->insert('identity_merges', ['uuid' => $uuid,'source_id' => $source['id'],'target_id' => $target['id'],'state' => 'merged','reason' => $reason,'proof' => $this->secrets->encrypt(json_encode($proof, JSON_THROW_ON_ERROR), 'merge-proof:' . $uuid),'snapshot' => $this->secrets->encrypt(json_encode($snapshot, JSON_THROW_ON_ERROR), 'merge-snapshot:' . $uuid)]);
            foreach ($identities as $identity) {
                $this->database->insert('identity_moves', ['merge_id' => $merge['id'],'identity_id' => $identity['id'],'source_id' => $source['id'],'target_id' => $target['id']]);
                $this->database->update('identities', $identity['uuid'], ['profile_id' => $target['id'],'verified_at' => null]);
            }
            $this->withdrawMergeScopes($source, $uuid);
            $this->withdrawMergeScopes($target, $uuid);
            $this->database->update('profiles', $sourceUuid, ['state' => 'merged','email_hash' => null,'email_cipher' => null,'user_id' => null,'tags' => '[]','attributes' => '{}','erasure_epoch' => (int)$source['erasure_epoch'] + 1], $sourceRevision);
            $this->database->update('profiles', $targetUuid, ['user_id' => $source['user_id'] ?: $target['user_id'],'erasure_epoch' => (int)$target['erasure_epoch'] + 1], $targetRevision);
            $this->audit->record('contact.merged', $uuid, ['source_uuid' => $sourceUuid,'target_uuid' => $targetUuid,'consent' => 'withdrawn_requires_fresh_evidence','financial_history' => 'original_subject']);
            return ['uuid' => $uuid,'source_uuid' => $sourceUuid,'target_uuid' => $targetUuid,'state' => 'merged'];
        });
    }

    public function unmerge(string $mergeUuid, string $reason): array
    {
        if (trim($reason) === '' || strlen($reason) > 191) {
            throw new \InvalidArgumentException('Unmerge reason required.');
        }
        return $this->database->transaction(function () use ($mergeUuid, $reason): array {
            $merge = $this->database->get('identity_merges', $mergeUuid) ?? throw new \RuntimeException('Merge not found.');
            $ids = [(int)$merge['source_id'],(int)$merge['target_id']];
            sort($ids);
            foreach ($ids as $id) {
                $this->lock($id);
            }
            $merge = $this->database->get('identity_merges', $mergeUuid);
            if ($merge['state'] === 'unmerged') {
                return ['uuid' => $mergeUuid,'state' => 'unmerged'];
            }
            $source = $this->database->find('profiles', 'id', (int)$merge['source_id']);
            $target = $this->database->find('profiles', 'id', (int)$merge['target_id']);
            if (!$source || !$target || $source['state'] !== 'merged' || $target['state'] !== 'active') {
                throw new \RuntimeException('Erased or subsequently changed contacts cannot be restored.');
            }
            $snapshot = json_decode($this->secrets->decrypt($merge['snapshot'], 'merge-snapshot:' . $mergeUuid), true, 32, JSON_THROW_ON_ERROR);
            $transferredUserId = !empty($snapshot['source']['user_id']) && array_key_exists('target_user_id', $snapshot) && empty($snapshot['target_user_id']) ? (int)$snapshot['source']['user_id'] : null;
            if ($transferredUserId !== null && (int)$target['user_id'] !== $transferredUserId) {
                throw new \RuntimeException('Account ownership changed; manual review required.');
            }
            foreach ($snapshot['identities'] as $old) {
                $identity = $this->database->get('identities', $old['uuid']);
                if (!$identity || (int)$identity['profile_id'] !== (int)$target['id']) {
                    throw new \RuntimeException('Identity provenance changed; manual review required.');
                }$this->database->update('identities', $old['uuid'], ['profile_id' => $source['id'],'verified_at' => $old['verified_at']]);
            }
            $restore = $snapshot['source'];
            $restore['email_hash'] = $restore['email_hash'] ? base64_decode($restore['email_hash'], true) : null;
            $restore['state'] = 'active';
            $restore['erasure_epoch'] = (int)$source['erasure_epoch'] + 1;
            $targetRestore = ['erasure_epoch' => (int)$target['erasure_epoch'] + 1];
            if ($transferredUserId !== null) {
                $targetRestore['user_id'] = $snapshot['target_user_id'];
            }
            // Release the unique principal on the survivor before restoring its original subject.
            $this->database->update('profiles', $target['uuid'], $targetRestore);
            $this->database->update('profiles', $source['uuid'], $restore);
            $this->database->update('identity_merges', $mergeUuid, ['state' => 'unmerged']);
            $this->audit->record('contact.unmerged', $mergeUuid, ['reason' => $reason,'consent' => 'remains_withdrawn']);
            return ['uuid' => $mergeUuid,'state' => 'unmerged','source_uuid' => $source['uuid'],'target_uuid' => $target['uuid']];
        });
    }

    private function withdrawMergeScopes(array $profile, string $mergeUuid): void
    {
        $rows = $this->database->list('consents', ['profile_id' => $profile['id']], 1000);
        $scopes = [];
        if (count($rows) === 1000) {
            throw new \RuntimeException('Consent history limit requires review.');
        }
        foreach ($rows as $row) {
            $scopes[$row['purpose'] . ':' . $row['channel']] = [$row['purpose'],$row['channel']];
        }
        foreach ($scopes as [$purpose,$channel]) {
            $this->database->insert('consents', ['profile_id' => $profile['id'],'purpose' => $purpose,'channel' => $channel,'status' => 'withdrawn','source' => 'identity_merge','policy_version' => 'identity-merge-v1','evidence' => '{}','request_key' => hash('sha256', 'merge:' . $mergeUuid . ':' . $profile['uuid'] . ':' . $purpose . ':' . $channel),'effective_at' => Database::now()]);
        }
        $db = $this->database->db();
        $db->query($db->prepare('UPDATE ' . $this->database->table('messages') . " SET state='blocked',last_error='identity_merge',row_version=row_version+1 WHERE profile_id=%d AND state IN ('queued','held','planned')", $profile['id']));
    }

    public function findByEmail(string $email): ?array
    {
        $email = $this->normalize('email', $email);
        $primaryHash = $this->secrets->hash($email, 'email');
        $profile = $this->database->find('profiles', 'email_hash', $primaryHash);
        if ($profile) {
            return $profile;
        }
        // Privacy lookup follows exact recorded aliases. It never links accounts or grants consent.
        $identityHash = $this->secrets->hash($email, 'identity.email.store');
        $db = $this->database->db();
        $profileId = $db->get_var($db->prepare('SELECT profile_id FROM ' . $this->database->table('identities') . ' WHERE kind=%s AND namespace=%s AND value_hash=%s LIMIT 1', 'email', 'store', $identityHash));
        if ($profileId) {
            return $this->database->find('profiles', 'id', (int)$profileId);
        }
        // Erasure deletes identities in the first page. Its retained prevention hash keeps later
        // WordPress pages associated with the same erasing subject without retaining plaintext.
        $profileId = $db->get_var($db->prepare('SELECT s.profile_id FROM ' . $this->database->table('suppressions') . ' s INNER JOIN ' . $this->database->table('profiles') . " p ON p.id=s.profile_id WHERE s.identity_hash IN (%s,%s) AND s.reason=%s AND p.state IN ('erasing','erased') ORDER BY s.id DESC LIMIT 1", $primaryHash, $identityHash, 'erasure'));
        return $profileId ? $this->database->find('profiles', 'id', (int)$profileId) : null;
    }

    public function exportIdentities(string $uuid): array
    {
        $profile = $this->raw($uuid);
        return array_map(fn(array $row): array => ['uuid' => $row['uuid'], 'kind' => $row['kind'], 'namespace' => $row['namespace'], 'value' => $this->secrets->decrypt($row['value_cipher'], 'identity.' . $row['kind'] . '.' . $row['namespace']), 'verified_at' => $row['verified_at']], $this->database->list('identities', ['profile_id' => $profile['id']], 100));
    }

    public function decryptEmail(array $row): string
    {
        return empty($row['email_cipher']) ? '' : $this->secrets->decrypt($row['email_cipher'], 'contact.email');
    }

    private function normalize(string $kind, string $value): string
    {
        $value = trim($value);
        if ($kind === 'email') {
            if (!filter_var($value, FILTER_VALIDATE_EMAIL) || strlen($value) > 254) {
                throw new \InvalidArgumentException('A valid email address is required.');
            }
            return strtolower($value);
        }
        if ($kind === 'phone' && !preg_match('/^\+[1-9][0-9]{6,14}$/D', $value)) {
            throw new \InvalidArgumentException('Phone identity must be E.164.');
        }
        if ($kind === 'telegram' && !preg_match('/^-?[0-9]{1,20}$/D', $value)) {
            throw new \InvalidArgumentException('Telegram identity must be a verified numeric chat ID.');
        }
        if (!in_array($kind, ['phone', 'telegram', 'push'], true) || strlen($value) > 4096 || $value === '') {
            throw new \InvalidArgumentException('Unsupported identity.');
        }
        return $value;
    }

    private function safe(array $row): array
    {
        return ['uuid' => $row['uuid'], 'email' => $this->decryptEmail($row), 'user_id' => $row['user_id'] ? (string) $row['user_id'] : null, 'state' => $row['state'], 'attributes' => json_decode($row['attributes'] ?: '{}', true, 32, JSON_THROW_ON_ERROR), 'tags' => json_decode($row['tags'] ?: '[]', true, 32, JSON_THROW_ON_ERROR), 'row_version' => (int) $row['row_version'], 'created_at' => $row['created_at']];
    }

    private function lock(int|string $id): void
    {
        $this->database->db()->get_var($this->database->db()->prepare('SELECT id FROM ' . $this->database->table('profiles') . ' WHERE id=%d FOR UPDATE', $id));
    }
}
