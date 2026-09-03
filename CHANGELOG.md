# Changelog

All notable changes to `repository_largefile` are documented here.

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
