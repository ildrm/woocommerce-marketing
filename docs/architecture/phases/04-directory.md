## Phase 4 — Complete directory structure

The following is the implementation target, not generated placeholder code. Each context contains only needed layers; naming one directory does not require speculative empty classes. PSR-4 maps `Wmos\\` to `src/`. All table-owning repositories and migration steps belong to their context.

```text
woocommerce-marketing-os/
├── woocommerce-marketing-os.php       # metadata, requirements, loader, lifecycle hooks
├── uninstall.php                     # guarded entry to scoped Uninstaller
├── composer.json / composer.lock
├── package.json / package-lock.json
├── readme.txt / README.md / LICENSE / CHANGELOG.md / SECURITY.md
├── phpcs.xml.dist / phpstan.neon.dist / phpunit.xml.dist
├── eslint.config.js / stylelint.config.js / playwright.config.ts
├── .wp-env.json / .distignore
├── config/
│   ├── modules.php                    # IDs, requirements, routes, migrations
│   ├── capabilities.php
│   └── defaults.php                   # non-secret typed settings
├── schemas/
│   ├── events/v1/                     # closed JSON envelopes and per-event properties
│   ├── automation/v1/                 # graph, criterion AST, action definitions
│   ├── campaigns/v1/
│   ├── imports/v1/
│   ├── rest/v1/
│   └── metrics/v1/                    # definitions, units, denominators
├── src/
│   ├── Bootstrap/
│   │   ├── Plugin.php / Requirements.php / ModuleManifest.php / ModuleRegistry.php
│   │   ├── CompositionRoot.php / CompatibilityDeclarations.php
│   │   └── Lifecycle/Activator.php / Deactivator.php / Uninstaller.php
│   ├── SharedKernel/
│   │   ├── Identity/ / Money/ / Time/ / Pagination/ / Error/ / Correlation/
│   ├── Contracts/V1/
│   │   ├── TriggerType.php / ConditionType.php / ActionType.php
│   │   ├── Channel.php / Provider.php / Registration.php
│   │   ├── Provider/                  # channel-specific provider ports and typed DTOs
│   │   ├── AttributionModel.php / RecommendationStrategy.php / MetricDefinition.php
│   │   └── CampaignContribution.php / CampaignTemplate.php
│   ├── Platform/
│   │   ├── Configuration/ / Security/ / Privacy/ / Audit/ / Logging/
│   │   ├── Health/ / Migration/ / FeatureFlags/
│   │   └── Queue/                     # AS adapter, leases, outbox pump, retry policy
│   ├── Customer/
│   │   ├── Profile/ / IdentityResolution/ / Consent/
│   ├── Events/
│   │   ├── Domain/                    # envelope, event names, dedup values
│   │   ├── Application/               # ingestion, dispatch, schema registry
│   │   └── Infrastructure/            # event/outbox/inbox repositories
│   ├── Campaign/ / Audience/ / Segmentation/ / Rules/ / Automation/
│   ├── Experience/
│   │   ├── Personalization/ / Recommendation/ / Promotion/ / Experimentation/
│   ├── Measurement/
│   │   ├── Tracking/ / Attribution/ / Analytics/
│   ├── Channels/
│   │   ├── Shared/                    # guarded dispatch, message/delivery types
│   │   ├── Email/ / Sms/ / Push/ / WhatsApp/ / Telegram/ / Social/ / Webhook/
│   ├── Partners/
│   │   ├── Affiliate/ / Referral/ / Loyalty/ / Influencer/
│   │   └── Rewards/                   # shared reward issue/reverse contract
│   ├── Marketing/
│   │   ├── Offline/ / Experiential/ / Events/ / Qr/ / ShortLinks/
│   │   ├── Content/ / Seo/ / Growth/ / Advertising/
│   ├── Providers/
│   │   ├── Application/ProviderRegistry.php / ConnectionService.php
│   │   ├── Domain/Capabilities.php / ProviderError.php
│   │   └── Adapters/                  # one independently enabled provider adapter
│   ├── Integration/
│   │   ├── WordPress/
│   │   │   ├── PrivacySubscriber.php / AdminSubscriber.php / SiteHealthSubscriber.php
│   │   │   ├── ContentGateway.php / UserSubscriber.php / SiteLifecycleSubscriber.php
│   │   └── WooCommerce/
│   │       ├── OrderGateway.php / ProductGateway.php / CustomerGateway.php / CouponGateway.php
│   │       ├── OrderSubscriber.php / CustomerSubscriber.php
│   │       ├── ProductSubscriber.php / CartSubscriber.php / CheckoutSubscriber.php
│   │       ├── ReconciliationWorker.php / CouponAdapter.php
│   │       ├── StoreApi/ / Blocks/ / ClassicCheckout/
│   └── Presentation/
│       ├── Rest/V1/                  # route/controller/schema/DTO, permission callbacks
│       ├── Admin/                    # submenu registration, assets, boot safe DTO
│       ├── Cli/                      # per-site bounded WP-CLI commands
│       └── ImportExport/             # preflight, reference mapping, async execution
├── assets/
│   ├── admin/src/
│   │   ├── app/ / navigation/ / components/ / accessibility/ / i18n/
│   │   ├── screens/overview/ / campaigns/ / automations/ / customers/ / segments/
│   │   ├── screens/channels/ / promotions/ / loyalty/ / referrals/ / affiliates/
│   │   ├── screens/influencers/ / experiments/ / offline/ / attribution/ / analytics/
│   │   ├── screens/integrations/ / logs/ / health/ / settings/
│   │   ├── builders/workflow/ / campaign/ / segment/
│   │   ├── stores/                   # supported WP data stores, no secret configuration
│   │   └── styles/
│   ├── storefront/src/collector.ts / consent.ts / personalization.ts
│   ├── blocks/src/checkout-opt-in/ / offer-surface/ / editor/
│   ├── push/src/service-worker.ts
│   └── build/                        # generated bundles and *.asset.php dependencies
├── templates/
│   ├── emails/ / unsubscribe/ / preference-center/ / personalization/
│   └── campaign-definitions/         # validated versioned JSON presets
├── languages/woocommerce-marketing-os.pot
├── tests/
│   ├── Unit/ / Integration/ / Contracts/ / Compatibility/ / Migration/
│   ├── Fixtures/ / Security/ / Performance/ / JavaScript/ / E2E/ / Accessibility/
│   └── Support/                     # platform bootstraps, factories, fake provider servers
├── tools/
│   ├── build-release.php / scope-dependencies.php / validate-schemas.php
│   ├── check-public-api-boundary.php / seed-performance.php
│   └── benchmark/ / release-matrix/
├── docs/
│   ├── architecture/ / adr/ / public-contracts/ / events/ / rest/
│   ├── operations/ / privacy/ / provider-adapters/ / compatibility/
│   └── merchant-guides/ / release-evidence/
├── .github/workflows/quality.yml / compatibility.yml / release.yml
└── vendor/                           # release-only production Composer autoload/dependencies
```

