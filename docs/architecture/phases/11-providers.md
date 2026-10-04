## Phase 11 — Provider framework

### Ownership, registration and contracts

Channel owns message semantics, rendering, destination validation and lifecycle. Provider Integration owns external protocols, credentials, account health and capability discovery. Campaign and Automation issue application commands through channel ports; they never select HTTP endpoints, use provider SDK classes or interpret provider-specific status codes. Optional adapters register only when their module is enabled. No remote discovery occurs at plugin bootstrap or checkout. A registry validates a stable adapter key, contract version, channel keys, configuration JSON Schema, adapter version and allowed endpoint domains. Duplicate keys fail registration visibly.

Contracts live under `Wmos\Contracts\V1\Provider` in `src/Contracts/V1/Provider`. IDs crossing public extension/REST boundaries are UUIDs; adapters receive typed DTOs, not database rows. Internal persistence uses the Phase 6 bigint keys and `{$wpdb->prefix}wmos_` tables. Dates are UTC instants; monetary estimates and actual charges use minor units, currency and exponent. Contract evolution adds optional capabilities or creates V2; an adapter declaring V1 is never silently passed a V2 payload.

```php
interface ProviderInterface {
    public function descriptor(): ProviderDescriptor;
    public function capabilities(AccountContext $account): CapabilitySnapshot;
    public function health(AccountContext $account): HealthResult;
}
interface EmailProviderInterface extends ProviderInterface {
    public function submit(EmailSubmission $request, DispatchContext $context): SubmissionResult;
}
interface SmsProviderInterface extends ProviderInterface {
    public function submit(SmsSubmission $request, DispatchContext $context): SubmissionResult;
}
interface PushProviderInterface extends ProviderInterface {
    public function submit(PushSubmission $request, DispatchContext $context): SubmissionResult;
}
interface WhatsAppProviderInterface extends ProviderInterface {
    public function submit(WhatsAppSubmission $request, DispatchContext $context): SubmissionResult;
}
interface TelegramProviderInterface extends ProviderInterface {
    public function publish(TelegramSubmission $request, DispatchContext $context): SubmissionResult;
}
interface SocialPublisherInterface extends ProviderInterface {
    public function publish(SocialSubmission $request, DispatchContext $context): SubmissionResult;
}
interface AdsProviderInterface extends ProviderInterface {
    public function synchronize(AudienceDelta $delta, TransferContext $context): SyncResult;
    public function importPerformance(ReportCursor $cursor): ReportPage;
}
interface WebhookProviderInterface extends ProviderInterface {
    public function deliver(WebhookSubmission $request, DispatchContext $context): SubmissionResult;
}
```

Separate optional `ReceiptLookupInterface`, `WebhookVerifierInterface`, `RemoteDeletionInterface`, `TemplateCatalogInterface`, `ScheduledSubmissionInterface`, `AiAssistanceInterface` and `CancellationInterface` describe real operations. A marketing action requires a `DispatchContext` from the application gate: message/delivery UUIDs, stable operation key, current consent-decision reference/revision, purpose, account, policy version and expiry. Ad audience upload and remote personalization use `TransferContext` from the same policy engine; being a non-message operation does not bypass privacy. Adapters cannot construct an approval themselves. PHP extensions are trusted server code, so this is an enforced application boundary and audited extension obligation, not a sandbox against malicious plugins.

DTOs have constructor validation and immutable fields. Common submission fields are account/sender reference, one resolved destination or approved public publication target, immutable content digest, locale, template revision, allowlisted variable map, approved media references and tracking policy. Optional fields must be declared by the channel schema; provider-native free-form request JSON is never accepted from a merchant/import. Email adds subject, sanitized HTML/plain text, from/reply-to and unsubscribe metadata; SMS adds validated text/encoding/segment estimate; WhatsApp adds approved external template/category/language and named typed components; Telegram adds verified chat/channel reference and parse mode; push adds encrypted subscription/device reference, title/body/action links and expiry; social adds account, text/media variants and publication operation. Webhooks use event/topic/schema, fixed destination subscription and minimized serialized body. No submission takes arbitrary PHP callbacks or customer attributes outside the approved field set.

