# Evolution X Discord Bots operations

## Architecture

The dashboard does not connect to the bot MariaDB server. Professor remains the only management service with database access and exposes two allowlisted endpoints:

- `GET /api/dashboard/overview` for bot status and aggregate counts.
- `GET /api/dashboard/features` for the available Professor management workflows.

There is no arbitrary SQL endpoint.

Requests are authenticated with HMAC-SHA256 headers covering the method, path, timestamp, nonce, and request body hash. Professor accepts requests for five minutes and records nonces to reject replays.

## Professor server

Deploy the updated `Planet_Express` shared package and Professor application together. Set dedicated dashboard credentials in the Professor environment:

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

The dashboard's **Evolution X Discord Bots > Management** page groups all Professor workflows and opens them on the Professor host. Professor continues to enforce its Discord OAuth session for those interactive pages. The feature manifest is cached for five minutes, with a built-in catalogue available until the remote endpoint is deployed.

Any future database mutation should be implemented as a separately named, validated Professor endpoint with a narrow payload and audit logging. Do not add a generic query endpoint or expose MariaDB to the dashboard host.