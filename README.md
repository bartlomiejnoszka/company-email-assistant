# Company Email Assistant

A self-hosted Symfony application that turns approved company knowledge into email drafts. Each installation serves one company and one mailbox. Staff review and send drafts from their mail client.

The app includes an authenticated Twig configuration panel, English and Polish interface translations, versioned YAML configuration, and an OAuth-protected MCP integration for configuration management and response previews. OpenAI is the initial AI adapter. Prices, conditions and signatures remain literal; unsupported enquiries require staff review.

## Requirements

PHP 8.4+ with DOM, mbstring, PDO SQLite and OpenSSL, Composer 2, and Node.js 22+ for CSS. IMAP uses verified TLS; optional test SMTP delivery uses implicit TLS. SQLite and snapshot storage require a local filesystem shared by the panel and scheduled command.

## Local setup

```sh
composer install
npm ci --ignore-scripts
npm run build:css
mkdir -p var/company
cp examples/repair/*.yaml var/company/
```

Create an ignored `.env.local` file. Set a randomly generated `APP_SECRET`, `COMPANY_CONFIG_DIR="%kernel.project_dir%/var/company"`, and `UI_LOCALE=en` or `pl`. Defaults contain only fictional example data.

```sh
php bin/console app:owner:configure
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console lint:container
php -S 127.0.0.1:8088 -t public public/router.php
```

Open http://127.0.0.1:8088/login. Provisioning writes the generated owner password to `var/identity/connection.txt` with mode 0600; no credentials are printed. The same owner credential authorizes MCP consent. `app:mcp:configure` remains an alias. Existing identities are preserved.

No mail is processed by starting the web server. To enable draft processing, configure `IMAP_HOST`, `IMAP_USERNAME`, `IMAP_PASSWORD`, `OPENAI_API_KEY`, `OPENAI_MODEL` and `EMAIL_START_AT` (ISO-8601 with timezone) in your private environment file. Use the existing database and start boundary when upgrading.

```sh
php bin/console app:email:generate-drafts --dry-run --limit=5
php bin/console app:email:generate-drafts --limit=50
```

Dry runs call AI and print proposed content but do not mutate mail or processing records. Keep their output private. Normal processing saves drafts. Optional test sending requires both `EMAIL_TEST_AUTO_SEND=1` and a nonempty JSON `EMAIL_AUTO_SEND_ALLOWLIST` of exact To recipients; configure `SMTP_DSN` as `smtps://...:465`. Single explicit To matching remains narrow; uncertainty after sending is never automatically retried.

## Company configuration

- `company.yaml`: `schema_version: 1`, company identity and reply preferences.
- `knowledge.yaml`: topics, approved facts and clarification questions.
- `pricing.yaml`: approved prices and complete conditions.

Copy an example from `examples/repair` or `examples/notary` to writable private storage. These are fictional demonstrations, not legal or pricing templates. Companies do not require PHP changes. UI language is independent of reply language; approved content is never automatically translated.

The panel edits all three YAML files together and preserves their text. Saves validate the complete package, reject stale versions and atomically publish immutable snapshots. After the first save, use the panel/MCP; root files are inactive. See [configuration reference](docs/panel/en/dokumentacja.md).

## Existing installations

```sh
php bin/console app:configuration:import-legacy /path/to/old-office /path/to/new-company
```

The destination must not exist. Import preserves approved content and IDs, copies source/history to a read-only archive, validates the result and initializes generic snapshot history. Original files and the mail database remain unchanged. Point `COMPANY_CONFIG_DIR` at the new directory after reviewing `migration-report.json`. See [migration notes](docs/migration.md).

## MCP and deployment

See [MCP setup](docs/mcp.md), [Docker deployment](deploy/README.md), and [architecture](docs/architecture.md). Public deployments require HTTPS, private credentials, writable persistent storage and an explicit `MCP_HOST`. The panel requires owner login; `/mcp` always requires bearer tokens.

## Development

```sh
composer check
composer analyse
composer cs:check
npm run build:css
composer audit
```

Normal tests use fakes and temporary company data. The real-mailbox test is opt-in and must only use a dedicated seeded account; see [testing](docs/testing.md). Contribution instructions are in [CONTRIBUTING.md](CONTRIBUTING.md). This project is licensed under [MIT](LICENSE).
