<?php

declare(strict_types=1);

namespace Wmos\Infrastructure;

final class Audit
{
    private const SAFE_KEYS = ['kind','state','reason','count','channel','purpose','version','provider_type','error_class','operation_uuid','job_uuid','definition_uuid','policy_version','correlation_id','currency','amount_minor','points','source','verified','evidence_digest','digest','from','to','exponent','credential_changed','external_removal','financial_retained'];

    public function __construct(private readonly Database $database)
    {
    }

    public function record(string $action, ?string $objectUuid = null, array $metadata = []): void
    {
        $safe = array_intersect_key($metadata, array_flip(self::SAFE_KEYS));
        foreach ($safe as $key => $value) {
            if (!is_scalar($value) && null !== $value) {
                unset($safe[$key]);
                continue;
            }
            if (is_string($value)) {
                $safe[$key] = substr($value, 0, 191);
            }
        }
        $this->database->insert('audit', [
            'actor_id' => function_exists('get_current_user_id') ? get_current_user_id() ?: null : null,
            'action' => substr($action, 0, 100),'object_uuid' => $objectUuid,
            'metadata' => Json::encode($safe),'correlation_id' => Database::uuid(),
        ]);
    }
}
