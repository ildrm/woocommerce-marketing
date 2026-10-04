## Phase 7 — Event catalog

### Envelope and schemas

An event states a fact, not a request to perform arbitrary executable work. Names use `context.subject.past_tense`, begin with a registered namespace and are stable under minor schema additions. Schema registry keys are `(event_name,schema_version)`; the initial catalog below is version 1. The serialized public envelope is:

```json
{
  "event_id": "12a5ec62-0da7-46b7-a7e1-65f013da6726",
  "schema_version": 1,
  "event_name": "commerce.order.paid",
  "occurred_at": "2026-10-04T08:00:00.000000Z",
  "recorded_at": "2026-10-04T08:00:00.050000Z",
  "contact_id": "a7a6c3ab-e4cc-451f-ae3a-a64305f112d1",
  "user_id": null,
  "session_id": null,
  "object": {"type": "woo_order", "id": "812"},
  "campaign_id": null,
  "automation_id": null,
  "channel": null,
  "source": "woocommerce",
  "provider": null,
  "correlation_id": "53cd80b6-f672-4d5e-a5c7-f511ac93dc28",
  "causation_id": null,
  "idempotency_key": "order:812:first-paid:v1",
  "consent_reference": null,
  "properties": {"order_id": "812", "snapshot_revision": "e2d58d1f", "money": {"minor": "12900", "currency": "USD", "exponent": 2}},
  "context": {"blog_id": "1", "capture_adapter": "woo-order-v1"}
}
```

Required: event ID, name, schema version, occurred/recorded UTC instants, source, correlation ID, source-qualified idempotency key, properties and context. The remaining fields are nullable and schemas define when they must be present. `contact_id` is CustomerProfile UUID; `user_id` is WordPress numeric identifier serialized as string, never another interpretation of contact. `session_id` is a scoped pseudonymous token or its hash representation where permitted, never a reusable credential. Provider is connection UUID, not a secret token. Automation/campaign IDs are public UUIDs; version IDs, run IDs and message IDs appear in typed properties when relevant. Stored events resolve internal IDs but retain origin profile UUID to explain merge provenance.

`object.id` follows object type: Woo numeric references are decimal strings and plugin references are UUIDs. No consumer assumes it is a posts-table ID. `consent_reference` identifies capture decision/evidence where required; a commerce accounting fact can have a documented operational processing basis without marketing consent. That fact does not authorize marketing actions. The dispatch decision engine independently evaluates intended purpose.

All objects disallow unknown properties by default. Registry schemas explicitly allow a namespaced extension object with registered field schemas, maximum length and privacy purpose; arbitrary extension JSON is rejected. Trusted internal event total serialized size ≤32 KiB; `properties` and `context` each bounded within that total; strings ≤1,024 bytes except specifically smaller schema fields; arrays ≤50 elements; depth ≤8. Public browser intake is stricter: ≤8 KiB per event, ≤20 events and ≤32 KiB total per batch, so a batch cannot contain twenty maximum-size events. Provider webhook staging defaults to ≤128 KiB, with an adapter permitted to set a lower limit; a larger contract requires explicit security review. No raw cart/order dump, email/phone, IP, full URL, HTML content, provider response body or credential belongs in the envelope. Product IDs/quantity counts are bounded; larger line allocations reside in projection tables.

Schema evolution: additive optional fields with unchanged meaning are compatible under the same version and published registry revision; required fields, changed meaning, type or unit produce a new integer schema version. Persist original payload; consumers use a registered pure upcaster for supported old versions without rewriting history. Unsupported versions are quarantined with safe diagnostics; unknown types do not invoke wildcard side effects. Import/export includes schema versions. Consumer semantic version is pinned in receipt; replay into new consumer code is an explicit isolated rebuild, not an automatic second live side effect.

### Catalog and semantic deduplication

Below `E` = trusted plugin/server publisher, `B` = consented browser observation, `P` = authenticated provider callback, `A` = authorized admin/API command result. Every row inherits envelope limits and classification. Keys are source-qualified and hashed into `events.source_key`; an explicit stable source fact key matters more than the example string syntax. Browser timestamp is untrusted, clamped to an allowed window and accompanied by server recorded time; a browser cannot assert order payment, reward or message delivery.

