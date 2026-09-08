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
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * Receiver side of backup sharing: fetch, decrypt and verify a peer's share.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
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

        // Prefer header transport for the credential (it stays out of access logs).
        // Only a peer still on a release that reads just the query string — one whose
        // reply carries no protocol marker — gets one retry in the legacy form, which
        // the download then also uses; any other failure (a transport error, or a
        // current peer rejecting the request) is reported as is, so a transient fault
        // never pushes a fresh signature into the URL.
        $legacy = false;
        $result = self::request_meta($base, $token, $secret, $security, false);
        if ($result['data'] === null) {
            if (!$result['oldpeer']) {
                throw new \moodle_exception('errorsharefetch', 'repository_largefile');
            }
            $legacy = true;
            $result = self::request_meta($base, $token, $secret, $security, true);
            if ($result['data'] === null) {
                throw new \moodle_exception('errorsharefetch', 'repository_largefile');
            }
        }
        $meta = $result['data'];
        if (!self::meta_is_wellformed($meta)) {
            throw new \moodle_exception('errorsharenofile', 'repository_largefile');
        }

        // Redirects are never followed on a signed request: libcurl would resend the
        // credential header to the redirect target, which need not be the peer.
        [$downloadurl, $headers] = self::signed_request($base, ['token' => $token, 'action' => 'download'], $secret, $legacy);
        $fetcher = new url_fetcher();
        $sitemax = (int) ($GLOBALS['CFG']->maxbytes ?? 0);
        $fetched = $fetcher->fetch($downloadurl, $sitemax, null, $security, $headers, false);

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
     * Whether share metadata from a peer has the shape this client relies on: a hex
     * salt of the agreed length, a hex SHA-256 and a usable file name. The response
     * body is not itself signed, so a malformed or hostile reply must fail cleanly
     * here rather than as a type error inside key derivation.
     *
     * @param array $meta The decoded metadata.
     * @return bool True when every required field is present and well-formed.
     */
    public static function meta_is_wellformed(array $meta): bool {
        if (!isset($meta['sha256'], $meta['salt'], $meta['filename'])) {
            return false;
        }
        if (!is_string($meta['salt']) || !preg_match('/^[0-9a-f]{' . (2 * crypto::salt_bytes()) . '}$/i', $meta['salt'])) {
            return false;
        }
        if (!is_string($meta['sha256']) || !preg_match('/^[0-9a-f]{64}$/i', $meta['sha256'])) {
            return false;
        }
        return is_string($meta['filename']) && clean_param($meta['filename'], PARAM_FILE) !== '';
    }

    /**
     * Build the cURL security helper for a peer fetch.
     *
     * The share endpoint must live on the peer's registered site origin (same
     * scheme, host and port), so it is checked against that first. When a peer has
     * a registered site URL, the returned helper exempts only that one origin from
     * the site's cURL block (so a peer on a private-range address is reachable while
     * every other host — and every other port or scheme on the same host, redirect
     * targets included — stays blocked). A legacy peer with no registered URL gets
     * the site's default policy (null), so a public peer keeps working and a peer
     * behind the block must be given its site URL to become reachable.
     *
     * @param int $peerid The peer the share came from.
     * @param string $base The share endpoint base URL (scheme://host[:port]/path).
     * @return \core\files\curl_security_helper_base|null The scoped helper, or null for the site default.
     * @throws \moodle_exception If the share URL is not on the peer's registered site origin.
     */
    private static function peer_security(int $peerid, string $base): ?\core\files\curl_security_helper_base {
        $peer = peer_manager::get($peerid);
        if (!$peer || empty($peer->baseurl)) {
            return null;
        }
        $security = new peer_curl_security($peer->baseurl);
        if (!$security->allows($base)) {
            throw new \moodle_exception('errorsharehostmismatch', 'repository_largefile');
        }
        return $security;
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
     * Build a signed request to the share endpoint: the URL to fetch plus any
     * request headers to send with it.
     *
     * @param string $base The share endpoint base URL.
     * @param array $params The parameters to sign (token, action).
     * @param string $secret The pairing secret.
     * @param bool $legacy True to put ts/nonce/sig in the query string (older peers);
     *        false to carry them in the {@see signer::AUTH_HEADER} header.
     * @return array [string $url, array $headers].
     */
    public static function signed_request(string $base, array $params, string $secret, bool $legacy): array {
        // The separator is given explicitly: Moodle sets PHP's arg_separator.output to
        // "&amp;" for HTML output, which http_build_query would otherwise use — and a
        // URL sent to a peer with "&amp;" between its parameters arrives with the
        // parameters after the first one misnamed ("amp;action"), so the peer rejects it.
        if ($legacy) {
            return [$base . '?' . http_build_query(signer::sign($params, $secret), '', '&'), []];
        }
        $signed = signer::sign_for_header($params, $secret);
        return [$base . '?' . http_build_query($signed['params'], '', '&'), [$signed['header']]];
    }

    /**
     * Whether a set of response headers carries the share endpoint's protocol marker
     * (sent by every release that understands header authentication).
     *
     * @param array $responseheaders Response headers as Moodle's curl wrapper parses them.
     * @return bool
     */
    public static function has_protocol_marker(array $responseheaders): bool {
        foreach ($responseheaders as $name => $value) {
            if (strtolower((string) $name) === 'x-largefile-protocol') {
                return true;
            }
        }
        return false;
    }

    /**
     * Fetch and decode the share metadata (a signed GET returning JSON).
     *
     * Redirects are not followed: the request carries the signed credential (in a
     * header, or in the URL for a legacy peer) and must reach only the peer.
     *
     * @param string $base The share endpoint base URL.
     * @param string $token The share token.
     * @param string $secret The pairing secret.
     * @param object|null $security The cURL security helper to apply, or null for the site default.
     * @param bool $legacy Whether to sign in the legacy query-string form.
     * @return array Keys 'data' (the decoded metadata, or null on failure) and 'oldpeer' (true when
     *               the failure came from an endpoint without the protocol marker — a peer on an
     *               older release — rather than a transport error or a current peer's rejection).
     */
    private static function request_meta(
        string $base,
        string $token,
        string $secret,
        ?object $security = null,
        bool $legacy = false
    ): array {
        [$url, $headers] = self::signed_request($base, ['token' => $token, 'action' => 'meta'], $secret, $legacy);
        $curl = new \curl($security ? ['securityhelper' => $security] : []);
        $curl->setHeader('Accept: application/json');
        foreach ($headers as $header) {
            $curl->setHeader($header);
        }
        $body = $curl->get($url, [], [
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_MAXREDIRS' => 0,
            'CURLOPT_CONNECTTIMEOUT' => 30,
            'CURLOPT_TIMEOUT' => 60,
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_USERAGENT' => self::USER_AGENT,
        ]);
        $httpcode = (int) ($curl->info['http_code'] ?? 0);
        $transporterror = !empty($curl->errno);
        $oldpeer = !$transporterror && !self::has_protocol_marker($curl->getResponse());
        if ($transporterror || $httpcode < 200 || $httpcode >= 300 || !is_string($body) || $body === '') {
            return ['data' => null, 'oldpeer' => $oldpeer];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['data' => null, 'oldpeer' => $oldpeer];
        }
        return ['data' => $data, 'oldpeer' => false];
    }
}
