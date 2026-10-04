# Official reference register

Verification date: **2026-10-04**, using the client's Asia/Tehran date. URLs below were opened or returned with substantive content by web browsing during this design. Documentation can describe newer APIs than the proposed minimum version; a live code-reference page is not a version-pinned compatibility certificate. Milestone 0 must record installed package versions, official release references, and the exact source tags used for contract tests. No implementation, HPOS certification, performance benchmark, legal determination, or accessibility conformance test was executed by preparing this document.

The proposed plugin floor is PHP 8.3, WordPress 6.9, WooCommerce 10.8, MySQL 8.0 or MariaDB 10.11, and transactional InnoDB for plugin tables. This is a **design policy**, deliberately stricter than some platform minimums; it is not an assertion that these are the latest releases. The WordPress requirements page recommends PHP 8.3+, MySQL 8.0+/MariaDB 10.11+, and HTTPS. The WooCommerce requirements table lists WordPress 6.9 for its WooCommerce 10.8 row. Later documentation and release advisories may coexist with that table; they do not justify inventing the latest version. Release engineering must reverify the floor and exact current stable pair before building and again before publishing.

## WordPress

| ID | Exact source | Verified assertion / use |
|---|---|---|
| WP-01 | [Requirements](https://wordpress.org/about/requirements/) | Hosting recommendations; design floor distinction above |
| WP-02 | [Activation and deactivation hooks](https://developer.wordpress.org/plugins/plugin-basics/activation-deactivation-hooks/) | Lifecycle entry points; plugin upgrade behavior is our migration design |
| WP-03 | [Adding custom REST endpoints](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/) | `rest_api_init`, namespace, arguments, validation/sanitization, permission callback, controller/response conventions |
| WP-04 | [REST authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/) | Cookie nonce flow and Application Passwords; capability authorization remains separate |
| WP-05 | [Nonces](https://developer.wordpress.org/plugins/security/nonces/) | CSRF mechanism; not authorization or one-use replay prevention |
| WP-06 | [Creating tables with plugins](https://developer.wordpress.org/plugins/creating-tables-with-plugins/) | `$wpdb->prefix`, charset/collation, `dbDelta()` syntax constraints; bounded online migrations are our design |
| WP-07 | [Enqueuing scripts and styles](https://developer.wordpress.org/plugins/javascript/enqueuing/) | Register/enqueue assets through WordPress |
| WP-08 | [Dependency extraction webpack plugin](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-dependency-extraction-webpack-plugin/) | Runtime dependency extraction and generated asset metadata |
| WP-09 | [add_submenu_page](https://developer.wordpress.org/reference/functions/add_submenu_page/) | WooCommerce parent submenu and capability parameter |
| WP-10 | [register_setting](https://developer.wordpress.org/reference/functions/register_setting/) | Settings registration, schema and sanitization options |
| WP-11 | [Personal data exporter](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-exporter-to-your-plugin/) | Plugin exporter registration and paginated callbacks |
| WP-12 | [Personal data eraser](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-eraser-to-your-plugin/) | Plugin eraser callbacks and retained/removed reporting |
| WP-13 | [wp_add_privacy_policy_content](https://developer.wordpress.org/reference/functions/wp_add_privacy_policy_content/) | Suggested policy content registration |
| WP-14 | [site_status_tests](https://developer.wordpress.org/reference/hooks/site_status_tests/) | Site Health test registration |
| WP-15 | [wp_safe_remote_request](https://developer.wordpress.org/reference/functions/wp_safe_remote_request/) | HTTP API with URL/redirect safety checks; stronger provider restrictions are our threat-model controls |
| WP-16 | [wp_set_script_translations](https://developer.wordpress.org/reference/functions/wp_set_script_translations/) | JavaScript translation catalogs |
| WP-17 | [wp_timezone](https://developer.wordpress.org/reference/functions/wp_timezone/) | Site timezone object, separate from stored UTC instants |
| WP-18 | [register_uninstall_hook](https://developer.wordpress.org/reference/functions/register_uninstall_hook/) | Uninstall callback convention; this design prefers guarded `uninstall.php` |
| WP-19 | [Privacy hooks and capabilities](https://developer.wordpress.org/plugins/privacy/privacy-related-options-hooks-and-capabilities/) | Privacy integration filters, verified-request workflow and capabilities |

## WooCommerce and Action Scheduler

| ID | Exact source | Verified assertion / use |
|---|---|---|
| WC-01 | [PHP and WordPress requirements](https://woocommerce.com/document/update-php-wordpress/) | Requirements table; not a latest-release lock |
| WC-02 | [HPOS extension recipe book](https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/) | CRUD source boundary and `FeaturesUtil::declare_compatibility` on `before_woocommerce_init` |
| WC-03 | [Order queries](https://developer.woocommerce.com/docs/features/orders/wc-get-orders/) | `wc_get_orders`, `WC_Order_Query`, IDs/pagination; `meta_query`/`field_query` support is HPOS-specific in this reference |
| WC-04 | [WC_Order](https://woocommerce.github.io/code-reference/classes/WC-Order.html) | Public order getters, metadata CRUD, refunds and monetary access; use only members public and not `@internal` |
| WC-05 | [Order lifecycle hook source](https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-order.html) | `woocommerce_payment_complete`, `woocommerce_order_status_changed`; never instantiate source's internal dependencies |
| WC-06 | [Order/refund functions](https://woocommerce.github.io/code-reference/files/woocommerce-includes-wc-order-functions.html) | `woocommerce_refund_created`, `woocommerce_order_refunded`; canonical refund through CRUD |
| WC-07 | [WC_Order_Refund](https://woocommerce.github.io/code-reference/classes/WC-Order-Refund.html) | Public refund amount/items/parent reference |
| WC-08 | [WC_Product](https://woocommerce.github.io/code-reference/classes/WC-Product.html) | Public product getters and CRUD; no replacement product store |
| WC-09 | [WC_Customer](https://woocommerce.github.io/code-reference/classes/WC-Customer.html) | Public customer access; guest profile does not require user creation |
| WC-10 | [WC_Coupon](https://woocommerce.github.io/code-reference/classes/WC-Coupon.html) | Coupon CRUD and restrictions |
| WC-11 | [Coupon validation source](https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-discounts.html) | Existing `woocommerce_coupon_is_valid` filter; newer `@since 11.1` filters are not assumed at floor 10.8 |
| WC-12 | [Cart hook source](https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-cart.html) | Add/remove/cart coupon hooks; source properties are not extension contracts |
| WC-13 | [Classic checkout source](https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-checkout.html) | Classic processed hook runs before payment; do not equate with payment success |
| WC-14 | [Store API checkout hook source](https://woocommerce.github.io/code-reference/files/woocommerce-src-storeapi-routes-v1-checkout.html) | `woocommerce_store_api_checkout_order_processed(WC_Order)` before payment; old Blocks processed hooks deprecated |
| WC-15 | [Blocks extensibility overview](https://developer.woocommerce.com/docs/block-development/getting-started/extensibility-overview) | Public filters, inner blocks, slots and Store API extensibility categories |
| WC-16 | [Cart/Checkout extensibility getting started](https://developer.woocommerce.com/docs/block-development/cart-and-checkout-blocks/extensibility-getting-started) | Public data-store access preferred; `useStoreCart` imports unsupported |
| WC-17 | [Blocks IntegrationInterface](https://developer.woocommerce.com/docs/block-development/reference/integration-interface) | Registry hooks, script/editor handles and script data methods |
| WC-18 | [Store API expose data](https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/extend-store-api-add-data/) | Register schema/data callbacks after `woocommerce_blocks_loaded`; global helpers avoid unnecessary container dependence |
| WC-19 | [Extensible endpoints](https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/available-endpoints-to-extend) | Namespaced cart/products/cart-item/checkout extension data; sensitive response data prohibited |
| WC-20 | [Hook alternatives](https://developer.woocommerce.com/docs/block-development/reference/hooks/hook-alternatives/) | Classic-to-Blocks support distinctions; cart update supported, template/core form substitutions vary |
| WC-21 | [Additional checkout fields](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/additional-checkout-fields/) | `woocommerce_init` or later registration; location/persistence; field validation and saved-value hooks |
| WC-22 | [Additional field tutorial](https://developer.woocommerce.com/docs/block-development/tutorials/how-to-additional-checkout-fields-guide/) | Tutorial states minimum WooCommerce 8.9; floor remains stricter and contract-tested |
| WC-23 | [Extension compatibility practices](https://developer.woocommerce.com/docs/extensions/best-practices-extensions/compatibility) | Test before compatibility declaration and publish tested metadata |
| WC-24 | [CRUD save hook source](https://woocommerce.github.io/code-reference/files/woocommerce-includes-abstracts-abstract-wc-data.html) | Public after-object-save hooks pass object and data store; adapters consume only object/ID |
| WC-25 | [New/update order hook source](https://woocommerce.github.io/code-reference/files/woocommerce-includes-data-stores-class-wc-order-data-store-cpt.html) | Public new/update hooks pass ID and order; no data-store dependency or post access in Wmos |
| WC-26 | [Stock hook source](https://woocommerce.github.io/code-reference/files/woocommerce-includes-wc-stock-functions.html) | Product/variation stock hooks pass product object |
| WC-27 | [Customer creation source](https://woocommerce.github.io/code-reference/files/woocommerce-includes-wc-user-functions.html) | `woocommerce_created_customer` hook docblock marked `@internal`; excluded despite conventional usage, replaced with WP user registration/public CRUD save observation |
| AS-01 | [Action Scheduler API](https://actionscheduler.org/api/) | Initialization timing, enqueue/schedule/unschedule/query helpers; domain leases/idempotency are plugin policy |

## Provider, privacy, measurement and accessibility references

These additional sources were verified on **2026-10-04**. Numeric queue limits, configured retention, permission policies and statistical decision gates remain plugin design choices; provider terms and legal applicability require rechecking for the actual store/account at release and operation.

| ID | Exact source | Verified assertion / use |
|---|---|---|
| EXT-01 | [RFC 8058 one-click unsubscribe](https://www.rfc-editor.org/rfc/rfc8058.html) | Marketing email one-click list-unsubscribe uses a scoped HTTPS POST and signed headers; ordinary browser GET is not the mutation contract |
| EXT-02 | [WhatsApp Business Messaging Policy](https://whatsappbusiness.com/policy/) | Published page states opt-in, initiated-conversation approved templates, customer service window and template/provider policy restrictions; displayed update September 23, 2026, not a future policy guarantee |
| EXT-03 | [Telegram bot introduction](https://core.telegram.org/bots) | Bots cannot initiate user conversations; user interaction/group addition precedes bot communication; channel/account rights differ |
| PRIV-01 | [European Commission legal grounds](https://commission.europa.eu/law/law-topic/data-protection/information-business-and-organisations/legal-grounds-processing-data_en) | Withdrawal consequences are tied to affected processing purposes; basis configuration is not a generic marketing boolean |
| PRIV-02 | [California CCPA guidance](https://oag.ca.gov/privacy/ccpa) | Guidance describes opt-out of sale/sharing and Global Privacy Control; applicability remains a merchant-specific policy decision |
| STAT-01 | [NIST binomial proportion test](https://itl.nist.gov/div898/software/dataplot/refman1/auxillar/binotest.htm) | Large-sample comparison of two proportions; chosen sufficient-count threshold and fixed-horizon protocol are design rules |
| STAT-02 | [NIST proportion confidence intervals](https://www.itl.nist.gov/div898/handbook/prc/section2/prc241.htm) | Wilson/score interval approach and sparse-data caveats |
| STAT-03 | [Official R Fisher exact test](https://stat.ethz.ch/R-manual/R-devel/library/stats/html/fisher.test.html) | Fixed-margin hypergeometric convention and two-sided probability ordering; R is a reference calculator, not a production plugin dependency |
| STAT-04 | [Newcombe original paper record](https://pubmed.ncbi.nlm.nih.gov/9595617/) | Original research comparison supports combining Wilson score intervals for independent-proportion difference; implementation still requires golden numerical fixtures |
| A11Y-01 | [W3C WCAG 2.2](https://www.w3.org/TR/WCAG22/) | Normative accessibility criteria; AA is a design/test target and no conformance test has yet run |

## Interpretation and release gates

Implementation references additionally verified on 2026-10-04: [WordPress api-fetch package](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-api-fetch/) documents passing an AbortController signal to cancel stale reads while retaining WordPress nonce and URL middleware. [QR generator source at pinned commit](https://github.com/kazuhikoarase/qrcode-generator/blob/83b7e8fe3fddd3b0368dbafd6ce56995bd25e3c8/js/dist/qrcode.js) and its [MIT license](https://github.com/kazuhikoarase/qrcode-generator/blob/83b7e8fe3fddd3b0368dbafd6ce56995bd25e3c8/LICENSE) support the isolated local QR generator; the manifest records the immutable source digest. Its default byte encoder is replaced through the documented UTF-8 function export, and decoding tests cover non-English store paths. These tests do not establish printed-placement quality.

Source-backed assertions above specify supported integration surfaces. Durability semantics, module boundaries, consent rules, idempotency keys, latency budgets, batch sizes, retention periods, release matrices, and chosen version floors are **architecture decisions or acceptance gates**, not promises made by WordPress/WooCommerce documentation. PHP dependencies and JavaScript exports must be checked against the exact supported release tags; generated code-reference pages can include future/newer symbols. Every compatibility declaration must be accompanied by successful implementation tests and an evidence manifest. Documentation verification alone never authorizes `true` for HPOS or Blocks compatibility.
