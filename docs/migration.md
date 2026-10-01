# Migrating the previous office-specific application

Prepare a backup and stop the old scheduler before a future production switch. Local imports can be reviewed without stopping production, but use copied source files for a consistent snapshot.

Run `app:configuration:import-legacy SOURCE DESTINATION`. It reads the active `CURRENT` snapshot when present. Destination must not exist and its parent must already exist outside the source directory. Imports reject source changes detected before publication. Import translates Polish field names and editing enums into the English schema without editing approved text, IDs, amounts, currency, conditions, dates or signatures. Original YAML comments are preserved in `legacy-archive`; generated generic YAML is newly serialized.

The archive contains old YAML and snapshot metadata, with read-only file permissions. Generic history starts independently and never restores old-format files. The import report records normalized equivalence; no processing database is opened or copied.

Compatibility changes:

- `OFFICE_CONFIG_DIR` becomes `COMPANY_CONFIG_DIR`.
- Configuration filenames become `company.yaml`, `knowledge.yaml`, `pricing.yaml`.
- Public editing keys and values use English; see the reference.
- MCP tool names and OAuth scopes stay unchanged; file argument keys and guidance change in version 2.0. The guidance resource URI is `assistant://configuration/guidance`.
- Default predefined OAuth client ID is `company-assistant`. Existing provisioned identity settings keep their own client ID; provisioning never overwrites them.
- The previous SMTP alias becomes `EMAIL_AUTO_SEND_ALLOWLIST`; sending remains disabled until explicitly configured.
- Doctrine mappings moved to XML with unchanged tables, columns and source identity keys. Keep the existing processing SQLite and lock directory; do not initialize a replacement state database for a live mailbox.

After review, set the new config directory, preserve the old mailbox settings and `EMAIL_START_AT`, and retain the identity directory if existing MCP connections should continue. Owner credentials from that directory now protect the panel as well. Configure `MCP_HOST` for the actual host. Verify login, configuration history and health before resuming the scheduler. Configuration changes never regenerate completed records.

Rollback means returning the old executable and original configuration directory while preserving processing state. Never restore a stale mail database after external mail operations without reconciling those operations.
