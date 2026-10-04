# Release security review

The release review used the code-reviewer skill's PHP/security/concurrency rules, PHPStan level 5, WordPress security sniffs and real permission/identity/privacy/worker regression tests. It is a source and behavior review of this release, not an external penetration-test certificate.

The original targeted WordPress sniff report had 77 errors and 2 warnings. All 79 findings were inspected in context:

| Findings | Count | Review |
| --- | ---: | --- |
| SQL identifiers and fragments | 69 | 48 schema-allowlisted plugin table identifiers, 16 fixed/compiled/prepared fragments, two integer/placeholder lists, three components of schema-owned uninstall DDL. User values are prepared; these findings are analyzer limitations, not a clean sniff report. |
| Output | 5 | Four arguments thrown as exceptions, not rendered as HTML; one fixed REST preference form whose dynamic text, attributes and URL use the corresponding WordPress escaping APIs. REST errors are JSON, and the native admin renders message text. |
| Public GET nonce warnings | 2 | Public shortlink lookup with a validated 24-hex slug and same-store destination. No privileged mutation is performed by these GETs. |
| Input validation | 3 | The encrypted preference cookie now has a type, size and encoding gate before authenticated decryption. The request method is unslashed and sanitized. Plain sanitization must not modify an authenticated ciphertext. |

Review also led to fixes beyond the reported sniff sites: immutable segment version/generation pairing; exact monetary scales; refund/expiry and refund deletion/recreation compensation; cancellation and unknown-outcome transport fences; account-principal transfer on identity merge; alias-aware privacy pagination; erasure of staff merge notes and recovery material; verification of only the challenged email address; consent-authorized workflow facts at execution; live account analytics withdrawal; and retry of transient database lock errors.

Security controls include capability-checked REST mutations, WordPress cookie nonces, closed request fields, optimistic revisions, durable API idempotency, bounded payloads, authenticated encryption with an external key, fixed provider endpoints, approved HTTPS relay hosts, signed receipt verification, escaped output, current consent/suppression checks and safe metadata-only audits. Runtime code contains no `eval`, unserialization of external input, invasive fingerprinting, or direct canonical WooCommerce storage queries.

Provider requests are intercepted during qualification. The plugin cannot establish a merchant's live account approvals, delivery reputation, processor erasure, hosting protection, or security of third-party extensions. Those remain installation-specific checks described in the release guide.
