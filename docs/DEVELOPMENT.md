# Developing and qualifying the plugin

Runtime code lives in `src/`; domain code uses explicit service composition in `Platform/Plugin.php`. WordPress/WooCommerce hooks are confined to integration/platform adapters. Tables and indexes are declared in `Infrastructure/Schema.php`. Plugin tables use InnoDB, explicit projections, prepared values and UUID identities; WooCommerce stays the source of truth for orders, products, customers and coupons.

## Local commands

```sh
rtk proxy composer install
rtk proxy php vendor/bin/phpunit --testsuite unit
rtk proxy node --test tests/JavaScript/*.test.js
rtk proxy php vendor/bin/phpstan analyse --debug --memory-limit=1G
rtk proxy php vendor/bin/phpcs --standard=PSR12 --extensions=php src woocommerce-marketing-os.php uninstall.php
```

The static analyzer scans the public symbols of the locally installed WooCommerce version. PHP formatting uses PSR-12 in namespaced classes; WordPress adapters use the host's escaping, nonce, capability, sanitization and HTTP primitives. Line-length warnings are advisory; analyzer errors and formatting errors block the archive.

## Isolated integration store

Use a task-owned MySQL 8 container, bound only to loopback port 13306, with database `wmos_test`. Store its generated credentials in ignored, mode-0600 `.runtime/mysql.env`. `tools/setup-runtime.py` installs pinned official WordPress/WooCommerce versions into `/private/tmp/wmos-wordpress`, sets a test encryption key and creates a local admin whose random password stays in `.runtime/admin-password`.

The physical WordPress directory must be outside the plugin checkout. `.runtime/wordpress` links to it; the WordPress plugin directory links back to the checkout. This prevents WordPress's realpath plugin mapping from corrupting unrelated asset URLs.

The test-only must-use plugin disables Action Scheduler's automatic async runner, blocks all external HTTP and intercepts mail. Integration tests invoke the durable workers explicitly. Keep these fences out of production. The PHP built-in server is also for local tests only.

```sh
rtk proxy python3 tools/setup-runtime.py
rtk proxy python3 tools/provision-matrix.py --prefix wmos_example_hpos_ --storage hpos
rtk proxy env WMOS_INTEGRATION=1 WMOS_TEST_PREFIX=wmos_example_hpos_ php -d memory_limit=512M vendor/bin/phpunit --log-junit .runtime/qualification/example.xml
rtk proxy php -d memory_limit=512M -S 127.0.0.1:8089 -t .runtime/wordpress tools/router.php
rtk proxy npm --prefix tests/e2e ci
rtk proxy npm --prefix tests/e2e test
```

Create an independent prefix per storage/runtime qualification rather than changing order storage underneath an existing unsynchronized store. Matrix provisioning refuses arbitrary non-test prefixes. Tests must produce actual JUnit counts; a WordPress error page that exits with status zero is not a passed PHPUnit run.

## Extension points

Implement `Wmos\Contracts\V1\Provider` and register it with the `Messaging` registry passed to `wmos_register_providers`. Return a `ProviderOutcome` describing acceptance, rejection, retryability or ambiguity; acceptance is not delivery. Preserve the durable operation key where the remote service supports idempotency. Bound requests and surface real capabilities. Credentials passed to the adapter must not be logged or placed in returned errors.

`wmos_rfm_monetary_thresholds` receives the configured value, profile currency and exponent; return four increasing integer thresholds for that scale, or null to leave the RFM fact unknown. `wmos_order_reconciled` receives a public `WC_Order` after local financial projections reconcile. `wmos_rest_failure` exposes exception class/location and a safe correlation ID to local operational hooks; never attach request bodies or credentials.

## Building a distribution

After every required gate passes, write the actual gate results, runtime versions and current runtime source digest to `release-evidence.json`. `tools/build-release.py` refuses mismatched evidence, failed gates, unsupported internal WooCommerce namespaces, wildcard source queries and unfinished source markers. It stages only runtime files, generates an authoritative production autoloader, writes checksums and creates a deterministic ZIP under `dist/`.

```sh
rtk proxy python3 tools/build-release.py
rtk proxy python3 tools/test-release.py dist/woocommerce-marketing-os-1.0.0.zip
rtk proxy python3 tools/record-release-evidence.py
rtk proxy python3 tools/build-release.py
rtk proxy python3 tools/verify-release.py dist/woocommerce-marketing-os-1.0.0.zip
```

Install that ZIP into an independent isolated WordPress store and test activation, REST health and native checkout again. Inspect its file list for secrets, local stores, tests, development dependencies and build artifacts; build twice and compare archive hashes. Never ship the test server, test-only network fences or fixture configuration.
