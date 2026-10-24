# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Security policy (`SECURITY.md`) for private vulnerability reports.
- README sections on the security model, compatibility with AWS S3 and the alternatives.
- Tests for the signature format and for URL parsing and request verification edge cases.
- Infection mutation testing for the signing, URL and verification code, run in CI.
- Dependabot updates for Composer and GitHub Actions.
- PHP 8.5 in the CI matrix and a weekly scheduled CI run.

### Fixed

- README claims that the code does not back: compression and `Accept-Encoding`, the CORS default, the Laravel environment variable names and the tested Laravel versions.

## [1.0.1] - 2026-01-07

### Added

- Unit tests for the Symfony and Laravel bridges, the configuration classes, `StorageFactory`, `Storage`, `FileServer` and `LocalAdapter`.

### Changed

- `FileServer` request handling split into smaller methods. Responses are unchanged.

### Fixed

- README badge links now point to this repository and its SonarCloud project.
- Coverage collection and report paths for SonarCloud in CI.

## [1.0.0] - 2026-01-07

### Added

- Presigned URLs signed with HMAC (SHA-256 by default) and checked with `hash_equals()`.
- Buckets backed by the local filesystem, League Flysystem or AWS S3 (native S3 presigned URLs).
- `FileServer` with ETag and `Last-Modified` handling (304), range requests (206), gzip compression and CORS.
- Extension block-list and allow-list, maximum file size and `..` rejection in `LocalAdapter`.
- Symfony bundle and Laravel service provider, facade and routes.

[Unreleased]: https://github.com/tattali/PresignedUrl/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/tattali/PresignedUrl/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/tattali/PresignedUrl/releases/tag/v1.0.0
