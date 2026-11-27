# Plesk staging for host-installed WordPress

`compose.plesk-staging.yaml` builds the capture backend, renderer, and Gateway. The existing WordPress and its database stay on the host. The Compose file binds only IPv4 loopback. The renderer and Gateway use separate bridges without outbound masquerading. Chromium stays offline, with synthetic fixtures fulfilled inside Playwright. Backend reference archives and Gateway state and artifacts use persistent volumes.

| WordPress setting | Host-installed WordPress | Default Docker WordPress | Service credential |
| --- | --- | --- | --- |
| `SYNDAMORPH_BACKEND_URL` | `http://127.0.0.1:3100` | `http://backend:3100` | `SYNDAMORPH_BACKEND_TOKEN` |
| `SYNDAMORPH_RENDERER_URL` | `http://127.0.0.1:3000` | `http://renderer:3000` | `SYNDAMORPH_RENDERER_TOKEN` |
| `SYNDAMORPH_GATEWAY_URL` | `http://127.0.0.1:3200` | `http://gateway:3200` | `SYNDAMORPH_GATEWAY_ADMIN_TOKEN` |

Use the exact origins above. The transport also accepts the existing `/capture` and `/render` configuration forms. When present, `SYNDAMORPH_RENDERER_INSPECT_URL` must equal the renderer origin plus `/inspect`. Remove stale overrides. Docker service names require a trusted Docker network and embedded resolver. Host WordPress should use the numeric loopback origins, which require no DNS lookup.

### Prepare a frozen workspace

1. Extract the exact reviewed Git SHA into a private directory outside `httpdocs`. Keep `compose.plesk-staging.yaml`, `backend/`, and `renderer/` in their repository-relative locations. Use `scripts/release_plugin.py` from that same tree to package the plugin. Keep the ZIP SHA-256 and three image revision labels with the checkpoint.
2. In the same private directory, provision a mode-0600 `.env` for Compose. Set `SYNDAMORPH_BUILD_SHA` to the full Git SHA. Set independent server secrets for the three service credentials in the table and for `SYNDAMORPH_GATEWAY_PEPPER`. Use at least 32 printable random characters for each. Set `SYNDAMORPH_CAPTURE_HOSTS` to the reviewed source allowlist and `SYNDAMORPH_RESOURCE_HOSTS` to any separately approved asset hosts. Optionally set `SYNDAMORPH_INSTALL_SINGLEFILE=1` to build and enable the archive dependency. Preserve the Gateway pepper and volume data when updating an existing stack.
3. From the frozen directory, run `docker compose -f compose.plesk-staging.yaml config --quiet`. Before any approved start, confirm the resolved contexts, image revision labels, and the three loopback bindings. Avoid printing resolved environment values. The renderer uses the `authenticated-renderer` profile. Build and run its authorization suite without publishing a port first. Enable the profile only with passing tests on the matching SHA and explicit staging authorization.

Run `python3 tests/plesk-compose-check.py` to exercise the staging model with fixture credentials. It invokes Compose from outside the frozen directory and checks adjacent `.env` discovery, resolved build contexts, revision labels, required secrets and loopback bindings without starting containers.

Plesk documents selecting a Webspace stack from an existing Compose file in the domain's home directory. Keep the existing Dockerfiles and build contexts alongside that file in the private checkout. The local CLI regression verifies Compose resolution and adjacent `.env` discovery. Plesk's Webspace working directory and environment handling still require a check on the target installation. If the stack cannot resolve the frozen contexts and required variables, stop before starting it. [Plesk Docker guide](https://docs.plesk.com/en-US/obsidian/administrator-guide/plesk-administration/using-docker.75823/) · [Compose build contexts](https://docs.docker.com/reference/compose-file/build/) · [Compose environment interpolation](https://docs.docker.com/compose/how-tos/environment-variables/variable-interpolation/)

### Configure the host WordPress

Define the three URLs and credentials in server-only `wp-config.php` or have it load a protected PHP configuration file before WordPress boots. The constants take precedence over the PHP process environment, including when a constant is empty. The table's credential values must match the corresponding Docker service. Use the domain's PHP-FPM handler to validate the effective configuration. PHP-FPM pools commonly clear inherited environment variables. A value in Compose's `.env` applies to the stack and does not populate PHP-FPM. [PHP-FPM pool configuration](https://www.php.net/manual/en/install.fpm.configuration.php)

Set `SYNDAMORPH_CAPTURE_HOSTS` in WordPress to the same comma-separated source hostnames as the backend. Both processes enforce this allowlist. Configure `SYNDAMORPH_RESOURCE_HOSTS` on the backend for separately approved asset hosts. If staging requires archived source evidence, enable SingleFile in Compose and set the WordPress constant `SYNDAMORPH_REFERENCE_ARCHIVE` to the string `'1'` so the plugin requires the archive.

PHP curl is required. If `WP_HTTP_BLOCK_EXTERNAL` is active, add the exact host `127.0.0.1` to the site's existing `WP_ACCESSIBLE_HOSTS` list. If WordPress uses a proxy, add `127.0.0.1` to the existing `WP_PROXY_BYPASS_HOSTS`. Preserve the site's other policy entries. [WordPress HTTP blocking](https://developer.wordpress.org/reference/classes/wp_http/block_request/) · [WordPress HTTP proxy](https://developer.wordpress.org/reference/classes/wp_http_proxy/)

### Check the application handshake

From **Tools → SyndaMorph Transport**, a WordPress administrator can run the nonce-protected check. It calls authenticated capture health, performs a bounded Chromium inspection of a generated 375 px section with a fractional source offset, and checks the authenticated Gateway protocol at `/admin/v1/healthz`. The capture check also reports an empty WordPress source allowlist. The renderer check verifies the protocol, build header, copy and paint origin. The Gateway probe never reads customer records. The page reports status for each service without returning credentials or the inspected document.

An approved staging check must show three **Verified** results. The admin page requires administrator access and a valid WordPress nonce before it runs any probes. The Chromium regression checks all three results and verifies that anonymous requests, subscribers and invalid nonce submissions are denied. From the private host, confirm missing and wrong credentials return HTTP 401 from renderer jobs and Gateway admin health, and HTTP 403 from capture health. The renderer's unauthenticated health route returns only its liveness status. Keep this preflight and the local regressions with the matching checkpoint record.
