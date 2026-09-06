# Large file repository (`repository_largefile`)

A standalone Moodle **repository plugin** that adds two ways to bring a file that
is too big for a normal upload into any Moodle file picker — including the
**course backup restore** "upload a backup file" screen:

- **Import from a URL** — the site fetches the file server-side (following
  redirects, size-capped), so the browser upload size never applies.
- **Chunked large-file upload** — the browser uploads the file in small chunks
  that are reassembled on the server, so PHP's `upload_max_filesize` /
  `post_max_size` never apply.

Because it is a repository plugin, both appear **everywhere the file picker is
used** (assignments, resources, the course restore upload, …) with no per-place
configuration.

It also adds **encrypted site-to-site backup sharing**: two sites running this
plugin can hand a course backup to each other over an authenticated, end-to-end
encrypted channel — see [Encrypted backup sharing](#encrypted-backup-sharing-site-to-site).

It installs to `repository/largefile/` in a Moodle tree. The plugin was extracted
from [`tool_canvasuplifter`](https://github.com/verzog/moodle-tool_canvasuplifter)
and generalised; the chunked-upload logic ultimately derives from
`local_chunkupload` (2020 Justus Dieckmann WWU).

## How it works

Both paths produce a *staged file*: one `repository_largefile_chunks` row plus a
file under `$CFG->dataroot/repository_largefile/chunks/`. The file picker lists a
user's staged files and, when one is selected, the repository hands its bytes to
the draft area via `get_file()`. Delivery happens through the picker's *download*
action rather than a multipart upload, which is why neither PHP upload limit
applies.

The upload/URL dialogue is launched from the file picker's **"Upload a file"**
toolbar button: `get_listing()` advertises `uploadfile`/`uploadevent`, the bundled
`repository_largefile/upload` AMD module subscribes to that event, opens a
dialogue (a tab to upload from the computer in chunks, a tab to fetch from a URL),
and re-lists the repository when a file has been staged. This is the same
supported extension point core's Google Docs repository uses.

Stale staged files are removed by the `cleanup_chunks` scheduled task, whose
retention windows are configurable.

## Destination size limits (important)

This plugin bypasses the **transport** limit: a file is uploaded in chunks (each
chunk is a small request, so `post_max_size` never applies) or fetched
server-side from a URL. But the **destination form** still applies its own size
policy when the staged file is selected. Moodle's file picker enforces
`get_user_max_upload_file_size()` on every incoming file — including files a
repository produces — and that value is `min(upload_max_filesize, post_max_size,
site limit, course/activity limit)`. So a staged file larger than the PHP upload
limit is still rejected at selection with a "file too large" error **unless the
user may ignore file-size limits**.

The escape hatch is the core capability **`moodle/course:ignorefilesizelimits`**
(when the user has it, the picker skips the size check entirely):

- **Site administrators** have it implicitly, so an admin restoring a large
  course backup — the main use case — can select a staged file of any size.
- For **non-admins** (a manager or teacher restoring, or a user uploading in a
  normal file picker), grant `moodle/course:ignorefilesizelimits` in the
  relevant context to those who need to bring in files above the PHP limit.
  Without it, the destination limit still caps them.

This is a Moodle design constraint, not something a repository plugin can
override: both delivery paths in `repository/repository_ajax.php` (`get_file()`
and `copy_to_area()`) enforce the same limit. Raising `$CFG->maxbytes` does not
help on its own, because the effective limit is still clamped by the PHP ini
values. The genuinely unlimited path for privileged users is what this plugin
adds.

## Install

1. Copy the **contents of this repository** to `repository/largefile/` in your
   Moodle tree (so `repository/largefile/version.php` exists) — or install the
   ZIP via *Site administration > Plugins > Install plugins*.
2. Visit *Site administration > Notifications* to complete installation.
3. Enable it at *Site administration > Plugins > Repositories > Manage
   repositories* — set **Large file** to "Enabled and visible".
4. (Optional) Tune the chunk size and retention on the plugin's own
   configuration page at *Site administration > Plugins > Repositories > Large
   file*. That page is also where the backup-sharing management links live
   (trusted peers, backup shares, import) — repository plugins are not part of the
   admin settings tree, so everything for this plugin hangs off its configuration
   page rather than sitting as separate nodes.

Requires Moodle 5.0+ and PHP 8.2–8.4.

## Security — server-side URL fetch (SSRF)

The URL-import path fetches the given URL server-side through Moodle's `\curl`
wrapper, so it is subject to the site's **cURL security settings** at *Site
administration > Security > HTTP security*. On Moodle 5.0+ those ship secure by
default (loopback, private ranges, `localhost`, the cloud-metadata address and
non-80/443 ports are blocked), so a user-supplied URL cannot reach internal
services out of the box. Keep those defaults in place, and extend the blocklist
for your network if needed (for example IPv6 link-local/unique-local ranges).

## Encrypted backup sharing (site-to-site)

Two sites that both run this plugin can share a course backup securely, without a
shared filesystem or a third-party service. The transfer is authenticated and the
payload is encrypted end to end and at rest.

All three management pages are reached from the plugin's configuration page
(*Site administration > Plugins > Repositories > Large file*).

**Pairing.** On each site, open **Trusted peers** from that configuration page,
add the other site with its **Site URL** (e.g. `https://peer.example.org`), and
paste the same shared secret (≥24 characters) on both. The secret is stored
encrypted with the site key (`\core\encryption`), never in the clear.

The Site URL does double duty: a share link is accepted only if it is on the same
origin (scheme, host and port) as the peer it is imported from, and that one origin
is the sole target allowed past the cURL block below when importing that peer's
share. So a peer on a private or internal network (a common self-hosting case) can
be reached without loosening SSRF protection for anything else. Every other host —
and every other port or scheme on the same host, redirect targets included — stays
subject to the site policy, so no other service on the peer machine is reachable.

**Publishing (sender).** On the **Backup shares** page, upload a backup (the
chunked uploader in the picker handles files above the PHP limit), pick a peer,
and set an optional expiry and download cap. The plugin derives a per-share key
(HKDF-SHA256 over the pairing secret and a fresh random salt), encrypts the file
with libsodium's XChaCha20-Poly1305 *secretstream* (authenticated and
truncation-detecting), stores only the ciphertext, and gives you a share link to
hand to the peer.

Encrypting a multi-gigabyte backup takes longer than a web request may last, so
**Create in the background** is on by default: the backup is encrypted on the
server as a queued transfer (run by cron, so cron must be enabled) rather than in
the request — avoiding a gateway timeout (504) on a large file. The job is tracked
under *Backups being published* on this page, and its share link appears in the
shares list when it is ready. Untick it to create a small share inline and get the
link straight away.

**Importing (receiver).** On the **Import a shared backup** page, choose the peer and
paste the link. The plugin fetches the metadata and ciphertext through Moodle's
SSRF-aware `\curl` wrapper, decrypts with the key re-derived from the pairing
secret and the share's salt, and verifies the recovered plaintext against the
advertised SHA-256 before saving it to your **private backup area** — where it
appears under *Restore > User private backup area* on any course, ready to restore
in one click.

**Request authentication.** Every request to the share endpoint is signed with
HMAC-SHA256 over the canonicalised parameters and carries a timestamp (a ±5-minute
window) and a single-use nonce, so a captured link cannot be replayed. The
signature is checked with `hash_equals` before the nonce is claimed.

**Capabilities.** Sharing and importing are gated by
`repository/largefile:share` and `repository/largefile:import` (both at the system
context, with no archetypes) — grant them explicitly to the administrators who
should manage transfers. Expired or exhausted shares and stale nonces are removed
by the `cleanup_chunks` scheduled task.

## Accepted files and destinations

The plugin's configuration page (*Site administration > Plugins > Repositories >
Large file*) carries an **import policy**:

