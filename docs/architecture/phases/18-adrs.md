## Phase 18 — Architectural decision records

These records describe the consensus design. Status is **accepted for implementation**, subject to the release/test gates in Phase 17; acceptance is not a claim that a build exists or compatibility has been tested. They apply to a native plugin with slug/text domain `woocommerce-marketing-os`, PHP namespace `Wmos`, public contracts `Wmos\Contracts\V1`, provider contracts under `Wmos\Contracts\V1\Provider`, REST `wmos/v1`, and site-prefixed `wmos_` tables. Official API evidence is recorded in [sources](../sources.md). Changes require a superseding ADR, migration/compatibility assessment and review from the affected domain/platform/privacy roles.

### ADR-001 — Modular monolith with ports and adapters

**Context.** Merchants need an ordinary WordPress deployment, while dozens of marketing methods share identity, consent, campaign, workflow and measurement concepts. Independent channel engines duplicate policy and data; microservices add deployment and consistency burdens inappropriate for the base plugin.

**Decision.** One installable modular monolith, with explicit domain/application/infrastructure boundaries and typed public ports. Marketing methods compose reusable primitives. Modules own writes; cross-module orchestration uses application contracts and versioned events. Optional modules register only when enabled. WordPress/WooCommerce subscribers remain integration adapters.

**Alternatives.** Microservices; a global hook-driven procedural plugin; one engine/table set per marketing label; a mandatory external SaaS.

**Consequences.** Local transactions and normal plugin operations; domain unit tests; replaceable providers and algorithms. Architecture rules must prevent cycles, universal managers, hidden global state and service-location abuse. External workers/data infrastructure can be future optional adapters without replacing the default.

**Risks.** Shared process failures/resources and complexity growth. Mitigate with module import rules, bounded jobs, feature gating, explicit ownership and cross-role review; do not imitate distributed systems unnecessarily.

### ADR-002 — Purpose-built plugin tables

**Context.** High-volume events, runs, messages, immutable ledgers and memberships need indexed queries and uniqueness/concurrency guarantees. Posts/postmeta do not provide an appropriate general storage model for these records.

**Decision.** Plugin-owned site-prefixed tables for aggregates, high-volume facts, ledgers and projections; schema/index/retention/PII inventory per table. Use prepared SQL and transactional plugin tables, application-level references and uniqueness/CAS. `dbDelta` only for suitable additive changes; versioned expand/backfill/validate/switch/contract migrations for complex upgrades.

**Alternatives.** Everything in posts/postmeta; every record as option/JSON blob; mandatory external database; blindly adding cross-platform foreign keys.

**Consequences.** Explicit efficient access paths and reliable effects; additional migration, backup, integrity and cleanup work. Editorial assets/media can reference normal WP resources. No table becomes an alternative commerce master.

**Risks.** Locking, disk growth, DDL implicit commits and unsupported hosting. Mitigate with bounded background migration/deletion, conservative floors, capacity health checks and measured scale gates.

### ADR-003 — WooCommerce canonical source boundary

**Context.** Orders can reside in HPOS or alternate storage; bypassing CRUD can read stale synchronized records. Product/customer/order duplication creates conflicting truths.

**Decision.** All canonical commerce state through public WooCommerce CRUD/query APIs. Plugin stores references and purpose-limited derived projections with provenance/freshness. No `Internal`/`@internal` dependency, direct order-table/posts query or write. Supported storage modes share semantics; HPOS-specific query features are gated. Compatibility declaration follows testing, on the documented lifecycle.

**Alternatives.** Direct HPOS SQL for speed; treating orders as WP posts; shadow commerce database; assuming synchronization implies equivalent reads.

