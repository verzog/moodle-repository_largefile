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
 * Privacy provider for repository_largefile.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\approved_userlist;

/**
 * Privacy provider for repository_largefile.
 *
 * Chunked uploads are short-lived working state: one row per in-flight upload in
 * {repository_largefile_chunks} plus a partial file on disk, both removed by the
 * cleanup task once consumed or expired. The rows carry the owning user's id and
 * the uploaded file's name, so they are declared and made exportable/erasable.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection The collection to add metadata to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('repository_largefile_chunks', [
            'userid' => 'privacy:metadata:repository_largefile_chunks:userid',
            'contextid' => 'privacy:metadata:repository_largefile_chunks:contextid',
            'filename' => 'privacy:metadata:repository_largefile_chunks:filename',
            'lastmodified' => 'privacy:metadata:repository_largefile_chunks:lastmodified',
        ], 'privacy:metadata:repository_largefile_chunks');
        $collection->add_database_table('repository_largefile_shares', [
            'userid' => 'privacy:metadata:repository_largefile_shares:userid',
            'filename' => 'privacy:metadata:repository_largefile_shares:filename',
            'timecreated' => 'privacy:metadata:repository_largefile_shares:timecreated',
        ], 'privacy:metadata:repository_largefile_shares');
        // A staged payload's bytes are streamed into an export through the file
        // API (a short-lived copy the cleanup task removes).
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');
        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the given user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contexts containing the user's data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT DISTINCT contextid FROM {repository_largefile_chunks} WHERE userid = :userid AND contextid IS NOT NULL",
            ['userid' => $userid]
        );
        // Shares are created at the system context.
        $contextlist->add_from_sql(
            "SELECT DISTINCT :sysctx AS contextid FROM {repository_largefile_shares} WHERE userid = :userid",
            ['sysctx' => \context_system::instance()->id, 'userid' => $userid]
        );
        return $contextlist;
    }

    /**
     * Get the list of users within a specific context.
     *
     * @param userlist $userlist The userlist to add users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        $userlist->add_from_sql(
            'userid',
            "SELECT userid FROM {repository_largefile_chunks} WHERE contextid = :contextid AND userid IS NOT NULL",
            ['contextid' => $context->id]
        );
        if ($context instanceof \context_system) {
            $userlist->add_from_sql('userid', "SELECT userid FROM {repository_largefile_shares}", []);
        }
    }

    /**
     * Export all user data for the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            $records = $DB->get_records('repository_largefile_chunks', [
                'contextid' => $context->id,
                'userid' => $user->id,
            ]);
            if (!$records) {
                continue;
            }
            $subcontext = [get_string('privacy:chunkspath', 'repository_largefile')];
            $writer = \core_privacy\local\request\writer::with_context($context);
            $fs = get_file_storage();
            $data = [];
            foreach ($records as $record) {
                $path = \repository_largefile\chunk_store::get_path_for_id((string) $record->id);
                $size = ($path !== null && is_readable($path)) ? (int) filesize($path) : 0;
                $data[] = (object) [
                    'filename' => $record->filename,
                    'filesize' => $size,
                    'lastmodified' => $record->lastmodified ? userdate($record->lastmodified) : '',
                ];
                if ($size > 0) {
                    // Include the actual bytes without loading the (possibly
                    // multi-gigabyte) file into memory: stage it as a stored_file so
                    // the writer streams it into the export. The temporary
                    // stored_file is removed by the cleanup task (see
                    // task\cleanup_chunks::purge_export_files()).
                    $filename = (string) $record->filename !== '' ? (string) $record->filename : ('upload_' . $record->id);
                    $filerecord = [
                        'contextid' => $context->id,
                        'component' => 'repository_largefile',
                        'filearea' => 'privacyexport',
                        'itemid' => (int) $record->id,
                        'filepath' => '/',
                        'filename' => clean_param($filename, PARAM_FILE),
                    ];
                    $existing = $fs->get_file(
                        $context->id,
                        'repository_largefile',
                        'privacyexport',
                        (int) $record->id,
                        '/',
                        $filerecord['filename']
                    );
                    if ($existing) {
                        $existing->delete();
                    }
                    $writer->export_file($subcontext, $fs->create_file_from_pathname($filerecord, $path));
                }
            }
            $writer->export_data($subcontext, (object) ['uploads' => $data]);
        }

        // Shares live at the system context; export their metadata when approved.
        $system = \context_system::instance();
        $approvedsystem = false;
        foreach ($contextlist->get_contexts() as $context) {
            if ((int) $context->id === (int) $system->id) {
                $approvedsystem = true;
                break;
            }
        }
        if ($approvedsystem) {
            $shares = $DB->get_records('repository_largefile_shares', ['userid' => $user->id]);
            if ($shares) {
                $sharedata = [];
                foreach ($shares as $share) {
                    $sharedata[] = (object) [
                        'filename' => $share->filename,
                        'peerid' => (int) $share->peerid,
                        'timecreated' => userdate((int) $share->timecreated),
                    ];
                }
                \core_privacy\local\request\writer::with_context($system)->export_data(
                    [get_string('manageshares', 'repository_largefile')],
                    (object) ['shares' => $sharedata]
                );
            }
        }
    }

    /**
     * Delete all data for all users in the given context.
     *
     * @param \context $context The context to delete in.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        self::delete_rows(['contextid' => $context->id]);
        if ($context instanceof \context_system) {
            self::delete_shares('1 = 1', []);
        }
    }

    /**
     * Delete all data for the given user in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to delete in.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            self::delete_rows(['contextid' => $context->id, 'userid' => $userid]);
            if ($context instanceof \context_system) {
                self::delete_shares('userid = :userid', ['userid' => $userid]);
            }
        }
    }

    /**
     * Delete data for the listed users in the userlist's context.
     *
     * @param approved_userlist $userlist The approved users to delete for.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $chunkparams = $params + ['contextid' => $context->id];
        $records = $DB->get_records_select(
            'repository_largefile_chunks',
            "contextid = :contextid AND userid $insql",
            $chunkparams
        );
        foreach ($records as $record) {
            \repository_largefile\chunk_store::delete((string) $record->id);
        }
        if ($context instanceof \context_system) {
            self::delete_shares("userid $insql", $params);
        }
    }

    /**
     * Delete every chunk row matching the conditions, removing its partial file too.
     *
     * @param array $conditions Column => value conditions for the rows to delete.
     * @return void
     */
    private static function delete_rows(array $conditions): void {
        global $DB;
        $records = $DB->get_records('repository_largefile_chunks', $conditions);
        foreach ($records as $record) {
            \repository_largefile\chunk_store::delete((string) $record->id);
        }
    }

    /**
     * Delete shares matching a select clause, including their encrypted files.
     *
     * @param string $select A SQL where-clause fragment.
     * @param array $params Named parameters for the clause.
     * @return void
     */
    private static function delete_shares(string $select, array $params): void {
        global $DB;
        $ids = $DB->get_fieldset_select('repository_largefile_shares', 'id', $select, $params);
        foreach ($ids as $id) {
            \repository_largefile\local\share_manager::delete((int) $id);
        }
    }
}
