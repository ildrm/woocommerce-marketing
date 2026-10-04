<?php

declare(strict_types=1);

namespace Wmos\Application;

use Wmos\Domain\Rules;
use Wmos\Infrastructure\Audit;
use Wmos\Infrastructure\Database;
use Wmos\Infrastructure\Json;

final class Experiments
{
    public function __construct(private Database $database, private Definitions $definitions, private Audit $audit)
    {
    }

    public function assign(string $definitionUuid, string $unit, ?string $profileUuid = null, ?int $versionId = null): array
    {
        if ($unit === '' || strlen($unit) > 191) {
            throw new \InvalidArgumentException('A bounded stable experiment unit is required.');
        }
        return $this->database->transaction(function () use ($definitionUuid, $unit, $profileUuid, $versionId): array {
            $definition = $this->database->get('definitions', $definitionUuid);
            if (!$definition || $definition['kind'] !== 'experiment' || $definition['state'] !== 'running' || empty($definition['published_version_id'])) {
                throw new \DomainException('A running published experiment is required.');
            }
            $version = $this->database->find('definition_versions', 'id', $versionId ?? (int) $definition['published_version_id']);
            if (!$version || (int) $version['definition_id'] !== (int) $definition['id']) {
                throw new \DomainException('Experiment version does not belong to definition.');
            }
            $body = $this->definitions->versionBody((int) $version['id']);
            $profile = $profileUuid === null ? null : $this->database->get('profiles', $profileUuid);
            if ($profileUuid !== null && (!$profile || $profile['state'] !== 'active')) {
                throw new \DomainException('Experiment profile is unavailable.');
            }
            if (isset($body['eligibility']) && Rules::matches($body['eligibility'], $profile ?? []) !== true) {
                throw new \DomainException('Subject is not experiment eligible.');
            }
            $salt = get_option('wmos_experiment_identity_key');
            if (!is_string($salt) || !preg_match('/^[a-f0-9]{64}$/D', $salt)) {
                add_option('wmos_experiment_identity_key', bin2hex(random_bytes(32)), '', false);
                $salt = get_option('wmos_experiment_identity_key');
                if (!is_string($salt) || !preg_match('/^[a-f0-9]{64}$/D', $salt)) {
                    throw new \DomainException('Experiment identity key is unavailable.');
                }
            }
            $hash = hash_hmac('sha256', $unit, $salt . ':' . $definitionUuid);
            $wpdb = $this->database->db();
            $table = $this->database->table('assignments');
            $existingUuid = $wpdb->get_var($wpdb->prepare("SELECT uuid FROM {$table} WHERE version_id=%d AND unit_hash=%s", $version['id'], $hash));
            $existing = $existingUuid ? $this->database->get('assignments', $existingUuid) : null;
            if ($existing) {
                return $existing;
            }
            $bucket = self::bucket($hash, $body['seed'] ?? $version['digest']);
            $variant = self::variant($bucket, $body['variants']);
            try {
                $assignment = $this->database->insert('assignments', ['definition_id' => $definition['id'], 'version_id' => $version['id'], 'profile_id' => $profile['id'] ?? null, 'unit_hash' => $hash, 'variant' => $variant]);
            } catch (\Wmos\Infrastructure\DatabaseException $error) {
                $existingUuid = $wpdb->get_var($wpdb->prepare("SELECT uuid FROM {$table} WHERE version_id=%d AND unit_hash=%s", $version['id'], $hash));
                $assignment = $existingUuid ? $this->database->get('assignments', $existingUuid) : null;
                if (!$assignment) {
                    throw $error;
                }
            }
            $this->audit->record('experiment.assigned', $assignment['uuid'], ['definition_uuid' => $definitionUuid, 'variant' => $variant]);
            return $assignment;
        });
    }

    public static function bucket(string $unitHash, string $seed): int
    {
        return (int) (hexdec(substr(hash_hmac('sha256', $unitHash, $seed), 0, 8)) % 10000);
    }

    public static function variant(int $bucket, array $variants): string
    {
        if ($bucket < 0 || $bucket >= 10000) {
            throw new \InvalidArgumentException('Invalid experiment bucket.');
        }
        $upper = 0;
        foreach ($variants as $variant) {
            $upper += (int) $variant['weight'];
            if ($bucket < $upper) {
                return $variant['key'];
            }
        }
        throw new \InvalidArgumentException('Experiment weights do not cover assignment.');
    }

    public function expose(string $assignmentUuid): array
    {
        $row = $this->database->get('assignments', $assignmentUuid) ?? throw new \DomainException('Assignment not found.');
        if ($row['exposed_at'] !== null) {
            return $row;
        }
        return $this->database->update('assignments', $assignmentUuid, ['exposed_at' => Database::now()], (int) $row['row_version']);
    }

    public function results(string $definitionUuid): array
    {
        $definition = $this->database->get('definitions', $definitionUuid);
        if (!$definition || $definition['kind'] !== 'experiment') {
            throw new \DomainException('Experiment not found.');
        }
        $wpdb = $this->database->db();
        $table = $this->database->table('assignments');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT version_id,variant,COUNT(*) AS assigned,SUM(exposed_at IS NOT NULL) AS exposed,SUM(converted_at IS NOT NULL AND exposed_at IS NOT NULL) AS converted FROM {$table} WHERE definition_id=%d GROUP BY version_id,variant", $definition['id']), ARRAY_A) ?: [];
        foreach ($rows as &$row) {
            $n = (int) $row['exposed'];
            $k = (int) $row['converted'];
            $row['conversion_rate'] = $n > 0 ? $k / $n : null;
            $row['wilson_95'] = self::wilson($k, $n);
        }
        unset($row);
        return ['definition_uuid' => $definitionUuid, 'variants' => $rows, 'interpretation' => 'Exploratory estimates. No automatic winner or statistical significance claim.', 'denominator' => 'Exposed unique assigned units; a qualifying conversion is credited once, after exposure.'];
    }

    public static function wilson(int $successes, int $n): ?array
    {
        if ($n < 0 || $successes < 0 || $successes > $n) {
            throw new \InvalidArgumentException('Invalid experiment counts.');
        }
        if ($n === 0) {
            return null;
        }
        $z = 1.959963984540054;
        $p = $successes / $n;
        $z2 = $z * $z;
        $center = ($p + $z2 / (2 * $n)) / (1 + $z2 / $n);
        $radius = $z * sqrt(($p * (1 - $p) + $z2 / (4 * $n)) / $n) / (1 + $z2 / $n);
        return ['lower' => max(0.0, $center - $radius), 'upper' => min(1.0, $center + $radius)];
    }
}
