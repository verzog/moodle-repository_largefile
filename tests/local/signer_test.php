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
 * Tests for HMAC request signing and replay protection.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \repository_largefile\local\signer
 */
final class signer_test extends \advanced_testcase {
    /**
     * A freshly signed request verifies.
     *
     * @return void
     */
    public function test_sign_then_verify(): void {
        $this->resetAfterTest();
        $secret = crypto::generate_secret();
        $signed = signer::sign(['token' => 'abc', 'action' => 'download'], $secret);
        $this->assertArrayHasKey('sig', $signed);
        $this->assertNull(signer::verify($signed, $secret));
    }

    /**
     * A wrong secret is rejected.
     *
     * @return void
     */
    public function test_wrong_secret_rejected(): void {
        $this->resetAfterTest();
        $signed = signer::sign(['token' => 'abc'], crypto::generate_secret());
        $this->assertSame('errorsharesig', signer::verify($signed, crypto::generate_secret()));
    }

    /**
     * Tampering with a parameter invalidates the signature.
     *
     * @return void
     */
    public function test_tampered_param_rejected(): void {
        $this->resetAfterTest();
        $secret = crypto::generate_secret();
        $signed = signer::sign(['token' => 'abc', 'action' => 'meta'], $secret);
        $signed['action'] = 'download';
        $this->assertSame('errorsharesig', signer::verify($signed, $secret));
    }

    /**
     * A request outside the timestamp window is rejected as stale.
     *
     * @return void
     */
    public function test_stale_request_rejected(): void {
        $this->resetAfterTest();
        $secret = crypto::generate_secret();
        $signed = signer::sign(['token' => 'abc'], $secret);
        // Re-sign with an old timestamp: recompute the signature over the old ts.
        $signed['ts'] = (string) (time() - 1000);
        $reflection = new \ReflectionMethod(signer::class, 'compute');
        $reflection->setAccessible(true);
        $signed['sig'] = $reflection->invoke(null, $signed, $secret);
        $this->assertSame('errorsharestale', signer::verify($signed, $secret));
    }

    /**
     * The same signed request cannot be verified twice (nonce replay).
     *
     * @return void
     */
    public function test_replay_rejected(): void {
        $this->resetAfterTest();
        $secret = crypto::generate_secret();
        $signed = signer::sign(['token' => 'abc'], $secret);
        $this->assertNull(signer::verify($signed, $secret));
        $this->assertSame('errorsharereplay', signer::verify($signed, $secret));
    }

    /**
     * A stale request with a bad signature is reported as a bad signature, never as
     * stale: otherwise an unauthenticated caller could tell a real share token apart
     * from a guessed one by sending an old timestamp and reading which error came back.
     *
     * @return void
     */
    public function test_bad_signature_is_reported_before_staleness(): void {
        $this->resetAfterTest();
        $signed = signer::sign(['token' => 'abc'], crypto::generate_secret());
        $signed['ts'] = (string) (time() - 1000);
        $this->assertSame('errorsharesig', signer::verify($signed, crypto::generate_secret()));
    }

    /**
     * A nonce is kept for at least twice the freshness window: a request stamped at
     * the future edge of the window is still fresh two windows after it was received,
     * so purging its nonce any sooner would reopen it to replay.
     *
     * @return void
     */
    public function test_nonce_retention_outlives_every_fresh_timestamp(): void {
        $window = (new \ReflectionClassConstant(signer::class, 'TIME_WINDOW'))->getValue();
        $this->assertGreaterThanOrEqual(2 * $window, signer::nonce_retention());
    }
}
