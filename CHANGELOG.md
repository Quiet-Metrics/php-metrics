# Changelog

All notable changes to `quiet-metrics/php-metrics` are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning: [SemVer](https://semver.org).

## [0.3.0] - 2026-08-28

### Added
- **Visit continuity cookie.** The visitor fingerprint is recomputed on every hit from the subscriber network and the browser. When that network changes MID-VISIT (switching from mobile data to wifi), the fingerprint changes and the same person was counted as two unique visitors on the same day. `qm_visit` closes that: a first-party cookie of the tracked site, value `1`, sliding ten-minute window refreshed on each hit (`path=/`, `samesite=lax`, `secure` over https). It holds no identifier, its value being the same for everyone, and it is never set for someone who has opted out. Only its presence travels, as the `c` boolean of the payload.

### Changed
- `VISIT_MARKER`, `VISIT_LIFETIME` and `handleVisitRequest()` added alongside the opt-out helpers. Unlike `handleOptOutRequest()`, it does not touch `$_COOKIE`: the hit sent later must read the state as it was BEFORE this request opened the window.
- Minor version bump to 0.3.0: the bridges pin `^0.3`.

## [0.2.0] - 2026-08-28

### Added
- Opt-out marker: a visitor loading any page of the tracked site with `?qm_ignore=1` stops being counted, and `?qm_ignore=0` puts them back into measurement. The marker is a first-party `qm_ignore` cookie of that site (`path=/`, `samesite=lax`, `secure` over https, five years); it holds no identifier, is never transmitted to Quiet Metrics, and exists only to stop measurement. Nothing is sent while it is present.

### Changed
- Minor version bump to 0.2.0: the bridges pin `^0.2`, `^0.1` excluding 0.2 under 0.x.
- The published promise is now "no identification or tracking cookies" rather than "cookie-free". Nothing is stored on the visitor's device in order to measure them; the one exception is the opt-out marker, which they store themselves and which is exempt from consent as an expression of refusal.
- README: the package is on Packagist, so installing it no longer needs a VCS repository entry.

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