**Consequences.** Future-proof source access and clear corrections. Aggregate rebuild/reconciliation is asynchronous and finite; projections cannot reconstruct missed intermediate history. The [HPOS recipe](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/) and [order query reference](https://developer.woocommerce.com/docs/features/orders/wc-get-orders/) anchor integration.

**Risks.** API throughput/staleness and extension-defined statuses. Mitigate with bounded IDs-first query plans, explicit eligibility policies, monitored reconciliation and version-pinned contract tests.

### ADR-004 — Canonical events and transactional plugin outbox

**Context.** Hook/webhook repeats, delayed receipts and failures make direct synchronous fan-out unsafe; arbitrary event JSON makes migrations/privacy unpredictable.

**Decision.** Versioned bounded envelopes and per-event property schemas, unique semantic keys where appropriate, correlation/causation and consent references. Capture minimally; publish through durable plugin outbox and idempotent consumers. WooCommerce commit is outside the plugin transaction, so dirty-reference/canonical reconciliation repairs current facts. Event retention is purpose-limited; audit/ledger are separate records.

**Alternatives.** Synchronous marketing inside commerce hooks; unconstrained JSON log as a universal database; claims of exactly-once hook delivery; a mandatory message broker.

**Consequences.** Traceable eventual processing and explicit duplicates/backfill semantics. Old schemas remain readable via named upcasters or rejection/replay policies; evolution cannot silently reinterpret published rules.

**Risks.** Missed upstream capture and growing backlog. Mitigate with outbox sweeping, canonical source reconciliation, generation/CAS, bounded payload/retention and visible degraded state.

### ADR-005 — Action Scheduler plus domain-owned execution state

**Context.** WordPress hosting has limited process control; WooCommerce supplies a compatible queue, but queue action completion is not marketing delivery or workflow truth.

**Decision.** Use initialized Action Scheduler public APIs for bounded namespaced/grouped wakeups. Plugin tables own intents, leases/fencing, run transitions, retries, cancellation and failure/unknown-outcome state. Short jobs checkpoint/requeue; domain uniqueness/CAS prevents duplicates. High-volume operation requires an observed runner/cron capacity plan, while default install needs no Redis/broker.

**Alternatives.** Core cron per recipient; custom queue runner replacing Woo runtime; vendor another scheduler by default; treating Action Scheduler unique flags as complete business idempotency.

**Consequences.** Familiar operational tooling and recoverable durable state; no direct queue-table writes. [Scheduler API](https://actionscheduler.org/api/) determines initialization/enqueue/unschedule surfaces, not our delivery guarantees.

**Risks.** Cron starvation, parallel workers, action growth and uncertain external sends. Mitigate with coalesced bounded wakeups, monitoring, dispatch fence, provider idempotency/reconciliation and explicit quarantine when retry cannot be safe.

### ADR-006 — Capability-aware provider adapters

**Context.** Messaging/social/ad platforms differ in scheduling, templates, quota, batch size, receipts and idempotency. Campaign logic cannot embed vendor assumptions.

**Decision.** Stable versioned channel/provider contracts in `Wmos\Contracts\V1\Provider`; typed capability discovery, normalized failures/results and secret references. Adapters own authentication, payload mapping, signatures, rate/timeout policies and receipt normalization. Consent and durable delivery orchestration remain central. Optional AI is a removable provider with review/transfer gates.

**Alternatives.** Direct SDK calls inside campaigns; one lowest-common-denominator interface; assume every provider supports all capabilities; put provider keys in UI state.

**Consequences.** Integrated execution remains explicit; unsupported actions fail validation before publication. Native management/tracking works without a provider. Minimize dependencies and scope vendor implementation symbols when necessary.

**Risks.** API churn, unknown delivery, vendor outages and capability drift. Mitigate with pinned contract tests, adapter ownership, health checks and capability refresh without altering immutable campaign versions silently.

### ADR-007 — Immutable automation publication and durable state machines

**Context.** Editing a live graph must not rewrite history or change what already running customers execute. Retries and delay wakeups can race cancellation/publication.

**Decision.** Draft graph validates against registered node/schema/capability constraints; publication creates immutable version. Every run/step references that version and has durable transition revision, dedup keys, lease/fence and schedule timezone semantics. New versions affect new enrollment by default; moving existing runs requires an explicit reviewed migration plan.

**Alternatives.** Mutable JSON graph used by all executions; reconstructing runs only from scheduled actions; implicit migration whenever draft is saved.

**Consequences.** Explainable historical execution and deterministic simulation. Graph editing has a non-canvas accessible equivalent; loops/subworkflows are bounded or rejected by policy. Pause, cancellation, goals and expiry are enforced before side effects.

**Risks.** Version proliferation, stale delays and ambiguous migration. Mitigate with retention by references, caps, revision/CAS, clock fixtures and explicit old/new version UI.

### ADR-008 — Conservative identity graph with reversible associations

**Context.** Guests, accounts, sessions and provider addresses overlap. Matching an order email is not proof the visitor owns the account; aggressive merging risks sending private data or violating withdrawals.

**Decision.** Separate marketing profile from identities and canonical Woo customer references. Associate using strong verified evidence/provenance; weak/probabilistic signals never auto-merge by default. Merge records preserve association/history lineage and support controlled unmerge. Anonymous observations require purpose permission; no fingerprinting or cross-site covert identity.

**Alternatives.** One profile per WP user only; auto-merge every matching email/phone; mandatory third-party identity service; unversioned destructive merge.

**Consequences.** Guest support and correct consent boundaries with more explicit ambiguity handling. Identity lookup/unique ownership scopes and privacy tombstones require careful indexed constraints and human review tools.

**Risks.** False merge, stale provider identity, erased identity resurrection. Mitigate with verification, conflict quarantine, dispatch-time destination ownership checks, minimal tombstones and merge/unmerge tests.

### ADR-009 — Purpose/channel consent decision engine

**Context.** A single marketing boolean cannot distinguish email/SMS/profiling/advertising rights, evidence or withdrawal. Captured historical consent may no longer authorize delivery.

**Decision.** Immutable provenance-rich records by subject/channel/purpose and configured legal-basis policy; derived effective decision with global/channel suppressions, double opt-in and do-not-contact. All enrollment, collection/personalization and outbound dispatch consult the appropriate purpose decision. Queue-time snapshot explains eligibility; current state controls dispatch. WP privacy exporter/eraser integration follows verified request handling.

**Alternatives.** Generic Boolean; assume purchase/registration grants consent; defer policy entirely to providers; only check at campaign creation.

**Consequences.** Defensible audit and consistent withdrawal with configurable jurisdiction-aware policy reviewed by merchant/legal owners. Unchecked/absent checkout fields do not grant or automatically withdraw. No campaign/extension bypass.

**Risks.** Policy error, race with sends, excessive evidence retention. Mitigate with conservative defaults, synchronized suppression fences, transfer inventory, retention controls and explicit legal-review decisions.

### ADR-010 — Transparent versioned attribution and monetary basis

**Context.** Touchpoints are incomplete observations, refunds revise commerce value, currencies vary, and campaign attribution is not causal uplift.

**Decision.** Canonical purpose-authorized touchpoints; Woo order-reference conversions; explicit model/window/version/eligibility and monetary basis. Start deterministic first/last/non-direct/linear/time-decay/position/custom weights. Incremental aggregates retain basis and revision; refunds/cancellations adjust by policy. Missing FX/cost yields separate currencies or unavailable metrics. Experiment inference is independently specified.

**Alternatives.** Black-box mandatory ML; last cookie wins silently; sum every provider's attributed revenue; combine currencies without rates; dashboard winner by highest raw rate.

**Consequences.** Explainable drill-down/recomputation and reduced disputes; attribution totals differ from provider dashboards for stated reasons. Cost/import quality and identity gaps remain visible.

**Risks.** Late data, coupon sharing and incomplete cross-device linkage. Mitigate with provenance/freshness/versioning, sensitivity reports, no invented facts and independently calculated golden fixtures.

### ADR-011 — Screen-scoped WordPress admin application

**Context.** Campaign/rule/graph editors need interactive state; merchants need familiar WooCommerce navigation and accessible operation rather than another isolated application shell.

**Decision.** Admin SPA under WooCommerce with scoped routes/bundles/styles and supported WordPress packages/runtime dependencies. Progressive disclosure, capability-aware actions, optimistic revision conflicts and explicit draft/publish states. The workflow graph has equivalent keyboard/list editing; charts provide tables/text; provider secret values never return to browser after storage.

**Alternatives.** Full global admin takeover; one form submission per node; separately bundled React runtime; pointer-only canvas.

**Consequences.** Cohesive efficient UX while respecting WP chrome/i18n/RTL. Generated asset metadata and package export tests are required; server permissions remain authoritative.

**Risks.** Bundle growth, package drift and inaccessible widgets. Mitigate with route/module splitting, screen gating, budgets and automated/manual accessibility evidence.

### ADR-012 — Versioned WordPress REST resource/command contracts

**Context.** Admin, CLI and third-party extensions need consistent validated APIs. Nonces alone do not authorize, and public tracking/provider endpoints have different trust boundaries.

**Decision.** `wmos/v1` REST controllers registered on `rest_api_init`, with request/response schemas, sanitization, validation, permission callbacks, resource policies, stable errors and bounded pagination. Long commands return job resources; mutations use optimistic revisions and operation-specific idempotency. Cookie/Application Password authentication uses WP facilities; provider webhooks/tracking get dedicated bounded signature/token contracts.

**Alternatives.** Ad hoc AJAX handlers; a separate framework REST server; always-public callbacks with UI-only restrictions; returning unconstrained database rows.

**Consequences.** Familiar discoverable integration and consistent errors; breaking API changes require a version/deprecation policy. Domain invariants remain application-owned. The [endpoint handbook](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/) supports WP conventions.

**Risks.** IDOR, field leaks, mass assignment and expensive collections. Mitigate with deny-by-default allowlists, capabilities/object scope, rate/query-cost bounds and adversarial integration tests.

### ADR-013 — Public Blocks/Store API integration with server authority

**Context.** Classic checkout hooks do not universally apply to Blocks. Private React hooks/client cart mutations break as WooCommerce evolves; Store API output is shopper/public-facing.

**Decision.** Conditional `IntegrationInterface` registration, supported filters/inner blocks/stable fills, Additional Checkout Fields and namespaced Store API schema/update helpers. Server validates and calculates; checkout-ready and payment-success facts stay distinct. Classic and Blocks adapters share application contracts. No private store mutation, secret frontend configuration or deprecated processed hook. Tested artifact alone declares Blocks compatibility.

**Alternatives.** DOM patching; unsupported cart reducer dispatch; PHP template-only fields; unconditional experimental surfaces; separate conflicting pricing rules per checkout.

**Consequences.** Modern/classic parity via different public surfaces. Unsupported placement is disclosed and offered through an available supported surface/integration; version-pinned public exports determine implementation. [Blocks integration](https://developer.woocommerce.com/docs/block-development/reference/integration-interface) and [additional fields](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/additional-checkout-fields/) provide extension mechanisms.

**Risks.** API churn, editor/frontend mismatch, account-default opt-in and response leakage. Mitigate with order-scoped fresh evidence, schema allowlists, absence-safe conditional loading and full guest/registered/editor/Store API tests.
