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
 * Tests for the receiver's peer-host security gate.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \repository_largefile\local\share_client
 */
final class share_client_test extends \advanced_testcase {
    /**
     * Invoke the private peer_security() gate for a peer and share base URL.
     *
     * @param int $peerid The peer id.
     * @param string $base The share endpoint base URL.
     * @return object|null The security helper the gate returns.
     */
    private function invoke_peer_security(int $peerid, string $base): ?object {
        $method = new \ReflectionMethod(share_client::class, 'peer_security');
        $method->setAccessible(true);
        return $method->invoke(null, $peerid, $base);
    }

    /**
     * A share on the peer's registered host yields a scoped security helper.
     *
     * @return void
     */
    public function test_matching_host_returns_scoped_helper(): void {
        $this->resetAfterTest();
        $id = peer_manager::create('Partner', str_repeat('s', 24), 'https://peer.example.org');

        $helper = $this->invoke_peer_security($id, 'https://peer.example.org/repository/largefile/share.php');

        $this->assertInstanceOf(peer_curl_security::class, $helper);
    }

    /**
     * A share whose host differs from the peer's registered host is rejected.
     *
     * @return void
     */
    public function test_mismatched_host_is_rejected(): void {
        $this->resetAfterTest();
        $id = peer_manager::create('Partner', str_repeat('s', 24), 'https://peer.example.org');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/registered site URL/');
        $this->invoke_peer_security($id, 'https://evil.example.org/repository/largefile/share.php');
    }

    /**
     * A share on the right host but a different port than registered is rejected.
     *
     * @return void
     */
    public function test_mismatched_port_is_rejected(): void {
        $this->resetAfterTest();
        $id = peer_manager::create('Partner', str_repeat('s', 24), 'https://peer.example.org:8443');

        $this->expectException(\moodle_exception::class);
        $this->invoke_peer_security($id, 'http://peer.example.org:2375/repository/largefile/share.php');
    }

    /**
     * A legacy peer with no registered site URL defers to the site default (null).
     *
     * @return void
     */
    public function test_legacy_peer_defers_to_site_default(): void {
        $this->resetAfterTest();
        $id = peer_manager::create('Legacy', str_repeat('s', 24));

        $this->assertNull($this->invoke_peer_security($id, 'https://anywhere.example.org/share.php'));
    }

    /**
     * The protocol marker is recognised case-insensitively and its absence marks an
     * endpoint that predates header authentication.
     *
     * @return void
     */
    public function test_has_protocol_marker(): void {
        $this->assertTrue(share_client::has_protocol_marker(['X-Largefile-Protocol' => '2']));
        $this->assertTrue(share_client::has_protocol_marker(['x-largefile-protocol' => '2', 'Content-Type' => 'a']));
        $this->assertFalse(share_client::has_protocol_marker(['Content-Type' => 'text/html']));
        $this->assertFalse(share_client::has_protocol_marker([]));
    }

    /**
     * The header form keeps the credential out of the URL, the legacy form puts it in
     * the query string; both carry the same signed parameters.
     *
     * @return void
     */
    public function test_signed_request_forms(): void {
        $this->resetAfterTest();
        $secret = crypto::generate_secret();
        $params = ['token' => 'abc', 'action' => 'download'];

        [$url, $headers] = share_client::signed_request('https://peer.example.org/share.php', $params, $secret, false);
        $this->assertSame('https://peer.example.org/share.php?token=abc&action=download', $url);
        $this->assertCount(1, $headers);
        $this->assertStringStartsWith(signer::AUTH_HEADER . ': ts=', $headers[0]);

        [$legacyurl, $legacyheaders] = share_client::signed_request('https://peer.example.org/share.php', $params, $secret, true);
        $this->assertSame([], $legacyheaders);
        parse_str((string) parse_url($legacyurl, PHP_URL_QUERY), $query);
        $this->assertSame('abc', $query['token']);
        $this->assertArrayHasKey('sig', $query);
        $this->assertNull(signer::verify($query, $secret));
    }

    /**
     * Share metadata from a peer is accepted only when its salt and SHA-256 are hex of
     * the agreed lengths and its file name is usable, so a malformed or hostile reply
     * fails cleanly instead of reaching key derivation.
     *
     * @dataProvider meta_provider
     * @param array $meta The decoded metadata.
     * @param bool $expected Whether it should be accepted.
     * @return void
     */
    public function test_meta_is_wellformed(array $meta, bool $expected): void {
        $this->assertSame($expected, share_client::meta_is_wellformed($meta));
    }

    /**
     * Cases for test_meta_is_wellformed.
     *
     * @return array
     */
    public static function meta_provider(): array {
        $good = ['filename' => 'backup.mbz', 'salt' => str_repeat('ab', 16), 'sha256' => str_repeat('c', 64)];
        return [
            'well-formed' => [$good, true],
            'upper-case hex' => [array_merge($good, ['salt' => str_repeat('AB', 16)]), true],
            'missing salt' => [array_diff_key($good, ['salt' => 1]), false],
            'salt not hex' => [array_merge($good, ['salt' => str_repeat('zz', 16)]), false],
            'salt wrong length' => [array_merge($good, ['salt' => 'abcd']), false],
            'sha256 wrong length' => [array_merge($good, ['sha256' => 'abcd']), false],
            'sha256 not a string' => [array_merge($good, ['sha256' => 12345]), false],
            'empty filename' => [array_merge($good, ['filename' => '']), false],
            'filename is only path separators' => [array_merge($good, ['filename' => '../']), false],
        ];
    }
}
