// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Live-refresh the Transfers page's "uploads in progress" region.
 *
 * Polls a read-only, admin-gated endpoint (transfers.php?ajax=uploads) every few
 * seconds and swaps just that region's markup in place, so a chunked upload —
 * including a Background Fetch upload that continues after its owner closed their
 * tab — shows its progress climbing without the admin reloading the whole page
 * (which would also disturb the "queue a new transfer" form). Polling pauses while
 * the tab is hidden, and a transient fetch failure simply waits for the next tick.
 *
 * @module     repository_largefile/transfers_monitor
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** @var {number|null} The active poll timer, so a re-init never stacks two. */
let timer = null;

/**
 * Fetch the current region markup and swap it into place. Any failure (network,
 * a non-200, a missing region) is swallowed so a single bad tick never stops the
 * polling — the next tick tries again.
 *
 * @param {string} url The AJAX endpoint returning the region's HTML.
 * @param {string} region The id of the element whose contents are replaced.
 * @return {Promise} Resolves once the tick is done.
 */
const refresh = async(url, region) => {
    try {
        const response = await fetch(url, {credentials: 'same-origin'});
        if (!response.ok) {
            return;
        }
        const html = await response.text();
        const target = document.getElementById(region);
        if (target) {
            target.innerHTML = html;
        }
    } catch (e) {
        // A transient failure just skips this tick; the next one retries.
    }
};

/**
 * Start polling the uploads-in-progress region.
 *
 * @param {object} config The {url, region, interval} to poll with; interval in ms.
 * @return {void}
 */
export const init = (config) => {
    const url = config.url;
    const region = config.region;
    const interval = config.interval || 5000;
    if (timer !== null) {
        window.clearInterval(timer);
    }
    timer = window.setInterval(() => {
        // Only poll while the tab is visible, to avoid needless load in the
        // background; the region is already current from the initial page render.
        if (document.visibilityState === 'visible') {
            refresh(url, region);
        }
    }, interval);
};
