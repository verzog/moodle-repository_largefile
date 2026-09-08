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
 * HMAC signing of share requests between paired sites.
 *
 * The receiving site proves possession of the pairing secret by signing each
 * request (HMAC-SHA256 over the sorted parameters, a fresh nonce and a
 * timestamp), so a leaked share URL alone cannot fetch the file. The signing
 * covers a fixed logical resource tag rather than the HTTP path, so the two
 * sites agree even when one is installed in a subdirectory or behind a proxy.
 * Replay is prevented by a short timestamp window plus a one-shot nonce.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * HMAC signing of share requests between paired sites.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signer {
    /** @var string Fixed logical resource the signature is bound to. */
    private const RESOURCE = 'repository_largefile:share:v1';

    /** @var int Seconds a signed request stays valid either side of its timestamp. */
    private const TIME_WINDOW = 300;

    /** @var string Table holding spent nonces, for replay protection. */
    private const NONCE_TABLE = 'repository_largefile_nonces';

    /** @var string Request header carrying ts/nonce/sig instead of the query string. */
    public const AUTH_HEADER = 'X-Largefile-Auth';

    /**
     * Add ts/nonce/sig to a set of request parameters and return the full set.
     *
     * @param array $params Request parameters (e.g. token, action).
     * @param string $secret The pairing secret.
     * @return array The parameters plus 'ts', 'nonce' and 'sig'.
     */
    public static function sign(array $params, string $secret): array {
        $params['ts'] = (string) time();
        $params['nonce'] = bin2hex(random_bytes(16));
        $params['sig'] = self::compute($params, $secret);
        return $params;
    }

    /**
     * Sign a request for header transport: the signed parameters stay in the query
     * string, while the timestamp, nonce and signature travel in a request header.
     *
     * A signed URL is written to web-server, proxy and CDN access logs on both sites,
     * and logs are the commonest way a credential leaks. Carrying the credential in a
     * header keeps it out of those logs; the signature itself is computed exactly as
     * for the query-string form, so the two transports verify identically.
     *
     * @param array $params Request parameters (e.g. token, action).
     * @param string $secret The pairing secret.
     * @return array Keys 'params' (the unsigned query parameters) and 'header' (the
     *               full "Name: value" header line to send).
     */
    public static function sign_for_header(array $params, string $secret): array {
        $signed = self::sign($params, $secret);
        $value = sprintf('ts=%s,nonce=%s,sig=%s', $signed['ts'], $signed['nonce'], $signed['sig']);
        return ['params' => $params, 'header' => self::AUTH_HEADER . ': ' . $value];
    }

    /**
     * Parse the value of the auth header back into its ts/nonce/sig parts.
     *
     * @param string $value The header value (e.g. "ts=1700000000,nonce=ab12,sig=cd34").
     * @return array|null ['ts' => string, 'nonce' => string, 'sig' => string], or null if malformed.
     */
    public static function parse_auth_header(string $value): ?array {
        $parts = [];
        foreach (explode(',', trim($value)) as $pair) {
            $pair = trim($pair);
            if (!preg_match('/^(ts|nonce|sig)=([A-Za-z0-9]+)$/', $pair, $m)) {
                return null;
            }
            $parts[$m[1]] = $m[2];
        }
        if (count($parts) !== 3) {
            return null;
        }
        return $parts;
    }

    /**
     * Verify a signed request: signature, freshness and single use.
     *
     * The signature is checked first: an unauthenticated caller then learns nothing
     * from the freshness check (a "stale" verdict would otherwise confirm that a
     * guessed token exists) and cannot fill the nonce table.
     *
     * @param array $params The received request parameters (including ts/nonce/sig).
     * @param string $secret The pairing secret.
     * @return string|null A lang-string error key, or null when the request is valid.
     */
    public static function verify(array $params, string $secret): ?string {
        foreach (['ts', 'nonce', 'sig'] as $required) {
            if (!isset($params[$required]) || $params[$required] === '') {
                return 'errorsharesig';
            }
        }
        $expected = self::compute($params, $secret);
        if (!hash_equals($expected, (string) $params['sig'])) {
            return 'errorsharesig';
        }
        if (abs(time() - (int) $params['ts']) > self::TIME_WINDOW) {
            return 'errorsharestale';
        }
        if (!self::claim_nonce((string) $params['nonce'])) {
            return 'errorsharereplay';
        }
        return null;
    }

    /**
     * Compute the HMAC signature over the canonical form of the parameters.
     *
     * @param array $params Request parameters (any existing 'sig' is ignored).
     * @param string $secret The pairing secret.
     * @return string Lowercase hex HMAC-SHA256.
     */
    private static function compute(array $params, string $secret): string {
        unset($params['sig']);
        ksort($params);
        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        $canonical = self::RESOURCE . "\n" . implode('&', $pairs);
        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * Record a nonce as spent, returning false if it was already used.
     *
     * @param string $nonce The nonce from the request.
     * @return bool True if the nonce was fresh (now claimed); false if replayed.
     */
    private static function claim_nonce(string $nonce): bool {
        global $DB;
        if ($DB->record_exists(self::NONCE_TABLE, ['nonce' => $nonce])) {
            return false;
        }
        try {
            $DB->insert_record(self::NONCE_TABLE, (object) ['nonce' => $nonce, 'timecreated' => time()]);
        } catch (\dml_exception $e) {
            // A concurrent request claimed the same nonce first (unique index).
            return false;
        }
        return true;
    }

    /**
     * How long a nonce must be retained before the cleanup task may drop it.
     *
     * A nonce is recorded at the time the request is *received*, but its timestamp
     * may sit anywhere within the window either side of that — a request stamped at
     * the far future edge stays fresh for two full windows after receipt. The nonce
     * must outlive that whole span (plus a margin for clock drift between web and
     * cron hosts), or a captured request could be replayed once the nonce was
     * purged while its timestamp was still fresh.
     *
     * @return int Seconds.
     */
    public static function nonce_retention(): int {
        return 2 * self::TIME_WINDOW + 60;
    }
}
