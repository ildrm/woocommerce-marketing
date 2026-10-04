=== WooCommerce Marketing OS ===
Contributors: ildrm
Tags: woocommerce, marketing, automation, consent, loyalty
Requires at least: 7.1
Tested up to: 7.1.2
Requires PHP: 8.3
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires Plugins: woocommerce

Native campaigns, durable automation, consent, messaging, loyalty, referrals, coupons and attribution for WooCommerce.

== Description ==

Executable WordPress/WooCommerce extension with its own transactional tables, a WordPress React administration application, public WooCommerce CRUD integrations and a durable queue woken by Action Scheduler.

Includes immutable campaign/workflow versions, visual and keyboard workflow editing, rule segments with complete membership generations, explicit consent and double opt-in, current suppression checks, bounded first-party tracking, offline links and locally generated QR codes, loyalty holds and compensating refund entries, referrals, affiliate commission approval and confirmed external payout recording, native WooCommerce coupons, deterministic attribution, costs, experiment assignments, rule-based recommendations and personalization.

Email uses Resend; SMS uses Twilio; WhatsApp uses approved Meta templates; Telegram uses the Bot API; push uses OneSignal and an existing merchant subscription SDK. Social, advertising-audience operations and outgoing webhooks require a merchant-operated HTTPS relay on an explicitly approved hostname. Capabilities are displayed for each connection. Network, account approval, subscription collection and external payment execution are provided by the configured services.

No marketing permission is inferred from checkout or payment. Checkout captures only bounded local facts. Remote submissions, broadcast planning, workflow progression and privacy work run asynchronously. A transport timeout with an unknown outcome is reconciled or stopped rather than blindly resubmitted to a non-idempotent provider.

== Installation ==

1. Back up the store and database. Install the complete release ZIP through Plugins > Add New > Upload Plugin.
2. Use PHP 8.3 or newer with JSON, sodium and mysqli, MySQL 8 with InnoDB, WordPress 7.1.2 and WooCommerce 11.1.2 (the qualified release environment).
3. Configure a base64-encoded random 32-byte WMOS_ENCRYPTION_KEY in wp-config.php or the server environment. Keep the key outside the database and back it up securely; losing or changing it makes existing encrypted records unreadable.
4. Activate the plugin separately on each site. Network-wide activation is rejected; each site's tables and settings remain separate.
5. Open WooCommerce > Marketing. Configure the policy version, retention, timezone, sender and desired execution modules in Settings. Commerce capture and nonessential tracking start disabled.
6. Connect and enable each provider, acknowledge its policies, and configure signed receipt callbacks. Credentials are encrypted and never returned by the REST API. Add WMOS_RELAY_HOSTS in wp-config.php only if an approved public HTTPS relay is used.
7. Configure a real system cron to run WordPress cron/Action Scheduler. The wp wmos tick command runs one bounded durable queue tick; wp wmos status shows scheduler/schema health.
8. Publish reviewed definitions before activation. Use a small verified, explicitly consented audience to qualify each real provider connection before a broad campaign.

== Storefront ==

Add [wmos_preferences] to a privacy-preferences page. The first-party consent bridge is window.wmosConsent.setPreferences(purposes), where approved purposes are analytics, personalization and profiling. Collection starts denied and stored preferences expire after 30 days or a policy-version change.

Add [wmos_personalization uuid="published-definition-uuid"] to render public fallback content safely in shared page caches. Private personalization resolves only for an authenticated, authorized contact with current purpose consent and uses private, no-store responses.

The optional email marketing checkbox starts unchecked in both classic and Checkout Blocks checkout. The shopper must confirm the separately queued security email before marketing enrollment. Confirmation and human unsubscribe links display a form on GET; GET link scanners never change consent. RFC 8058 one-click unsubscribe uses POST.

== Operations and data ==

Deactivation cancels plugin scheduling and prevents worker effects while preserving history. Uninstall preserves data by default. Setting the explicit wmos_remove_data option before uninstall removes only plugin tables and options; it does not delete WooCommerce orders, products, users or shared scheduler tables. Review financial retention obligations before enabling removal.

Use WordPress personal-data exporters/erasers or the contact's Privacy controls. Local erasure immediately suppresses queued marketing, then processes bounded jobs. Minimal suppression evidence and configured financial obligations may remain. Removal by external processors is explicitly marked for merchant review and is never falsely reported as completed.

Amounts use integer minor units, currency and exponent. Reports keep currency/exponent groups separate, account for cumulative refunds, and state the attribution definition. Attribution is correlation and does not establish incremental causation. Open events depend on lawful provider observations and are not treated as reliable delivery proof.

== Release evidence ==

release-evidence.json records the exact qualified source digest, runtime matrix and gates. checksums.json records every packaged file. The ZIP excludes tests, local test stores, development tools and credentials. Refer to the repository's docs/RELEASE.md for supported behavior and qualification boundaries.

== Changelog ==

= 1.0.0 =
* Initial executable release implementation and qualification.
