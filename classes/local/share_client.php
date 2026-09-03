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
 * Receiver side of backup sharing: fetch, decrypt and verify a peer's share.
 *
 * Given a share URL and the peer it came from, this signs each request with the
 * pairing secret ({@see signer}), fetches the metadata and then the encrypted
 * file through Moodle's SSRF-aware curl wrapper ({@see url_fetcher}), decrypts it
 * with the key derived from the pairing secret and the share's salt, and checks
 * the recovered plaintext against the advertised SHA-256 before returning it.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * Receiver side of backup sharing: fetch, decrypt and verify a peer's share.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class share_client {
    /** @var string Browser-like User-Agent, matching url_fetcher. */
    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0';

    /**
     * Fetch, decrypt and verify a shared backup from a peer.
     *
     * @param int $peerid The peer the share came from (whose secret unlocks it).
     * @param string $shareurl The share URL provided by the sending site.
     * @return array Keys 'path' (absolute plaintext temp path) and 'filename'.
     * @throws \moodle_exception On any transport, authentication or integrity failure.
     */
    public static function import(int $peerid, string $shareurl): array {
        $secret = peer_manager::get_secret($peerid);
        if ($secret === null) {
            throw new \moodle_exception('errorsharenopeer', 'repository_largefile');
        }
        [$base, $token] = self::split_url($shareurl);
        $security = self::peer_security($peerid, $base);

        $meta = self::fetch_meta($base, $token, $secret, $security);
        if (!isset($meta['sha256'], $meta['salt'], $meta['filename'])) {
            throw new \moodle_exception('errorsharenofile', 'repository_largefile');
        }

        $downloadurl = $base . '?' . http_build_query(
            signer::sign(['token' => $token, 'action' => 'download'], $secret)
        );
        $fetcher = new url_fetcher();
        $sitemax = (int) ($GLOBALS['CFG']->maxbytes ?? 0);
        $fetched = $fetcher->fetch($downloadurl, $sitemax, null, $security);

        $key = crypto::derive_key($secret, hex2bin($meta['salt']));
        $plainpath = make_request_directory() . '/' . clean_param($meta['filename'], PARAM_FILE);
        $actual = crypto::decrypt_file($fetched['path'], $plainpath, $key);
        if (!hash_equals((string) $meta['sha256'], $actual)) {
            @unlink($plainpath);
            throw new \moodle_exception('errorshareintegrity', 'repository_largefile');
        }

        return ['path' => $plainpath, 'filename' => clean_param($meta['filename'], PARAM_FILE)];
    }

    /**
     * Build the cURL security helper for a peer fetch.
     *
     * The share endpoint must live on the peer's registered site host, so it is
     * checked against that host first. When a peer has a registered site URL, the
     * returned helper exempts only that one host from the site's cURL block (so a
     * peer on a private-range address is reachable while every other host, redirect
     * targets included, stays blocked). A legacy peer with no registered URL gets
     * the site's default policy (null), so a public peer keeps working and a peer
     * behind the block must be given its site URL to become reachable.
     *
     * @param int $peerid The peer the share came from.
     * @param string $base The share endpoint base URL (scheme://host[:port]/path).
     * @return \core\files\curl_security_helper_base|null The scoped helper, or null for the site default.
     * @throws \moodle_exception If the share host does not match the peer's registered site host.
     */
    private static function peer_security(int $peerid, string $base): ?\core\files\curl_security_helper_base {
        $peerhost = peer_manager::get_host($peerid);
        if ($peerhost === null) {
            return null;
        }
        $host = \core_text::strtolower((string) parse_url($base, PHP_URL_HOST));
        if ($host === '' || $host !== $peerhost) {
            throw new \moodle_exception('errorsharehostmismatch', 'repository_largefile');
        }
        return new peer_curl_security($peerhost);
    }

    /**
     * Split a share URL into its base (no query) and token.
     *
     * @param string $shareurl The full share URL.
     * @return array [string $base, string $token].
     * @throws \moodle_exception If the URL is not a valid http(s) share link.
     */
    private static function split_url(string $shareurl): array {
        $shareurl = trim($shareurl);
        if (!url_fetcher::is_fetchable_url($shareurl)) {
            throw new \moodle_exception('errorshareinvalidurl', 'repository_largefile');
        }
        $parts = parse_url($shareurl);
        parse_str($parts['query'] ?? '', $query);
        $token = clean_param($query['token'] ?? '', PARAM_ALPHANUM);
        if ($token === '') {
            throw new \moodle_exception('errorshareinvalidurl', 'repository_largefile');
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $base = $parts['scheme'] . '://' . $parts['host'] . $port . ($parts['path'] ?? '');
        return [$base, $token];
    }

    /**
     * Fetch and decode the share metadata (a signed GET returning JSON).
     *
     * @param string $base The share endpoint base URL.
     * @param string $token The share token.
     * @param string $secret The pairing secret.
     * @param object|null $security The cURL security helper to apply, or null for the site default.
     * @return array The decoded metadata.
     * @throws \moodle_exception On a transport error or non-JSON response.
     */
    private static function fetch_meta(string $base, string $token, string $secret, ?object $security = null): array {
        $url = $base . '?' . http_build_query(signer::sign(['token' => $token, 'action' => 'meta'], $secret));
        $curl = new \curl($security ? ['securityhelper' => $security] : []);
        $curl->setHeader('Accept: application/json');
        $body = $curl->get($url, [], [
            'CURLOPT_FOLLOWLOCATION' => 1,
            'CURLOPT_MAXREDIRS' => 3,
            'CURLOPT_CONNECTTIMEOUT' => 30,
            'CURLOPT_TIMEOUT' => 60,
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_USERAGENT' => self::USER_AGENT,
        ]);
        $httpcode = (int) ($curl->info['http_code'] ?? 0);
        if (!empty($curl->errno) || $httpcode >= 400 || !is_string($body) || $body === '') {
            throw new \moodle_exception('errorsharefetch', 'repository_largefile');
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \moodle_exception('errorsharefetch', 'repository_largefile');
        }
        return $data;
    }
}
