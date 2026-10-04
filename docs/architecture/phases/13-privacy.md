## Phase 13 — Privacy

### Policy model and data inventory

Privacy is an application dependency, not a settings checkbox. A `ProcessingPolicy` version defines purpose, channel, operation, permitted fields, required authorization, retention, external destinations, notices and region-specific choices. Consent is one possible required authorization; a separate documented lawful-basis decision may authorize a strictly scoped non-marketing process such as financial records. The plugin does not declare that all processing is consent-based or that buying something consents to marketing. Default behavioral tracking, personalization/profiling, ad synchronization and telemetry are off until configured; unknown purpose/basis/evidence denies processing. A merchant selects policies with qualified advice; the plugin validates their internal consistency rather than asserting universal legal compliance.

The European Commission describes purpose-specific consequences of withdrawal, and California's Attorney General describes opt-out of sale/sharing including Global Privacy Control. These motivate independent policy dimensions and propagation, not a hard-coded law selector. Settings record applicable policy/version, why it applies and how the store handles regional uncertainty. [European Commission legal grounds](https://commission.europa.eu/law/law-topic/data-protection/information-business-and-organisations/legal-grounds-processing-data_en), [California CCPA guidance](https://oag.ca.gov/privacy/ccpa).

All fields in Phase 6 have a classification and purpose. The inventory below is a required registry expanded by every optional module; a new field without classification, retention and exporter/eraser behavior cannot migrate to production. Pseudonymous hashes, UUIDs and relationship graphs remain potentially identifying/personal when linkable.

| Field families / ownership | Classification and permitted use | Export/erasure treatment |
|---|---|---|
| Campaign names, rule definitions, public product references, aggregate definitions | Non-personal unless merchant inserts personal content; campaign operation | Validate/redact embedded personal text; definitions export excludes secrets |
| `contacts` identity references, names, email/phone, WP/Woo customer IDs, attributes/tags | Personal; declared CRM/customer service or specific marketing purpose | Export provenance/current values; remove identifiers and derived personal attributes |
| Identity normalized value, verification/provenance, external ID, merge/unmerge graph | Personal; safe identity resolution, never arbitrary cross-site linkage | Export verified subject associations only; sever graphs and caches on erase |
| Consent records/heads, policy/method/source/evidence, suppressions | Personal/potentially identifying; authorization proof and contact prevention | Export understandable history; retain minimized evidence only under selected policy |
| Behavior/session/touchpoints/UTMs/click IDs/cart reference | Potentially identifying/personal; analytics, personalization or advertising as separately authorized | Remove session/profile joins and raw events; strip URL query PII at capture |
| Messages/rendered variables, destinations, receipt IDs/statuses | Personal; authorized delivery/support | Export safe content/history; delete bodies/destinations/provider links as retention permits |
| Conversion/order/refund projection and reward/commission/loyalty entries | Personal financial relationship; analytics or program administration | Sever optional marketing joins; retain necessary financial fields under explicit policy |
| Partner contact/payout details, influencer deliverables/costs, event registrations | Personal; partner/program/registration contract and optional marketing | Export partner-specific records; restrict retained financial contact details |
| Offline location descriptors, QR asset/public links | Usually non-personal; campaign management | Avoid exact shopper location; scans follow tracking purpose policy |
| Aggregate buckets, experiment assignments, segment membership, RFM/CLV | Aggregates may identify small groups; assignments/profile scores personal | Suppress small groups; remove assignments/memberships/scores; recompute affected aggregates |
| Credential/token/private push endpoint/signing secret, export token | Sensitive credential/security; narrowly scoped integration | Never include in personal/definition export; revoke/delete separately |
| Logs/audit actor IDs, transient IP rate-control hash | Potentially identifying; security/operations | Minimize and TTL; retain redacted necessary audit evidence under policy |

No raw payment card, authentication password, national ID, health diagnosis or inferred protected trait is a supported custom attribute. Extensions cannot turn generic event JSON into a sensitive-data repository. Address/precise location is not copied from orders just because WooCommerce has it. Birthdays use month/day where sufficient; year/age is collected only if an actual approved requirement needs it. Account-based segmentation references authorized organization attributes and membership evidence, never silently infers employers from email domains.

### Consent and runtime guards

