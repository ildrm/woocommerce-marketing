## Phase 12 — Security

### Threat model and trust boundaries

Assets are customer identities/behavior/consent, provider secrets, campaign content, affiliate payouts, loyalty balances, private exports and merchant checkout availability. Actors include anonymous visitors, shoppers, low-privilege staff, authorized operators, compromised providers, malicious webhook senders and malicious installed extensions. WordPress administrators/plugins with arbitrary PHP or database access are outside an enforceable plugin isolation boundary; the design limits accidental exposure and lesser-privilege abuse without claiming protection from a fully compromised host.

Boundaries are browser → public/administration REST, WooCommerce hooks → application events, application → plugin tables, queue → handlers, application → provider network, provider → callbacks, and operator → privacy/financial commands. Each entry adapter validates identity, input schema, scope and bounded resources before a use case. Domain authorization is repeated where asynchronous work might outlive the originating user's privileges. A provider callback proves provider provenance, not authorization to invoke arbitrary automation actions.

| Threat | Concrete mitigation and verification |
|---|---|
| Spoofed/duplicate commerce events | Accept authoritative order facts only from Woo CRUD subscribers/reconciliation; unique event/operation keys; browser order claims never create rewards |
| IDOR or privilege escalation | Capability plus object/site scope on every query/mutation; UUIDs do not constitute permission; direct-ID and cross-site tests |
| CSRF | WordPress REST cookie authentication with `wp_rest` nonce and capability check; OAuth state/PKCE where supported; no privileged/security-relevant or consent mutation through public GET; policy-permitted incidental click observation is separate |
| Stored/reflected XSS | Typed block/rule schema; restricted `wp_kses` HTML; final `esc_html`/`esc_attr`/`esc_url` or React text rendering; sandbox preview; prohibit executable template expressions |
| SQL/expression injection | Allowlisted operators/fields; bound values with `$wpdb->prepare()`; fixed identifier registry; bounded AST; no PHP/SQL/eval/imported objects |
| Credential/PII disclosure | Secret abstraction, field allowlist logging, private exports, no secrets in REST or Store API; automated canary-secret tests |
| Forged/replayed callbacks | Adapter-specific raw-body signature verification, replay guard, receipt uniqueness and bounded parsing |
| SSRF and unsafe redirect | Fixed provider domains; controlled outbound webhook domains and safe HTTP; reject private/link-local targets and redirects |
| Financial tampering/double spend | Immutable ledger/reversals, compare-and-set reserves, payout isolation, idempotent order/refund eligibility |
| Resource exhaustion | Request/payload/query limits, bounded batches, per-origin/account quotas, queue admission control, no anonymous audience queries |
| Supply-chain compromise | Pinned/scoped minimal dependencies, reproducible build, reviewed adapter registries, dependency/security scan and release provenance |

### Permissions and REST contracts

Namespace `/wmos/v1`; routes register through `register_rest_route()` on `rest_api_init`. Every route declares a permission callback, input JSON Schema, explicit validators/sanitizers and response schema. A nonce is CSRF protection within cookie authentication; `current_user_can()` and object scope authorize actions. Integrations use WordPress Application Passwords over HTTPS with a dedicated service user and narrowly granted plugin capabilities; unsupported shared API-key backdoors are absent. These follow [WordPress REST authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/) and [custom endpoint conventions](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/).

Capabilities are primitive, server-checked and separately grantable. Install grants them to administrators; a store's shop-manager receives overview/campaign-management permissions through an explicit onboarding choice, not secrets/privacy/payout authority by default. Role membership itself is never checked in application services. Site administrators lack implicit access to another multisite site's data or credentials.

