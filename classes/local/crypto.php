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
 * Authenticated encryption of shared files, at rest.
 *
 * A share's file is encrypted with a key derived (HKDF-SHA256) from the peer
 * pairing secret plus a per-share random salt, so a leaked ciphertext or share
 * URL is useless without the pairing secret. Encryption uses libsodium's
 * secretstream (XChaCha20-Poly1305) in fixed-size chunks, streamed file-to-file,
 * so a multi-gigabyte backup is never held in memory and truncation is detected
 * (the last chunk carries the FINAL tag).
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * Authenticated encryption of shared files, at rest.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class crypto {
    /** @var string HKDF context string, versioned so the scheme can evolve. */
    private const HKDF_INFO = 'repository_largefile:share:v1';

    /** @var int Plaintext bytes per secretstream chunk (1 MiB). */
    private const CHUNK = 1048576;

    /**
     * Number of random bytes in a per-share salt.
     *
     * @return int
     */
    public static function salt_bytes(): int {
        return 16;
    }

    /**
     * Generate a fresh, high-entropy pairing secret (hex-encoded 32 bytes).
     *
     * @return string A 64-character hex string.
     */
    public static function generate_secret(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * Derive the 32-byte content key for a share from the pairing secret and salt.
     *
     * @param string $secret The peer pairing secret (shared out of band).
     * @param string $salt The per-share random salt (raw bytes).
     * @return string 32 raw key bytes.
     */
    public static function derive_key(string $secret, string $salt): string {
        return hash_hkdf('sha256', $secret, 32, self::HKDF_INFO, $salt);
    }

    /**
     * Encrypt a file to another path, returning the SHA-256 of the plaintext.
     *
     * @param string $srcpath Absolute path of the plaintext file.
     * @param string $destpath Absolute path to write the ciphertext to.
     * @param string $key 32-byte content key (see {@see derive_key()}).
     * @return string Lowercase hex SHA-256 of the plaintext.
     * @throws \moodle_exception If the files cannot be opened.
     */
    public static function encrypt_file(string $srcpath, string $destpath, string $key): string {
        $in = fopen($srcpath, 'rb');
        $out = fopen($destpath, 'wb');
        if ($in === false || $out === false) {
            if ($in !== false) {
                fclose($in);
            }
            if ($out !== false) {
                fclose($out);
            }
            throw new \moodle_exception('errorshareencrypt', 'repository_largefile');
        }
        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            fwrite($out, $header);
            $hash = hash_init('sha256');
            $final = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;

            // Read-ahead by one chunk so the last chunk can be tagged FINAL, which
            // lets decryption detect a truncated ciphertext.
            $chunk = self::read_full($in, self::CHUNK);
            do {
                $next = self::read_full($in, self::CHUNK);
                $islast = ($next === '');
                hash_update($hash, $chunk);
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $chunk,
                    '',
                    $islast ? $final : 0
                );
                fwrite($out, $cipher);
                $chunk = $next;
            } while (!$islast);
        } finally {
            fclose($in);
            fclose($out);
        }
        return hash_final($hash);
    }

    /**
     * Decrypt a file to another path, returning the SHA-256 of the recovered
     * plaintext. Throws if any chunk fails authentication or the ciphertext was
     * truncated (no FINAL tag seen).
     *
     * @param string $srcpath Absolute path of the ciphertext file.
     * @param string $destpath Absolute path to write the plaintext to.
     * @param string $key 32-byte content key (see {@see derive_key()}).
     * @return string Lowercase hex SHA-256 of the recovered plaintext.
     * @throws \moodle_exception If the files cannot be opened or decryption fails.
     */
    public static function decrypt_file(string $srcpath, string $destpath, string $key): string {
        $headerbytes = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        $cipherchunk = self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        $final = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;

        $in = fopen($srcpath, 'rb');
        $out = fopen($destpath, 'wb');
        if ($in === false || $out === false) {
            if ($in !== false) {
                fclose($in);
            }
            if ($out !== false) {
                fclose($out);
            }
            throw new \moodle_exception('errorsharedecrypt', 'repository_largefile');
        }
        try {
            $header = self::read_full($in, $headerbytes);
            if (strlen($header) !== $headerbytes) {
                throw new \moodle_exception('errorsharedecrypt', 'repository_largefile');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $hash = hash_init('sha256');
            $sawfinal = false;
            while (($cipher = self::read_full($in, $cipherchunk)) !== '') {
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($result === false) {
                    throw new \moodle_exception('errorsharedecrypt', 'repository_largefile');
                }
                [$plain, $tag] = $result;
                hash_update($hash, $plain);
                fwrite($out, $plain);
                if ($tag === $final) {
                    $sawfinal = true;
                    break;
                }
                if ($tag !== 0) {
                    throw new \moodle_exception('errorsharedecrypt', 'repository_largefile');
                }
            }
            if (!$sawfinal) {
                throw new \moodle_exception('errorsharedecrypt', 'repository_largefile');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
        return hash_final($hash);
    }

    /**
     * Read exactly $length bytes from a handle, or fewer only at end of file.
     *
     * A single fread() may return a short read before EOF; this loops so a full
     * chunk is assembled and a short return reliably signals the final chunk.
     *
     * @param resource $handle An open, readable file handle.
     * @param int $length Number of bytes to read.
     * @return string The bytes read (shorter than $length only at EOF).
     */
    private static function read_full($handle, int $length): string {
        $buffer = '';
        while (strlen($buffer) < $length && !feof($handle)) {
            $part = fread($handle, $length - strlen($buffer));
            if ($part === false || $part === '') {
                break;
            }
            $buffer .= $part;
        }
        return $buffer;
    }
}
