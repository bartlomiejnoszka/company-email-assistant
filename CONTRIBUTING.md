# Contributing

Use PHP 8.4+, Composer and Node.js 22+. Follow the local setup in README. Keep one company per installation and company-specific behavior in configuration. Domain classes remain framework-free; adapters depend on application ports. Introduce abstractions only for real boundaries or reuse.

Run `composer check`, `composer analyse`, `composer cs:check`, `npm run build:css` and `composer audit` before submitting a change. Run `composer cs:fix` to format PHP. Add behavioral tests for changed contracts and recovery boundaries. Use fictional fixtures; never commit correspondence, credentials or customer configuration.

Document schema or MCP contract changes in CHANGELOG and migration notes. Report vulnerabilities privately through the repository's GitHub security advisory form once published; avoid public issues containing exploit details or sensitive data.
