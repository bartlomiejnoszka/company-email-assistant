# Testing

`composer check` runs PHPUnit, container validation, Twig validation and YAML validation. Tests cover deterministic domain rules, configuration storage, migration, isolated companies, authentication, OAuth scopes, MCP previews and crash recovery. Architecture tests enforce framework-free domain classes. `composer analyse` runs PHPStan; `composer cs:check` verifies formatting.

Normal tests need no external account or API key. One live test is intentionally skipped unless explicitly enabled with `OFFICE_LIVE_TEST=1`. It additionally requires `OFFICE_SMOKE_MAILBOX` equal to `IMAP_USERNAME`, `OFFICE_SMOKE_CONFIG_DIR` containing generic fictional configuration, one seeded eligible enquiry and the remaining private IMAP/OpenAI settings. It writes a draft in that dedicated mailbox and verifies a second run does not duplicate it. Never use a production account for this test.

The PHP built-in server is for local review. Use `public/router.php` so static CSS is served while arbitrary routes reach Symfony. Production uses the Docker PHP-FPM/Nginx deployment or equivalent.
