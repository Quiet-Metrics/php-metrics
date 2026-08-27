# Changelog

All notable changes to `quiet-metrics/php-metrics` are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning: [SemVer](https://semver.org).

## [Unreleased]

## [0.1.1] - 2026-08-27

Documentation, artwork and test harness only. The library code is unchanged since 0.1.0.

### Changed
- Banner redrawn to the current brand: product typefaces instead of two webfonts that were never part of the design system, the damped wave instead of the pre-redesign bars, and the title in ink rather than in the accent colour.
- README: the pre-Packagist install note no longer says that access to the repository is required. It is public.

### Fixed
- The test capture server no longer guesses its port, which made the producer-to-consumer contract tests fail intermittently.

## [0.1.0] - 2026-07-24

First tagged release (private beta), after a full pre-publication review.

### Added
- `Client` with `pageview()` and `event()`: cookie-free, 100% server-side hits, context inferred from the current HTTP request and overridable (`url`, `referrer`, `ip`, `ua`, `lang`, `ts`).
- Signed mode (HMAC-SHA256, `X-QM-Timestamp` / `X-QM-Signature`): the only mode where the visitor identity carried in the payload is trusted by the platform. Essential for server-side sending.
- Non-blocking transports: fire-and-forget socket, short-timeout cURL fallback; every failure silent by contract.
- First-party anti-adblock proxy example (`examples/qm-proxy.php`).
- Producer-to-consumer contract proven against the real platform ingestion (signed identity honoured, unsigned payload IP ignored, tampered body rejected).

### Fixed
- Default endpoint now `https://quietmetrics.dev/api/v1/collect`.
- Empty public key: no request is sent at all (misconfigured installs no longer fire doomed hits on every page).
