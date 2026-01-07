# Organization Instructions — Developer Experience (Dev XP) (PHP library)

Purpose
- This file explains *Developer Experience (Dev XP)* expectations specifically for this PHP library and documents semantic file suffixes and patterns for repository organization.

Scope
- Applies to contributor onboarding, local development setup, tests, CI, release steps, and documentation practices for the `pxp/pdf` PHP library.

What is Dev XP (short)
- Dev XP = the frictionless, reproducible experience for contributors: installing deps (Composer), running static analysis and tests (PHPStan, PHPUnit), ensuring style (php-cs-fixer), debugging, and preparing PRs for CI.

Where to place organizational docs
- `.agent.md` — Machine-friendly AI agent instructions (short, explicit). Example: `.github/copilot.agent.md`.
- `.example.md` / `.example.php` — Executable example code or scripts.

PHP-specific conventions and patterns
- Composer and PHP version: Follow `composer.json` (requires PHP ^8.4). Use Composer for dependency management and all scripts (e.g., `composer test`).
- Autoloading: PSR-4 under `PXP\` => `src/` (see `composer.json`). Tests use the `Test\` namespace for `tests/`.
- Strict typing: Files use `declare(strict_types=1)`; preserve this and add types for public APIs.
- Headers & coding style: Files must contain the project license header and conform to `.php-cs-fixer.dist.php`. Run `composer cs:fix` and `composer cs:check` during PRs.
- PSR interfaces: Prefer PSR-6, PSR-3, PSR-14 for caching, logging, and events. Follow Null-object patterns where used (e.g., `NullLogger`, `NullCache`, `NullDispatcher`).
- Tests and helpers: Use `tests/TestCase.php` helpers (`TestCase::createFPDF()`, `getCache()`, `getLogger()`, `getEventDispatcher()`). Tests are split into Unit / Feature / Integration suites defined in `phpunit.xml`.

Commands & tooling (exact commands to use)
- Install: `composer install`
- Tests: `composer test` (aliases: `composer test:unit`, `composer test:feature`, `composer test:integration`)
- Code style: `composer cs:check` and `composer cs:fix`
- Static analysis: `composer phpstan` (configured by `phpstan.neon`)
- Run a single test file: `vendor/bin/phpunit tests/Path/To/Test.php`
- Mutation tests: `infection` is available in `require-dev` — use with care and CI guards
- Benchmarking: `phpbench` is included for micro-benchmarks

Testing notes and environment
- Visual tests rely on external CLI tools for PDF -> image conversion: prefer `mutool` (MuPDF) or `pdftoppm` (poppler-utils); fallback to ImageMagick `convert/compare` or Ghostscript `gs`.
- Debugging visual tests: set `PERSISTENT_PDF_TEST_FILES=1` to keep generated files and avoid cleanup.
- Test temp dir: use `TestCase::getRootDir()` for writing temporary artifacts in tests.
- CI: Keep tests deterministic. Add explicit fallbacks and guards for missing system deps (see `tests/TestCase::pdfToImage` logic).

Documentation placement & examples
- Small usage examples should live in `examples/` and be runnable. Tests are authoritative for behavior and should be referenced in docs.
- When adding a how-to or onboarding doc, include exact commands and any required environment variables.

PR & maintenance checklist for Dev XP changes
- For code changes: add/adjust tests in the appropriate suite and ensure `composer test` passes locally.
- For style: run `composer cs:fix` and make sure `composer cs:check` is clean.
- Update `phpstan.neon` if new public APIs need extra static checks or stubs.
- For new external tooling (e.g., new CLI dependency), document fallbacks and update `.devxp.md` and CI accordingly.
- Add or update a short changelog entry in `CHANGELOG.md` if releasing with a new version.

Agent guidance (how AI agents should use these docs)
- Prefer `.org.md` for orientation, `.devxp.md` for setup/commands, and `.agent.md` for machine instructions.
- Be explicit in changes: make focused edits, add tests, run `composer test`, `composer cs:check`, and `composer phpstan` locally before proposing PRs.
- If touching external-tool-dependent tests, add guards and mention them in the test or a `.devxp.md` note so maintainers know why a system dependency is required.

Examples & quick prompts
- "Add `getPageCount()` to `PageManager`, expose `FPDF::getPageCount()` delegating to it, and add unit tests using `TestCase::createFPDF()` to validate after `addPage()` calls."
- "Document how to set `FPDF_FONTPATH` for CI to load custom fonts in `docs/setup.devxp.md` with exact commands used on Ubuntu/Debian-based runners."

Next steps
- If you want, I can generate `docs/setup.devxp.md` with reproducible Linux steps (including installing `mutool`, `pdftoppm`, ImageMagick, and Ghostscript), and add an `.agent.md` file summarizing explicit guard rails and short prompts for automation.

---
Refactored for PHP library context — tell me if you'd like me to generate the Linux setup doc or an `.agent.md` file next.