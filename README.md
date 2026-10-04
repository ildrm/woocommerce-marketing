# WooCommerce Marketing Operating System

Executable WordPress/WooCommerce plugin with a native administration application, durable workers and transactional marketing data. Install the production ZIP from `dist/` through WordPress **Plugins → Add New → Upload Plugin**. The ZIP includes its production autoloader; merchants do not need Composer or Node.

The plugin implements campaigns, rule segments, versioned automation graphs, consent and double opt-in, channel providers, first-party tracking, QR links, loyalty and referral ledgers, affiliate commissions, WooCommerce coupons, attribution, experiments, recommendations and personalization. The administration also manages content, media assets, offline/event placements, influencers and partners.

Read [installation and operation](readme.txt), [release qualification and supported behavior](docs/RELEASE.md), and [developer commands and extension points](docs/DEVELOPMENT.md). `release-evidence.json` identifies the exact qualified source and runtime matrix. Provider account approval and real delivery must be qualified with the merchants own credentials before enabling sends.

Source is in `src/`, the WordPress entry point is `woocommerce-marketing-os.php`, and native WordPress JavaScript is in `assets/`. Tests and local test stores are excluded from the distribution.

The original 21-phase [architecture specification](docs/architecture/SPECIFICATION.md) remains the design reference. Its capacity targets and future roadmap are not release-test results. The following documents describe that architecture:

| Phase | Document |
|---|---|
| 1 | [Requirements normalization](docs/architecture/phases/01-requirements.md) |
| 2 | [Marketing capability matrix](docs/architecture/phases/02-capabilities.md) |
| 3 | [Domain architecture and module boundaries](docs/architecture/phases/03-modules.md) |
| 4 | [Implementation directory structure](docs/architecture/phases/04-directory.md) |
| 5 | [Domain model and state machines](docs/architecture/phases/05-domain-model.md) |
| 6 | [Database schema, indexes and migrations](docs/architecture/phases/06-database-schema.md) |
| 7 | [Event schema and catalog](docs/architecture/phases/07-event-catalog.md) |
| 8 | [Automation architecture](docs/architecture/phases/08-automation.md) |
| 9 | [WordPress integration map](docs/architecture/phases/09-wordpress.md) |
| 10 | [WooCommerce, HPOS and Blocks integration map](docs/architecture/phases/10-woocommerce.md) |
| 11 | [Provider framework and channel execution](docs/architecture/phases/11-providers.md) |
| 12 | [Threat model and security contracts](docs/architecture/phases/12-security.md) |
| 13 | [Privacy, consent, retention and erasure](docs/architecture/phases/13-privacy.md) |
| 14 | [Administration UX and accessibility](docs/architecture/phases/14-admin-ux.md) |
| 15 | [Analytics, attribution and financial correctness](docs/architecture/phases/15-analytics.md) |
| 16 | [Performance and capacity strategy](docs/architecture/phases/16-performance.md) |
| 17 | [Test architecture and acceptance matrices](docs/architecture/phases/17-tests.md) |
| 18 | [Architectural decision records](docs/architecture/phases/18-adrs.md) |
| 19 | [Risk register](docs/architecture/phases/19-risks.md) |
| 20 | [Implementation roadmap](docs/architecture/phases/20-roadmap.md) |
| 21 | [Final cross-role architecture audit](docs/architecture/phases/21-final-audit.md) |

The phase documents are the editable sources. Regenerate and validate the combined specification with:

```sh
rtk proxy python3 docs/architecture/build_specification.py
```

The existing repository license is preserved in [LICENSE](LICENSE).
