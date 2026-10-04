<?php

declare(strict_types=1);

namespace Wmos\Rest;

use Throwable;
use Wmos\Infrastructure\Database;
use Wmos\Infrastructure\ConflictException;

/** Explicit resource adapters; application services retain domain invariants. */
final class Api
{
    private const RESOURCES = [
        'campaigns' => ['campaign', 'manage_campaigns', 'publish_campaigns'],
        'automations' => ['automation', 'manage_automations', 'publish_automations'],
        'segments' => ['segment', 'manage_campaigns', 'manage_campaigns'],
        'promotions' => ['promotion', 'manage_promotions', 'manage_promotions'],
        'programs' => ['program', 'manage_partners', 'manage_partners'],
        'experiments' => ['experiment', 'manage_campaigns', 'publish_campaigns'],
        'assets' => ['asset', 'manage_campaigns', 'publish_campaigns'],
        'offline' => ['offline', 'manage_campaigns', 'publish_campaigns'],
        'marketing-events' => ['event', 'manage_campaigns', 'publish_campaigns'],
        'influencers' => ['influencer', 'manage_partners', 'manage_partners'],
        'content' => ['content', 'manage_campaigns', 'publish_campaigns'],
        'partners' => ['partner', 'manage_partners', 'manage_partners'],
        'personalization' => ['personalization', 'manage_campaigns', 'publish_campaigns'],
        'recommendations' => ['recommendation', 'manage_campaigns', 'publish_campaigns'],
    ];

