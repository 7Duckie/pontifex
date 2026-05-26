# Changelog

All notable changes to Pontifex are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Public API stability begins at v1.0.0. Pre-v1.0 releases (v0.x.y) may
introduce breaking changes between minor versions, though the project
holds itself to a stricter "public-API stability across commits within
a release" discipline — see
[ADR 0001](docs/adr/0001-wordpress-context-abstraction.md) and the
v0.0.x decision log for the reasoning.

## [Unreleased]

### Added
- Phase 1: format primitives — `Codec` interface, `RawCodec` (`0x0000`),
  `GzipCodec` (`0x0001`), `CodecRegistry`, `HashingStream`, `ByteOrder`
  big-endian helpers, and the `Header`, `Provenance`, `EntryHeader`,
  `ArchiveManifest`, `Footer` value objects.
- Phase 2: archive writer — `EntryWriter`, `FooterWriter`,
  `ArchiveWriter`, `FileScanner`, `DatabaseScanner`, `ManifestBuilder`,
  `ExclusionRules`.
- Phase 3: archive reader — `EntryReader`, `ArchiveReader`,
  `EntryReadResult`, with the mandatory verification-order contract
  from the format spec.
- Phase 4: CLI integration — `WordPressContext` abstraction
  ([ADR 0001](docs/adr/0001-wordpress-context-abstraction.md)),
  refactored `DoctorCommand` to use it, and new `ExportCommand`
  registering `wp pontifex export`.

### Notes
- v0.1.0 archives are **unencrypted**. Encryption (codecs `0x0100`,
  `0x0101`, `0x0102`) lands in v0.2.0. See
  [`docs/roadmap.md`](docs/roadmap.md) for the full deferred list.

## [0.0.4] — pre-alpha (docs-only)

### Added
- pip and SSL troubleshooting section in `CONTRIBUTING.md` covering
  certifi setup behind corporate TLS interception, for contributors
  whose Python install cannot reach PyPI during `pre-commit install`.

### Notes
- Byte-identical to v0.0.3 for end users; documentation-only release.

## [0.0.3] — pre-alpha

### Added
- Archive format specification (`docs/archive-format.md`) and design
  rationale (`docs/archive-format-design.md`) — the public contract
  every future version of Pontifex commits to honour.
- `Environment` interface and `RealEnvironment` implementation
  abstracting PHP-runtime queries so `DoctorCommand`'s checks can be
  exercised under controlled test conditions.
- brain/monkey added as a test dependency for WordPress-function
  mocking; behavioural tests now cover `DoctorCommand`'s runtime
  checks, PHP-configuration checks, required and recommended
  extension checks, filesystem-permission checks, WP-config status
  checks, and the status-summary computation.
- `compute_status_counts()` extracted from `print_summary()` for
  direct test coverage.
- Xdebug-with-Local-and-PhpStorm setup guide in `CONTRIBUTING.md`.
- `composer dev:setup` script bundling first-time contributor setup
  (pre-commit hook installation) into a single command.

### Notes
- No user-facing feature changes; foundation work for the archive
  implementation that lands in v0.1.0.

## [0.0.2] — pre-alpha

### Added
- `PONTIFEX_VERSION` constant defined in `pontifex.php`.

### Changed
- `pontifex.php` registers the new `wp pontifex export` command stub
  alongside the existing `wp pontifex doctor`.

## [0.0.1] — pre-alpha

### Added
- Initial plugin scaffolding: `pontifex.php` bootstrap, Composer
  autoload, PSR-4 namespace, GPL-2.0-or-later licence.
- `wp pontifex doctor` WP-CLI command — environment-audit checklist
  covering PHP runtime, PHP configuration, required and recommended
  extensions, filesystem permissions, WordPress configuration, and
  database compatibility.
- `Environment` interface and `RealEnvironment` production
  implementation, decoupling all PHP-runtime queries from the doctor
  command for test isolation.
- WPCS-clean codebase: PHPCS WordPress-Extra, PHPStan level 6, PHPUnit
  unit tests with comprehensive coverage of every check method.
- Project documentation: README, archive format specification
  (`docs/archive-format.md`), design rationale
  (`docs/archive-format-design.md`), threat model
  (`docs/threat-model.md`), idea bank (`docs/idea-bank.md`).
- CI workflow running PHPCS, PHPStan, PHPUnit, and `composer audit`
  on every push.
- Pre-commit hooks: gitleaks, PHPCS fast subset, PHPStan changed-files.
- Security tooling: `roave/security-advisories` in `require-dev`
  refusing installation of any CVE-flagged dependency.

[Unreleased]: https://github.com/7Duckie/pontifex/compare/v0.0.4...HEAD
[0.0.4]: https://github.com/7Duckie/pontifex/releases/tag/v0.0.4
[0.0.3]: https://github.com/7Duckie/pontifex/releases/tag/v0.0.3
[0.0.2]: https://github.com/7Duckie/pontifex/releases/tag/v0.0.2
[0.0.1]: https://github.com/7Duckie/pontifex/releases/tag/v0.0.1