- **Restrict accepted file types** (opt-in). Off by default — every file type is
  accepted, as the plugin always did. Turn it on to accept only the ticked kinds:
  **course backups** (`.mbz`), **SCORM packages** (`.zip`), **IMS Common
  Cartridge** (`.imscc`) and **video**. The gate applies to every way in — the
  Import page, the URL-import queue, and the chunked large-file uploader (a
  rejected upload is refused at its first chunk, before any bytes are written).
- **Destinations.** An imported file can be routed to your **private backup area**
  (where it appears under *Restore > User private backup area*, ready to restore),
  the **large file picker** (to pick into an activity — a video as a resource, a
  SCORM package as a SCORM activity), or generic **private files**. Choose which
  destinations are offered site-wide; the Import form only shows those that also
  suit the file's kind, and a file whose kind has just one enabled destination is
  routed there automatically.

## Scheduled, unattended transfers

A **URL import** and a **peer-share import** both run entirely on the server, so
they can be **queued to run unattended** — right away, or **at a quiet time such
as overnight** — from the **Transfers** page (linked from the plugin's
configuration page at *Site administration > Plugins > Repositories > Large
file*). Queue one and close the browser: the `process_transfers` scheduled task
picks it up (a scheduled transfer waits for its chosen time), fetches it, and — for
a URL import — stages the file into your large-file picker, or — for a peer-share
import — decrypts, verifies and saves it to your private backup area, ready to
restore from any course under *Restore > User private backup area*.

The **Transfers** page is also a **site-wide monitor**: it lists every chunked
upload currently streaming in and every queued, running or finished transfer,
with their owner and status, and lets an administrator cancel a queued transfer
or clear a finished one. Finished transfers are pruned after a week by the cleanup
task.

**Why local uploads can't be unattended.** A file on your own computer can only be
read by the page that's open, so the chunked uploader now *warns you before you
navigate away* from an upload still in progress — but it cannot keep uploading a
local file once the page is gone (a browser limitation that no server can work
around; Background Fetch does not fit the resumable multi-request chunk protocol).
When you need "start it and walk away", upload from a URL or a peer share and use a
scheduled transfer, which runs on the server with no page open.

## Settings

| Setting | Default | Purpose |
|---|---|---|
| Chunk size (MB) | 20 | Bytes per chunk. Lower it if large uploads fail behind a proxy/WAF that rejects big request bodies. |
| Keep unused upload tokens for | 1 hour | Retention for a token that was created but never used. |
| Keep unfinished uploads for | 1 hour | Retention for a partially uploaded file. |
| Keep completed uploads for | 1 day | Retention for a completed upload that was never selected. |

## Developing / validating

CI (`.github/workflows/moodle-ci.yml`) runs `moodle-plugin-ci` against a real
Moodle across PHP 8.2–8.4 × Moodle 5.0–5.2 × pgsql/mariadb/mysqli, with the same
blocking checks Moodle plugins use: `phplint`, `phpcs`, `phpdoc`, `validate`,
`savepoints`, `mustache` and `phpunit`. To reproduce locally, install
`moodle-plugin-ci` and run those steps against a checkout of this repository.

After editing `amd/src/upload.js`, rebuild `amd/build/` with `grunt amd`.

## Licence

GPL-3.0-or-later. The chunked-upload logic derives from `local_chunkupload`
(2020 Justus Dieckmann WWU).