    public function __construct(private Database $database, private array $services)
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'routes']);
        add_filter('rest_post_dispatch', static function ($response, $server, $request) {
            if (str_starts_with($request->get_route(), '/wmos/v1/')) {
                $response->header('Cache-Control', 'private, no-store');
                $response->header('X-Content-Type-Options', 'nosniff');
            }
            return $response;
        }, 10, 3);
        add_filter('rest_pre_serve_request', static function ($served, $response, $request) {
            if (in_array($request->get_route(), ['/wmos/v1/subscriptions/confirm', '/wmos/v1/unsubscribe'], true) && is_string($response->get_data())) {
                echo $response->get_data(); // HTML consists solely of fixed markup and escaped token/URL.
                return true;
            }
            return $served;
        }, 10, 3);
    }

    public function routes(): void
    {
        foreach (self::RESOURCES as $resource => [$kind, $capability, $publish]) {
            $this->route('/' . $resource, 'GET', $capability, $this->pagination(), fn($r) => $this->collection(
                $this->service('definitions')->list($kind, (int) $r['per_page'] + 1, $this->cursor($r['cursor'], $resource)),
                $resource,
                (int) $r['per_page'],
                fn($row) => $this->definition($row)
            ));
            $this->route('/' . $resource, 'POST', $capability, [
                'name' => $this->string(191, true),
                'body' => $this->object(true),
            ], fn($r) => $this->created($this->definition($this->service('definitions')->create($kind, $r['name'], $this->readableBody($r['body'])))), true);
            $path = '/' . $resource . '/(?P<uuid>[a-f0-9-]{36})';
            $this->route($path, 'GET', $capability, ['uuid' => $this->uuid()], fn($r) => $this->definition($this->definitionOf($r['uuid'], $kind)));
            $this->route($path, 'PUT', $capability, ['uuid' => $this->uuid(), 'body' => $this->object(true)], function ($r) use ($kind) {
                $this->definitionOf($r['uuid'], $kind);
                return $this->definition($this->service('definitions')->save($r['uuid'], $this->readableBody($r['body']), $this->revision($r)));
            }, true);
            $this->route($path . '/publish', 'POST', $publish, ['uuid' => $this->uuid()], function ($r) use ($kind) {
                $this->definitionOf($r['uuid'], $kind);
                return $this->definition($this->service('definitions')->publish($r['uuid'], $this->revision($r)));
            }, true);
            $this->route($path . '/transition', 'POST', $publish, ['uuid' => $this->uuid(), 'state' => ['type' => 'string', 'required' => true, 'enum' => ['running', 'paused', 'cancelled', 'completed', 'archived', 'scheduled', 'active', 'enabled']]], function ($r) use ($kind) {
                $this->definitionOf($r['uuid'], $kind);
                return $this->definition($this->service('definitions')->transition($r['uuid'], $r['state'], $this->revision($r)));
            }, true);
        }
        $this->contactsRoutes();
        $this->channelRoutes();
        $this->executionRoutes();
        $this->operatorRoutes();
    }

    private function contactsRoutes(): void
    {
        $this->route('/contacts', 'GET', 'view_contacts', $this->pagination(), fn($r) => $this->collection(
            $this->service('contacts')->list((int) $r['per_page'] + 1, $this->cursor($r['cursor'], 'contacts')),
            'contacts',
            (int) $r['per_page'],
            fn($row) => $this->contact($row)
        ));
        $this->route('/contacts', 'POST', 'manage_contacts', ['email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 254, 'required' => true]], fn($r) => $this->created($this->contact($this->service('contacts')->create($r['email']))), true);
        $path = '/contacts/(?P<uuid>[a-f0-9-]{36})';
        $this->route($path, 'GET', 'view_contacts', ['uuid' => $this->uuid()], fn($r) => $this->contact($this->service('contacts')->get($r['uuid'])));
        $this->route($path, 'PUT', 'manage_contacts', ['uuid' => $this->uuid(), 'attributes' => $this->object(true), 'tags' => ['type' => 'array', 'required' => true, 'maxItems' => 50, 'items' => ['type' => 'string', 'maxLength' => 64]]], fn($r) => $this->contact($this->service('contacts')->update($r['uuid'], $r['attributes'], $r['tags'], $this->revision($r))), true);
        $this->route($path . '/identities', 'POST', 'manage_contacts', ['uuid' => $this->uuid(), 'kind' => ['type' => 'string', 'enum' => ['email', 'phone', 'telegram', 'push'], 'required' => true], 'value' => $this->string(4096, true), 'verified' => ['type' => 'boolean', 'default' => false], 'evidence_reference' => $this->string(191, true)], function ($r) {
            $result = $this->service('contacts')->addIdentity($r['uuid'], $r['kind'], $r['value'], 'store', $r['verified']);
            $this->service('audit')->record('identity.evidence_recorded', $r['uuid'], ['kind' => $r['kind'], 'verified' => $r['verified'], 'evidence_digest' => hash('sha256', $r['evidence_reference'])]);
            return $result;
        }, true);
        $this->route($path . '/merge', 'POST', 'manage_contacts', ['uuid' => $this->uuid(), 'target_uuid' => $this->uuid(), 'reason' => $this->string(191, true), 'proof' => $this->object(true), 'target_revision' => ['type' => 'integer', 'minimum' => 1, 'required' => true]], fn($r) => $this->service('contacts')->merge($r['uuid'], $r['target_uuid'], $r['reason'], $r['proof'], $this->revision($r), (int) $r['target_revision']), true);
        $this->route('/identity-merges/(?P<uuid>[a-f0-9-]{36})/undo', 'POST', 'manage_contacts', ['uuid' => $this->uuid(), 'reason' => $this->string(191, true)], fn($r) => $this->service('contacts')->unmerge($r['uuid'], $r['reason']), true);
        $this->route($path . '/consents', 'GET', 'view_contacts', ['uuid' => $this->uuid()], fn($r) => $this->service('consent')->history($r['uuid']));
        $consent = ['uuid' => $this->uuid(), 'purpose' => $this->string(64, true), 'channel' => ['type' => 'string', 'enum' => ['email', 'sms', 'push', 'whatsapp', 'telegram', 'analytics', 'ads', 'social', 'webhook', 'personalization', 'profiling', '*'], 'required' => true]];
        $this->route($path . '/consents/grant', 'POST', 'manage_consent', $consent + ['policy' => $this->string(64, true), 'evidence' => $this->object(true)], fn($r) => $this->service('consent')->grant($r['uuid'], $r['purpose'], $r['channel'], 'admin', $r['policy'], $r['evidence'], $this->operation($r)), true);
        $this->route($path . '/consents/withdraw', 'POST', 'manage_consent', $consent, fn($r) => $this->service('consent')->withdraw($r['uuid'], $r['purpose'], $r['channel'], $this->operation($r)), true);
        $this->route($path . '/consents/request-confirmation', 'POST', 'manage_consent', $consent + ['policy' => $this->string(64, true), 'evidence' => $this->object(true)], fn($r) => $this->service('consent')->requestDoubleOptIn($r['uuid'], $r['purpose'], $r['channel'], $r['policy'], $r['evidence']), true);
        $this->route($path . '/privacy/export', 'POST', 'manage_privacy', ['uuid' => $this->uuid(), 'cursors' => ['type' => 'object', 'properties' => array_fill_keys(['consents', 'messages', 'events', 'touchpoints', 'ledger', 'program_accounts'], ['type' => 'integer', 'minimum' => 0]), 'additionalProperties' => false, 'default' => []]], fn($r) => $this->service('privacy')->export($r['uuid'], $r['cursors']));
        $this->route($path . '/privacy/erase', 'POST', 'manage_privacy', ['uuid' => $this->uuid()], fn($r) => $this->accepted($this->service('privacy')->erase($r['uuid'])), true);
        $this->route('/privacy-jobs/(?P<uuid>[a-f0-9-]{36})', 'GET', 'manage_privacy', ['uuid' => $this->uuid()], fn($r) => $this->service('privacy')->job($r['uuid']));
        $this->route('/subscriptions/confirm', 'POST', null, ['token' => $this->string(512, true)], fn($r) => $this->service('consent')->confirm($r['token']));
        $this->route('/subscriptions/confirm', 'GET', null, ['token' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'required' => true]], function ($r) {
            $html = '<!doctype html><html lang="' . esc_attr(get_bloginfo('language')) . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html__('Confirm your subscription', 'woocommerce-marketing-os') . '</title><body><main><h1>' . esc_html__('Confirm your subscription', 'woocommerce-marketing-os') . '</h1><p>' . esc_html__('Press Confirm to record the subscription choice you requested. Opening this link does not change your permission.', 'woocommerce-marketing-os') . '</p><form method="post" action="' . esc_url(rest_url('wmos/v1/subscriptions/confirm')) . '"><input type="hidden" name="token" value="' . esc_attr($r['token']) . '"><button type="submit">' . esc_html__('Confirm subscription', 'woocommerce-marketing-os') . '</button></form></main></body></html>';
            $response = new \WP_REST_Response($html, 200);
            $response->header('Content-Type', 'text/html; charset=utf-8');
            $response->header('Referrer-Policy', 'no-referrer');
            $response->header('X-Robots-Tag', 'noindex, nofollow');
            $response->header('Content-Security-Policy', "default-src 'none'; form-action 'self'; frame-ancestors 'none'");
            return $response;
        });
    }

    private function channelRoutes(): void
    {
        $this->route('/channel-providers', 'GET', 'manage_campaigns', $this->pagination(), fn($r) => $this->collection($this->service('messaging')->providerList((int) $r['per_page'] + 1, $this->cursor($r['cursor'], 'channel-providers')), 'channel-providers', (int) $r['per_page'], fn($p) => $this->only($p, ['uuid', 'name', 'type', 'state', 'capabilities'])));
        $this->route('/providers', 'GET', 'manage_integrations', $this->pagination(), fn($r) => $this->collection($this->service('messaging')->providerList((int) $r['per_page'] + 1, $this->cursor($r['cursor'], 'providers')), 'providers', (int) $r['per_page'], fn($p) => $this->provider($p)));
        $provider = ['type' => $this->string(64, true), 'name' => $this->string(191, true), 'configuration' => $this->object(true), 'secret' => ['type' => ['string', 'null'], 'maxLength' => 16384, 'default' => null]];
        $this->route('/providers', 'POST', 'manage_integrations', $provider, fn($r) => $this->created($this->provider($this->service('messaging')->saveProvider($r['type'], $r['name'], $r['configuration'], $r['secret']))), true);
        $this->route('/providers/(?P<uuid>[a-f0-9-]{36})', 'PUT', 'manage_integrations', ['uuid' => $this->uuid()] + $provider, fn($r) => $this->provider($this->service('messaging')->saveProvider($r['type'], $r['name'], $r['configuration'], $r['secret'], $r['uuid'], $this->revision($r))), true);
        $this->route('/providers/(?P<uuid>[a-f0-9-]{36})/capabilities', 'GET', 'manage_integrations', ['uuid' => $this->uuid()], function ($r) {
            $provider = $this->database->get('providers', $r['uuid']) ?? throw new \DomainException('Provider not found.');
            return $this->service('messaging')->capabilities($provider['type']);
        });
        $this->route('/messages', 'GET', 'manage_campaigns', $this->pagination(), fn($r) => $this->collection($this->service('messaging')->list((int) $r['per_page'] + 1, $this->cursor($r['cursor'], 'messages')), 'messages', (int) $r['per_page'], fn($row) => $this->message($row)));
        $this->route('/messages', 'POST', 'manage_campaigns', ['profile_uuid' => $this->uuid(), 'provider_uuid' => $this->uuid(), 'channel' => $this->string(32, true), 'purpose' => $this->string(64, true), 'content' => $this->object(true)], fn($r) => $this->created($this->message($this->service('messaging')->plan($r['profile_uuid'], $r['channel'], $r['provider_uuid'], $r['content'], $this->operation($r), $r['purpose']))), true);
        $this->route('/webhooks/(?P<uuid>[a-f0-9-]{36})', 'POST', null, ['uuid' => $this->uuid()], function ($r) {
            if (strlen($r->get_body()) > 131072) {
                return new \WP_Error('wmos_payload_too_large', __('Webhook body exceeds 128 KiB.', 'woocommerce-marketing-os'), ['status' => 413]);
            }
            $headers = array_map(static fn($values) => is_array($values) ? implode(',', $values) : $values, $r->get_headers());
            return $this->service('messaging')->callback($r['uuid'], $r->get_body(), $headers, rest_url(ltrim($r->get_route(), '/')));
        });
        $this->route('/unsubscribe', 'POST', null, ['token' => $this->string(2048, true), 'List-Unsubscribe' => ['type' => 'string', 'enum' => ['One-Click']]], fn($r) => $this->service('messaging')->unsubscribe($r['token']));
        $this->route('/unsubscribe', 'GET', null, ['token' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]+$', 'maxLength' => 2048, 'required' => true]], fn($r) => $this->landing(__('Unsubscribe from marketing', 'woocommerce-marketing-os'), __('Press Unsubscribe to withdraw the purpose and channel permission carried by this link. Opening the link does not change your choice.', 'woocommerce-marketing-os'), 'unsubscribe', $r['token']));
    }

    private function executionRoutes(): void
    {
        $this->route('/segments/preview', 'POST', 'manage_campaigns', ['rule' => $this->object(true), 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25]], fn($r) => $this->service('segments')->preview($r['rule'], (int) $r['per_page']));
        $this->route('/segments/(?P<uuid>[a-f0-9-]{36})/rebuild', 'POST', 'manage_campaigns', ['uuid' => $this->uuid()], fn($r) => $this->accepted($this->job($this->service('segments')->rebuild($r['uuid']))), true);
        $this->route('/personalization/(?P<uuid>[a-f0-9-]{36})/preview', 'POST', 'manage_campaigns', ['uuid' => $this->uuid(), 'profile_uuid' => ['type' => ['string', 'null'], 'format' => 'uuid', 'default' => null]], fn($r) => $this->service('personalization')->resolve($r['uuid'], $r['profile_uuid']));
        $this->route('/recommendations/refresh', 'POST', 'manage_campaigns', [], fn() => $this->accepted($this->job($this->service('recommendations')->refresh())), true);
        $this->route('/automations/(?P<uuid>[a-f0-9-]{36})/enter', 'POST', 'run_automations', ['uuid' => $this->uuid(), 'profile_uuid' => $this->uuid(), 'event_uuid' => ['type' => ['string', 'null'], 'default' => null]], fn($r) => $this->created($this->run($this->service('automation')->enter($r['uuid'], $r['profile_uuid'], $r['event_uuid'], $this->operation($r)))), true);
        $this->route('/runs', 'GET', 'manage_automations', $this->pagination(), fn($r) => $this->collection($this->database->list('runs', [], (int) $r['per_page'] + 1, $this->cursor($r['cursor'], 'runs')), 'runs', (int) $r['per_page'], fn($row) => $this->run($row)));
        $this->route('/runs/(?P<uuid>[a-f0-9-]{36})/cancel', 'POST', 'manage_automations', ['uuid' => $this->uuid()], fn($r) => $this->run($this->service('automation')->cancel($r['uuid'])), true);
        $this->route('/experiments/(?P<uuid>[a-f0-9-]{36})/results', 'GET', 'view_analytics', ['uuid' => $this->uuid()], fn($r) => $this->service('experiments')->results($r['uuid']));
        $this->route('/experiments/(?P<uuid>[a-f0-9-]{36})/assign', 'POST', 'manage_campaigns', ['uuid' => $this->uuid(), 'unit' => $this->string(191, true), 'profile_uuid' => ['type' => ['string', 'null'], 'default' => null]], fn($r) => $this->service('experiments')->assign($r['uuid'], $r['unit'], $r['profile_uuid']), true);
        $this->route('/assignments/(?P<uuid>[a-f0-9-]{36})/expose', 'POST', 'manage_campaigns', ['uuid' => $this->uuid()], fn($r) => $this->service('experiments')->expose($r['uuid']), true);
        $this->route('/programs/(?P<uuid>[a-f0-9-]{36})/balance', 'GET', 'manage_partners', ['uuid' => $this->uuid(), 'profile_uuid' => $this->uuid()], fn($r) => $this->service('programs')->balance($r['profile_uuid'], $r['uuid']));
        $points = ['uuid' => $this->uuid(), 'profile_uuid' => $this->uuid(), 'points' => ['type' => 'integer', 'required' => true, 'minimum' => 1, 'maximum' => 1000000000]];
        $this->route('/programs/(?P<uuid>[a-f0-9-]{36})/earn', 'POST', 'adjust_rewards', $points, fn($r) => $this->service('programs')->earn($r['profile_uuid'], $r['uuid'], (int) $r['points'], $this->operation($r)), true);
        $this->route('/programs/(?P<uuid>[a-f0-9-]{36})/reserve', 'POST', 'manage_partners', $points, fn($r) => $this->service('programs')->reserve($r['profile_uuid'], $r['uuid'], (int) $r['points'], $this->operation($r)), true);
        $this->route('/rewards/(?P<uuid>[a-f0-9-]{36})/redeem', 'POST', 'manage_partners', ['uuid' => $this->uuid()], fn($r) => $this->service('programs')->redeem($r['uuid'], $this->operation($r)), true);
        $this->route('/rewards/(?P<uuid>[a-f0-9-]{36})/release', 'POST', 'manage_partners', ['uuid' => $this->uuid()], fn($r) => $this->service('programs')->release($r['uuid'], $this->operation($r)), true);
        $this->route('/programs/(?P<uuid>[a-f0-9-]{36})/referrals', 'POST', 'manage_partners', ['uuid' => $this->uuid(), 'profile_uuid' => $this->uuid()], fn($r) => $this->service('programs')->createReferral($r['uuid'], $r['profile_uuid']), true);
        $this->route('/referrals/claim', 'POST', 'manage_partners', ['code' => $this->string(64, true), 'profile_uuid' => $this->uuid()], fn($r) => $this->service('programs')->claimReferral($r['code'], $r['profile_uuid']), true);
        $this->route('/commissions/(?P<uuid>[a-f0-9-]{36})/approve', 'POST', 'manage_payouts', ['uuid' => $this->uuid()], fn($r) => $this->service('programs')->approveCommission($r['uuid']), true);
        $this->route('/programs/(?P<uuid>[a-f0-9-]{36})/commissions', 'POST', 'manage_partners', ['uuid' => $this->uuid(), 'affiliate_uuid' => $this->uuid(), 'order_id' => ['type' => 'integer', 'minimum' => 1, 'required' => true], 'base_minor' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000000000, 'required' => true], 'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$', 'required' => true]], fn($r) => $this->service('programs')->recordCommission($r['uuid'], $r['affiliate_uuid'], (int) $r['order_id'], (int) $r['base_minor'], $r['currency'], $this->operation($r)), true);
        $this->route('/payouts/confirm', 'POST', 'manage_payouts', ['commission_uuids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'uniqueItems' => true, 'items' => $this->uuid()], 'external_reference' => $this->string(191, true)], fn($r) => $this->service('programs')->markPayout($r['commission_uuids'], $r['external_reference'], $this->operation($r)), true);
        $this->route('/programs/(?P<uuid>[a-f0-9-]{36})/adjust', 'POST', 'adjust_rewards', ['uuid' => $this->uuid(), 'profile_uuid' => $this->uuid(), 'points' => ['type' => 'integer', 'minimum' => -1000000000, 'maximum' => 1000000000, 'required' => true], 'reason' => $this->string(191, true)], fn($r) => $this->service('programs')->adjust($r['profile_uuid'], $r['uuid'], (int) $r['points'], $r['reason'], $this->operation($r)), true);
        $this->route('/promotions/(?P<uuid>[a-f0-9-]{36})/issue', 'POST', 'manage_promotions', ['uuid' => $this->uuid(), 'profile_uuid' => $this->uuid()], fn($r) => $this->service('promotions')->issue($r['uuid'], $r['profile_uuid'], $this->operation($r)), true);
        foreach (['ledger' => 'manage_partners', 'referrals' => 'manage_partners', 'commissions' => 'manage_partners'] as $table => $capability) {
            $this->route('/' . $table, 'GET', $capability, $this->pagination(), fn($r) => $this->collection($this->database->list($table, [], (int) $r['per_page'] + 1, $this->cursor($r['cursor'], $table)), $table, (int) $r['per_page'], fn($row) => $this->financial($row, $table)));
        }
    }

    private function operatorRoutes(): void
    {
        $this->route('/links', 'GET', 'manage_campaigns', $this->pagination(), fn($r) => $this->collection($this->service('tracking')->list((int) $r['per_page'] + 1, $this->cursor($r['cursor'], 'links')), 'links', (int) $r['per_page'], fn($row) => $this->only($row, ['uuid', 'slug', 'destination', 'url', 'state', 'created_at', 'row_version'])));
        $this->route('/links', 'POST', 'manage_campaigns', ['destination' => ['type' => 'string', 'format' => 'uri', 'maxLength' => 2048, 'required' => true], 'definition_uuid' => ['type' => ['string', 'null'], 'default' => null], 'placement_uuid' => ['type' => ['string', 'null'], 'default' => null], 'dimensions' => $this->object(true)], fn($r) => $this->created($this->only($this->service('tracking')->create($r['destination'], $r['definition_uuid'], $r['dimensions'], $r['placement_uuid']), ['uuid', 'slug', 'url', 'state', 'row_version'])), true);
        $this->route('/costs', 'POST', 'manage_campaigns', ['definition_uuid' => $this->uuid(), 'minor' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000000000, 'required' => true], 'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$', 'required' => true], 'exponent' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 4, 'required' => true], 'effective_at' => ['type' => ['string', 'null'], 'format' => 'date-time', 'default' => null]], fn($r) => $this->service('measurement')->recordCost($r['definition_uuid'], (int) $r['minor'], $r['currency'], (int) $r['exponent'], $this->operation($r), $r['effective_at']), true);
        $this->route('/reports', 'GET', 'view_analytics', ['from' => ['type' => 'string', 'format' => 'date', 'default' => gmdate('Y-m-d', time() - 30 * DAY_IN_SECONDS)], 'to' => ['type' => 'string', 'format' => 'date', 'default' => gmdate('Y-m-d')], 'model' => ['type' => 'string', 'enum' => ['first_touch', 'last_touch', 'last_non_direct', 'linear', 'time_decay', 'position_based', 'custom'], 'default' => 'last_touch']], fn($r) => $this->service('measurement')->report(['from' => $r['from'], 'to' => $r['to'], 'model' => $r['model']]));
        $this->route('/attribution/configuration', 'GET', 'manage_settings', [], fn() => (array) get_option('wmos_custom_attribution', ['default_weight' => 1, 'channel_weights' => []]));
        $this->route('/attribution/configuration', 'PUT', 'manage_settings', ['default_weight' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000, 'required' => true], 'channel_weights' => ['type' => 'object', 'maxProperties' => 20, 'additionalProperties' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000000], 'required' => true]], function ($r) {
            $this->service('measurement')->configureCustomModel((int) $r['default_weight'], $r['channel_weights']);
            return ['accepted' => true];
        }, true);
        $this->route('/health', 'GET', 'view_health', [], fn() => is_callable($this->services['health'] ?? null) ? ($this->services['health'])() : ['queue' => $this->queueCounts(), 'schema_version' => (string) get_option('wmos_schema_version', ''), 'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON]);
        $this->route('/jobs', 'GET', 'view_health', $this->pagination(), fn($r) => $this->collection($this->database->list('jobs', [], (int) $r['per_page'] + 1, $this->cursor($r['cursor'], 'jobs')), 'jobs', (int) $r['per_page'], fn($row) => $this->job($row)));
        $this->route('/jobs/(?P<uuid>[a-f0-9-]{36})/retry', 'POST', 'operate_queue', ['uuid' => $this->uuid()], function ($r) {
            return $this->job($this->service('queue')->retry($r['uuid']));
        }, true);
        $this->route('/audit', 'GET', 'view_health', $this->pagination(), fn($r) => $this->collection($this->database->list('audit', [], (int) $r['per_page'] + 1, $this->cursor($r['cursor'], 'audit')), 'audit', (int) $r['per_page'], fn($row) => $this->only($row, ['uuid', 'action', 'object_uuid', 'created_at', 'correlation_id'])));
        $this->route('/settings', 'GET', 'manage_settings', [], fn() => $this->settings());
        $this->route('/settings', 'PUT', 'manage_settings', [
            'tracking_enabled' => ['type' => 'boolean', 'required' => true],
            'retention_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3650, 'required' => true],
            'time_zone' => $this->string(64, true),
            'commerce_enabled' => ['type' => 'boolean', 'required' => true],
            'consent_policy_version' => $this->string(64, true),
            'financial_retention_days' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 36500, 'required' => true],
            'sender_email' => ['type' => 'string', 'maxLength' => 254, 'default' => ''],
            'sender_name' => ['type' => 'string', 'maxLength' => 191, 'default' => ''],
            'telemetry_enabled' => ['type' => 'boolean', 'default' => false],
            'enabled_modules' => ['type' => 'array', 'maxItems' => 30, 'uniqueItems' => true, 'items' => ['type' => 'string', 'enum' => ['messaging', 'campaigns', 'automations', 'segments', 'tracking', 'measurement', 'consent', 'privacy', 'ads', 'email', 'sms', 'push', 'whatsapp', 'telegram', 'social', 'webhook', 'promotions', 'programs', 'experiments', 'offline', 'content', 'personalization', 'recommendations']], 'default' => []],
        ], function ($r) {
            if (!in_array($r['time_zone'], timezone_identifiers_list(), true) && $r['time_zone'] !== 'UTC') {
                throw new \InvalidArgumentException('Choose a supported IANA timezone.');
            }
            // Serialize only this plugin's revision option; WordPress owns option writes.
            add_option('wmos_settings_revision', 1, '', false);
            $db = $this->database->db();
            $lockedRevision = (int) $db->get_var($db->prepare("SELECT option_value FROM {$db->options} WHERE option_name=%s FOR UPDATE", 'wmos_settings_revision'));
            if ($this->revision($r) !== $lockedRevision) {
                throw new \DomainException('Settings revision conflict. Refresh before saving.');
            }
            if ($r['sender_email'] !== '' && !is_email($r['sender_email'])) {
                throw new \InvalidArgumentException('Sender email is invalid.');
            }
            $settings = [];
            foreach (['tracking_enabled', 'retention_days', 'time_zone', 'commerce_enabled', 'consent_policy_version', 'financial_retention_days', 'sender_email', 'sender_name', 'enabled_modules', 'telemetry_enabled'] as $field) {
                $settings[$field] = $r[$field];
            }
            update_option('wmos_settings', $settings, false);
            update_option('wmos_settings_revision', $this->revision($r) + 1, false);
            $this->service('audit')->record('settings.updated', null, ['fields' => array_keys($settings)]);
            return $this->settings();
        }, true);
        $this->route('/definitions/export', 'POST', 'manage_settings', ['uuid' => $this->uuid()], function ($r) {
            $row = $this->service('definitions')->get($r['uuid']);
            return ['schema_version' => 1, 'kind' => $row['kind'], 'name' => $row['name'], 'body' => $this->definitionBody($this->decode($row['draft']))];
        });
        $this->route('/definitions/import', 'POST', 'manage_settings', ['schema_version' => ['type' => 'integer', 'enum' => [1], 'required' => true], 'kind' => ['type' => 'string', 'enum' => array_column(self::RESOURCES, 0), 'required' => true], 'name' => $this->string(191, true), 'body' => $this->object(true)], fn($r) => $this->created($this->definition($this->service('definitions')->create($r['kind'], $r['name'], $this->readableBody($r['body'])))), true);
    }

    private function route(string $path, string $method, ?string $capability, array $args, callable $callback, bool $idempotent = false): void
    {
        foreach ($args as &$argument) {
            $argument['validate_callback'] = static fn($value, $request, $key) => rest_validate_value_from_schema($value, $request->get_attributes()['args'][$key], $key);
            $argument['sanitize_callback'] = static fn($value, $request, $key) => rest_sanitize_value_from_schema($value, $request->get_attributes()['args'][$key], $key);
        }
        unset($argument);
        register_rest_route('wmos/v1', $path, [
            'methods' => $method,
            'permission_callback' => fn($request) => $capability === null ? true : (current_user_can('wmos_' . $capability) ? true : new \WP_Error('wmos_forbidden', __('You cannot perform this marketing operation.', 'woocommerce-marketing-os'), ['status' => is_user_logged_in() ? 403 : 401])),
            'args' => $args,
            'callback' => function ($request) use ($callback, $idempotent, $path) {
                $correlation = Database::uuid();
                try {
                    if (strlen((string) $request->get_body()) > 262144) {
                        return new \WP_Error('wmos_payload_too_large', __('Request exceeds 256 KiB.', 'woocommerce-marketing-os'), ['status' => 413, 'correlation_id' => $correlation]);
                    }
                    $known = array_keys($request->get_attributes()['args']);
                    $pathFields = array_keys($request->get_url_params());
                    foreach (array_keys($request->get_json_params() ?? []) as $field) {
                        if (str_starts_with($path, '/webhooks/')) {
                            break; // Provider verifies bounded raw payload against its own protocol schema.
                        }
                        if (in_array($field, $pathFields, true) || !in_array($field, $known, true)) {
                            throw new \InvalidArgumentException('Unknown request field: ' . sanitize_key($field));
                        }
                    }
                    $response = $idempotent ? $this->idempotent($request, $callback) : $callback($request);
                    if (is_wp_error($response)) {
                        return $response;
                    }
                    $response = rest_ensure_response($response);
                    $response->header('X-Wmos-Correlation-ID', $correlation);
                    $response->header('Cache-Control', 'private, no-store');
                    if (is_array($response->get_data()) && isset($response->get_data()['row_version'])) {
                        $response->header('ETag', '"' . (int) $response->get_data()['row_version'] . '"');
                    }
                    return $response;
                } catch (Throwable $exception) {
                    do_action('wmos_rest_failure', $exception::class, $exception->getFile(), $exception->getLine(), $correlation);
                    $message = $exception->getMessage();
                    $status = $exception instanceof \InvalidArgumentException ? 400 : ($exception instanceof \DomainException || $exception instanceof ConflictException ? 409 : 422);
                    if ($exception instanceof AccessDenied) {
                        $status = 403;
                    }
                    if (str_contains(strtolower($message), 'not found')) {
                        $status = 404;
                    }
                    if (!($exception instanceof \InvalidArgumentException) && (str_contains(strtolower($message), 'revision') || str_contains(strtolower($message), 'conflict'))) {
                        $status = 409;
                    }
                    if (!($exception instanceof \RuntimeException) && !($exception instanceof \InvalidArgumentException) && !($exception instanceof \DomainException)) {
                        $message = __('The operation could not be completed. Check system health.', 'woocommerce-marketing-os');
                        $status = 500;
                    }
                    if (str_starts_with($message, 'Node is missing a required outcome: ')) {
                        $message = sprintf(__('Node is missing a required outcome: %s', 'woocommerce-marketing-os'), substr($message, 36));
                    } elseif (str_starts_with($message, 'Unknown request field: ')) {
                        $message = sprintf(__('Unknown request field: %s', 'woocommerce-marketing-os'), substr($message, 23, 64));
                    } else {
                        $message = __($message, 'woocommerce-marketing-os');
                    }
                    return new \WP_Error('wmos_operation_failed', $message, ['status' => $status, 'correlation_id' => $correlation]);
                }
            },
            'schema' => fn() => $this->responseSchema($path),
        ]);
    }

    private function landing(string $title, string $description, string $path, string $token): \WP_REST_Response
    {
        $html = '<!doctype html><html lang="' . esc_attr(get_bloginfo('language')) . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html($title) . '</title><body><main><h1>' . esc_html($title) . '</h1><p>' . esc_html($description) . '</p><form method="post" action="' . esc_url(rest_url('wmos/v1/' . $path)) . '"><input type="hidden" name="token" value="' . esc_attr($token) . '"><button type="submit">' . esc_html($title) . '</button></form></main></body></html>';
        return new \WP_REST_Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow', 'Content-Security-Policy' => "default-src 'none'; form-action 'self'; frame-ancestors 'none'"]);
    }

    private function idempotent($request, callable $callback): mixed
    {
        $operation = $this->operation($request);
        $digest = hash('sha256', wp_json_encode($request->get_json_params() ?? []) . '|' . $request->get_header('if-match'));
        try {
            return $this->database->transaction(function () use ($request, $callback, $operation, $digest) {
                $table = $this->database->table('api_requests');
                $db = $this->database->db();
                $row = $db->get_row($db->prepare("SELECT body_hash,state,response,status FROM {$table} WHERE request_key=%s FOR UPDATE", $operation), ARRAY_A);
                if ($row) {
                    if (!hash_equals((string) $row['body_hash'], $digest)) {
                        throw new \DomainException('Idempotency key was already used for different input.');
                    }
                    if ($row['state'] !== 'completed') {
                        throw new \DomainException('This operation is already in progress.');
                    }
                    return new \WP_REST_Response($this->decode($row['response']), (int) $row['status']);
                }
                $claim = $this->database->insert('api_requests', ['request_key' => $operation, 'body_hash' => $digest, 'actor_id' => get_current_user_id(), 'route' => $request->get_route(), 'state' => 'pending', 'response' => '{}', 'status' => 202, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS)]);
                $response = rest_ensure_response($callback($request));
                $response->set_data($this->mutationSummary($response->get_data()));
                $this->database->update('api_requests', $claim['uuid'], ['state' => 'completed', 'response' => wp_json_encode($response->get_data()), 'status' => $response->get_status()]);
                return $response;
            });
        } finally {
            if ($request->get_route() === '/wmos/v1/settings') {
                wp_cache_delete('wmos_settings', 'options');
                wp_cache_delete('wmos_settings_revision', 'options');
                wp_cache_delete('notoptions', 'options');
            }
        }
    }

    private function operation($request): string
    {
        $key = $request->get_header('idempotency-key');
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9._:-]{8,191}$/D', $key)) {
            throw new \InvalidArgumentException('A valid Idempotency-Key header is required.');
        }
        return hash('sha256', get_current_blog_id() . '|' . get_current_user_id() . '|' . $request->get_route() . '|' . $request->get_method() . '|' . $key);
    }

    private function revision($request): int
    {
        $revision = trim((string) $request->get_header('if-match'), '"');
        if (!preg_match('/^[1-9][0-9]{0,17}$/D', $revision)) {
            throw new \InvalidArgumentException('An If-Match revision header is required.');
        }
        return (int) $revision;
    }

    private function cursor(?string $cursor, string $resource): int
    {
        if (!$cursor) {
            return 0;
        }
        $parts = explode('.', $cursor);
        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0] . '|' . $resource . '|' . get_current_blog_id(), wp_salt('auth')), $parts[1])) {
            throw new \InvalidArgumentException('Invalid pagination cursor.');
        }
        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if (!$decoded || !ctype_digit($decoded)) {
            throw new \InvalidArgumentException('Invalid pagination cursor.');
        }
        return (int) $decoded;
    }

    private function collection(array $rows, string $resource, int $limit, callable $present): array
    {
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $cursor = null;
        if ($more && $rows) {
            $last = end($rows);
            $table = ['contacts' => 'profiles', 'channel-providers' => 'providers'][$resource] ?? $resource;
            $lastId = $last['id'] ?? $this->database->get($table, $last['uuid'])['id'];
            $payload = rtrim(strtr(base64_encode((string) $lastId), '+/', '-_'), '=');
            $cursor = $payload . '.' . hash_hmac('sha256', $payload . '|' . $resource . '|' . get_current_blog_id(), wp_salt('auth'));
        }
        return ['items' => array_map($present, $rows), 'next_cursor' => $cursor];
    }

    private function definitionOf(string $uuid, string $kind): array
    {
        $row = $this->service('definitions')->get($uuid);
        if ($row['kind'] !== $kind) {
            throw new \RuntimeException('Definition not found.');
        }
        return $row;
    }

    private function definition(array $row): array
    {
        $safe = $this->only($row, ['uuid', 'kind', 'name', 'state', 'row_version', 'created_at', 'updated_at', 'cancel_epoch']);
        $safe['body'] = $this->definitionBody($this->decode($row['draft'] ?? $row['body'] ?? []));
        $safe['published'] = !empty($row['published_version_id']);
        return $safe;
    }

    private function contact(array $row): array
    {
        $safe = $this->only($row, ['uuid', 'email', 'state', 'row_version', 'created_at', 'updated_at', 'order_count', 'revenue_minor', 'currency']);
        $safe['attributes'] = $this->decode($row['attributes'] ?? []);
        $safe['tags'] = $this->decode($row['tags'] ?? []);
        return $safe;
    }

    private function provider(array $row): array
    {
        $safe = $this->only($row, ['uuid', 'type', 'name', 'state', 'row_version', 'created_at', 'updated_at', 'configured', 'capabilities']);
        $safe['configuration'] = $this->decode($row['configuration'] ?? []);
        $safe['configured'] = (bool) ($row['configured'] ?? !empty($row['secret']));
        return $safe;
    }

    private function message(array $row): array
    {
        return $this->only($row, ['uuid', 'channel', 'purpose', 'provider_uuid', 'state', 'row_version', 'created_at', 'scheduled_at', 'attempted_at', 'accepted_at', 'last_error']);
    }

    private function run(array $row): array
    {
        return $this->only($row, ['uuid', 'state', 'row_version', 'created_at', 'updated_at', 'cancel_epoch']);
    }

    private function job(array $row): array
    {
        return $this->only($row, ['uuid', 'kind', 'state', 'attempts', 'available_at', 'expires_at', 'last_error', 'row_version', 'created_at']);
    }

    private function financial(array $row, string $table): array
    {
        $fields = match ($table) {
            'ledger' => ['kind', 'points', 'amount_minor', 'currency', 'source_order_id', 'reason'],
            'referrals' => ['code', 'state', 'order_id'],
            'commissions' => ['order_id', 'amount_minor', 'currency', 'state', 'paid_at'],
            default => throw new \InvalidArgumentException('Unsupported financial resource.'),
        };
        return $this->only($row, array_merge(['uuid', 'created_at', 'row_version'], $fields));
    }

    private function only(array $row, array $fields): array
    {
        $safe = array_intersect_key($row, array_fill_keys($fields, true));
        foreach (['row_version', 'cancel_epoch', 'order_id', 'source_order_id', 'attempts'] as $integer) {
            if (isset($safe[$integer])) {
                $safe[$integer] = (int) $safe[$integer];
            }
        }
        return $safe;
    }

    private function mutationSummary(mixed $body): array
    {
        if (!is_array($body)) {
            return ['accepted' => true];
        }
        // Durable request receipts never duplicate private contact/content/configuration.
        return $this->only($body, ['uuid', 'row_version', 'state', 'configured', 'accepted', 'status', 'code', 'points', 'balance', 'held', 'amount_minor', 'currency', 'job_uuid', 'slug', 'url', 'external_removal', 'payout_reference']);
    }

    private function settings(): array
    {
        $settings = (array) get_option('wmos_settings', []);
        return ['tracking_enabled' => (bool) ($settings['tracking_enabled'] ?? false), 'retention_days' => (int) ($settings['retention_days'] ?? 90), 'time_zone' => (string) ($settings['time_zone'] ?? 'UTC'), 'commerce_enabled' => (bool) ($settings['commerce_enabled'] ?? false), 'consent_policy_version' => (string) ($settings['consent_policy_version'] ?? 'setup-required'), 'financial_retention_days' => (int) ($settings['financial_retention_days'] ?? 0), 'sender_email' => (string) ($settings['sender_email'] ?? ''), 'sender_name' => (string) ($settings['sender_name'] ?? ''), 'enabled_modules' => (array) ($settings['enabled_modules'] ?? []), 'telemetry_enabled' => (bool) ($settings['telemetry_enabled'] ?? false), 'row_version' => (int) get_option('wmos_settings_revision', 1)];
    }

    private function definitionBody(array $body): array
    {
        foreach ($body as $key => $value) {
            if (preg_match('/secret|password|credential|api[_-]?key|access[_-]?token/i', (string) $key)) {
                unset($body[$key]);
            } elseif (is_array($value)) {
                $body[$key] = $this->definitionBody($value);
            }
        }
        return $body;
    }

    private function readableBody(array $body): array
    {
        if (isset($body['attachment_id']) && is_int($body['attachment_id']) && $body['attachment_id'] > 0 && !current_user_can('read_post', $body['attachment_id'])) {
            throw new AccessDenied('You cannot use this media attachment.');
        }
        return $body;
    }

    private function responseSchema(string $path): array
    {
        $item = ['type' => 'object', 'properties' => ['uuid' => ['type' => 'string', 'format' => 'uuid'], 'row_version' => ['type' => 'integer', 'minimum' => 1], 'state' => ['type' => 'string'], 'created_at' => ['type' => 'string'], 'body' => ['type' => 'object'], 'configured' => ['type' => 'boolean']]];
        return ['title' => 'wmos_' . sanitize_key($path), 'type' => 'object', 'properties' => $item['properties'] + ['items' => ['type' => 'array', 'maxItems' => 100, 'items' => $item], 'next_cursor' => ['type' => ['string', 'null'], 'maxLength' => 200], 'status' => ['type' => 'string'], 'accepted' => ['type' => 'boolean']]];
    }

    private function queueCounts(): array
    {
        $table = $this->database->table('jobs');
        return $this->database->db()->get_results("SELECT state,COUNT(*) AS count,MIN(available_at) AS oldest FROM {$table} GROUP BY state", ARRAY_A) ?? [];
    }

    private function service(string $key): mixed
    {
        if (!isset($this->services[$key])) {
            throw new \RuntimeException('Required marketing service unavailable.');
        }
        return $this->services[$key];
    }

    private function decode(mixed $value): array
    {
        return is_array($value) ? $value : (json_decode((string) $value, true) ?: []);
    }

    private function uuid(): array
    {
        return ['type' => 'string', 'format' => 'uuid', 'required' => true, 'pattern' => '^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$'];
    }

    private function string(int $max, bool $required): array
    {
        return ['type' => 'string', 'minLength' => $required ? 1 : 0, 'maxLength' => $max, 'required' => $required];
    }

    private function object(bool $required): array
    {
        return ['type' => 'object', 'required' => $required];
    }

    private function pagination(): array
    {
        return ['per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25], 'cursor' => ['type' => 'string', 'maxLength' => 200, 'default' => '']];
    }

    private function created(array $body): \WP_REST_Response
    {
        return new \WP_REST_Response($body, 201);
    }

    private function accepted(array $body): \WP_REST_Response
    {
        return new \WP_REST_Response($body, 202);
    }
}
