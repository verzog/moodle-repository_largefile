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
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * HMAC signing of share requests between paired sites.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signer {
    /** @var string Fixed logical resource the signature is bound to. */
    private const RESOURCE = 'repository_largefile:share:v1';

    /** @var int Seconds a signed request stays valid either side of its timestamp. */
    private const TIME_WINDOW = 300;

    /** @var string Table holding spent nonces, for replay protection. */
    private const NONCE_TABLE = 'repository_largefile_nonces';

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
     * Verify a signed request: signature, freshness and single use.
     *
     * The signature is checked before the nonce is recorded, so an unauthenticated
     * caller cannot fill the nonce table.
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
        if (abs(time() - (int) $params['ts']) > self::TIME_WINDOW) {
            return 'errorsharestale';
        }
        $expected = self::compute($params, $secret);
        if (!hash_equals($expected, (string) $params['sig'])) {
            return 'errorsharesig';
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
     * How long a nonce must be retained (the validity window) before the cleanup
     * task may drop it. After this, an old nonce can never pass the timestamp
     * check, so re-use is already impossible.
     *
     * @return int Seconds.
     */
    public static function nonce_retention(): int {
        return self::TIME_WINDOW;
    }
}