| Endpoint family | Required capability; additional rule |
|---|---|
| Overview, aggregate analytics | `wmos_view_analytics`; personal drill-down additionally `wmos_view_contacts` |
| Contacts/identities/consent history | `wmos_view_contacts`; verified row scope; identity changes require `wmos_manage_contacts` |
| Consent override | `wmos_manage_consent`; evidence/reason mandatory, cannot clear hard-bounce/complaint to manufacture consent |
| Campaigns/assets/segments | `wmos_manage_campaigns`; drafts editable; publish/schedule/cancel additionally `wmos_publish_campaigns` |
| Automations | `wmos_manage_automations`; publication additionally `wmos_publish_automations`; manual/API entry `wmos_run_automations`; immutable published version |
| Promotions/rewards/loyalty | `wmos_manage_promotions`; adjustments `wmos_adjust_rewards`, reason and idempotency key |
| Affiliate review/payout | `wmos_manage_partners`; approve/export payout `wmos_manage_payouts`; separate review actor when configured |
| Integrations/secrets/paid-ad changes | `wmos_manage_integrations`; secret write-only; budget/spend mutations additionally `wmos_manage_ad_spend` |
| Privacy jobs | `wmos_manage_privacy` plus relevant `export_others_personal_data`/`erase_others_personal_data`; verified subject request |
| Logs/health/queue retry | `wmos_view_health`; retry/reconcile `wmos_operate_queue`; privacy-sensitive content separately gated |
| Import/export definitions/settings | `wmos_manage_settings`; personal export never shares this permission |

Public endpoints have explicit custom authorization rather than an unconditional administration capability check:

| Public route | Permitted authority and bounds |
|---|---|
| `POST /events` | Server-issued short-lived consent-scoped anonymous/session token; same-site origin policy; 32 KiB/20 events request, 8 KiB/event; only allowlisted behavioral events; 60 accepted requests/minute/session plus site admission budget |
| `POST /consent` | Same-site session and CSRF challenge or verified account; binds anonymous choices to that session only; cannot grant consent to supplied contact UUID/email |
| `POST /subscriptions` | Rate-limited destination challenge; generic response; double-opt-in policy where configured; no list enumeration |
| `POST /subscriptions/confirm` | Hashed 256-bit random single-use expiring purpose/channel/identity token; issue timestamp and policy version; resend does not grant |
| `POST /unsubscribe` | 256-bit scoped signed/random token; idempotent withdrawal only; RFC8058 endpoint has its own bounded POST contract |
| `POST /webhooks/{adapter}/{connection}` | Registered verifier, not shopper token; raw body limit default 128 KiB, adapter may lower; valid replay/signature proof before acceptance |
| Short link/QR GET route | Opaque public placement token and persisted HTTPS destination allowlist; redirect needs no shopper identity; telemetry only under current policy |
| `POST /referral/claim` | Verified shopper/session identity and referral token; no reward issuance or order-paid assertion |

Return generic subscription/token responses to prevent identity enumeration. Public event tokens prove only allowed capture scope, not trustworthy commerce facts; anonymous traffic can still be falsified, so analytics provenance remains untrusted and financial eligibility excludes it. Visitor event limits use a short-lived rate-control hash, not an invasive permanent device identity. `Origin`/CORS are supporting controls, not authentication.

Schemas reject additional properties, excessive nesting, invalid enum/UUID, lengths and unauthorized object IDs. Validate before sanitization; do not turn malformed IDs into valid ones. Admin list `per_page` defaults 25/max 100; reports use bounded dates and dimension registry; bulk operations max 100 IDs/request and create chunked jobs. JSON import max 2 MiB, no secrets/remote URLs/executable expressions; assets are separate validated WordPress Media references. Administrative mutations of an existing aggregate require `If-Match` revision (409 on conflict); creation has no prior revision. Durable administration commands use an idempotency key bound to actor/site/route/body digest (409 if reused with different body). Public consent/subscription/webhook routes use their scoped token/request/source idempotency protocols rather than an aggregate ETag. Exports neutralize spreadsheet formula prefixes and bound rows/files.

