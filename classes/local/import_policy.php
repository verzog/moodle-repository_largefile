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
 * Site policy for what a transfer may carry and where an imported file may land.
 *
 * A site can (optionally) restrict which kinds of file this plugin accepts —
 * course backups, SCORM packages, Common Cartridge exports, video — and choose
 * which destinations an imported file may be routed to: the user's private
 * backup area (restorable), the large-file picker (general use), or generic
 * private files. The accepted set which destinations each kind may use, and the
 * routing itself all live here so the import page, the URL-import queue, the
 * chunked uploader and the unattended runner all enforce one policy.
 *
 * All tunables are read from the repository's type configuration
 * ({@see \repository_largefile::type_config_form()}), stored under the bare type
 * name and read back with get_config('largefile', ...). Type restriction is
 * opt-in: with it off (the default) every file kind is accepted, preserving the
 * plugin's original permissive behaviour.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

use repository_largefile\chunk_store;

/**
 * Site policy for accepted file kinds and import destinations.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_policy {
    /** @var string A Moodle course/activity backup (.mbz), for restore. */
    public const TYPE_BACKUP = 'backup';

    /** @var string A SCORM content package (.zip). */
    public const TYPE_SCORM = 'scorm';

    /** @var string An IMS Common Cartridge export (.imscc). */
    public const TYPE_IMSCC = 'imscc';

    /** @var string A video file (mp4, webm, mov, ...). */
    public const TYPE_VIDEO = 'video';

    /** @var string Anything else (only reachable when type restriction is off). */
    public const TYPE_OTHER = 'other';

    /** @var string Destination: the user's private backup area (user/backup), restorable. */
    public const DEST_BACKUPAREA = 'backuparea';

    /** @var string Destination: the large-file picker (this plugin's staging store). */
    public const DEST_PICKER = 'picker';

    /** @var string Destination: the user's generic private files (user/private). */
    public const DEST_PRIVATEFILES = 'privatefiles';

    /**
     * File extensions that identify each restrictable kind.
     *
     * @var array Map of kind => list of lower-case extensions (no dot).
     */
    private const EXTENSIONS = [
        self::TYPE_BACKUP => ['mbz'],
        self::TYPE_IMSCC => ['imscc'],
        self::TYPE_SCORM => ['zip'],
        self::TYPE_VIDEO => ['mp4', 'm4v', 'mov', 'webm', 'ogv', 'avi', 'mkv', 'flv', 'wmv', 'mpg', 'mpeg', '3gp'],
    ];

    /**
     * Which destinations even make sense for each kind, before the site's own
     * enabled-destinations choice is applied. A backup belongs in the backup area
     * (to restore) or private files; content packages and video belong in the
     * picker (to attach to an activity) or private files.
     *
     * @var array Map of kind => ordered list of destination constants.
     */
    private const SUITABLE = [
        self::TYPE_BACKUP => [self::DEST_BACKUPAREA, self::DEST_PRIVATEFILES],
        self::TYPE_SCORM => [self::DEST_PICKER, self::DEST_PRIVATEFILES],
        self::TYPE_IMSCC => [self::DEST_PICKER, self::DEST_PRIVATEFILES],
        self::TYPE_VIDEO => [self::DEST_PICKER, self::DEST_PRIVATEFILES],
        self::TYPE_OTHER => [self::DEST_PICKER, self::DEST_PRIVATEFILES],
    ];

    /**
     * Detect a file's kind from its name.
     *
     * @param string $filename The file name (or path); only the extension matters.
     * @return string One of the TYPE_* constants (TYPE_OTHER when unrecognised).
     */
    public static function detect_type(string $filename): string {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        foreach (self::EXTENSIONS as $type => $exts) {
            if (in_array($ext, $exts, true)) {
                return $type;
            }
        }
        return self::TYPE_OTHER;
    }

    /**
     * Whether the site restricts which file kinds are accepted (opt-in).
     *
     * @return bool True when only the checked kinds are accepted.
     */
    public static function restricts_types(): bool {
        return (bool) get_config('largefile', 'restricttypes');
    }

    /**
     * The restrictable kinds the site accepts. Meaningful only when
     * {@see self::restricts_types()} is true.
     *
     * @return array List of TYPE_* constants that are switched on.
     */
    public static function accepted_types(): array {
        $accepted = [];
        foreach (array_keys(self::EXTENSIONS) as $type) {
            // Default on, so turning restriction on without touching the boxes still
            // accepts every named kind rather than silently rejecting everything.
            if (get_config('largefile', 'accept_' . $type) !== '0') {
                $accepted[] = $type;
            }
        }
        return $accepted;
    }

    /**
     * Whether a file of the given kind may be accepted at all.
     *
     * @param string $type A TYPE_* constant (as from {@see self::detect_type()}).
     * @return bool True when the kind is allowed in.
     */
    public static function is_type_accepted(string $type): bool {
        if (!self::restricts_types()) {
            return true;
        }
        // An unrecognised file has no box to tick, so restriction excludes it.
        return in_array($type, self::accepted_types(), true);
    }

    /**
     * The destinations the site has enabled, site-wide.
     *
     * @return array List of DEST_* constants that are switched on.
     */
    public static function enabled_destinations(): array {
        $enabled = [];
        foreach ([self::DEST_BACKUPAREA, self::DEST_PICKER, self::DEST_PRIVATEFILES] as $dest) {
            if (get_config('largefile', 'dest_' . $dest) !== '0') {
                $enabled[] = $dest;
            }
        }
        // Never leave a site with no destination at all; fall back to the backup area.
        return $enabled ?: [self::DEST_BACKUPAREA];
    }

    /**
     * Whether the large-file picker is an enabled destination. When it is not, the
     * plugin stages nothing into the picker at all — neither an import nor a direct
     * browser/URL upload through the picker's own upload dialogue.
     *
     * @return bool True when the picker destination is enabled.
     */
    public static function picker_enabled(): bool {
        return in_array(self::DEST_PICKER, self::enabled_destinations(), true);
    }

    /**
     * Why a direct upload into the picker (a browser chunk upload or the dialogue's
     * URL fetch) should be refused, or null when it is allowed. Centralises the two
     * gates the picker's own upload paths must apply: the picker must be an enabled
     * destination, and the file's kind must be accepted.
     *
     * @param string $filename The uploaded/fetched file name.
     * @return string|null A ready-to-show error message, or null when allowed.
     */
    public static function upload_rejection_reason(string $filename): ?string {
        if (!self::picker_enabled()) {
            return get_string('errorpickerdisabled', 'repository_largefile');
        }
        $kind = self::detect_type($filename);
        if (!self::is_type_accepted($kind)) {
            return get_string('errortypenotaccepted', 'repository_largefile', self::type_label($kind));
        }
        return null;
    }

    /**
     * The destinations offered for a given kind: those that suit the kind and are
     * enabled site-wide, in preference order.
     *
     * @param string $type A TYPE_* constant.
     * @return array Ordered list of DEST_* constants (may be empty).
     */
    public static function destinations_for(string $type): array {
        $suitable = self::SUITABLE[$type] ?? self::SUITABLE[self::TYPE_OTHER];
        $enabled = self::enabled_destinations();
        return array_values(array_intersect($suitable, $enabled));
    }

    /**
     * The default destination for a kind (the first one offered, or null if none).
     *
     * @param string $type A TYPE_* constant.
     * @return string|null A DEST_* constant, or null when nothing is offered.
     */
    public static function default_destination(string $type): ?string {
        $offered = self::destinations_for($type);
        return $offered[0] ?? null;
    }

    /**
     * Whether a chosen destination is permitted for a file of the given kind.
     *
     * @param string $type A TYPE_* constant.
     * @param string $destination A DEST_* constant.
     * @return bool True when the routing is allowed.
     */
    public static function is_destination_allowed(string $type, string $destination): bool {
        return in_array($destination, self::destinations_for($type), true);
    }

    /**
     * The accepted-type marker for {@see \repository_largefile::supported_filetypes()}:
     * '*' when unrestricted, otherwise the extensions (and the 'video' group) of the
     * accepted kinds, so the file picker only advertises this repository for them.
     *
     * @return string|array '*' or a list of extensions/groups.
     */
    public static function supported_filetypes() {
        if (!self::restricts_types()) {
            return '*';
        }
        $accepted = self::accepted_types();
        $types = [];
        foreach ($accepted as $type) {
            if ($type === self::TYPE_VIDEO) {
                // The 'video' group covers the video extensions Moodle knows.
                $types[] = 'video';
                continue;
            }
            foreach (self::EXTENSIONS[$type] as $ext) {
                $types[] = '.' . $ext;
            }
        }
        // Restriction on with nothing ticked means the plugin accepts nothing; a
        // sentinel that matches no real file keeps it from being advertised (and, in
        // particular, from claiming backups) rather than falling back to '.mbz'.
        return $types ?: ['.repository_largefile_none'];
    }

    /**
     * A human-readable label for a file kind.
     *
     * @param string $type A TYPE_* constant.
     * @return string The translated label.
     */
    public static function type_label(string $type): string {
        return get_string('filetype_' . $type, 'repository_largefile');
    }

    /**
     * A human-readable label for a destination.
     *
     * @param string $destination A DEST_* constant.
     * @return string The translated label.
     */
    public static function destination_label(string $destination): string {
        return get_string('destination_' . $destination, 'repository_largefile');
    }

    /**
     * Menu of the destinations enabled site-wide, keyed by DEST_* constant, for a
     * form selector. (The type-specific narrowing is enforced when the file's kind
     * is known — at import time, once its name is fetched.)
     *
     * @return array Map of DEST_* constant => label.
     */
    public static function destination_menu(): array {
        $menu = [];
        foreach (self::enabled_destinations() as $dest) {
            $menu[$dest] = self::destination_label($dest);
        }
        return $menu;
    }

    /**
     * Route an imported file to the chosen destination, consuming the source file.
     *
     * Enforces both gates: the file's kind must be accepted, and the destination
     * must be permitted for that kind. On success the source at $srcpath has been
     * moved or copied into place (callers need not delete it).
     *
     * @param int $userid The owner the file is stored for.
     * @param string $srcpath Absolute path of the recovered plaintext file.
     * @param string $filename The file name to store under.
     * @param string $destination A DEST_* constant.
     * @param int $contextid Context for a picker-staged file (0 = system context).
     * @return string The final stored file name (may be de-duplicated).
     * @throws \moodle_exception When the kind is rejected, the destination is not
     *         allowed for it, or storage fails.
     */
    public static function store_imported_file(
        int $userid,
        string $srcpath,
        string $filename,
        string $destination,
        int $contextid = 0
    ): string {
        $type = self::detect_type($filename);
        if (!self::is_type_accepted($type)) {
            throw new \moodle_exception('errortypenotaccepted', 'repository_largefile', '', self::type_label($type));
        }
        // An empty destination means "auto": route to this kind's default among the
        // site's enabled destinations. This covers a single-destination site (the
        // form shows no selector) and a queued transfer that recorded no choice.
        if ($destination === '') {
            $destination = self::default_destination($type);
        }
        if ($destination === null || !self::is_destination_allowed($type, $destination)) {
            throw new \moodle_exception('errordestnotallowed', 'repository_largefile', '', self::type_label($type));
        }

        if ($destination === self::DEST_PICKER) {
            $ctx = $contextid ?: \context_system::instance()->id;
            $token = chunk_store::create_token_for($userid, $ctx, -1);
            if (!chunk_store::adopt_file($token, $srcpath, $filename)) {
                throw new \moodle_exception('errordownloadfailed', 'repository_largefile');
            }
            return $filename;
        }

        // Backup area (user/backup) or generic private files (user/private).
        $filearea = $destination === self::DEST_BACKUPAREA ? 'backup' : 'private';
        $fs = get_file_storage();
        $usercontext = \context_user::instance($userid);
        if ($fs->file_exists($usercontext->id, 'user', $filearea, 0, '/', $filename)) {
            $filename = time() . '-' . $filename;
        }
        $fs->create_file_from_pathname([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => $filearea,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $userid,
        ], $srcpath);
        @unlink($srcpath);
        return $filename;
    }
}