`SubmissionResult` is a tagged union: `accepted(provider_receipt, accepted_at, estimated_cost)`, `rejected(error)`, `ambiguous(error, reconciliation_hint)`. Acceptance means accepted by the provider, not delivered. `TransferContext` for a batch carries per-subject child operation keys, decision revisions and erasure epochs, not one permission for the whole list; each item's durable transfer ledger supports later removal/export. `SyncResult` includes per-item acceptance/failure and a cursor; a batch success never marks failed recipients sent. Raw credentials are obtained inside the adapter from `SecretStore`, never returned in these DTOs. Receipt lookup uses the original operation key/receipt and never resubmits the message.

### Capabilities and policy

Persist account-specific snapshots with `adapter_version`, `api_version`, `observed_at`, `expires_at`, safe account revision, source and policy revision. Capabilities include scheduling, batching and maximum batch size, templates and template status, media types/size, locale support, delivery receipts, click/open tracking, marketing/transactional modes, unsubscribe headers, verified webhooks, cancellation, receipt lookup, provider idempotency support and retention period, audience deletion, analytics import and cost availability. Limits include characters/encoding segments, required account scopes, recipient eligibility, country restrictions, quotas and provider throttles. `unknown` differs from `false`; publication fails for required unknown features. Limits are typed quantities, never a free-form promise of parity.

Policy evaluation returns `allowed`, `blocked(reason)`, `defer(until)` or `review_required`, with policy/version/evidence. Country, age or service-window requirements use only trustworthy, legitimately collected evidence; unknown evidence blocks the restricted action. Account permission and template approval can disappear after publication, so dispatch checks freshness and revalidates mutable requirements. Changes invalidate affected drafts and pause pending actions with a repair link. Provider-specific policy adapters are versioned and tested against official documentation at each release; law and provider terms are not replaced by a generic marketing toggle.

Local scheduling is the default, allowing consent/quiet-hour checks immediately before sending. Remote scheduling is available only when cancellation and reconciliation semantics satisfy the selected policy; otherwise reject that option. A queue delay never counts as an actual provider send. Quiet hours use a confirmed recipient timezone, then configured fallback region/timezone, and finally a conservative merchant window. Every saved schedule includes IANA zone, resolved UTC instant and the chosen behavior for DST gaps/overlaps; no inferred precise location is needed.

### Dispatch, receipts and failure semantics

Preparation creates an immutable rendered revision with allowlisted substitutions and a bounded recipient-specific payload. A Message reserves one recipient/channel/logical-send operation using the Phase 6 unique `logical_key`; its Operation holds stable provider idempotency identity. Each actual network attempt has a Delivery row with unique `(message_id,attempt_number)`, sharing that operation. Worker claims use a database compare-and-set lease/fencing token; stale workers cannot change durable state. Final authorization checks campaign/run cancellation, erasure state, identity ownership, current consent/suppression, frequency caps, quiet hours, account policy and budget. Reserve quota/cost atomically; reconcile actual costs later without treating estimates as spend.

Before network I/O commit Message `dispatching`, Delivery `attempting` and stable idempotency key. Do not hold a database transaction over a network call. Following timeout, crash or lost response, mark/recover `ambiguous`, not `failed`. For provider idempotency, use the same key and identical payload only within the verified provider key lifetime. For receipt lookup, reconcile first. With neither guarantee, stop automatic retry; show “submission outcome unknown,” allow a privileged operator to reconcile or consciously create a new operation with duplicate-risk acknowledgment. Exactly-once external delivery is not promised. A worker crash after acceptance and before persistence follows this same recovery path.

Message states: `planned → queued → held|dispatching → submitted|blocked|cancelled|failed|ambiguous`; held can return to queued after gates permit it. Delivery states: `prepared → attempting → accepted|rejected|ambiguous`; `accepted → delivered|bounced|complained|expired|failed` where supported by documented provider outcomes. Ambiguous resolves to accepted/rejected or an audited manual closure; accepted submission makes Message submitted, while receipt outcomes remain on Delivery. Opens/clicks are independent observations, not states. Receipts may be duplicated or reordered. A provider idempotent retry can return the same receipt: only its canonical Delivery owns the unique provider receipt reference; other attempt rows point to `canonical_delivery_id` and do not count as additional sends. Store receipt evidence idempotently against that canonical owner, then apply provider transition rules; late acceptance cannot override verified permanent bounce. Unknown status is retained safely for adapter repair.

