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
 * A cURL security helper that exempts one trusted peer origin from the site block.
 *
 * A trusted peer is paired by an administrator (name, site URL and shared secret),
 * so fetching a share from it is a deliberate, authenticated operation — even when
 * the peer sits on a private-range address that the site's default cURL policy
 * (curlsecurityblockedhosts) blocks to stop server-side request forgery. This
 * helper allows exactly the one registered peer origin — the same scheme, host and
 * effective port — and defers every other URL, including a redirect to a different
 * port or scheme on the same host, to the site's normal policy. So it cannot be
 * used to reach another service on the peer machine (say a database or a Docker
 * API on a different port), only the configured site.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * A cURL security helper that exempts one trusted peer origin from the site block.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class peer_curl_security extends \core\files\curl_security_helper {
    /** @var string The exempt scheme, lower-cased (e.g. https). */
    private string $exemptscheme;

    /** @var string The exempt host, lower-cased. */
    private string $exempthost;

    /** @var int The exempt effective port (explicit, or the scheme default); 0 if unknown. */
    private int $exemptport;

    /**
     * Build a helper that exempts the given peer site URL's origin.
     *
     * @param string $allowedurl The trusted peer's site URL (scheme://host[:port][/...]).
     */
    public function __construct(string $allowedurl) {
        $parts = parse_url(trim($allowedurl));
        $parts = is_array($parts) ? $parts : [];
        $this->exemptscheme = isset($parts['scheme']) ? \core_text::strtolower($parts['scheme']) : '';
        $this->exempthost = isset($parts['host']) ? \core_text::strtolower($parts['host']) : '';
        $this->exemptport = self::effective_port($this->exemptscheme, $parts['port'] ?? null);
    }

    /**
     * The effective port for a scheme and optional explicit port.
     *
     * @param string $scheme The URL scheme, lower-cased.
     * @param int|null $port The explicit port, or null if none was given.
     * @return int The explicit port, the scheme's default (80/443), or 0 if unknown.
     */
    private static function effective_port(string $scheme, ?int $port): int {
        if ($port) {
            return (int) $port;
        }
        if ($scheme === 'https') {
            return 443;
        }
        if ($scheme === 'http') {
            return 80;
        }
        return 0;
    }

    /**
     * Whether a URL is on the one exempt peer origin (scheme, host and port).
     *
     * @param string $urlstring The URL to test.
     * @return bool True only when the URL matches the registered peer origin exactly.
     */
    public function allows(string $urlstring): bool {
        if ($this->exempthost === '' || $this->exemptport === 0) {
            return false;
        }
        $parts = parse_url(trim($urlstring));
        if (!is_array($parts) || !isset($parts['host'], $parts['scheme'])) {
            return false;
        }
        $scheme = \core_text::strtolower($parts['scheme']);
        return \core_text::strtolower($parts['host']) === $this->exempthost
            && $scheme === $this->exemptscheme
            && self::effective_port($scheme, $parts['port'] ?? null) === $this->exemptport;
    }

    /**
     * Whether a URL is blocked, allowing only the one registered peer origin.
     *
     * The registered peer origin (scheme, host and port) is allowed even when it
     * resolves to an address the site would otherwise block; every other URL — a
     * different host, or a different scheme or port on the same host, a redirect
     * target included — falls through to the site's normal block list, so no other
     * host or service becomes reachable.
     *
     * The site check always runs first, even for the exempt origin: it records the
     * state ({@see \core\files\curl_security_helper::$urlblockchecked} and the host,
     * resolved IPs and port) that {@see \core\files\curl_security_helper::get_resolve_info()}
     * reads back. Only its final verdict is overridden for the trusted origin.
     *
     * @param string $urlstring The URL to check.
     * @param int $notused Unused legacy parameter kept for signature compatibility.
     * @return bool True if the URL is blocked.
     */
    public function url_is_blocked($urlstring, $notused = null) {
        $blocked = parent::url_is_blocked($urlstring);
        if ($blocked && $this->allows((string) $urlstring)) {
            return false;
        }
        return $blocked;
    }
}
