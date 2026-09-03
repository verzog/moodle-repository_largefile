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
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../config.php');

use repository_largefile\local\peer_manager;
use repository_largefile\local\share_manager;
use repository_largefile\local\signer;

$token = required_param('token', PARAM_ALPHANUM);
$action = required_param('action', PARAM_ALPHA);
$ts = required_param('ts', PARAM_INT);
$nonce = required_param('nonce', PARAM_ALPHANUM);
$sig = required_param('sig', PARAM_ALPHANUM);

// Send a status with a short message and stop. Kept deliberately vague so the
// endpoint cannot be used to probe for valid tokens.
$reject = function (int $status, string $message): void {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $message;
    die;
};

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
    // Count the download before streaming so a flaky retry cannot exceed the cap.
    share_manager::record_download($share);
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
