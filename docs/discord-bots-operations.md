# Evolution X Discord Bots operations

## Architecture

The dashboard does not connect to the bot MariaDB server. Professor remains the only management service with database access and exposes narrowly scoped endpoints for:

- Bot status, feature discovery, and aggregate counts.
- Applications, applicants, questions, users, abuse records, privacy requests, cache entries, and logs.
- Group and per-user command permissions and permission templates.
- Explicitly validated management mutations for those resources.

There is no arbitrary SQL endpoint and the PHP client uses a method-and-path allowlist.

Dashboard administrators continue to sign in with the dashboard's password or passkey flow. Each account links its Discord user ID under **Account > Discord Account**; a second Discord OAuth login is not required. Professor authorizes that linked identity against its own users table and requires an active, unblocked account with permission level 3 or higher.

Requests are authenticated with HMAC-SHA256 headers covering the method, path, timestamp, nonce, linked Discord actor, and request body hash. Professor accepts requests for five minutes and records nonces to reject replays. Binding the actor into the signature prevents identity-header substitution and lets Professor attribute mutations to the correct administrator.

## Professor server

Deploy the updated `Planet_Express` shared package and Professor application together because both sides must use the actor-bound signature format. Set dedicated dashboard credentials in the Professor environment:

```dotenv
DASHBOARD_EXTERNAL_IDENTIFIER=awarner-dashboard
DASHBOARD_EXTERNAL_PASSWORD=replace-with-at-least-32-random-characters
DASHBOARD_NONCE_FILE=/var/lib/evox-professor/dashboard-nonces.json
```

Generate the password once with `openssl rand -hex 32`. The Professor service user must be able to create and update the nonce file. Multiple Professor processes can share it directly; multiple containers must mount the same file path from shared storage.

Expose Professor through HTTPS. Restrict the endpoint to the dashboard server IP at the firewall or reverse proxy when practical.

## Dashboard server

Open **Evolution X Discord Bots > Settings** and configure:

- The externally reachable Professor base URL.
- The same dashboard identifier.
- The same dashboard password.

The values can be bootstrapped with `DISCORD_BOTS_API_URL`, `DISCORD_BOTS_API_IDENTIFIER`, and `DISCORD_BOTS_API_SECRET` in the dashboard `.env`.

No scheduled password update is required. Both servers derive the same short-lived UTC daily key from the shared password, then sign every request with a fresh timestamp and nonce. The dashboard caches successful responses for 30 seconds and serves the last response as stale data during a remote outage.

After signing in, link the dashboard account to the Discord ID of a Professor administrator. Each dashboard account must use a unique Discord ID.

The dashboard's **Evolution X Discord Bots > Management** page provides native views and controls for Professor workflows. **Open Professor** remains available as a fallback and uses Professor's own Discord OAuth session. Reads require a linked authorized actor; writes additionally require dashboard CSRF validation and `DASHBOARD_ALLOW_WRITES=true`.

The feature manifest is cached for five minutes, with a built-in catalogue available during a remote outage. Overview data is cached for 30 seconds and the last successful response can be shown as stale data.

Any future database mutation must be implemented as a separately named, authenticated Professor endpoint with a narrow validated payload and audit logging through the signed actor. Add the exact method and path to the PHP allowlist; do not add a generic query endpoint or expose MariaDB to the dashboard host.