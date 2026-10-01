# Local verification

Verified on 2026-10-01 with PHP 8.5.5. The source archive was extracted into a separate directory without private configuration or Git history and installed from its lockfiles.

- PHPUnit: 211 tests, 977 assertions, one intentionally skipped live-mailbox test.
- Symfony container, seven Twig templates and sixteen YAML files passed validation.
- PHPStan and PSR-12 formatting checks passed.
- Composer and npm audits reported no known vulnerabilities at verification time.
- Owner provisioning, database migrations and Doctrine schema validation passed on the fresh installation.
- The CSS rebuilt from the exported source matched the development artifact byte for byte.
- Desktop and mobile panel layouts were reviewed. Invalid YAML retained the submitted text and left the active snapshot unchanged.
- A private legacy configuration import preserved normalized approved content and left the source and mail-processing database unchanged.
- Source export boundaries exclude private configuration, credentials, runtime data, dependencies, IDE files and Git history.

Docker Compose configuration passed validation. An image build was not run because the local Docker daemon was unavailable. PHP 8.4 is covered by the configured CI matrix but was not run locally. No live mailbox processing, production deployment or public repository publication was performed.
