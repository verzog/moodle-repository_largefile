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
 * Upgrade steps for repository_largefile.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Apply repository_largefile upgrade steps.
 *
 * @param int $oldversion The version being upgraded from.
 * @return bool Always true.
 */
function xmldb_repository_largefile_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026090300) {
        // External sharing tables: trusted peers, published shares and spent nonces.
        foreach (['repository_largefile_peers', 'repository_largefile_shares', 'repository_largefile_nonces'] as $tablename) {
            $table = new xmldb_table($tablename);
            if (!$dbman->table_exists($table)) {
                // The definitions live in db/install.xml; create from there.
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $tablename);
            }
        }

        upgrade_plugin_savepoint(true, 2026090300, 'repository', 'largefile');
    }

    if ($oldversion < 2026090500) {
        // Queue of scheduled/unattended server-side transfers.
        $table = new xmldb_table('repository_largefile_transfers');
        if (!$dbman->table_exists($table)) {
            // The definition lives in db/install.xml; create from there.
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'repository_largefile_transfers');
        }

        upgrade_plugin_savepoint(true, 2026090500, 'repository', 'largefile');
    }

    if ($oldversion < 2026090503) {
        // A peer now records its site URL; its host is the only one exempt from the
        // site's cURL block when importing that peer's share.
        $table = new xmldb_table('repository_largefile_peers');
        $field = new xmldb_field('baseurl', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'name');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026090503, 'repository', 'largefile');
    }

    if ($oldversion < 2026090506) {
        // A running transfer now records its percent complete, for display.
        $table = new xmldb_table('repository_largefile_transfers');
        $field = new xmldb_field('progress', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0', 'attempts');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026090506, 'repository', 'largefile');
    }

    if ($oldversion < 2026090508) {
        // Track when a running transfer's percent last advanced, so a stalled run is
        // distinguishable from a merely slow one.
        $table = new xmldb_table('repository_largefile_transfers');
        $field = new xmldb_field('progressupdated', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'progress');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026090508, 'repository', 'largefile');
    }

    if ($oldversion < 2026090602) {
        // A Background Fetch upload writes its chunks out of order, so the token
        // records which byte ranges have arrived.
        $table = new xmldb_table('repository_largefile_chunks');
        $field = new xmldb_field('receivedmap', XMLDB_TYPE_TEXT, null, null, null, null, null, 'currentpos');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026090602, 'repository', 'largefile');
    }

    return true;
}
