# Changelog

All notable changes to `repository_largefile` are documented here.

## 0.3.4 — 2026-09-03

- **Publish a large backup share in the background.** Creating a share encrypted
  the whole backup inside the web request, so a large file (e.g. several GB) could
  exceed the web server's request timeout (a 504) before the share was ready. The
  Backup shares form now offers **Create in the background** (on by default): the
  uploaded backup is encrypted on the server as a queued transfer, immune to the
  request timeout, and its share link appears on the **Transfers** page when ready.
  The upload is referenced into a plugin-owned staging area (no second copy of a
  large backup is made, and it survives Moodle's draft cleanup while it waits) and
  removed once it has been encrypted — or on cancellation, with the cleanup task
  sweeping any source left by a failed publish. A new *Backup share (publish)*
  transfer type; runs under the same `process_transfers` scheduled task, so it needs
  cron.
  A **Backups being published** section on the page tracks each queued job (and
  lets you cancel one), the shares list now shows each share's **link** so it can
  be retrieved at any time, and the expiry is measured from when the share is
  actually created (not from when it was queued). Publications stay within the
  sharing capability — an import-only operator on the Transfers monitor does not
  see or act on them.
- **Clearer "could not be fetched" message on import.** When a share import fails
  to reach the peer, the message now also points at the peer's **Site URL** — a
  share on a private or internal address is only reachable once that URL is
  registered (see 0.3.3) — alongside checking the link's expiry and reachability.

## 0.3.3 — 2026-09-03

- **Import a share from a peer on a private/internal network.** Importing a share
  could fail with *"The URL is blocked"* when the peer's address resolved to a
  private range — Moodle's outgoing-request (SSRF) protection blocking it, as it
  does by default. A trusted peer now records its **Site URL**, and only that one
  registered origin — its exact scheme, host and port — is allowed past the block
  when importing that peer's share (every other host, and every other port or
  scheme on the same host, redirect targets included, stays blocked, so no other
  service on the peer machine becomes reachable). A share link is now also accepted
  only when it is on that same origin. Existing peers keep working; edit
  a peer to add its Site URL to reach one behind the block. New `baseurl` column on
  the peers table (added by upgrade).

## 0.3.2 — 2026-09-03

- **Management pages now share navigation.** Trusted peers, Backup shares, Import
  and Transfers previously stood alone with no way back to the plugin's settings.
  They now carry a common tab bar (with a **Settings** tab back to the
  configuration page) and a breadcrumb, so you can move between them and back to
  settings.
- **Import a large shared backup in the background.** A foreground import of a
  large backup could exceed the web server's request timeout (a 504). The import
  form now offers "Import in the background" (on by default), which queues the
  import as a server-side transfer that is immune to the request timeout and lands
  in your private files when done — watchable on the Transfers page.

## 0.3.1 — 2026-09-03

Bug fix: the upload dialogue failed to load with JavaScript caching turned off
(developer mode). With no source map beside `amd/build/upload.min.js`, Moodle's
dev-mode loader served the ES6 `amd/src/upload.js` directly ("Cannot use import
statement outside a module" / "No define call for repository_largefile/upload").
Ships a source map beside the built module so the loader serves the built AMD in
every mode. (A future `grunt amd` regenerates the map with full line mappings.)

## 0.3.0 — 2026-09-03

Adds **scheduled, unattended server-side transfers** and a **site-wide monitor**.

- **Queue a transfer** — a URL import or a peer-share import can be queued to run
  **on the server**, either as soon as possible or **at a scheduled time** (e.g.
  overnight), so you can start it and walk away. A URL import stages the fetched
  file into your large-file picker; a peer-share import is fetched, decrypted,
  verified and saved to your private files. New `repository_largefile_transfers`
  table, `transfer_manager`/`transfer_runner`, and a `process_transfers` scheduled
  task that runs due transfers (every 5 minutes; a scheduled transfer waits for
  its time).
- **Site-wide monitor** — a new *Transfers* page (linked from the plugin's
  configuration page) shows every chunked upload in progress and every queued,
  running or finished transfer across the site, and lets an admin cancel a queued
  transfer or clear a finished one.
- **Navigation guard** — the chunked uploader now warns before you navigate away
  from an upload still in progress (a local file can only be read by the open
  page). True background upload of a *local* file is not possible in the browser;
  the unattended path is the server-side transfers above.
- New `transfer_completed` event; the cleanup task prunes finished transfers after
  a week; privacy provider declares, exports and erases transfer records; unit
  tests for the transfer queue. Version 0.3.0.

## 0.2.1 — 2026-09-03

Bug fix and admin-UI tidy-up.

- **Fix: settings and management pages were unreachable.** Repository plugins do
  not load `settings.php` the way admin-tool plugins do, so the chunk-size and
  retention settings never registered (the uploader silently used its 20 MB
  fallback) and the trusted-peers / backup-shares / import pages had no admin-tree
  entry. The tunables now live on the plugin's own configuration page via
  `type_config_form()` (read with `get_config('largefile', …)`), and the three
  management pages are linked from there — so everything sits one level under the
  plugin's configuration page instead of as loose or missing nodes.
- **Upload dialogue now shows a live percentage** next to the progress bar, so
  it's clear an upload is actually progressing.

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
