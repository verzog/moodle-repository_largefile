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

namespace repository_largefile\local;

/**
 * Tests for at-rest share encryption.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \repository_largefile\local\crypto
 */
final class crypto_test extends \advanced_testcase {
    /**
     * A file survives an encrypt/decrypt round-trip across several chunks, and
     * the reported plaintext hash matches on both sides.
     *
     * @return void
     */
    public function test_round_trip_multichunk(): void {
        $dir = make_request_directory();
        $plain = $dir . '/plain.bin';
        $cipher = $dir . '/cipher.bin';
        $out = $dir . '/out.bin';
        // 2.5 MiB spans three 1 MiB chunks (the last one partial).
        $data = random_bytes(2621440);
        file_put_contents($plain, $data);

        $secret = crypto::generate_secret();
        $salt = random_bytes(crypto::salt_bytes());
        $key = crypto::derive_key($secret, $salt);

        $enchash = crypto::encrypt_file($plain, $cipher, $key);
        $this->assertSame(hash('sha256', $data), $enchash);
        $this->assertNotSame($data, file_get_contents($cipher), 'ciphertext must differ from plaintext');

        $dechash = crypto::decrypt_file($cipher, $out, $key);
        $this->assertSame($enchash, $dechash);
        $this->assertStringEqualsFile($out, $data);
    }

    /**
     * A file smaller than one chunk round-trips too.
     *
     * @return void
     */
    public function test_round_trip_small(): void {
        $dir = make_request_directory();
        $data = random_bytes(1000);
        file_put_contents($dir . '/p', $data);
        $key = crypto::derive_key(crypto::generate_secret(), random_bytes(crypto::salt_bytes()));
        crypto::encrypt_file($dir . '/p', $dir . '/c', $key);
        crypto::decrypt_file($dir . '/c', $dir . '/o', $key);
        $this->assertStringEqualsFile($dir . '/o', $data);
    }

    /**
     * Decrypting with the wrong key (a different pairing secret) fails.
     *
     * @return void
     */
    public function test_wrong_key_fails(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/p', random_bytes(2000));
        $salt = random_bytes(crypto::salt_bytes());
        crypto::encrypt_file($dir . '/p', $dir . '/c', crypto::derive_key(crypto::generate_secret(), $salt));

        $this->expectException(\moodle_exception::class);
        crypto::decrypt_file($dir . '/c', $dir . '/o', crypto::derive_key(crypto::generate_secret(), $salt));
    }

    /**
     * A tampered ciphertext is rejected by authentication.
     *
     * @return void
     */
    public function test_tampered_ciphertext_fails(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/p', random_bytes(2000));
        $key = crypto::derive_key(crypto::generate_secret(), random_bytes(crypto::salt_bytes()));
        crypto::encrypt_file($dir . '/p', $dir . '/c', $key);

        $bytes = file_get_contents($dir . '/c');
        $bytes[strlen($bytes) - 1] = $bytes[strlen($bytes) - 1] === 'A' ? 'B' : 'A';
        file_put_contents($dir . '/c', $bytes);

        $this->expectException(\moodle_exception::class);
        crypto::decrypt_file($dir . '/c', $dir . '/o', $key);
    }

    /**
     * A truncated ciphertext (missing its FINAL chunk) is rejected.
     *
     * @return void
     */
    public function test_truncated_ciphertext_fails(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/p', random_bytes(2000));
        $key = crypto::derive_key(crypto::generate_secret(), random_bytes(crypto::salt_bytes()));
        crypto::encrypt_file($dir . '/p', $dir . '/c', $key);

        // Drop the trailing bytes so the FINAL-tagged chunk is lost.
        $bytes = file_get_contents($dir . '/c');
        file_put_contents($dir . '/c', substr($bytes, 0, 30));

        $this->expectException(\moodle_exception::class);
        crypto::decrypt_file($dir . '/c', $dir . '/o', $key);
    }
}
