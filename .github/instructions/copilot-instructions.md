# Copilot / AI Agent Instructions for pxp/pdf

Summary
- Keep responses concise and actionable. Prefer small, testable changes and include unit tests when behavior changes.

Big picture (what to know quickly)
- This repository is a PHP library providing a modern FPDF-compatible API (namespace `PXP\`) with utilities to read/modify/merge/split PDFs.
- Core area: `src/PDF/Fpdf/` (primary public entrypoint `FPDF` in `src/PDF/Fpdf/FPDF.php`). Important collaborators: `PageManager`, `FontManager`, `ImageHandler`, `PDFStructure`, `TextRenderer`, `OutputHandler`.
- Dependency injection pattern: most heavy components accept PSR interfaces (PSR-3 Logger, PSR-6 Cache, PSR Event Dispatcher). Many classes accept null and default to Null implementations (see `NullLogger`, `NullCache`, `NullDispatcher`).

Key developer workflows (explicit commands)
- Install dependencies and run tests: use Composer. E.g., `composer install` then `composer test` (or `composer test:unit`, `composer test:feature`, `composer test:integration`).
- Static analysis: `composer phpstan` (configured at `phpstan.neon`).
- Code style: `composer cs:check` and `composer cs:fix` (configuration in `.php-cs-fixer.dist.php`).
- Run single test file: `vendor/bin/phpunit tests/Path/To/Test.php` or use PHPUnit suite names from `phpunit.xml`.

Project-specific conventions & patterns
- Strict typing and file headers: files use `declare(strict_types=1)` and include a project license header — follow `.php-cs-fixer.dist.php` rules for formatting and header placement.
- PSR-4 autoload: root namespace is `PXP\` (see `composer.json`); tests autoload under `Test\`.
- Tests use shared helper singletons from `tests/TestCase.php`: `TestCase::getLogger()`, `::getCache()`, `::getEventDispatcher()`. Use `TestCase::createFPDF()` when creating instances in tests to get consistent environment.
- External CLI dependency in tests: image comparisons depend on external tools (mutool, pdftoppm, ImageMagick `convert`/`compare`, or Ghostscript `gs`). If a tool is missing tests may fall back to other approaches (see `tests/TestCase::pdfToImage`, `::compareImages`). Document these dependencies when adding image-based tests.
- Persistent test debugging: `PERSISTENT_PDF_TEST_FILES=1` keeps generated files and changes some cleanup behaviour — useful when debugging failing visual tests.
- Resource paths & font loading: fonts default to `FPDF_FONTPATH` constant or `src/PDF/Fpdf/resources/font/` — set `FPDF_FONTPATH` in tests or CI if needed.
- Public convenience methods: `FPDF::splitPdf`, `::extractPage`, `::mergePdf` provide high-level utilities — prefer those for end-to-end features.
- Error handling: methods throw `PXP\PDF\Fpdf\Exception\FpdfException` on library errors.

Testing notes
- Tests split into suites: Unit / Feature / Integration per `phpunit.xml`. Use the same suite names when running targeted runs via composer scripts (`composer test:unit`, etc.).
- Integration/feature tests may be slower and rely on filesystem (check `tests/resources/` fixtures). Use `TestCase::getRootDir()` for temporary files.
- Keep image-based assertions tolerant: when composite rendering tools can't produce identical bitmaps, the suite falls back to text extraction comparison (see `assertPdfPagesSimilar`). Prefer writing tests that assert content/invariants rather than raw image equality where possible.

Where to look for examples
- `examples/` contains small usage snippets (e.g., `buffer_extraction_easy.php`, `extract_text.php`) — use them as reference code for API usage patterns.
- Tests are the canonical source of behavior: `tests/Feature/`, `tests/Integration/` and `tests/Unit/` include many real-world cases (merging, splitting, preserving XObjects, CCITT Fax tests).

Coding and PR guidance for AI agents
- Make small, focused changes with tests. If changing behavior, add or update tests in the appropriate suite and run `composer test` locally.
- Follow existing style rules: run `composer cs:fix` and ensure `composer cs:check` passes before raising a PR.
- Use PSR interfaces when adding dependencies; prefer constructor injection. If you must add optional behaviour, follow existing Null-object patterns (see `NullLogger`, `NullCache`, `NullDispatcher`).
- Mention external requirements in tests and add guards/fallbacks (see `TestCase::pdfToImage`). Avoid introducing fragile system-dependent tests without a clear fallback.

Quick prompts/examples for tasks
- "Add a new method `getPageCount()` to `PageManager`, update `FPDF::getPageCount()` delegating to it, and add unit tests in `tests/Unit/PDF/` using `TestCase::createFPDF()` to verify counts after `addPage()` calls." 
- "Refactor font caching: move cache key generation to `FontManager::makeCacheKey()` and add tests verifying that loading the same font twice uses the cache (use `TestCase::getCache()` to assert cache hits)."

If something is unclear
- Prefer opening a draft PR with the change and include the test run output and any failing CI logs. Ask maintainers for clarification when the required behavior isn't explicit in tests or examples.

Files / paths referenced as authoritative
- `src/PDF/Fpdf/FPDF.php` (primary public API)
- `src/PDF/Fpdf/` (core implementation)
- `tests/TestCase.php` (shared test helpers and environment setup)
- `phpunit.xml`, `composer.json` (scripts), `phpstan.neon`, `.php-cs-fixer.dist.php`

Thank you — please review these notes and tell me anything you'd like expanded or emphasized. I can iterate on this file if there are project norms I missed.