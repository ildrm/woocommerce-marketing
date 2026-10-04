## Phase 1 — Requirements normalization

### Scope and interpretation

This specification designs **WooCommerce Marketing Operating System (WMOS)**, working plugin slug/text domain `woocommerce-marketing-os`, PHP namespace `Wmos`, REST namespace `wmos/v1`, hook prefix `wmos_`, queue group `wmos`, and per-site table prefix `{$wpdb->prefix}wmos_`. Names are provisional product branding, but the contracts below are normative. This delivery is an architecture and implementation plan; it does not claim that a plugin, compatibility tests, performance benchmarks, or provider integrations have already been implemented.

The unit of installation is one native WordPress plugin. The unit of data isolation is one WordPress site, including per-site activation in multisite. Network-wide activation is disabled in the initial release; a future qualified adapter must initialize sites in batches and provision new sites through the documented site lifecycle. Jobs carry their site context and cannot mix tables or credentials between sites. There is no network-wide identity graph, remote execution service, required paid account, Redis requirement, or AI dependency. The merchant selects enabled features; disabling a feature prevents new execution but preserves its history.

The product has three execution levels: Native Execution for plugin-owned computation and WooCommerce-supported operations; Integrated Execution for actions requiring provider APIs or an installed compatible extension; Management + Tracking for physical activity or API-inaccessible activity. A methodology can use several levels. A campaign is a composition of reusable capabilities, never a subclass for every marketing label.

### Normalized requirement groups

| ID | Requirement and acceptance boundary | Implementing phases |
|---|---|---|
| R01 | One modular, installable plugin with minimal bootstrap, namespaces, Composer, bounded dependencies and optional module gating | 3, 4, 9, 18 |
| R02 | Every marketing family in phase 2 is expressible using campaign/audience/assets/offers/actions/touchpoints/goals; external and physical execution limits visible | 2, 5, 11, 14 |
| R03 | WooCommerce remains canonical for products, customers, orders, coupons and refunds; all retrieval/mutation uses supported APIs | 5, 9, 10, 17 |
| R04 | HPOS, classic checkout, Store API and Cart/Checkout Blocks have explicit supported integration boundaries and tested declarations | 10, 17, 18 |
| R05 | Customer profile, conservative identities, provenance, consent history, merge/unmerge and derived commerce metrics | 5, 6, 13 |
| R06 | Versioned bounded events, durable ingestion, deduplication, correlation and asynchronous fan-out | 6–8, 16 |
| R07 | Immutable campaign/automation versions, explicit state machines, durable waits, concurrency-safe steps and cancellation | 5, 8, 14 |
| R08 | Nested reusable segment/rule criteria, indexed query compilation, materialization and membership entry/exit | 5, 6, 8, 16 |
| R09 | Capability-aware removable providers for email, SMS, push, WhatsApp, Telegram, social, ads, webhooks and optional AI | 11, 12, 13 |
| R10 | Promotions integrate native WooCommerce coupon/pricing/shipping behavior; no private cart manipulation | 10, 11 |
| R11 | Referral, affiliate, influencer and loyalty have explicit ledgers, fraud review, refund reversal and payout boundaries | 5, 6, 11, 15 |
| R12 | Tracking, offline placements, QR and short links respect consent; no fingerprinting or covert cross-site identity | 7, 12, 13, 15 |
| R13 | Deterministic personalization/recommendations and stable experiments work with full-page caching and fallbacks | 8, 11, 14, 15 |
| R14 | Versioned authenticated REST, scoped capabilities, import validation, WP-CLI, audit and structured safe errors | 9, 12, 14 |
| R15 | Defined attribution/KPI formulas, monetary conventions, corrections, cohorts and responsible statistics | 6, 15 |
| R16 | Purpose/channel decisions, suppression, export, erasure, retention, external disclosure and no silent telemetry | 12, 13 |
| R17 | Bounded queue/migration/export/cleanup work, documented scale limits and observable failure handling | 6, 8, 16 |
| R18 | WooCommerce-integrated admin, progressive disclosure, localized accessible builders and actionable health | 9, 14, 17 |
| R19 | Unit/integration/compatibility/E2E/security/accessibility/migration/performance tests and static quality gates | 17, 20 |
| R20 | Reviewed decisions, risk owners and dependency-correct milestones with measurable acceptance criteria | 18–21 |

