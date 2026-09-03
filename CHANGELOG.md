# Changelog

All notable changes to `repository_largefile` are documented here.

## 0.2.0 — 2026-09-03

Adds **encrypted backup sharing** between two sites that both run this plugin.

- **Trusted peers** — pair with another site by exchanging a shared secret (≥24
  chars). Secrets are stored encrypted at rest with the site key
  (`\core\encryption`). Managed at *Site administration > Plugins > Repositories
  > Large file: trusted peers*.
- **Publish a share** — encrypt a backup for a chosen peer and hand back a share
  link. The file is encrypted at rest with a per-share key derived (HKDF-SHA256)
  from the pairing secret and a random salt, streamed through libsodium's
  XChaCha20-Poly1305 secretstream (authenticated, truncation-detecting). Shares
  carry an optional expiry and a download cap.
- **Import a share** — paste a peer's share link to fetch, decrypt and verify it.
  The receiver checks the recovered plaintext against the advertised SHA-256
  before handing it to the file picker.
- **Request authentication** — every share request is signed with HMAC-SHA256
  over the canonicalised parameters, with a timestamp window and single-use
  nonces (replay protection). Fetches use Moodle's SSRF-aware `\curl` wrapper.
- New capabilities `repository/largefile:share` and `repository/largefile:import`
  (system context, no archetypes — grant explicitly). New `share_created`,
  `share_downloaded` and `backup_imported` events. Expired/exhausted shares and
  stale nonces are purged by the cleanup scheduled task. Privacy provider extended
  to declare, export and erase share metadata.

## 0.1.0 — 2026-09-02

Initial release. A Moodle repository plugin that brings two ways of importing a
large file into every file picker (including the course backup restore upload):

- **Import from a URL** — server-side fetch through Moodle's SSRF-aware `\curl`
  wrapper, following redirects, with a finite size cap.
- **Chunked large-file upload** — the browser uploads in small chunks that are
  reassembled server-side, bypassing PHP's `upload_max_filesize` / `post_max_size`;
  resumable, with retry-and-backoff and cancellation propagated to the server.

Both are delivered through the picker's download action (not a multipart upload),
launched from the picker's "Upload a file" button via the supported
`uploadfile`/`uploadevent` hook. Includes a cleanup scheduled task, admin
settings (chunk size + retention), a privacy provider, an uninstall hook, and
PHPUnit coverage.

Requires Moodle 5.0+ and PHP 8.2–8.4.

Note: the destination form's size limit still applies at selection unless the
user holds `moodle/course:ignorefilesizelimits` (site admins do) — see the
README's "Destination size limits" section. Extracted and generalised from
`tool_canvasuplifter`; chunked-upload logic derives from `local_chunkupload`
(2020 Justus Dieckmann WWU).