| Normalized error | Automatic behavior | Merchant repair |
|---|---|---|
| temporary/unavailable | Retry only known unaccepted operation or verified idempotent submission | Show backlog and provider health |
| rate limited | Honor valid Retry-After; account-wide token bucket and randomized release | Reduce rate or wait |
| quota/budget exceeded | Pause scope until renewal or approval | Increase authorized budget/quota |
| authentication/scope | Circuit breaker for account; no hot retry | Reconnect/rotate, then retry safe operations |
| invalid recipient/permanent rejection | Terminal; suppress appropriate identity reason | Correct verified identity |
| consent/policy blocked | Terminal for this operation, or defer for a known quiet-hour boundary | Obtain valid authorization/new operation |
| template rejected | Pause template's pending deliveries | Approve a new template revision |
| ambiguous | Reconcile; no blind resend | Evidence-based reconciliation |

Known retryable failures use exponential full-jitter backoff `random(0,min(3600s,30s×2^attempt))`, default five retries over at most 24 hours, respecting later provider Retry-After and campaign expiry. Adapter policy may lower limits. A circuit opens after five account-wide transient failures in 60 seconds; one guarded half-open health probe occurs after five minutes. Rate buckets use provider/account/channel quotas with durable atomic reservations; object cache is an acceleration only. Failure details include safe provider code, diagnostic category and correlation ID, never a recipient or full response body. Manual retry re-runs every gate.

### Channel execution