| Event(s) | Publisher; trigger/fact boundary | Required properties and deduplication unit |
|---|---|---|
| `commerce.order.created` | E; supported Woo creation observer, loaded current order | order_id, canonical digest, created date; order ID + creation fact. |
| `commerce.order.paid` | E; current order confirms first recorded payment under configured payment semantics | order_id, snapshot_revision, money; persistent order:first-paid key. COD/manual status alone is not payment proof. |
| `commerce.order.completed` | E; transition into completed with fresh public order state | order_id, transition digest, from/to status; order+semantic transition revision. A repeat completion can be a new transition, never a new first purchase. |
| `commerce.order.cancelled` | E; canonical cancel transition | order_id, transition digest, reason_code if safe; order+transition revision. |
| `commerce.order.refunded` | E; refund creation/change projection confirms actual refund | order_id, refund_id, source_revision, signed amount tuple; refund ID+revision. Partial/full are distinguished; not each duplicate status hook. |
| `commerce.order.updated` | E; relevant current facts change | order_id, source_revision, changed-field names; order+digest; supports reconciliation/corrections. |
| `commerce.product.viewed` | B; lawful rendered product view observation | product_id, variation_id?, surface; session+client event UUID; not a sale or guaranteed human view. |
| `commerce.product.added_to_cart`, `commerce.product.removed_from_cart` | E/B; supported cart adapter accepted mutation, or browser observational event marked nonauthoritative | product_id, variation_id?, quantity, cart_generation, cart_ref; cart+generation+mutation operation key; distinguish observations from verified mutations. |
| `commerce.cart.created`, `commerce.cart.updated` | E; permitted minimal cart observation committed | cart_ref, generation, item_count, eligible total?; cart+generation+digest. |
| `commerce.cart.abandoned` | E; scheduler atomically claims unchanged observed generation after timeout | cart_ref, generation, last_seen_at, policy_version; cart+generation+abandonment policy. Fresh activity/payment invalidates action at execution. |
| `commerce.checkout.started` | E/B; supported checkout view/session observation | cart_ref?, generation?, flow classic/blocks; session+checkout attempt. |
| `commerce.checkout.completed` | E; Woo has created resulting order after successful checkout processing | order_id, checkout_attempt_ref?; order+checkout completion; explicitly not payment/delivery. |
| `customer.registered`, `customer.logged_in` | E; WordPress public observer | user_id, association_proof_ref?; registration:user ID; login:session operation UUID. |
| `customer.identity.linked`, `customer.identity.retired` | E; identity transaction committed | profile_uuid, identity_uuid, proof_ref?, revision; identity+revision. |
| `customer.profile.merged`, `customer.profile.unmerged`, `customer.profile.erased` | E/A; fenced job completes | merge/job UUID, relevant origin/target UUIDs only where retained lawfully, epoch; job+completion operation. |
| `customer.consent.changed`, `customer.suppression.changed` | E/A/P; scope/head transition committed | scope_hash, purpose, channel, status, revision; scope+revision. High-priority cancellation consumer. |
| `customer.segment.entered`, `customer.segment.exited` | E; complete generation published and diff committed | segment_uuid, rule_revision, generation, profile UUID; segment+generation+profile+direction. |
| `campaign.started`, `campaign.paused`, `campaign.completed`, `campaign.cancelled` | E/A; guarded state transition committed | campaign_uuid, campaign_version_uuid, transition revision; campaign+revision. |
| `automation.run.entered`, `automation.run.completed`, `automation.run.exited`, `automation.run.cancelled`, `automation.run.failed` | E; durable run transition | run_uuid, automation_version_uuid, revision, reason_code?; run+revision. |
| `message.queued` | E; one logical message/outbox intent committed | message_uuid, channel, purpose, scheduled_at; logical message UUID+queued state. |
| `message.sent` | E/P; provider acceptance confirmed/reconciled | message_uuid, delivery_uuid, provider_message_ref?; accepted operation key. **Sent means provider accepted, not delivered.** |
| `message.delivered`, `message.failed` | P/E; verified receipt or terminal dispatch failure | message_uuid, delivery_uuid?, reason_code?; provider fact ID or normalized terminal operation key. A temporary retry attempt is not final failed. |
| `message.blocked`, `message.cancelled`, `message.ambiguous` | E; policy block, cancellation or uncertain transmission | message_uuid, operation_uuid, reason_code; message+operation+transition. Ambiguous is neither failed nor sent. |
| `message.opened`, `message.clicked` | P/B; permitted observation, signature/token checked | message_uuid, link_uuid? (click), observation_quality; provider receipt/event UUID. Proxy opens and bot clicks flagged; not guaranteed human engagement. |
| `message.unsubscribed` | P/E; signed unsubscribe request applied to consent/suppression | message_uuid?, scope_hash, revision; consent transition key; event emission never delays suppression. |
| `promotion.viewed` | B/E; permitted offer impression | promotion_uuid, placement/surface; client UUID+session. |
| `promotion.applied` | E; supported Woo cart/order confirms acceptance | promotion_uuid, policy_uuid, coupon_ref?, cart/order ref; Woo operation+policy+application revision. Cart apply does not imply redeemed order. |
| `tracking.link.clicked`, `tracking.qr.scanned` | E/B; redirect observation legally recordable | link_uuid, qr_uuid?, placement_uuid?, observation_quality; server observation UUID. Redirect succeeds even if event capture fails; scan is not unique person. |
| `conversion.recorded`, `conversion.corrected` | E; current projection revision commits | conversion_uuid, revision_uuid, policy_uuid, amount tuple, correction_ref?; conversion+revision. |
| `attribution.calculated` | E; complete immutable result set published | result_uuid, conversion_revision_uuid, model/version, input_digest; result UUID. |
| `referral.created`, `referral.qualified`, `referral.converted`, `referral.reversed` | E/A; guarded qualifying policy/state transition | referral_uuid, policy_uuid, conversion_uuid?, transition revision; referral+revision. |
| `commission.approved`, `commission.paid`, `commission.reversed` | E/A; ledger/liability transition commits | commission_uuid, policy_uuid, amount tuple, payout_uuid?; commission+revision or reversal UUID. |
| `reward.issued`, `reward.redeemed`, `reward.reversed` | E; benefit state confirmed | reward_uuid, beneficiary profile UUID, policy_uuid, operation_uuid; reward+operation+state. |
| `loyalty.points.earned`, `loyalty.points.redeemed`, `loyalty.points.expired`, `loyalty.points.adjusted`, `loyalty.points.reversed` | E/A; immutable account ledger transaction | account_uuid, ledger_entry_uuid, signed points decimal string, account revision; entry UUID. |
| `experiment.assigned`, `experiment.exposed`, `experiment.converted` | E/B; assignment transaction, permitted exposure, qualified conversion | experiment_version_uuid, assignment_uuid, variant_uuid, exposure/conversion UUID as relevant; assignment UUID / exposure UUID / assignment+conversion+metric policy. |
| `offline.placement.started`, `offline.placement.completed`, `marketing.event.registered`, `marketing.event.attended` | E/A; management/registration state commits | placement/event UUID, registration UUID?; aggregate+revision. No assertion physical execution occurred without evidence. |

