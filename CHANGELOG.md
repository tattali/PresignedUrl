# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-10-24

A signing secret under 32 bytes now throws, and some responses change for existing users. See [UPGRADE-1.1.md](UPGRADE-1.1.md).

### Security

- `LocalAdapter` resolves symbolic links before checking that a file is inside the bucket directory, and the check now requires a path separator after the bucket path. A symbolic link to a file or directory outside the bucket, or a sibling directory whose name starts with the bucket's name (`files-old` next to `files`), is rejected with `InvalidPathException` (400) instead of being served. Paths that contain a NUL byte are rejected too.
- `FileServer` sends `X-Content-Type-Options: nosniff` with every file. HTML, SVG, XML and JavaScript files, recognised by extension or by media type (including malformed or comma-separated `Content-Type` values), are sent as `Content-Disposition: attachment` with `Content-Security-Policy: sandbox`, so a file uploaded by a user cannot run script on the serving domain.
- `Config` and `HmacSigner` throw `InvalidConfigurationException` when the signing secret is shorter than 32 bytes (`HmacSigner::MIN_SECRET_LENGTH`, counted with `strlen()`), including an empty secret. `SignatureConfig` throws it when `length` is under 16 bytes (`SignatureConfig::MIN_LENGTH`) or above the digest size of the algorithm, or when the algorithm is not one of `hash_hmac_algos()`. With a short secret anyone could guess it and sign URLs for any file, and a short length let signatures be brute-forced. The message never contains the secret.
- `HmacSigner::verify()` rejects a signature whose length differs from the expected one before it compares them with `hash_equals()`.
- The file name in `Content-Disposition` is no longer inserted as is: control characters are removed, `"`, `\`, `%`, `/` and non-ASCII characters are replaced with `_` in `filename`, and the UTF-8 name is sent in `filename*` (RFC 6266).

### Added

- `allow_unsafe_inline` serving option (`ServingConfig::$allowUnsafeInline`, `false` by default) to serve HTML, SVG, XML and JavaScript files with the configured `content_disposition`. Use it only when every file is trusted or the files are served from a separate, cookieless domain.
- `ContentDisposition::format()` to build an RFC 6266 `Content-Disposition` header value.
- `InvalidConfigurationException`, which extends `InvalidArgumentException`, and the `HmacSigner::MIN_SECRET_LENGTH` and `SignatureConfig::MIN_LENGTH` constants.
- Security policy (`SECURITY.md`) for private vulnerability reports.
- README sections on the security model, compatibility with AWS S3 and the alternatives.
- Tests for the signature format and for URL parsing and request verification edge cases.
- Infection mutation testing for the signing, URL and verification code, run in CI.
- Dependabot updates for Composer and GitHub Actions.
- PHP 8.5 in the CI matrix and a weekly scheduled CI run.

### Changed

- `FileResponse::getBody()` returns a stream for 206 Partial Content responses instead of a string, as it already did for 200 responses.

### Fixed

- Gzip compression: `FileServer` compresses only when the request's `Accept-Encoding` allows gzip, and then sends `Content-Encoding: gzip`, the compressed `Content-Length` and a separate ETag. Compressible files are sent with `Vary: Accept-Encoding`. In 1.0.x the body was compressed for every client and sent without `Content-Encoding`, so clients could not decode it. HEAD and 304 responses describe the representation that GET would return, and partial responses are never compressed.
- Range requests: a suffix range such as `bytes=-500` returns the last 500 bytes instead of the first ones. A range that starts after the end of the file, `bytes=-0` and any range on an empty file get `416 Range Not Satisfiable` with `Content-Range: bytes */<size>` instead of the whole file. `If-Range` is honoured: when it does not match the ETag or `Last-Modified`, the whole file is sent. The range is copied in chunks, which fixes truncated 206 bodies from S3 and Flysystem streams.
- `LocalAdapter::mimeType()` uses the file extension when `finfo` only gives a generic type (`text/plain`, `application/octet-stream`, an empty-file type or a `text/x-*` source-code guess such as `text/x-java`), so `.js`, `.mjs`, `.css`, `.svg`, `.json` and other web files get their real type. With `X-Content-Type-Options: nosniff`, browsers refused such scripts and styles. A specific type detected from the content still wins.
- `Storage::temporaryUrl()` signs the path without its leading slash, as it appears in the URL. A path such as `/invoice.pdf` gave a URL that was rejected with 403.
- The Laravel routes file called `Route::head()`, which Laravel's router does not have, so loading the routes threw "Attribute [head] does not exist". It now registers the GET route only, which Laravel also matches for HEAD requests. The `presigned-url.serve.head` route name, which was never registered, is gone.
- README claims not backed by the code: the CORS default, the Laravel environment variable names and the tested Laravel versions.

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

[Unreleased]: https://github.com/tattali/PresignedUrl/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/tattali/PresignedUrl/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/tattali/PresignedUrl/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/tattali/PresignedUrl/releases/tag/v1.0.0
