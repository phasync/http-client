# Changelog

## 2.0.1-RC1 (2026-10-29)

- `CurlResponse::getHeaders()` keeps header names as the server sent them (`Content-Type`, not `content-type`), as PSR-7 requires; lookups stay case-insensitive. (#10)
- The body's `getSize()` is the body's length once it has arrived, and its `Content-Length` before that when the body is not compressed; it was always null. (#11)
- `request()`'s options apply to its own request only: another coroutine using the same client while a middleware waits gets the client's defaults, not them. (#12)

## 2.0.0 (2026-10-19)

- Requires phasync ^2.0 (released 2026-10-19), and waits for curl transfers with
  `phasync\Services\CurlAwaiter`, the 2.0 name of `CurlMulti`.
