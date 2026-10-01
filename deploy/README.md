# Docker deployment

Copy fictional configuration into `var/company`; create `var/identity` and `var/runtime`. Bind directories must be writable by container UID 10001 (use equivalent ACLs if preferred). Create an ignored `.env.local` with a random `APP_SECRET`, `MCP_BASE_URL`, `MCP_HOST`, `UI_LOCALE` and private mailbox/AI settings as needed. Docker does not expand Symfony project placeholders in the environment file; use the Compose path overrides.

```sh
mkdir -p var/company var/identity var/runtime
cp examples/repair/*.yaml var/company/
# Set writable ownership/ACLs for UID 10001 before the following commands.
docker compose -f deploy/compose.yaml build
docker compose -f deploy/compose.yaml run --rm cli app:owner:configure
docker compose -f deploy/compose.yaml run --rm cli doctrine:migrations:migrate --no-interaction
docker compose -f deploy/compose.yaml up -d panel web
```

The local HTTP listener is bound to 127.0.0.1:8088. Place an HTTPS reverse proxy in front for public access. Configure `TRUSTED_PROXIES` to the actual proxy IPs only and forward the original protocol so secure session cookies are set correctly. Never expose PHP-FPM port 9000 directly.

Schedule `docker compose -f deploy/compose.yaml run --rm cli app:email:generate-drafts --limit=50` once per minute using your host's scheduler after explicit mailbox setup. Panel and CLI share runtime state; the process lock prevents overlapping runs. Stop scheduling during production migrations.

Back up company snapshots, processing state and identity material together. SQLite needs a consistent backup (`.backup` or stopped writers). Verify login, the three editors, MCP discovery and a dedicated-account draft smoke test before any production switch. Retain previous executable/configuration for rollback; external mail operations must be reconciled before restoring stale state.
