# CCITTFax Module Agent Documentation

This document provides comprehensive context for future agents or developers working on the CCITTFax module within the PXP PDF library. It summarizes the reorganization effort, technical foundation, codebase status, and guidance for continued development.

## 1. Conversation Overview

- **Primary Objectives**: Analyze and reorganize the CCITTFax folder structure for semantic clarity, separating concerns into subfolders (Decoder, Interface, Model, Constants, Util), adjusting namespaces, and ensuring backward compatibility.
- **Session Context**: Began with structural analysis and suggestions, followed by full implementation including file moves, renames, namespace updates, and test adjustments. Concluded with validation through unit tests.
- **User Intent Evolution**: Initial analysis request evolved into complete refactoring for maintainability, with emphasis on testing and compatibility.

## 2. Technical Foundation

- **PHP ^8.4**: Core language with strict typing and PSR-4 autoloading.
- **Composer**: Dependency management and script execution (e.g., `composer test:unit`).
- **PHPUnit**: Testing framework for unit tests, configured in `phpunit.xml`.
- **Terminal Tools**: `mkdir`, `mv`, `sed` for file operations; `run_in_terminal` for execution.
- **Architectural Pattern**: Responsibility separation (Decoder/, Model/, etc.), factory pattern for decoders.
- **Environment Detail**: Linux with zsh, absolute paths (`/home/vector/Documents/1doc/1dom/library/pdf/`), no specific constraints beyond standard PHP setup.

## 3. Codebase Status

### Decoder/
- **Purpose**: Houses concrete decoder implementations for CCITT fax formats.
- **Current State**: Files moved and renamed (e.g., `CCITT3Decoder.php`), namespaces updated to `PXP\PDF\CCITTFax\Decoder`, class names standardized.
- **Key Code Segments**: `CCITT3Decoder` implements `StreamDecoderInterface`, uses `Params`, `Codes`, etc.; `DecoderFactory` provides static `createForParams` method.
- **Dependencies**: Relies on `Model/Params`, `Constants/Codes`, `Util/BitBuffer`.

### Interface/
- **Purpose**: Defines contracts for decoders.
- **Current State**: `StreamDecoderInterface` moved and namespace adjusted.
- **Key Code Segments**: Interface with `decode()`, `decodeToStream()`, `getWidth()`, `getHeight()` methods.
- **Dependencies**: Used by all decoders.

### Model/
- **Purpose**: Contains data models and value objects.
- **Current State**: `Params.php` (renamed from `CCITTFaxParams`), `Mode.php`, etc., with updated namespaces.
- **Key Code Segments**: `Params` class with `fromArray()`, `isGroup4()`, etc.; `Mode` enum for 2D modes.
- **Dependencies**: Used by decoders and constants.

### Constants/
- **Purpose**: Lookup tables for Huffman codes and modes.
- **Current State**: `Codes.php` and `Modes.php` moved, namespaces adjusted, added use for `HorizontalCode`.
- **Key Code Segments**: `Codes::findCode()` for run-length lookup; `Modes::getMode()` for 2D mode detection.
- **Dependencies**: `HorizontalCode` from `Model/`.

### Util/
- **Purpose**: Utility classes for bit manipulation and packing.
- **Current State**: `BitBuffer.php` and `BitmapPacker.php` moved, namespaces updated, `BitBuffer` adjusted to validate input types.
- **Key Code Segments**: `BitBuffer` for bit-level reading; `BitmapPacker::packLines()` for data compression.
- **Dependencies**: Used by decoders.

## 4. Problem Resolution

- **Issues Encountered**: Import conflicts (e.g., duplicate `Params` use), missing test fixtures, `BitBuffer` type errors.
- **Solutions Implemented**: Removed conflicting imports, adjusted `BitBuffer` to throw `InvalidArgumentException` for invalid inputs, bulk-edited files with `sed`.
- **Debugging Context**: Ran targeted tests (`CCITT3DecoderTest`, `ParamsTest`, `BitmapPackerTest`) to isolate issues; fixed namespace mismatches.
- **Lessons Learned**: Importance of consistent namespace updates across all files; value of factory pattern for extensibility; need for input validation in utilities.

## 5. Progress Tracking

- **Completed Tasks**: Folder creation, file moves/renames, namespace adjustments, import fixes, test reorganization, README creation, factory implementation.
- **Partially Complete Work**: Test validation (core tests pass, but some fail due to missing resources).
- **Validated Outcomes**: Key tests confirm decoders, params, and utilities work correctly post-refactor.

## 6. Active Work State

- **Current Focus**: Validating the reorganization through unit tests after fixing import and type issues.
- **Recent Context**: Executed `composer test:unit` with filters, resolved conflicts in test files, adjusted `BitBuffer` for better error handling.
- **Working Code**: Primarily test files and `BitBuffer.php`; no active code snippets being modified at summarization.
- **Immediate Context**: Ensuring the refactored structure maintains functionality, with tests as the final validation step.

## 7. Recent Operations

- **Last Agent Commands**: `run_in_terminal` for `composer test:unit -- --filter=CCITT3DecoderTest`, then `ParamsTest`, then `BitmapPackerTest`.
- **Tool Results Summary**: `CCITT3DecoderTest`: 17/17 OK (6 skipped); `ParamsTest`: 7/7 OK; `BitmapPackerTest`: 17/17 OK. No long outputs truncated.
- **Pre-Summary State**: Agent was running and analyzing test results to confirm refactoring success.
- **Operation Context**: These commands validated the code reorganization, directly supporting the user's goal of semantic folder restructuring without breaking functionality.

## 8. Continuation Plan

- **Pending Task 1**: Address missing test fixture files (e.g., `18x18.bin`) to enable full test suite.
- **Pending Task 2**: Potentially update any external references to old class names if discovered.
- **Priority Information**: High priority on test fixtures for complete validation; low priority on further optimizations.
- **Next Action**: Proceed with development, as the structure is organized and tested.

## Usage Notes

- Refer to the `README.md` in this directory for API usage examples.
- Run tests with `composer test:unit` to validate changes.
- Follow PSR-4 autoloading and project conventions for new code.
- Use `DecoderFactory` for creating decoders to maintain extensibility.