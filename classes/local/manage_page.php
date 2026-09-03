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
 * Shared page setup and navigation for the plugin's standalone management pages.
 *
 * A repository plugin cannot register pages in Moodle's admin settings tree, so
 * the peers, shares, import and transfers screens are standalone pages linked
 * from the plugin's configuration page. On their own they would have no way back
 * or across, so this helper gives them all a common breadcrumb (leading back to
 * the configuration page) and a tab bar that links every management page plus a
 * "Settings" tab back to the configuration page.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * Shared page setup and navigation for the management pages.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manage_page {
    /**
     * URL of the plugin's configuration page (the management pages' home).
     *
     * @return \moodle_url The repository type configuration page.
     */
    public static function config_url(): \moodle_url {
        return new \moodle_url('/admin/repository.php', ['action' => 'edit', 'repos' => 'largefile']);
    }

    /**
     * Set a management page up with the admin layout, its title, and a breadcrumb
     * that leads back to the plugin's configuration page.
     *
     * @param \moodle_url $url The page's own URL.
     * @param string $title The page title and heading.
     * @return void
     */
    public static function setup(\moodle_url $url, string $title): void {
        global $PAGE;
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url($url);
        $PAGE->set_pagelayout('admin');
        $PAGE->set_title($title);
        $PAGE->set_heading($title);
        // The configuration page is core admin, so only link back to it for a user
        // who can actually open it; a delegated manager sees the title alone.
        if (has_capability('moodle/site:config', \context_system::instance())) {
            $PAGE->navbar->add(get_string('pluginname', 'repository_largefile'), self::config_url());
        }
        $PAGE->navbar->add($title);
    }

    /**
     * Render the management tab bar, with the given tab marked current.
     *
     * A "Settings" tab returns to the configuration page (only for a user who can
     * open it); the rest link the management pages the current user may reach.
     *
     * @param string $active The key of the current tab (settings, peers, shares,
     *        import or transfers).
     * @return string The rendered tab tree.
     */
    public static function tabs(string $active): string {
        global $OUTPUT;
        $context = \context_system::instance();

        $tabs = [];
        // The configuration page is core admin, so only offer the Settings tab to a
        // user who can actually open it; a delegated manager sees the rest alone.
        if (has_capability('moodle/site:config', $context)) {
            $tabs[] = new \tabobject('settings', self::config_url(), get_string('settings'));
        }
        if (has_capability('repository/largefile:share', $context)) {
            $tabs[] = new \tabobject(
                'peers',
                new \moodle_url('/repository/largefile/manage_peers.php'),
                get_string('managepeers', 'repository_largefile')
            );
            $tabs[] = new \tabobject(
                'shares',
                new \moodle_url('/repository/largefile/manage_shares.php'),
                get_string('manageshares', 'repository_largefile')
            );
        }
        if (has_capability('repository/largefile:import', $context)) {
            $tabs[] = new \tabobject(
                'import',
                new \moodle_url('/repository/largefile/import.php'),
                get_string('importshared', 'repository_largefile')
            );
            $tabs[] = new \tabobject(
                'transfers',
                new \moodle_url('/repository/largefile/transfers.php'),
                get_string('transfers', 'repository_largefile')
            );
        }
        return $OUTPUT->tabtree($tabs, $active);
    }
}
