# Secure Gateway leasing V1 — isolated backend MVP

This service belongs to **SyndaMorph**, not the SyndaMorph Unpacker plugin. It is an independent Node HTTP server in `backend/gateway/`. It does not modify the current AI Production Team, WordPress plugin, or publishing pipeline.

## Agreed access model

- **One customer-level API credential per client** as the default. Multiple credentials can briefly coexist for safe rotation; no package-specific keys.
- Every package belongs to **exactly one customer**, with immutable numbered versions and potentially multiple sections/patterns per artifact.
- The client credential authenticates the customer. Package ownership **and** an active, unexpired lease authorize package discovery and download.
- One effective lease per customer/package. A revoked lease is terminal; creating a new lease restores future access. Suspension blocks download until resumed. Expiration is always evaluated at request time.
- Reusable internal templates and design policies remain **outside** this client package system.
- Leases and credentials affect future Gateway access only. Installed WordPress content and local archives are never contacted, disabled, or deleted.
- Unpacker plugin self-updates remain independent from leases.

## Start the isolated MVP

Run from the repository root with Node 22+:

```bash
export SYNDAMORPH_GATEWAY_PEPPER='minimum-32-byte-random-secret-for-hmac'
export SYNDAMORPH_GATEWAY_ADMIN_TOKEN='minimum-32-byte-random-admin-token'
export SYNDAMORPH_GATEWAY_DATA='/absolute/private/gateway/state.json'
export SYNDAMORPH_GATEWAY_ARTIFACTS='/absolute/private/gateway/artifacts'
node backend/gateway/server.js
```

Default bind is `127.0.0.1:3200`; override with `SYNDAMORPH_GATEWAY_HOST` and `SYNDAMORPH_GATEWAY_PORT`. Use a TLS reverse proxy and prevent any direct public HTTP access. Never commit or log keys. Generate real secrets with a cryptographic random source, not the illustrative strings above.

## Admin onboarding API

Admin operations require `X-SyndaMorph-Admin-Token` and never accept customer Bearer tokens.

| Method | Path | JSON payload |
| --- | --- | --- |
| POST | /admin/v1/customers | `{"name":"Client A"}` |
| PATCH | /admin/v1/customers/{customerId}/status | `{"status":"suspended"}` |
| POST | /admin/v1/customers/{customerId}/keys | `{}` — key shown once |
| POST | /admin/v1/customers/{customerId}/keys/{keyId}/revoke | `{}` |
| POST | /admin/v1/customers/{customerId}/packages | `{"name":"Hotel Website"}` |
| POST | /admin/v1/customers/{customerId}/packages/{packageId}/versions | `{"version":"1.0.0","sha256":"<64 lowercase hex>","size":1234,"storageKey":"some-file.zip"}` |
| POST | /admin/v1/customers/{customerId}/packages/{packageId}/leases | `{"startsAt":"2026-11-23T00:00:00.000Z","expiresAt":"2027-11-23T00:00:00.000Z"}` |
| PATCH | /admin/v1/customers/{customerId}/leases/{leaseId} | `{"status":"suspended"}` or `{"status":"active","expiresAt":"..."}` or `{"status":"revoked"}` |

To publish a version, provision its ZIP **outside** the document root, under `SYNDAMORPH_GATEWAY_ARTIFACTS`, with a filename containing only letters, digits, underscore, hyphen, and `.zip`; supply the exact SHA-256 and byte length. Publish validates both. Never overwrite published ZIP files: versions are immutable by contract; a future artifact registry will enforce immutable object storage.

## Unpacker client API (not yet wired into the plugin)

All client routes except one-time download require `Authorization: Bearer smup_...`.

| Method | Path | Behavior |
| --- | --- | --- |
| GET | /v1/client/me | Authenticated client identity |
| GET | /v1/packages | Only this client's packages with active leases |
| GET | /v1/packages/{packageId}/versions | Only authorized published versions; no storage paths |
| POST | /v1/packages/{packageId}/downloads | `{"version":"1.0.0"}` → 60-second one-time grant, size and SHA-256 |
| GET | /v1/downloads/{grant} | Streams the verified ZIP bytes; single-use grant |

Errors: invalid credentials `401`, non-owned packages `404`, missing/expired/suspended leases `403`. Downloads recheck the customer's and lease's status at redemption; ZIP content is rehashed before sending. Download grants live in process memory and vanish on restart.

## Testing

```bash
node --test backend/gateway/gateway.test.js
npm test --prefix backend
```


## WordPress Client Access Manager (admin UI)

The existing SyndaMorph WordPress plugin now includes **Tools → SyndaMorph → Client Access Manager**. Only users with `manage_options` can open the page or send management requests.

Configure **server-side** in WordPress `wp-config.php`:

```php
define( 'SYNDAMORPH_GATEWAY_URL', 'https://gateway.example.com' );
define( 'SYNDAMORPH_GATEWAY_ADMIN_TOKEN', getenv( 'SYNDAMORPH_GATEWAY_ADMIN_TOKEN' ) ?: '' );
```

Keep the token in a server-secret store or environment variable, not JavaScript or source control. The Gateway must be reachable from PHP over HTTPS. HTTP is permitted **only** for explicitly configured `localhost` / `127.0.0.1` during local development. Protect the administrator API using network restrictions in addition to the configured token.

Supported operations in the UI:

1. Register a customer, select a customer, suspend/resume access.
2. Issue a client-level API key, display/copy it **once**, list only key prefixes, revoke an existing key.
3. Register private packages under the selected customer, view published version summaries.
4. Assign, suspend, resume, change expiration, or revoke package leases.

Browser requests use WordPress admin AJAX with `manage_options` and `check_ajax_referer`. PHP sends a fixed set of permitted requests to the Gateway. Neither the Gateway administrator token nor credential hashes are returned to the browser. The newly issued **client** key is intentionally returned once to the administrator and should be securely delivered to the client.

The UI does **not** upload or publish artifact ZIPs, connect to Unpacker, or provide an installation registry. These remain separate milestones. The Gateway administrator overview endpoint (`GET /admin/v1/overview`) returns only customer names/status, credential prefixes/status, package version summaries, and lease states.

**Additional production requirements:** add per-admin audit events, scoped roles, stronger admin authentication, CSRF review, rate limits, production-grade durable storage, and integration tests against a configured WordPress runtime before any live customer rollout.

## V1 limitations / required production hardening

This is an **MVP backend**, not a production-grade multi-tenant SaaS Gateway.

- JSON file storage is atomic for **one Node process only**, without multi-instance locking, relational constraints, transactional outbox, or audited admin edits. Move to PostgreSQL with migrations, constraints, backups, and service authorization before multi-replica deployment.
- Admin access uses one configured secret; replace with administrator identities, RBAC, key rotation, audit records, and rate limits before live client onboarding.
- Artifacts are supplied out of band and verified by SHA-256 but are **not cryptographically signed**. Add signed immutable manifests, ZIP contents validation, artifact size/type limits, and secure object storage before production distribution. A hash by itself is not proof of authenticity.
- The plugin client is not integrated yet. No automatic WordPress installation, UI, billing, or AI production artifact export is claimed.
- The package download is fully buffered in memory (25 MiB max). Replace with checked streamed object storage for large packages.
- Only internal administrators can create customers, keys, packages, and leases. Never expose `/admin/v1/` to a customer application or the public Internet.
