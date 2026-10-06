# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Infection mutation testing in CI, MSI 100%.

## [2.0.0] - 2026-10-05

First tagged release, aligned with the Contenir 2.x packages: PHP 8.3+, the
php-db QA toolchain, and fixes that make the command run at all. See
[UPGRADE-2.0.md](UPGRADE-2.0.md).

### Changed

- Requires PHP 8.3, 8.4 or 8.5, symfony/console 6.4.3+ or 7.0.3+ (and
  conflicts with symfony/string below 6.4.3, which PHP 8.4 deprecates) and
  laminas/laminas-servicemanager 3.22+.
- Every concrete class is `final`: `ConfigProvider`,
  `DiagnosticsCommandFactory` and `DiagnosticsCommand`. Sites add checks
  through `Check\CheckProviderInterface` services instead of subclassing.
- The check lists are public constants.
- Database adapters are read from `db.cms` / `db.site`, falling back to
  `db.adapters.cms` / `db.adapters.site`.
- The security advisories check is skipped, with the reason, when
  enlightn/security-checker or `composer.lock` is missing.
- `bin/diagnostics` takes the command's options (`--fix`) instead of
  `key=value` arguments.
- Results are classified by their laminas-diagnostics interface, so custom
  result classes are listed.

### Added

- `Check\CheckProviderInterface`: services listed under
  `contenir_diagnostics.check_providers` add checks to the command.
- `contenir_diagnostics.required_extensions` replaces the required PHP
  extension list.
- `LICENSE` with the MIT licence text `composer.json` already declared.

- `laminas/laminas-diagnostics` as a dependency, and suggestions for
  enlightn/security-checker and laminas/laminas-cli.
- The site database's `port` is included in the connection DSN.
- Continuous integration on PHP 8.3, 8.4 and 8.5 against lowest, locked and
  latest dependencies, with coverage reported to Codecov.
- Unit and integration test suites, with 100% line and branch coverage.

### Fixed

- The package did not require laminas/laminas-diagnostics, which the command
  is built on.
- The command always failed with `InvalidArgumentException` unless
  enlightn/security-checker was installed.
- `bin/diagnostics` called the protected `execute()` method with an array
  and never loaded the autoloader, so it could not run.
- Database checks read `db.adapters.*`, but Contenir CMS configures `db.cms`
  and `db.site`, so they never ran.
- Failed auto-fixes raised PHP warnings (the `catch (Throwable)` never saw
  them) instead of only reporting the failure.

### Removed

- `laminas/laminas-cache`, which nothing used.
- `phpstan/phpstan`, `laminas/laminas-coding-standard`, `phpcs.xml` and
  `phpstan.neon`, replaced by Mago via `php-db/phpdb-qa-tools`.

## Before 2.0 (untagged)

- Initial diagnostics command extracted from Contenir CMS, then code quality
  tooling, a PSR-4 layout and laminas-cli registration.
