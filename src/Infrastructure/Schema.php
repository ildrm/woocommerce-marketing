<?php

declare(strict_types=1);

namespace Wmos\Infrastructure;

/** Versioned plugin schema. All commerce identifiers are references, never WooCommerce storage. */
final class Schema
{
    public const VERSION = 2;

    public static function tables(): array
    {
        return [
            'profiles' => ['email_hash' => 'binary(32) NULL','email_cipher' => 'longtext NULL','user_id' => 'bigint unsigned NULL','state' => "varchar(32) NOT NULL DEFAULT 'active'",'tags' => 'longtext NOT NULL','attributes' => 'longtext NOT NULL','erasure_epoch' => 'bigint unsigned NOT NULL DEFAULT 0','order_count' => 'bigint unsigned NOT NULL DEFAULT 0','revenue_minor' => 'bigint NOT NULL DEFAULT 0','currency' => "char(3) NOT NULL DEFAULT 'USD'",'exponent' => 'int unsigned NULL','last_order_at' => 'datetime NULL'],
            'identities' => ['profile_id' => 'bigint unsigned NOT NULL','kind' => 'varchar(32) NOT NULL','namespace' => 'varchar(100) NOT NULL','value_hash' => 'binary(32) NOT NULL','value_cipher' => 'longtext NOT NULL','verified_at' => 'datetime NULL'],
            'identity_merges' => ['source_id' => 'bigint unsigned NOT NULL','target_id' => 'bigint unsigned NOT NULL','state' => 'varchar(32) NOT NULL','reason' => 'varchar(191) NOT NULL','proof' => 'longtext NOT NULL','snapshot' => 'longtext NOT NULL'],
            'identity_moves' => ['merge_id' => 'bigint unsigned NOT NULL','identity_id' => 'bigint unsigned NOT NULL','source_id' => 'bigint unsigned NOT NULL','target_id' => 'bigint unsigned NOT NULL'],
            'consents' => ['profile_id' => 'bigint unsigned NOT NULL','purpose' => 'varchar(64) NOT NULL','channel' => 'varchar(32) NOT NULL','status' => 'varchar(32) NOT NULL','source' => 'varchar(64) NOT NULL','policy_version' => 'varchar(64) NOT NULL','evidence' => 'longtext NOT NULL','request_key' => 'char(64) NOT NULL','effective_at' => 'datetime NOT NULL'],
            'suppressions' => ['profile_id' => 'bigint unsigned NULL','identity_hash' => 'binary(32) NULL','channel' => 'varchar(32) NOT NULL','purpose' => 'varchar(64) NOT NULL','reason' => 'varchar(64) NOT NULL','scope_key' => 'char(64) NOT NULL'],
            'consent_tokens' => ['profile_id' => 'bigint unsigned NOT NULL','purpose' => 'varchar(64) NOT NULL','channel' => 'varchar(32) NOT NULL','token_hash' => 'char(64) NOT NULL','policy_version' => 'varchar(64) NOT NULL','evidence' => 'longtext NOT NULL','expires_at' => 'datetime NOT NULL','consumed_at' => 'datetime NULL'],
            'definitions' => ['kind' => 'varchar(32) NOT NULL','name' => 'varchar(191) NOT NULL','state' => "varchar(32) NOT NULL DEFAULT 'draft'",'draft' => 'longtext NOT NULL','published_version_id' => 'bigint unsigned NULL','materialized_version_id' => 'bigint unsigned NULL','cancel_epoch' => 'bigint unsigned NOT NULL DEFAULT 0','generation' => 'bigint unsigned NOT NULL DEFAULT 0'],
            'definition_versions' => ['definition_id' => 'bigint unsigned NOT NULL','version' => 'bigint unsigned NOT NULL','body' => 'longtext NOT NULL','digest' => 'char(64) NOT NULL'],
            'memberships' => ['definition_id' => 'bigint unsigned NOT NULL','generation' => 'bigint unsigned NOT NULL','profile_id' => 'bigint unsigned NOT NULL'],
            'events' => ['name' => 'varchar(100) NOT NULL','source' => 'varchar(64) NOT NULL','source_key' => 'char(64) NOT NULL','profile_id' => 'bigint unsigned NULL','object_type' => 'varchar(32) NULL','object_id' => 'varchar(100) NULL','properties' => 'longtext NOT NULL','context' => 'longtext NOT NULL','occurred_at' => 'datetime NOT NULL','processed_at' => 'datetime NULL'],
            'jobs' => ['kind' => 'varchar(64) NOT NULL','operation_key' => 'char(64) NOT NULL','payload' => 'longtext NOT NULL','state' => "varchar(32) NOT NULL DEFAULT 'pending'",'attempts' => 'int unsigned NOT NULL DEFAULT 0','available_at' => 'datetime NOT NULL','lease_token' => 'char(64) NULL','lease_until' => 'datetime NULL','last_error' => 'varchar(191) NULL','expires_at' => 'datetime NULL'],
            'runs' => ['definition_id' => 'bigint unsigned NOT NULL','version_id' => 'bigint unsigned NOT NULL','profile_id' => 'bigint unsigned NULL','event_id' => 'bigint unsigned NULL','entry_key' => 'char(64) NOT NULL','state' => "varchar(32) NOT NULL DEFAULT 'pending'",'cancel_epoch' => 'bigint unsigned NOT NULL DEFAULT 0','context' => 'longtext NOT NULL'],
            'steps' => ['run_id' => 'bigint unsigned NOT NULL','node_key' => 'varchar(64) NOT NULL','activation_key' => 'char(64) NOT NULL','state' => 'varchar(32) NOT NULL','due_at' => 'datetime NULL','result' => 'longtext NULL'],
            'messages' => ['profile_id' => 'bigint unsigned NULL','channel' => 'varchar(32) NOT NULL','purpose' => 'varchar(64) NOT NULL','provider_uuid' => 'char(36) NOT NULL','state' => 'varchar(32) NOT NULL','logical_key' => 'char(64) NOT NULL','content' => 'longtext NOT NULL','content_hash' => 'char(64) NOT NULL','definition_id' => 'bigint unsigned NULL','run_id' => 'bigint unsigned NULL','provider_ref' => 'varchar(191) NULL','last_error' => 'varchar(191) NULL','scheduled_at' => 'datetime NOT NULL','attempted_at' => 'datetime NULL','accepted_at' => 'datetime NULL'],
            'providers' => ['type' => 'varchar(64) NOT NULL','name' => 'varchar(191) NOT NULL','state' => 'varchar(32) NOT NULL','configuration' => 'longtext NOT NULL','secret' => 'longtext NULL'],
            'webhook_receipts' => ['provider_id' => 'bigint unsigned NOT NULL','event_key' => 'char(64) NOT NULL','body_hash' => 'char(64) NOT NULL','payload' => 'longtext NULL','processed_at' => 'datetime NULL','attempts' => 'int unsigned NOT NULL DEFAULT 0'],
            'links' => ['slug' => 'varchar(64) NOT NULL','destination' => 'varchar(2048) NOT NULL','definition_id' => 'bigint unsigned NULL','placement_uuid' => 'char(36) NULL','dimensions' => 'longtext NOT NULL','state' => 'varchar(32) NOT NULL'],
            'touchpoints' => ['profile_id' => 'bigint unsigned NULL','session_hash' => 'char(64) NULL','link_id' => 'bigint unsigned NULL','definition_id' => 'bigint unsigned NULL','channel' => 'varchar(32) NOT NULL','occurred_at' => 'datetime NOT NULL','event_key' => 'char(64) NOT NULL'],
            'conversions' => ['order_id' => 'bigint unsigned NOT NULL','profile_id' => 'bigint unsigned NULL','state' => 'varchar(32) NOT NULL','net_minor' => 'bigint NOT NULL','currency' => 'char(3) NOT NULL','exponent' => 'int unsigned NOT NULL','paid_at' => 'datetime NOT NULL','source_digest' => 'char(64) NOT NULL'],
            'credits' => ['conversion_id' => 'bigint unsigned NOT NULL','definition_id' => 'bigint unsigned NULL','model' => 'varchar(32) NOT NULL','weight' => 'bigint NOT NULL','amount_minor' => 'bigint NOT NULL','currency' => 'char(3) NOT NULL','exponent' => 'int unsigned NOT NULL DEFAULT 2','source_digest' => 'char(64) NOT NULL','credit_key' => 'char(64) NOT NULL'],
            'costs' => ['definition_id' => 'bigint unsigned NOT NULL','currency' => 'char(3) NOT NULL','exponent' => 'int unsigned NOT NULL DEFAULT 2','amount_minor' => 'bigint NOT NULL','source_key' => 'char(64) NOT NULL','effective_at' => 'datetime NOT NULL'],
            'ledger' => ['profile_id' => 'bigint unsigned NOT NULL','program_id' => 'bigint unsigned NOT NULL','kind' => 'varchar(32) NOT NULL','points' => 'bigint NOT NULL','amount_minor' => 'bigint NULL','currency' => 'char(3) NULL','exponent' => 'int unsigned NOT NULL DEFAULT 2','operation_key' => 'char(64) NOT NULL','source_order_id' => 'bigint unsigned NULL','reverses_id' => 'bigint unsigned NULL','reason' => 'varchar(191) NULL','policy_version_id' => 'bigint unsigned NULL','expires_at' => 'datetime NULL'],
            'program_accounts' => ['profile_id' => 'bigint unsigned NOT NULL','program_id' => 'bigint unsigned NOT NULL','balance' => 'bigint NOT NULL DEFAULT 0','held' => 'bigint NOT NULL DEFAULT 0'],
            'loyalty_holds' => ['profile_id' => 'bigint unsigned NOT NULL','program_id' => 'bigint unsigned NOT NULL','points' => 'bigint unsigned NOT NULL','state' => 'varchar(32) NOT NULL','operation_key' => 'char(64) NOT NULL','order_id' => 'bigint unsigned NULL','expires_at' => 'datetime NOT NULL'],
            'loyalty_lots' => ['ledger_id' => 'bigint unsigned NOT NULL','profile_id' => 'bigint unsigned NOT NULL','program_id' => 'bigint unsigned NOT NULL','remaining' => 'bigint unsigned NOT NULL','expires_at' => 'datetime NULL'],
            'loyalty_allocations' => ['lot_id' => 'bigint unsigned NOT NULL','ledger_id' => 'bigint unsigned NULL','hold_id' => 'bigint unsigned NULL','points' => 'bigint NOT NULL','operation_key' => 'char(64) NOT NULL'],
            'referrals' => ['program_id' => 'bigint unsigned NOT NULL','referrer_id' => 'bigint unsigned NOT NULL','referee_id' => 'bigint unsigned NULL','code' => 'varchar(64) NOT NULL','state' => 'varchar(32) NOT NULL','order_id' => 'bigint unsigned NULL','policy_version_id' => 'bigint unsigned NULL','qualified_key' => 'char(64) NULL'],
            'commissions' => ['program_id' => 'bigint unsigned NOT NULL','affiliate_id' => 'bigint unsigned NOT NULL','order_id' => 'bigint unsigned NOT NULL','amount_minor' => 'bigint NOT NULL','currency' => 'char(3) NOT NULL','state' => 'varchar(32) NOT NULL','operation_key' => 'char(64) NOT NULL','paid_at' => 'datetime NULL','exponent' => 'int unsigned NOT NULL DEFAULT 2','base_minor' => 'bigint NOT NULL DEFAULT 0','policy_version_id' => 'bigint unsigned NULL','hold_until' => 'datetime NULL','reverses_id' => 'bigint unsigned NULL','payout_uuid' => 'char(36) NULL'],
            'assignments' => ['definition_id' => 'bigint unsigned NOT NULL','version_id' => 'bigint unsigned NOT NULL','profile_id' => 'bigint unsigned NULL','unit_hash' => 'char(64) NOT NULL','variant' => 'varchar(64) NOT NULL','exposed_at' => 'datetime NULL','converted_at' => 'datetime NULL','conversion_id' => 'bigint unsigned NULL'],
            'audit' => ['actor_id' => 'bigint unsigned NULL','action' => 'varchar(100) NOT NULL','object_uuid' => 'char(36) NULL','metadata' => 'longtext NOT NULL','correlation_id' => 'char(36) NOT NULL'],
            'aggregates' => ['bucket_key' => 'char(64) NOT NULL','metric' => 'varchar(64) NOT NULL','definition_id' => 'bigint unsigned NULL','currency' => 'char(3) NOT NULL','exponent' => 'int unsigned NOT NULL DEFAULT 2','day' => 'date NOT NULL','value' => 'bigint NOT NULL','count' => 'bigint unsigned NOT NULL DEFAULT 0'],
            'carts' => ['cart_key' => 'char(64) NOT NULL','profile_id' => 'bigint unsigned NULL','fingerprint' => 'char(64) NOT NULL','state' => 'varchar(32) NOT NULL','last_activity' => 'datetime NOT NULL','generation' => 'bigint unsigned NOT NULL DEFAULT 1','order_id' => 'bigint unsigned NULL','contents' => 'longtext NOT NULL','total_minor' => 'bigint NULL','currency' => 'char(3) NULL','exponent' => 'tinyint unsigned NULL'],
            'privacy_jobs' => ['profile_id' => 'bigint unsigned NOT NULL','kind' => 'varchar(32) NOT NULL','state' => 'varchar(32) NOT NULL','cursor_id' => 'bigint unsigned NOT NULL DEFAULT 0','policy' => 'longtext NOT NULL','last_error' => 'varchar(191) NULL'],
            'api_requests' => ['request_key' => 'char(64) NOT NULL','body_hash' => 'char(64) NOT NULL','actor_id' => 'bigint unsigned NOT NULL','route' => 'varchar(191) NOT NULL','state' => 'varchar(32) NOT NULL','response' => 'longtext NOT NULL','status' => 'int unsigned NOT NULL','expires_at' => 'datetime NOT NULL'],
            'recommendation_products' => ['product_id' => 'bigint unsigned NOT NULL','total_sales' => 'bigint unsigned NOT NULL DEFAULT 0','published' => 'tinyint unsigned NOT NULL DEFAULT 0','observed_at' => 'datetime NOT NULL'],
            'recommendation_order_products' => ['order_id' => 'bigint unsigned NOT NULL','product_id' => 'bigint unsigned NOT NULL','units' => 'bigint NOT NULL','occurred_at' => 'datetime NOT NULL','source_digest' => 'char(64) NOT NULL'],
        ];
    }