Within, for example, `Automation/`, concrete structure is `Domain/Definition/`, `Domain/Run/`, `Domain/Node/`, `Application/Command/`, `Application/Query/`, `Application/Port/`, `Infrastructure/Persistence/`, `Infrastructure/Migration/` and `Infrastructure/Queue/`. `Customer/Consent/` contains the decision engine and evidence repository; `Channels/Shared/` invokes it rather than copying consent logic into each channel. Woo adapters implement narrow product, customer, order and coupon query/command ports; a composite commerce registration helper contains no mixed business logic. UI builders share an accessible rule AST editor and typed REST client; they do not duplicate PHP evaluation semantics.

The main PHP file does no domain work. It reads plugin headers, defines bounded paths/version, checks PHP before loading syntax requiring the floor, loads Composer, registers lifecycle hooks and the tested feature declaration callback at its documented lifecycle, then starts the composition root after dependencies are available. Requirements failure shows an escaped authorized admin notice and prevents marketing execution. Activation creates only small prerequisite tables/configuration and queues resumable work; bulk backfill never occurs in activation or an ordinary admin page.

Use Composer primarily for autoloading and development tools. Production provider adapters prefer WordPress HTTP APIs and documented runtime-provided APIs. Introduce SDKs only when their benefit outweighs collision and maintenance cost; scope third-party symbols into `WmosVendor` during release builds, including generated autoload metadata, and contract-test scoped artifacts. Do not scope WP/Woo or public extension interfaces. Build JS against supported WordPress/WooCommerce packages with dependency extraction; externalize React/ReactDOM to platform handles, never bundle a second runtime. Lockfiles, a software bill of materials, dependency license review, reproducible ZIP contents and integrity checks are release outputs.

No provider key or store-specific customer fixture appears in the distributable. Build output includes production vendor/bundles/translations and excludes test data, source maps with sensitive paths, caches, development dependencies and `.env`. Marketplace/distribution-specific packaging rules are validated separately. Deployment does not require Composer or Node on the merchant's server.
