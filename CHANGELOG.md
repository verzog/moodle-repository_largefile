# Changelog

All notable changes to `repository_largefile` are documented here.

## 0.6.1 — 2026-09-07

- **Show background uploads distinctly on the Transfers monitor.** The
  *Uploads in progress* table now has a **Mode** column marking each upload as
  **Background (continues after the tab is closed)** or **In-page (only while the
  tab is open)**, so an admin can see a Background Fetch upload streaming in even
  after its owner has closed the browser. (No schema change — a background upload
  is identified by its received-range map, which only that path sets.)
- **Fix: the last column of that table was mislabelled "Expires".** It showed the
  upload's **last-activity** time (a timestamp already in the past), not an expiry.
  It is now correctly headed **Last activity**.

## 0.6.0 — 2026-09-07

- **Import straight into a course's backup area.** The destination chooser gains
  **"A course's backup area"**: pick a target course and the imported backup lands
  in that course's backup area, where it appears under *Restore > Course backup
  area* on that course — one step closer to restore than the per-user backup area.
  A course selector appears when the destination is chosen, limited to courses you
  may add a backup to (`moodle/restore:uploadfile`); the capability is re-checked
  server-side, so a background job can never place a file into a course the user
  can't. Offered on both the Import page and the URL-import queue, and enabled per
  site alongside the other destinations. This destination is always an explicit
  choice (never the "Automatic" default, since it needs a course). No schema
  change; the chosen course rides the transfer's existing payload.

## 0.5.2 — 2026-09-07

- **Fix: the large-file upload dialogue was broken in every browser.** The AMD
  module (`amd/build/upload.min.js`) had been rebuilt without default-import
  interop, so `core/templates`, the modal, and `core/notification` resolved to
  `undefined` and the dialogue threw `Cannot read properties of undefined
  (reading 'render')` the moment it opened — no file could be uploaded. Rebuilt
  with `_interopRequireDefault`/`_interopRequireWildcard` wrapping (matching
  Moodle's own AMD output), restoring both the chunked upload and the URL-import
  dialogue. Source unchanged; build artefact only.

## 0.5.1 — 2026-09-07

- **Fix: ensure the `receivedmap` upgrade actually runs.** Re-asserts the
  `receivedmap` column added in 0.5.0 with a fresh version bump and an idempotent
  upgrade step, so a site that recorded 0.5.0 without the column landing (for
  example an interrupted upgrade) picks it up on the next upgrade. No behaviour
  change; a site already carrying the column is unaffected.

## 0.5.0 — 2026-09-07

- **Background upload that continues after you close the tab (experimental,
  Chrome/Edge).** The upload dialogue now offers **"Keep uploading after I close
  this page"** where the browser supports the Background Fetch API. Tick it and the
  file is handed to the browser's background uploader through a **service worker**
  (`sw.js`): it keeps going even if you close the tab, and the file appears in the
  picker when it finishes. There is no progress bar in the dialogue while it runs —
  the browser shows its own. The chunks arrive **out of order**, so the server now
  reassembles them by byte offset and completes the upload once every byte has
  landed (new `receivedmap` column on the chunks table, added by upgrade; writes
  are idempotent and serialised per token). Browsers without Background Fetch
  (Safari, Firefox) upload in the foreground as before, with resume (0.4.1).
  <br>_Marked experimental: the background path depends on a browser feature and
  an HTTPS origin, so verify it on your own Chrome/Edge + HTTPS site._

## 0.4.1 — 2026-09-07

- **Resume a chunked upload after navigating away.** A large browser upload no
  longer loses its progress when you leave the page. The server already keeps the
  partial file; the upload dialogue now also remembers it in your browser, so when
  you come back and re-select the **same file**, it carries on from where it
  stopped instead of starting over — a banner offers the resume and shows how far
  it got. The bytes still only flow while the tab is open (a browser can only read
  a local file from an open page), so for a genuinely unattended transfer a URL
  import or the Transfers page is still the way; this just means an accidental
  navigation, refresh or closed tab no longer wastes a half-finished upload. The
  resume hint is stored per browser and expires after 24 hours. No schema change.

## 0.4.0 — 2026-09-06

- **Choose where an imported file goes, and restrict what the site accepts.** An
  import (peer share or URL) can now be routed to a chosen **destination** — the
  private backup area (restorable), the large file picker (to attach to an
  activity), or generic private files — with the choices on the form narrowed to
  the file's kind once it is fetched (a course backup can't go to the picker; a
  SCORM/Cartridge/video can't go to the restore area). A new **admin policy** on
  the plugin's configuration page can **restrict accepted file types** (course
  backups `.mbz`, SCORM `.zip`, IMS Common Cartridge `.imscc`, video) and choose
  which destinations are offered. Type restriction is **opt-in**: with it off (the
  default) every file type is accepted, as before. When on, the accepted-type gate
  applies to every ingest path — the import page, the URL-import queue, and the
  chunked large-file uploader (both its browser-chunk and URL-fetch paths, rejected
  before any bytes are kept). The destination chooser defaults to **Automatic**
  (route by file kind), and disabling the picker destination also turns off direct
  uploads into the picker. No schema change; the routing travels in the transfer's
  existing payload.
  <br>_A follow-up will add a specific course's backup area as a further
  destination (with a course picker and capability checks)._