### Conservative decisions for ambiguities

1. **Design versus implementation:** deliver all 21 design phases with actionable contracts and release gates. Directory trees describe the future implementation; empty classes and fake provider implementations are not generated.
2. **Version floor:** choose PHP 8.3, WordPress 6.9, WooCommerce 10.8, MySQL 8.0 or MariaDB 10.11, InnoDB, HTTPS, 64-bit PHP and UTF-8 as the initial plugin target. These are plugin design choices, stricter than some platform minima, and are not assertions about the latest release. WordPress's current recommended environment and WooCommerce's documented 10.8/6.9 pairing support this starting point. Pin current stable and supported release combinations from official release data at milestone M0; raise or widen the floor only through a documented compatibility decision and passing tests. [WordPress requirements](https://wordpress.org/about/requirements/), [WooCommerce version requirements](https://woocommerce.com/document/update-php-wordpress/).
3. **Compatibility claims:** neither `WC tested up to` nor HPOS/Blocks compatibility is asserted from architectural intent. Release-generated metadata and compatibility declarations refer only to passing matrix evidence. Optional APIs are capability-detected; missing dependencies disable the corresponding feature without breaking purchases. [WooCommerce compatibility guidance](https://developer.woocommerce.com/docs/extensions/best-practices-extensions/compatibility).
4. **Jurisdiction:** merchant configuration selects applicable purposes, lawful bases, evidence/retention requirements and messaging restrictions. Default promotional communication requires affirmative channel/purpose opt-in; operational WooCommerce transaction messages remain WooCommerce's responsibility. “Transaction-adjacent” marketing passes the same marketing guard. This architecture does not invent jurisdiction-independent legal rules.
5. **Identity:** verified ownership or authorized administrative review permits strong links; guest billing email, matching IP, household, browser attributes and similar names alone do not permit irreversible profile merges. Preserve order/contact reference provenance and allow correction.
6. **History:** financial facts are plugin analytics projections referencing canonical orders, not a second order system. Immutable definition versions and ledger adjustments preserve traceability. Erasure removes personal associations while retaining only justified, non-identifying financial aggregates.
7. **Money/time:** store signed integer minor units plus ISO currency and exponent; use UTC instants and named IANA timezones for calendar intent. Do not aggregate currencies without a disclosed dated rate source. The site timezone governs report boundaries; saved campaign timezones govern schedules.
8. **Provider delivery:** accept at-least-once local queue execution, not universal exactly-once external delivery. A timeout after a possible send is an ambiguous outcome; reconcile using provider lookup/idempotency before retry, or stop for review when the provider cannot make retry safe.
9. **Cost and statistics:** missing costs/margins/rates yield unavailable CAC/ROI/normalized currency, never fabricated zero. Attribution explains allocation rather than causation. Experiment conclusions require a predeclared method and data quality gates.
10. **Scale:** Small and Medium are mandatory benchmark profiles; Large is a capacity qualification project with retention and operational prerequisites. High-volume sites may need real cron/CLI workers or optional external reporting adapters, but base execution stays in WordPress.
11. **Physical execution:** the plugin plans deliverables, budgets, placements, QR links and measured outcomes. Printing, events, sponsorships and street activity remain outside software execution.
12. **Marketing data quality:** browser events, opens and anonymous sessions are fallible. Reports distinguish observed, provider-reported, estimated and unavailable values. A tracking outage cannot block checkout.

### Product-wide constraints

Public supported WordPress/WooCommerce APIs override generic architectural purity. Domain classes contain no `add_action()` calls; subscribers translate platform state to application commands. Direct order SQL, `Automattic\WooCommerce\Internal`, `@internal` dependencies, arbitrary executable merchant code, `eval()`, `extract()`, unserialized imported objects, silent telemetry, inline provider secrets, synchronous bulk work and unbounded customer scans are prohibited. API review and static checks enforce these rules; documentation alone is insufficient.

Phase 21 records a twelve-viewpoint design review and cross-role correction log. The product, lifecycle marketing, architecture, platform, security/privacy, data/attribution, operations, UX/accessibility and QA perspectives are applied to each decision; differences are resolved in ADR consequences and acceptance gates.