Append-only `ConsentRecord` captures contact/identity scope, purpose, channel, status (`unknown`, `pending`, `granted`, `withdrawn`, `denied`), UTC time, source, method, policy version, evidence reference, actor and request correlation. `ConsentHead` indexes the effective revision; corrections append a reasoned record instead of rewriting history. Double opt-in grants only after a single-use challenge confirms the same identity/purpose/policy. Imports require provenance; blank/missing consent imports as unknown. Administrative consent entry requires explicit evidence and does not override destination hard-bounce, complaint, do-not-contact or an applicable opt-out.

Purposes include email/SMS/WhatsApp/push marketing, personalization, analytics, advertising, profiling, partner program administration and operational service; channels scope grants within them. Global marketing withdrawal dominates all marketing grants. Destination-level suppression prevents recontact via another profile with the same verified destination. Deny/withdrawal overrides weaker grant during conservative merges; unmerge restores provenance but never removes shared destination suppression. Identity verification is distinct from permission. Service/contractual exceptions cannot be used for promotional templates; the classified operation chooses the required policy.

```text
ConsentDecision evaluate(subject, identity, purpose, channel, operation,
                         processing_policy_version, field_set, destination):
  allowed: bool
  reason: known enum
  consent_revision: bigint
  erasure_epoch: bigint
  evidence_refs: UUID[]
  policy_version: UUID
  expires_at: UTC instant
```

The decision engine runs at collection, profile derivation/segment evaluation, personalization serving, queue creation and actual dispatch/transfer. It checks global/channel choices, suppression, legal-basis policy, field permission, processor approval and erasure/retention state. An audience snapshot is never permission to send later. Imported ad matching IDs do not authorize profiling. Delay/wait steps recheck current policy; new policy requirements invalidate old decisions rather than backfill grants.

Dispatch uses a short durable per-recipient/purpose gate with revision and erasure epoch. Withdrawal acquires the same gate, increments revision, writes suppression and cancels queued/retryable work before acknowledging success. A worker that has not committed its submission state must reauthorize under the gate. A worker already in flight may have crossed the network boundary; do not claim atomic revocation across an external provider. Record that boundary, attempt cancellation when supported and expose reconciliation status. No lock is held over external I/O. Ads additions carry consent revision; removals/erasure outrank additions and stale epochs are rejected during local replay. Remote processors may have asynchronous deletion delays, tracked explicitly.

Preference center offers independent channel/purpose choices and global marketing unsubscribe, accessible without login through narrowly scoped tokens. It never reveals full destination or all profile information to a forwarded-link holder. Browser privacy choices use a documented CMP adapter: a server-validated receipt with policy/version, grant time, scope and proof; arbitrary client `consent=true` is insufficient. When applicable policy honors GPC, receipt of the signal blocks sale/sharing/ad transfers without translating it into unrelated email withdrawal. Pending/unknown CMP state prevents nonessential storage/capture. Withdrawal removes optional cookies/local data and stops queued browser events; already collected data follows the configured deletion/purpose policy.

### First-party tracking and retention

Short links and QR codes work as static redirects without cookies or customer identity. Optional consented scan/click collection links opaque campaign/placement IDs with a first-party short-lived session. UTMs are length-limited, strip disallowed query fields and never include email/name. Referrer retains permitted origin/path only after PII sanitization. No fingerprinting, covert cross-site identifier, browser-protection bypass or assumption that a QR scan proves physical presence. IP is not stored in the marketing event; ephemeral abuse-control hashing expires. Contact/order linkage uses verified first-party identity/order context and current policy, not loose IP or household guesses.

| Data | Engineering default | Enforcement |
|---|---|---|
| Anonymous identity/session links | 30 days since last permitted activity | Expire and sever relationships; do not silently refresh without permission |
| Raw behavioral events/touchpoints | 90 days | Indexed time chunks; attribution window cannot exceed available permitted evidence |
| Operational logs | 30 days | Field allowlist; shorter sensitive-debug expiry |
| Message rendered bodies | 90 days | Purge body/variables; keep permitted delivery counters |
| Delivery metadata/message headers | 365 days | Minimize destination and receipt linkage |
| Audit | 365 days | Redacted records; explicit configured extension where justified |
| Consent evidence, suppression, financial ledgers | Merchant-selected documented policy at onboarding | No invented statutory universal period; legal holds narrow and reviewable |
| Personal exports | 48-hour plugin artifact default | Access-controlled token expiry and deletion; core-generated exports follow core cleanup |
| Aggregates | Configured 24-month operational default | Retain only suitably de-identified cells; small-cell suppression and rebuild capability |