Email uses an API/SMTP service adapter with durable submission semantics; no PHP `mail()` bulk engine. Sender profiles require verified sending domain/from-address evidence where the provider offers it. Setup surfaces provider-specific SPF/DKIM/DMARC guidance and verification health, not a false deliverability guarantee. Render HTML with an approved block schema, plain-text alternative and contextual escaping. Per-purpose unsubscribe, global preferences, hard-bounce and complaint suppression apply before dispatch. Separate delivery, open and click capabilities; privacy proxies/bots make opens observational. Marketing email supports RFC 8058 one-click headers where the selected provider can supply the necessary signed message headers; the HTTPS POST token is scoped to unsubscribe and changes no other state. Ordinary browser GET opens a preference page without side effects. This distinction prevents link scanners from unsubscribing through GET. [RFC 8058](https://www.rfc-editor.org/rfc/rfc8058.html) specifies that one-click mechanism.

Welcome, newsletter, nurture, cart/browse abandonment, back-in-stock, price drop, birthday, anniversary, post-purchase, cross-sell, upsell and win-back are versioned workflow templates, not separate send engines. Commerce receipts remain owned by WooCommerce; adding promotional material is a separate marketing-purpose decision, never a transactional exemption created by the plugin.

SMS adapters validate E.164 destinations using country-aware validation without assuming a phone is reachable or consensual. Cost estimation uses GSM/UCS encoding and provider segmentation rules, then actual receipts replace estimates. STOP/opt-out callbacks write suppression before downstream processing. WhatsApp template ID/language/category/status and variables are pinned to a published message revision; dispatch rejects disapproved or mismatched templates. Current WhatsApp policy requires opt-in and approved templates for initiated conversations, and restricts free-form replies to its customer service window; provider restrictions can change, so the adapter owns a refreshable window/template gate. [WhatsApp Business Messaging Policy](https://whatsappbusiness.com/policy/) was checked for this design.

Telegram distinguishes bot-to-user messaging with verified chat authorization from bot-to-channel publication with required channel rights; arbitrary address-based promotional outreach is unavailable. Bots cannot initiate a user conversation, so signup requires a user interaction/deep-link challenge and explicit purpose permission. [Telegram bot documentation](https://core.telegram.org/bots) describes this boundary. Web push requires permission plus purpose consent, application-scoped subscription endpoint, encrypted key storage and deletion on expiry/withdrawal. Mobile push integrates existing merchant applications through a provider adapter; this plugin does not manufacture a mobile app. No push/SMS fallback is automatically enabled merely because email fails.

Social publishing creates account/platform-specific text/media variants, validated limits, explicit scopes and merchant-reviewed schedules. Media URLs are generated from approved public campaign assets, never arbitrary uploads containing private exports. Upload, publish and status reconciliation are separate steps with stable operation keys. Imported engagement metrics retain provider definitions and reporting windows. Public channel publishing normally has no individual recipient gate, but audience targeting, customer-based variants and transfers do. Content Marketing owns editorial brief, assets, landing-page references and publication tasks; WordPress owns posts/editor, while SEO Integration reads public extension contracts and offers metadata/tasks. No ranking guarantee or universal social publishing capability is promised.

Advertising integrations support consent-filtered audience deltas, removal queues, permitted conversion uploads and performance/cost import only when the adapter/account exposes them. Hashed email/phone remains personal data; record its transfer purpose and normalize only as that provider documents. Removal after withdrawal has priority over additions; consent revision and erasure epoch fence stale uploads. Provider ROAS is shown separately from first-party attributed ROAS. Budget changes, paid ad creation and automatic spend require a dedicated explicit merchant permission and preview, even when credential connection exists.

Outbound webhooks use a versioned allowlisted event schema and redact customer fields by default. Signed delivery includes event ID, timestamp, key ID and HMAC over exact body; consumers deduplicate by event ID. Optional AI assists copy, subject lines, campaign ideas, explanations and summaries through an isolated adapter; default input contains merchant-approved product/content facts and thresholded aggregate metrics. Customer-level input requires explicitly configured purpose/legal basis and transfer disclosure. Generated copy remains a draft requiring merchant approval; no AI dependency exists in dispatch, segmentation or checkout.

### Promotion and partner execution contracts

Promotion delegates monetary calculation to WooCommerce public coupon/cart APIs. A published promotion records its immutable eligibility/rule version, referenced native coupon IDs and operation keys. Percentage, fixed-cart, fixed-product, category/product restrictions, usage limits, time windows and free shipping map to native coupon properties where supported. First-order, VIP, birthday and segment restrictions add server-side eligibility checks through `woocommerce_coupon_is_valid`; avoid newer unqualified hooks below the supported version floor. Coupon creation/update uses `WC_Coupon` CRUD; availability is always rechecked by WooCommerce at checkout. Plugin eligibility is not a discount total copied from JavaScript. The verified public references are [WC_Coupon](https://woocommerce.github.io/code-reference/classes/WC-Coupon.html) and [coupon validation source](https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-discounts.html).

Buy-X-get-Y, quantity and bundle discounts require an optional strategy with tested public cart/coupon hooks and Blocks parity, or a compatible promotion extension adapter; publication rejects native execution when that strategy is absent. The merchant may still represent the same idea as a tracked offer and manual fulfillment task. Free-shipping coupons additionally depend on merchant shipping-method configuration. Native coupon stacking, taxes, rounding and WooCommerce usage counts remain authoritative. A campaign pause can disable plugin-managed eligibility/new issuance; shared merchant coupons are never silently deleted. Reward-issued coupon creation is a durable job with deterministic ownership metadata and reconciliation before retry.

Affiliate, Referral and Loyalty issue typed reward/commission commands from verified paid-order conversion events. Influencer assignments reuse tracking, offers, affiliate commission strategies, deliverables and costs; offline placements reuse tracking links/QR/creative/costs. Their financial and fraud rules are specified in Phase 15. Outbound actions from partner programs still use the same consent and provider gates. Co-marketing/partnership and applicable B2B account concepts use authorized organization attributes, contacts, assets and shared campaign assignments rather than a second CRM.

### Review resolutions

Marketing review found a generic provider API would hide channel restrictions: replaced it with channel DTOs plus account capabilities/policy. Automation review found timeouts could duplicate messages: ambiguous outcomes now require reconciliation. Privacy review found audience uploads and AI summaries could escape message consent: all external personal-data operations now require transfer decisions. Commerce review found advanced promotion types could imply unsupported pricing manipulation: native coupon mapping, tested optional strategies and explicit management-only fallback now define execution limits.
