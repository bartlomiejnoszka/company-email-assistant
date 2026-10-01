# Working in this repository

Read README.md and docs/architecture.md before changing application behavior.

## Architecture

- One company and one mailbox per installation. Company-specific rules belong in private YAML configuration, not executable code.
- Keep plain PHP domain rules independent of Symfony, provider SDKs and persistence. Application services coordinate ports; infrastructure implements them.
- HTTP, console and MCP adapters share the same application services.
- Preserve immutable configuration snapshots, validation, optimistic concurrency and stable content IDs.

## Mail and content boundaries

- Draft creation is the default. Preserve UIDVALIDITY/UID identity, pending-state recovery and protection against duplicate mailbox or SMTP side effects.
- Prices, full conditions and signatures remain literal. Unsupported or uncertain enquiries require staff review.
- Use fakes and temporary data for ordinary tests. Do not process a real mailbox or deploy without an explicit task instruction.
- Never commit company data, correspondence, credentials, runtime databases or .env.local. Examples must remain fictional.

## Validation

Run the checks relevant to the change; before proposing a release, run:

```sh
composer check
composer analyse
composer cs:check
npm ci --ignore-scripts
npm run build:css
python3 scripts/export_public.py --check
```

Commit the rebuilt public/assets/panel.css when templates or CSS change. Keep English and Polish interface text and documentation consistent. Update migration notes when changing the configuration schema or state compatibility.