Errors are `WP_Error`-compatible `{code,message,data:{status,correlation_id,field_errors?,retry_after?}}`. Use 400 malformed, 401 unauthenticated, 403 unauthorized, 404 absent/not-disclosable, 409 revision/state conflict, 413 oversized, 422 valid syntax with invalid business rules, 429 quota, 503 temporary infrastructure. No stack traces/provider body. Creation 201, durable job acceptance 202 with authorized polling link, successful repeat idempotent response identical; list pagination includes totals when affordable and cursors for large histories. Permission callbacks also protect status/download endpoints.

### Secret lifecycle and safe transport

`SecretStore` exposes write/resolve/rotate/revoke references. Preferred source is configured environment/constants injected by host, never copied into options. Database-backed credentials use authenticated encryption with random nonce, key ID and site/connection associated data, backed by an independently provisioned master key outside the database. Use reviewed sodium support; do not invent cryptography or treat base64/WordPress salts as encryption. Capability detection fails closed when database secret storage lacks a configured protected key. Losing a key marks accounts disconnected; restoring a database alone cannot decrypt credentials. This protects a database-only leak; host compromise can still read the key.

UI accepts replacement secrets once and returns `configured`, masked label, version and last-tested time. Rotations create a new key/credential version, test least-privilege scopes, activate atomically and retire old version after a bounded overlap needed for callback validation. Master-key rotation re-encrypts in small resumable batches, checks decryptability before retirement. Queue payloads contain secret reference IDs only. OAuth callback validates state, exact redirect URI, authorization actor/site and PKCE where supported; encrypt refresh tokens and serialize refresh to prevent token races. Disconnect cancels new operations, attempts supported remote token revocation and documents any required manual step.

Use WordPress HTTP APIs with TLS verification, fixed adapter hosts, timeout/connect bounds and bounded response bodies. For merchant webhook URLs require HTTPS, port 443 by default, exact hostname allowlist approved by integration capability, no userinfo/fragments/IP literal ambiguity and no redirect by default. Resolve and reject loopback, RFC1918, link-local, multicast, reserved IPv4/IPv6, cloud metadata addresses and unsafe CNAME targets; revalidate each attempt. `wp_safe_remote_request()` supplies WordPress's URL/redirect safety baseline, but is not a complete egress firewall. DNS rebinding defense additionally requires connection-address validation/pinning by the supported transport or a host egress policy; unavailable enforcement disables arbitrary webhook destinations and permits fixed audited provider domains only. [WordPress safe HTTP request](https://developer.wordpress.org/reference/functions/wp_safe_remote_request/) documents the baseline.

Inbound verifier checks raw bytes before JSON parsing, content type and size, constant-time signature comparison and provider-specific timestamp tolerance (default five minutes only where provider signatures include timestamps). Providers without timestamps use authenticated event IDs/replay receipts and documented alternative verification; IP allowlisting alone is insufficient. Challenge verification endpoints expose only protocol-required responses. Webhook secrets are separate from API tokens. Commit durable receipt/outbox before a 2xx response; on persistence failure return retryable 503. Replayed valid receipts return successful acknowledgment without re-execution; invalid signatures are denied and rate limited without echoing payload.

### Audit and operational controls

Audit sensitive publication/cancellation, manual consent, reward adjustment/approval, payout exports, credentials, privacy and settings. Record actor/site, command, object UUID, UTC instant, reason, correlation and redacted changed-field names/digests. Operational logs omit message body, URLs with query tokens, addresses and raw payloads. Allowlist diagnostics instead of relying only on regex redaction. Do not expose logs to public Site Health responses. Authorized detailed payload inspection is a separate time-limited, audited debugging mode with minimization and automatic purge.

Security review corrected three design flaws: public collection originally trusted submitted contact/order identifiers, now identity is server-bound and browser commerce claims cannot award; webhook URL validation originally stopped at hostname checking, now transport/egress enforcement addresses DNS rebinding; provider test-send originally bypassed the campaign gate, now test destinations require verification, explicit purpose eligibility and the same suppression policy. Tests include cross-role REST matrix, token replay/enumeration, malicious import AST, DOM preview XSS, SSRF IPv6/redirect/rebinding, concurrent payouts and secret canaries in all output channels.