## 0.3.8 — 2026-09-04

- **Imported peer backups now land where you can actually restore them.** A
  successful peer-share import was saved into your generic **Private files**, which
  Moodle's course-restore screen does not read — so the transfer reported success
  but the backup could not be restored without fishing it out through a file picker.
  Imported backups now go to your **private backup area** (the user `backup` file
  area) instead, so each appears directly under *Restore > User private backup area*
  on any course, with a one-click **Restore** link. Applies to both the synchronous
  and the background (unattended/scheduled) import paths. No schema change; existing
  files already in Private files can be moved across via *Manage backup files*.

## 0.3.7 — 2026-09-04

- **Throughput, ETA and stall detection on a running publication.** Alongside the
  percent complete, a running background publish now shows a rough **average speed
  (MB/s)** and **estimated time remaining**, plus how long it has been running — on
  the Backup shares page and the Transfers monitor. The publish records the backup's
  size in its queued job so the readout can be computed. To tell "slow" from "stuck"
  reliably (an average speed alone keeps ticking down even on a frozen job), a
  running transfer now records **when its percent last advanced**; if it has not
  moved for well over its own average step time the readout says *no progress for …*
  instead of a speed and ETA. New nullable `progressupdated` column on the transfers
  table (added by upgrade).

## 0.3.6 — 2026-09-04

Makes background publication of a **very large** backup faster, observable and
self-healing.

- **Progress and elapsed time.** A running publication now shows a percent-complete
  and how long it has been running (on the Backup shares page and the Transfers
  monitor), instead of an opaque "Running" — so a slow or stuck encryption is
  legible. New `progress` column on the transfers table (added by upgrade).
- **One fewer pass over the data.** The backup is now encrypted **straight from the
  stored file** rather than first copied to a temporary plaintext, cutting roughly a
  third of the disk I/O for a multi-gigabyte publish.
- **Faster recovery of a stuck job.** If the cron worker dies mid-encryption (for
  example a host that caps cron run time), the job was left "Running" for up to six
  hours before the scheduled task returned it to the queue; the stale-lease is now
  one hour. A scheduled task holds its lock for the whole run, so a genuinely
  slow-but-alive encryption is never reclaimed early, and only a job whose worker
  actually died is retried.

## 0.3.5 — 2026-09-04

- **Fix: importing a share from a trusted peer threw a coding error.** The
  peer-scoped cURL security helper short-circuited its allow decision for the
  registered peer origin without running the site check, so Moodle's cURL wrapper
  then raised *"url_is_blocked() must be called before get_resolve_info()"* and the
  fetch failed. The helper now always runs the site check first (recording the
  state the wrapper reads back) and only overrides the verdict for the trusted
  origin.

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
