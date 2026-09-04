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
 * Publish, look up and revoke encrypted backup shares.
 *
 * A share's file is encrypted at rest (see {@see crypto}) with a key derived
 * from the target peer's pairing secret plus a per-share salt, and stored in the
 * file API. The plaintext SHA-256 and salt are recorded so the receiving site
 * can derive the same key and verify integrity after decryption.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * Publish, look up and revoke encrypted backup shares.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class share_manager {
    /** @var string The shares table. */
    private const TABLE = 'repository_largefile_shares';

    /** @var string File-API component/area holding the encrypted share files. */
    private const FILEAREA = 'share';

    /**
     * Publish a file (by path) as an encrypted share for a peer.
     *
     * @param int $peerid The target peer.
     * @param string $srcpath Absolute path of the plaintext file to share.
     * @param string $filename The original file name.
     * @param int $expires Unix expiry time, or 0 for never.
     * @param int $maxdownloads Maximum successful downloads, or 0 for unlimited.
     * @param int $userid The user creating the share.
     * @return \stdClass The stored share row (including its token).
     * @throws \moodle_exception If the peer is unknown.
     */
    public static function create(
        int $peerid,
        string $srcpath,
        string $filename,
        int $expires,
        int $maxdownloads,
        int $userid
    ): \stdClass {
        return self::store_encrypted(
            $peerid,
            $filename,
            (int) (@filesize($srcpath) ?: 0),
            $expires,
            $maxdownloads,
            $userid,
            fn(string $dest, string $key) => crypto::encrypt_file($srcpath, $dest, $key)
        );
    }

    /**
     * Publish a stored file as an encrypted share, streaming straight from it.
     *
     * Encrypting directly from the stored file's content handle avoids first copying
     * a multi-gigabyte plaintext to a temporary file — one fewer full pass over the
     * data — which matters for the background publish of a large backup.
     *
     * @param int $peerid The target peer.
     * @param \stored_file $file The plaintext stored file to share.
     * @param int $expires Unix expiry time, or 0 for never.
     * @param int $maxdownloads Maximum successful downloads, or 0 for unlimited.
     * @param int $userid The user creating the share.
     * @param callable|null $onprogress Optional callback invoked as ($bytesdone, $bytestotal).
     * @return \stdClass The stored share row (including its token).
     * @throws \moodle_exception If the peer is unknown.
     */
    public static function create_from_storedfile(
        int $peerid,
        \stored_file $file,
        int $expires,
        int $maxdownloads,
        int $userid,
        ?callable $onprogress = null
    ): \stdClass {
        return self::store_encrypted(
            $peerid,
            $file->get_filename(),
            (int) $file->get_filesize(),
            $expires,
            $maxdownloads,
            $userid,
            function (string $dest, string $key) use ($file, $onprogress) {
                $in = $file->get_content_file_handle();
                try {
                    return crypto::encrypt_stream($in, (int) $file->get_filesize(), $dest, $key, $onprogress);
                } finally {
                    if (is_resource($in)) {
                        fclose($in);
                    }
                }
            }
        );
    }

    /**
     * Encrypt a plaintext (via the given encryptor), store the ciphertext and record
     * the share.
     *
     * @param int $peerid The target peer.
     * @param string $filename The original file name.
     * @param int $filesize The plaintext size in bytes.
     * @param int $expires Unix expiry time, or 0 for never.
     * @param int $maxdownloads Maximum successful downloads, or 0 for unlimited.
     * @param int $userid The user creating the share.
     * @param callable $encryptto Encryptor called as ($destpath, $key) returning the plaintext SHA-256.
     * @return \stdClass The stored share row (including its token).
     * @throws \moodle_exception If the peer is unknown.
     */
    private static function store_encrypted(
        int $peerid,
        string $filename,
        int $filesize,
        int $expires,
        int $maxdownloads,
        int $userid,
        callable $encryptto
    ): \stdClass {
        global $DB;

        $secret = peer_manager::get_secret($peerid);
        if ($secret === null) {
            throw new \moodle_exception('errorsharenopeer', 'repository_largefile');
        }

        $saltraw = random_bytes(crypto::salt_bytes());
        $key = crypto::derive_key($secret, $saltraw);
        $encrypted = make_request_directory() . '/share.enc';
        $sha256 = $encryptto($encrypted, $key);

        $record = (object) [
            'token' => bin2hex(random_bytes(20)),
            'peerid' => $peerid,
            'filename' => $filename,
            'filesize' => $filesize,
            'sha256' => $sha256,
            'salt' => bin2hex($saltraw),
            'expires' => $expires,
            'maxdownloads' => $maxdownloads,
            'downloadcount' => 0,
            'userid' => $userid,
            'timecreated' => time(),
        ];
        $record->id = (int) $DB->insert_record(self::TABLE, $record);

        $filerecord = [
            'contextid' => \context_system::instance()->id,
            'component' => 'repository_largefile',
            'filearea' => self::FILEAREA,
            'itemid' => $record->id,
            'filepath' => '/',
            'filename' => $record->token . '.enc',
        ];
        get_file_storage()->create_file_from_pathname($filerecord, $encrypted);

        return $record;
    }

    /**
     * Find a share by its public token.
     *
     * @param string $token The share token.
     * @return \stdClass|null The share row, or null if not found.
     */
    public static function get_by_token(string $token): ?\stdClass {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['token' => $token], '*', IGNORE_MISSING);
        return $record ?: null;
    }

    /**
     * Whether a share may still be downloaded (not expired, under its cap).
     *
     * @param \stdClass $share The share row.
     * @return bool
     */
    public static function is_valid(\stdClass $share): bool {
        if ((int) $share->expires !== 0 && time() >= (int) $share->expires) {
            return false;
        }
        if ((int) $share->maxdownloads !== 0 && (int) $share->downloadcount >= (int) $share->maxdownloads) {
            return false;
        }
        return true;
    }

    /**
     * The stored encrypted file for a share.
     *
     * @param \stdClass $share The share row.
     * @return \stored_file|null The encrypted file, or null if missing.
     */
    public static function get_encrypted_file(\stdClass $share): ?\stored_file {
        $file = get_file_storage()->get_file(
            \context_system::instance()->id,
            'repository_largefile',
            self::FILEAREA,
            (int) $share->id,
            '/',
            $share->token . '.enc'
        );
        return $file ?: null;
    }

    /**
     * Atomically record a successful download.
     *
     * @param \stdClass $share The share row.
     * @return void
     */
    public static function record_download(\stdClass $share): void {
        global $DB;
        $DB->execute(
            'UPDATE {' . self::TABLE . '} SET downloadcount = downloadcount + 1 WHERE id = ?',
            [(int) $share->id]
        );
    }

    /**
     * Delete a share and its encrypted file.
     *
     * @param int $id The share id.
     * @return void
     */
    public static function delete(int $id): void {
        global $DB;
        $fs = get_file_storage();
        $fs->delete_area_files(\context_system::instance()->id, 'repository_largefile', self::FILEAREA, $id);
        $DB->delete_records(self::TABLE, ['id' => $id]);
    }

    /**
     * All shares with their peer name, newest first, for the management screen.
     *
     * @return array Share rows, each with an added 'peername'.
     */
    public static function list_all(): array {
        global $DB;
        $sql = 'SELECT s.*, p.name AS peername
                  FROM {' . self::TABLE . '} s
             LEFT JOIN {repository_largefile_peers} p ON p.id = s.peerid
              ORDER BY s.timecreated DESC';
        return $DB->get_records_sql($sql);
    }
}