    public static function columns(string $table): array
    {
        $tables = self::tables();
        if (!isset($tables[$table])) {
            throw new ValidationException('Unknown table.');
        }
        return ['id' => 'bigint unsigned NOT NULL AUTO_INCREMENT','uuid' => 'char(36) NOT NULL','created_at' => 'datetime NOT NULL','updated_at' => 'datetime NOT NULL','row_version' => 'bigint unsigned NOT NULL DEFAULT 1'] + $tables[$table];
    }

    public static function indexes(): array
    {
        return [
            'profiles' => ['UNIQUE KEY email_hash (email_hash)','UNIQUE KEY user_id (user_id)','KEY state_id (state,id)','KEY order_count (order_count,id)','KEY last_order (last_order_at,id)'],
            'identities' => ['UNIQUE KEY identity_key (kind,namespace,value_hash)','KEY profile (profile_id,id)'],
            'identity_merges' => ['KEY source_state (source_id,state,id)','KEY target_state (target_id,state,id)'],
            'identity_moves' => ['UNIQUE KEY merge_identity (merge_id,identity_id)','KEY identity_id (identity_id,id)'],
            'consents' => ['UNIQUE KEY request_key (request_key)','KEY subject_scope (profile_id,purpose,channel,id)'],
            'suppressions' => ['UNIQUE KEY scope_key (scope_key)','KEY subject_scope (profile_id,channel,purpose)','KEY destination (identity_hash,channel)'],
            'consent_tokens' => ['UNIQUE KEY token_hash (token_hash)','KEY expires (expires_at,id)'],
            'definitions' => ['KEY kind_state (kind,state,id)'],
            'definition_versions' => ['UNIQUE KEY definition_version (definition_id,version)'],
            'memberships' => ['UNIQUE KEY membership (definition_id,generation,profile_id)','KEY subject (profile_id,definition_id,generation)'],
            'events' => ['UNIQUE KEY source_key (source,source_key)','KEY pending (processed_at,id)','KEY subject_time (profile_id,occurred_at,id)','KEY type_time (name,occurred_at,id)','KEY retention (created_at,id)'],
            'jobs' => ['UNIQUE KEY operation_key (operation_key)','KEY due (state,available_at,id)','KEY recovery (state,lease_until,id)','KEY retention (state,created_at,id)'],
            'runs' => ['UNIQUE KEY entry_key (entry_key)','KEY subject (profile_id,state,id)','KEY definition (definition_id,state,id)'],
            'steps' => ['UNIQUE KEY activation_key (activation_key)','KEY run_state (run_id,state,id)','KEY due (state,due_at,id)'],
            'messages' => ['UNIQUE KEY logical_key (logical_key)','KEY due (state,scheduled_at,id)','KEY subject (profile_id,created_at,id)','KEY provider_ref (provider_uuid,provider_ref)'],
            'providers' => ['KEY provider_type (type,state,id)'],
            'webhook_receipts' => ['UNIQUE KEY receipt (provider_id,event_key)','KEY retention (processed_at,created_at,id)'],
            'links' => ['UNIQUE KEY slug (slug)','KEY definition (definition_id,id)'],
            'touchpoints' => ['UNIQUE KEY event_key (event_key)','KEY subject_time (profile_id,occurred_at,id)','KEY session_time (session_hash,occurred_at,id)','KEY retention (created_at,id)'],
            'conversions' => ['UNIQUE KEY order_id (order_id)','KEY subject_paid (profile_id,paid_at,id)','KEY subject_metrics (profile_id,currency,exponent,state,paid_at,id)','KEY paid (paid_at,id)'],
            'credits' => ['UNIQUE KEY credit_key (credit_key)','KEY conversion (conversion_id,model,id)','KEY campaign (definition_id,model,id)'],
            'costs' => ['UNIQUE KEY source_key (source_key)','KEY campaign_time (definition_id,effective_at,id)'],
            'ledger' => ['UNIQUE KEY operation_key (operation_key)','KEY balance (profile_id,program_id,id)','KEY source_order (source_order_id,id)','KEY expires (expires_at,id)','KEY reverses_id (reverses_id,id)'],
            'program_accounts' => ['UNIQUE KEY account (profile_id,program_id)'],
            'loyalty_holds' => ['UNIQUE KEY operation_key (operation_key)','KEY due (state,expires_at,id)','KEY account (profile_id,program_id,state,id)'],
            'loyalty_lots' => ['UNIQUE KEY ledger_id (ledger_id)','KEY spend (profile_id,program_id,expires_at,id)'],
            'loyalty_allocations' => ['UNIQUE KEY operation_key (operation_key)','KEY lot_id (lot_id,id)','KEY hold_id (hold_id,id)'],
            'referrals' => ['UNIQUE KEY code (code)','UNIQUE KEY qualified_key (qualified_key)','KEY referrer (referrer_id,state,id)','KEY referee (referee_id,state,id)'],
            'commissions' => ['UNIQUE KEY operation_key (operation_key)','KEY review (state,hold_until,id)','KEY beneficiary (affiliate_id,state,id)','KEY order_id (order_id,id)','KEY reverses_id (reverses_id,id)','KEY payout (payout_uuid,id)'],
            'assignments' => ['UNIQUE KEY assignment (version_id,unit_hash)','KEY subject (profile_id,id)'],
            'audit' => ['KEY object (object_uuid,id)','KEY retention (created_at,id)'],
            'aggregates' => ['UNIQUE KEY bucket_key (bucket_key)','KEY report (metric,currency,day,id)'],
            'carts' => ['UNIQUE KEY cart_key (cart_key)','KEY abandoned (state,last_activity,id)','KEY subject (profile_id,id)'],
            'privacy_jobs' => ['KEY subject (profile_id,state,id)'],
            'api_requests' => ['UNIQUE KEY request_key (request_key)','KEY expires (expires_at,id)'],
            'recommendation_products' => ['UNIQUE KEY product_id (product_id)','KEY sales (published,total_sales,product_id)'],
            'recommendation_order_products' => ['UNIQUE KEY order_product (order_id,product_id)','KEY trending (occurred_at,product_id)'],
        ];
    }

    public static function install(\wpdb $wpdb): void
    {
        foreach (self::tables() as $name => $_columns) {
            self::installTable($wpdb, $name);
        }
        update_option('wmos_schema_version', self::VERSION, false);
    }

    /** One additive table migration per background action; no store-wide backfill at boot. */
    public static function installTable(\wpdb $wpdb, string $name): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $database = new Database($wpdb);
            $lines = [];
        foreach (self::columns($name) as $column => $type) {
            $lines[] = "{$column} {$type}";
        }
            $lines[] = 'PRIMARY KEY  (id)';
            $lines[] = 'UNIQUE KEY uuid (uuid)';
        foreach (self::indexes()[$name] ?? [] as $index) {
            $lines[] = $index;
        }
            $sql = 'CREATE TABLE ' . $database->table($name) . " (\n" . implode(",\n", $lines) . "\n) ENGINE=InnoDB " . $wpdb->get_charset_collate() . ';';
            dbDelta($sql);
        if ('' !== $wpdb->last_error) {
            throw new DatabaseException('Marketing schema installation failed.');
        }
            $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $database->table($name)));
        if ($engine !== 'InnoDB') {
            throw new DatabaseException('Marketing tables require InnoDB transaction support.');
        }
    }
}
