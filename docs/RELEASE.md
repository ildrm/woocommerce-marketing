# Release 1.0.0

This is an installable native WordPress plugin. The distribution contains the PHP runtime, production Composer autoloader, WordPress administration assets, translation catalog, dependency notices and checksums. Upload the ZIP from `dist/` through WordPress Plugins. No build tools are required on the merchant's server.

## Supported installation

The qualified environment uses WordPress 7.1.2, WooCommerce 11.1.2, MySQL 8.0.46 with InnoDB, PHP 8.3.35 and PHP 8.5.8. Qualification covers both legacy order storage and HPOS with synchronization enabled. The plugin uses public WooCommerce order/product/coupon CRUD and the official additional Checkout Blocks field API. It does not query WooCommerce's canonical tables directly.

Set an external `WMOS_ENCRYPTION_KEY` containing a base64-encoded random 32-byte key before activation. Store it in `wp-config.php` or the server environment, outside the database; protect and back it up with the database. Contact destinations, identity recovery material, provider credentials and outbound content use authenticated encryption. Losing the key makes those records unreadable; changing it requires a separate key migration.

Activate separately per site. Network-wide activation is rejected. Enable only the modules you intend to use, set the current consent policy version and retention, and connect approved provider accounts. Commerce capture and nonessential tracking begin disabled. Keep a system cron driving WordPress cron/Action Scheduler; `wp wmos status` reports health and `wp wmos tick` runs one bounded tick.

## Executable behavior

| Area | Implemented behavior |
| --- | --- |
| Administration | Native WordPress components, capability-scoped REST writes, draft editors, workflow canvas and keyboard outline, local QR generation and SVG export, contacts, channel connections, reports and queue controls. |
| Definitions | Immutable published versions, revision checks, validated workflow DAGs and pinned referenced policies. Campaigns have scheduled/running/paused/cancelled/completed states. |
| Audiences | Nested three-valued rules, indexed scalar queries and bounded fact batches; full segment generations publish atomically. Republished rules require a new materialization before a campaign can use them. |
| Automation | Durable entry/step identities, branches, wait events, timezone-aware delay, split/join, experiments, child workflows and cancellation. Tag, message, coupon, points, review-request and webhook actions execute through the same guarded services. |
| Consent | Explicit scoped grants/withdrawals, current profile/destination suppressions, queued email double opt-in and exact challenged-address verification. Purchase or payment never grants marketing consent. Human confirmation/unsubscribe GETs only display forms; mutation requires POST. |
| Commerce | Classic and Blocks checkout capture bounded local facts; order/refund reconciliation is queued and repeatable. Cart reminders are cancelled on checkout acceptance and check their cart generation again before sending. |
| Programs | Integer point accounts, expiring lots, reservation holds, redeem/release, order-bound compensations, referral qualification, held affiliate commissions and approved payout references. Refund corrections preserve original policy and financial units, including deletion/recreation of refund records. |
| Measurement | Integer currency/exponent accounting, refund-corrected conversions, separate currency groups, campaign costs and seven deterministic attribution models. Oversized touch histories use exact grouped allocation. |
| Storefront | Default-denied first-party collection, consent preferences, same-store short links, public fallback personalization, private authorized responses and deterministic public-product recommendations. |
| Operations | Leased durable jobs, idempotency, bounded retry/backoff, current cancellation fences, safe audits, resumable schema actions, privacy export/erasure and bounded retention. Unknown transport outcomes stop for reconciliation rather than automatic duplicate submission. |

Content/SEO, asset, offline/event, influencer and partner workspaces manage validated published records and tracking placements. They do not automatically publish WordPress pages, register attendees, negotiate contracts or purchase advertisements. Affiliate payout entries record confirmed external execution; the plugin does not move money itself.

## Provider and extension boundaries

Resend email, Twilio SMS, approved Meta WhatsApp templates, Telegram Bot API and OneSignal push have real HTTP adapters. Push requires the merchant's existing OneSignal subscription SDK and verified subscription identifiers. Social, advertising-audience actions and webhooks use a merchant-operated HTTPS relay on a fixed approved public hostname. The relay must implement its documented signed requests/receipts; the plugin does not impersonate native integrations with every social network.

Provider payloads and acceptance/rejection/rate-limit/unknown outcomes are tested with intercepted HTTP. No release test uses merchant credentials or sends a real campaign. The merchant must qualify its live accounts, approved templates, callback signatures and destinations before enabling delivery. Account approval, provider policy, deliverability, and processor erasure remain external responsibilities. External privacy removal is visibly marked for review, never silently declared complete.

The stable extension interface is `Wmos\Contracts\V1\Provider`, registered through `wmos_register_providers`. The compatibility interface `Wmos\Contracts\Provider` extends V1. Capabilities disclose supported channels and idempotency/receipt behavior. ML recommendation services and AI copy generation are optional future integrations; this release implements deterministic strategies and merchant-reviewed content.

## Audience and privacy semantics

Segment broadcasts pin the materialized generation and published rule version. An audience of all profiles is a bounded cursor walk of currently active profiles rather than an immutable recipient snapshot. Every message checks the current contact, consent, suppression, provider/module state, owner cancellation and relevant cart generation at submission.

Purchase product/category/coupon and open/click facts use the retained 30-day observation window. Missing, capped, truncated, mixed-unit or unauthorized evidence evaluates as unknown, rather than making a negative assertion. These detailed profiling facts require their own explicit account-purpose consent and workflows refresh them at condition evaluation. Browser analytics and shopping reminders do not imply profiling or email enrollment. Current account withdrawal overrides a stale browser cookie. Browser preferences expire after 30 days or a policy-version change; account grants remain explicit history until withdrawn or suppressed.

`facts.rfm` is an explicit fixed score string such as `R5-F3-M2`. Recency scores use 7/30/90/180-day bands; lifetime frequency boundaries are 1/2/5/10. Monetary bands require four increasing integer minor-unit thresholds from the `wmos_rfm_monetary_thresholds` filter for the exact currency/exponent. Without a configured compatible monetary scale, RFM remains unknown. Loyalty facts omit totals when multiple independent program accounts would mix point units.

Local erasure immediately blocks marketing, then removes personal observations, execution context and identity merge recovery material in bounded pages. Configured financial obligations and minimal suppression evidence may remain. Uninstall preserves history by default. Explicit `wmos_remove_data` removal affects only this plugin's tables, options and capabilities; WooCommerce data remains managed by WooCommerce.

## Qualification evidence

`release-evidence.json` records the source digest, exact runtime matrix and passing release gates. Local JUnit and browser reports are generated under ignored `.runtime/qualification` and `.runtime/e2e-results.json`; development fixtures and credentials are excluded from the ZIP. `checksums.json` lists every packaged file and the adjacent `.sha256` file checks the archive.

The release checks exercise actual WordPress REST permissions/revisions/idempotency, native WooCommerce checkout/order/refund operations, database transactions and worker contention, financial compensation, consent/identity/privacy transitions, rule materialization, attribution, recommendation privacy and administration in Chrome. Browser checks cover all 21 workspaces, editing/publication, keyboard workflows, QR decoding/export, serious/critical axe findings and RTL overflow.

These checks establish behavior in the listed environment. They are not a load benchmark, all-theme/all-extension compatibility certificate, live-provider delivery test, or a claim of full WCAG conformance. Merchant-specific payment gateways, caching/CSP policies and extensions should be checked in staging before broad campaigns.
