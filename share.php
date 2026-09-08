<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Download endpoint for a published backup share.
 *
 * This is a server-to-server endpoint: the receiving peer authenticates by
 * signing the request with the pairing secret (no Moodle session). The request
 * is rejected unless the HMAC signature verifies against the share's peer, the
 * timestamp is fresh and the nonce is unused. `action=meta` returns the metadata
 * needed to derive the key and verify integrity; `action=download` streams the
 * encrypted file.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../config.php');

use repository_largefile\local\peer_manager;
use repository_largefile\local\share_manager;
use repository_largefile\local\signer;

$token = optional_param('token', '', PARAM_ALPHANUM);
$action = required_param('action', PARAM_ALPHA);

// Every response — success or rejection — carries the protocol marker, so a
// receiving site can tell this release (which reads the auth header) apart from an
// older one whose error page lacks it, and only fall back to query-string signing
// for the latter.
header('X-Largefile-Protocol: 2');

// Send a status with a short message and stop. Kept deliberately vague so the
// endpoint cannot be used to probe for valid tokens.
$reject = function (int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    die;
};

// The timestamp, nonce and signature arrive in the X-Largefile-Auth header (so
// they are kept out of access logs), or — from a peer running an older release —
// in the query string. Either way they are verified identically.
$authheader = trim((string) ($_SERVER['HTTP_X_LARGEFILE_AUTH'] ?? ''));
if ($authheader !== '') {
    $auth = signer::parse_auth_header($authheader);
    if ($auth === null) {
        $reject(403, get_string('errorsharesig', 'repository_largefile'));
    }
    $ts = (int) $auth['ts'];
    $nonce = clean_param($auth['nonce'], PARAM_ALPHANUM);
    $sig = clean_param($auth['sig'], PARAM_ALPHANUM);
} else {
    $ts = optional_param('ts', 0, PARAM_INT);
    $nonce = optional_param('nonce', '', PARAM_ALPHANUM);
    $sig = optional_param('sig', '', PARAM_ALPHANUM);
}

// A connection check from a paired site: signed with the pairing secret but tied
// to no share, so the caller is identified by which peer's secret verifies. It
// confirms reachability, TLS, the SSRF exemption, the shared secret and the clocks
// in one round trip, and tells the caller how this site knows it.
if ($action === 'ping') {
    $params = ['action' => 'ping', 'ts' => (string) $ts, 'nonce' => $nonce, 'sig' => $sig];
    $match = peer_manager::find_by_signature($params);
    if ($match['peer'] === null) {
        $reject(403, get_string($match['error'], 'repository_largefile'));
    }
    $info = core_plugin_manager::instance()->get_plugin_info('repository_largefile');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true, 'peer' => $match['peer']->name, 'release' => (string) ($info->release ?? '')]);
    die;
}

if ($token === '') {
    $reject(403, get_string('errorsharesig', 'repository_largefile'));
}
$share = share_manager::get_by_token($token);
$secret = $share ? peer_manager::get_secret((int) $share->peerid) : null;
if (!$share || $secret === null) {
    // Do not distinguish "no such share" from "bad signature".
    $reject(403, get_string('errorsharesig', 'repository_largefile'));
}

$params = ['token' => $token, 'action' => $action, 'ts' => (string) $ts, 'nonce' => $nonce, 'sig' => $sig];
$sigerror = signer::verify($params, $secret);
if ($sigerror !== null) {
    $reject(403, get_string($sigerror, 'repository_largefile'));
}

if (!share_manager::is_valid($share)) {
    $reject(410, get_string('errorshareexpired', 'repository_largefile'));
}

// Streaming a multi-gigabyte encrypted file may take longer than the web server's
// max_execution_time; a peer download must not be killed mid-stream by PHP's own
// clock. The session lock is dropped on the download branch below so a long
// stream also does not block the caller's other requests.
\core_php_time_limit::raise();

if ($action === 'meta') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'filename' => $share->filename,
        'filesize' => (int) $share->filesize,
        'sha256' => $share->sha256,
        'salt' => $share->salt,
    ]);
    die;
}

if ($action === 'download') {
    $file = share_manager::get_encrypted_file($share);
    if (!$file) {
        $reject(404, get_string('errorsharenofile', 'repository_largefile'));
    }
    // Count the download before streaming so a flaky retry cannot exceed the cap,
    // and claim it under a row lock so two simultaneous requests cannot both take
    // the last download of a capped share.
    if (!share_manager::claim_download((int) $share->id)) {
        $reject(410, get_string('errorshareexpired', 'repository_largefile'));
    }
    \repository_largefile\event\share_downloaded::for_share($share)->trigger();

    \core\session\manager::write_close();
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . $file->get_filesize());
    header('Content-Disposition: attachment; filename="' . $token . '.enc"');
    header('Cache-Control: no-store');
    $file->readfile();
    die;
}

$reject(400, get_string('errorsharesig', 'repository_largefile'));