Defaults are operational choices, not legal advice. A policy change may shorten retention immediately through a resumable cleanup; extending it cannot resurrect deleted data. Display oldest due row, next cleanup, estimated storage and deletion backlog in Health. Chunk by indexed `(expires_at,id)` cursor, bounded transaction and runtime. Retention never deletes an active legal hold silently; hold records include authority, scope, reviewer and expiry. Any window, feature or report dependent on unavailable evidence reports incompleteness rather than inventing results.

### Export, erasure and external transfer lifecycle

Register `wp_privacy_personal_data_exporters`, `wp_privacy_personal_data_erasers` and suggested privacy-policy content through documented WordPress privacy integration. Callbacks accept email and page, use bounded pages and return core-compatible completion/retained-item responses. WordPress's verified privacy request remains the authorization source; guest identities are included when ownership is verified. Phone/provider-only requests use a separate authenticated verification workflow and map to the same subject job. The plugin does not erase canonical WooCommerce orders or WordPress users through its private tables. [WordPress privacy hooks](https://developer.wordpress.org/plugins/privacy/privacy-related-options-hooks-and-capabilities/) and [eraser contract](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-eraser-to-your-plugin/) define these integration points.

Create a durable `PrivacyJob` with UUID, verified subject, type, policy version, erasure epoch, status, table/source checkpoints, counts, retained reasons and external tasks. Core callbacks process local work in bounded pages and report `done` honestly; long external tasks cannot be described as completed because a queue item exists. Persistent failures yield retained-items/messages referencing the operator-visible pending job. Completion UI distinguishes local completion, processor tasks pending and failed/unsupported external deletion. Retries reuse job keys; workers can resume after interruption without changing erased records back into identified state.

Erasure sequence: (1) establish erasure fence/suppression and cancel runs/deliveries, (2) enumerate verified identities plus merge provenance, (3) delete optional identity/contact/behavior/message/assignment data and invalidate caches/search indexes/segment materializations, (4) sever personal joins in retained financial records, (5) recompute or invalidate affected aggregates, (6) enqueue processor deletion/removal with proof, (7) verify no local subject references remain outside explicitly retained scope. Retained HMAC destination tombstones are pseudonymous personal data, used only to prevent forbidden reimport/recontact under an authorized retention policy; the operator must select whether their retention is justified. A pure anonymization claim requires removing reidentification paths, including rare dimensions and external receipt IDs.

Late callbacks, import jobs, analytics rebuilds and backups can restore identity accidentally. Every import/receipt consumer checks erasure epoch/tombstone before recreation. Restoration enters mandatory operator-paused mode before any workers/campaigns resume: reconcile the snapshot's durable jobs with current suppression/erasure decisions from permitted recovery evidence held outside the rollback, then replay all post-snapshot erasures/withdrawals. A restored snapshot's job history alone cannot reveal later requests. Reconcile post-snapshot provider submissions/payouts too, holding unknown outcomes instead of replaying them. If required recovery evidence is unavailable, remain paused and require manual reconciliation. Document that the plugin cannot physically remove data from host backups: host policy must expire backups and support this recovery process. Audit stores job reference/reason, not copied subject data.

Every enabled adapter declares an `ExternalTransferManifest`: processor/service and destination/region, purpose/field list, transport, credential mechanism, consent/basis requirement, retention contract, removal/export capabilities, subprocessor/documentation links and last merchant review. Email transfers destination/rendered content; SMS/WhatsApp/Telegram/push transfer destination/content/receipt data; social transfers reviewed public content/media; ads transfer permitted normalized identifiers/events; webhooks transfer explicitly selected schema fields; AI transfers approved prompt facts/aggregate summaries or separately approved personal fields. Connection alone does not enable transfer. Store ledger entries for operation/subject/purpose/fields/destination revision and deletion task, without duplicating payloads. Unsupported processor deletion appears as a required manual task with evidence, never hidden success. Plugin telemetry is off by default; opting in specifies exactly what is sent and turning it off cancels pending telemetry.

### Review resolutions

Privacy review found erasure could leave queued rendered messages and derived segment scores: the erasure fence now precedes cleanup and covers all materializations/caches. It found WordPress eraser `done` could misrepresent remote completion: responses and Health now expose retained/pending tasks. It found hashes were described as anonymous: they are classified as linkable personal data. Security/marketing review found global unsubscribe could be undone by import/merge: destination suppression and evidence revision dominate those paths.
