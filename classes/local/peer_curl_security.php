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
 * A cURL security helper that exempts one trusted peer host from the site block.
 *
 * A trusted peer is paired by an administrator (name, site URL and shared secret),
 * so fetching a share from it is a deliberate, authenticated operation — even when
 * the peer sits on a private-range address that the site's default cURL policy
 * (curlsecurityblockedhosts) blocks to stop server-side request forgery. This
 * helper allows exactly the one registered peer host and defers every other host,
 * including a redirect target, to the site's normal policy, so it can never be
 * used to reach an arbitrary internal service.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * A cURL security helper that exempts one trusted peer host from the site block.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class peer_curl_security extends \core\files\curl_security_helper {
    /** @var string The one host exempt from the site block, lower-cased. */
    private string $allowedhost;

    /**
     * Build a helper that exempts the given peer host.
     *
     * @param string $allowedhost The trusted peer host (e.g. peer.example.org).
     */
    public function __construct(string $allowedhost) {
        $this->allowedhost = \core_text::strtolower(trim($allowedhost));
    }

    /**
     * Whether a URL is blocked, allowing only the one registered peer host.
     *
     * The registered peer host is allowed even if it resolves to an address the
     * site would otherwise block; every other host — a redirect target included —
     * falls through to the site's normal block list, so no other internal host
     * becomes reachable.
     *
     * @param string $urlstring The URL to check.
     * @param int $notused Unused legacy parameter kept for signature compatibility.
     * @return bool True if the URL is blocked.
     */
    public function url_is_blocked($urlstring, $notused = null) {
        if ($this->allowedhost !== '') {
            try {
                $host = \core_text::strtolower((string) (new \moodle_url($urlstring))->get_host());
            } catch (\moodle_exception $e) {
                return true;
            }
            if ($host !== '' && $host === $this->allowedhost) {
                return false;
            }
        }
        return parent::url_is_blocked($urlstring);
    }
}