Trusted publisher rights are scoped to event namespaces; registered extensions cannot inject arbitrary built-in server facts through an anonymous tracking endpoint. Browser event replay protection and rate limits apply without a login nonce being mistaken for authorization. Inbound webhook raw payloads are short-lived encrypted staging records; transformed events contain only normalized fields.

### Capture, routing, ordering and failure

Server/plugin-state events insert state+event+outbox in one short InnoDB transaction. Storefront observational capture performs bounded validation and the minimum durable insert, with no segments, remote calls or fan-out. A failed marketing write must not fail checkout; log a redacted failure indicator/health backlog and reconcile commerce facts later through public APIs. That means some ephemeral browser observations can be lost; no false exactly-once capture claim is made. API capture returns accepted only after persistence; provider webhook 2xx follows a durable verified receipt, not merely a successful signature check.

Outbox pump emits at most a bounded batch of namespaced/grouped Action Scheduler wakeups. Scheduling is a hint: an existing committed outbox record survives an enqueue outage. A periodic pump finds pending/due/expired-lease rows. Workers claim through atomic lease/CAS and the operation/consumer receipt decides whether effects are already done. Per-consumer event receipts are unique; successful consumer transactions commit projection/run transition and receipt/outbox together. Large fan-out persists a cursor and emits a bounded continuation, never a million actions in a web request.

There is no total distributed ordering. Within one aggregate, transitions carry monotonic revisions and stale events cannot regress current state. `occurred_at` governs attribution windows, `recorded_at` and ID govern processing cursors. Late refund/correction facts reopen affected buckets; future/suspicious provider timestamps are bounded and flagged. A provider callback preceding send response is kept in verified inbox and matched later by stable provider key/reference; unmatched receipts expire to an operator-visible quarantine, not silently drop.

Retry temporary failures with bounded exponential backoff and full jitter; invalid schema/authorization/permanent policy failure are terminal. Local event consumers default to eight retries after initial attempt within 24 hours; external providers default to five retries after initial attempt within 24 hours and additionally respect delivery deadline and provider idempotency lifetime. Delay is sampled from `0..min(3600s,30s*2^retry_index)` with retry_index starting at zero; module overrides are documented. Dead letters preserve safe code, attempts, correlation and IDs, expose manual retry with capability/audit, and reuse the same operation. Replay CLI requires purpose permissions, date/ID bounds, consumer selection and dry-run; analytics rebuild writes a new generation. Erased subjects and expired purpose are filtered before replay. Events retained for accounting never become a consent bypass.

The event review corrected “sent equals delivered,” duplicated Woo status facts, unchecked browser authority, and replay into a new live consumer generating second sends. Explicit acceptance semantics, canonical fact keys, publisher scopes and isolated rebuild generations address these flaws.
