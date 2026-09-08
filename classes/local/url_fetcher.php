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
 * Streams a remote file to a temporary path for the Large file repository.
 *
 * The download goes through Moodle's {@see \curl} wrapper, so the site's cURL
 * security policy (Site administration > Security > HTTP security:
 * curlsecurityblockedhosts / curlsecurityallowedport) is enforced on every
 * redirect hop. On the supported Moodle versions (5.0+) that policy ships secure
 * by default — blocking loopback, private ranges, localhost and the
 * cloud-metadata address and restricting ports to 80/443 — so a user-supplied
 * URL is treated as untrusted and can never reach internal services out of the
 * box. The transfer is aborted the moment it exceeds the size cap, so an oversize
 * or endless body cannot fill the disk.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace repository_largefile\local;

/**
 * Streams a remote file to a temporary path for the Large file repository.
 *
 * @package    repository_largefile
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_fetcher {
    /** @var string Browser-like User-Agent so WAF/CDN-fronted hosts serve the file. */
    private const FETCH_USER_AGENT =
        'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0';

    /**
     * @var int Fallback download ceiling used when the caller sets no limit (0) and
     * the free space on the download filesystem cannot be measured. 2 GB - 1, the
     * largest value that stays an int on 32-bit PHP.
     */
    private const DEFAULT_MAXBYTES = 2147483647;

    /**
     * @var int Disk space (1 GiB) an unlimited download must leave free, so a fetch
     * that is allowed to be as large as the disk can hold still cannot fill it
     * completely and take the site down with it.
     */
    private const DISK_RESERVE = 1073741824;

    /** @var string|null Effective URL of the most recent download (after redirects). */
    private ?string $lastfinalurl = null;

    /** @var string|null Content-Type header of the most recent download. */
    private ?string $lastcontenttype = null;

    /** @var string|null Content-Disposition filename of the most recent download. */
    private ?string $lastdispositionname = null;

    /**
     * Whether a string is an absolute http(s) URL with a host.
     *
     * @param string $url The candidate URL.
     * @return bool
     */
    public static function is_fetchable_url(string $url): bool {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        return in_array($scheme, ['http', 'https'], true) && $host !== '';
    }

    /**
     * Download $url to a fresh temporary file and return its path plus metadata.
     *
     * @param string $url Absolute http(s) URL.
     * @param int $maxbytes Maximum accepted size in bytes; 0 (or negative) falls back to a finite ceiling.
     * @param callable|null $iscancelled Optional predicate polled during the transfer; when it returns
     *        true the download is aborted (so a cancelled request stops streaming instead of running on).
     * @param object|null $securityhelper Optional cURL security helper; when null the site's default
     *        policy applies (a user-supplied URL stays untrusted), when given it scopes the block, e.g.
     *        to exempt one trusted peer host ({@see peer_curl_security}).
     * @return array Keys: 'path' (absolute temp path), 'filename', 'contenttype'.
     * @throws \moodle_exception With a repository_largefile error string key.
     */
    public function fetch(string $url, int $maxbytes, ?callable $iscancelled = null, ?object $securityhelper = null): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if (!self::is_fetchable_url($url)) {
            throw new \moodle_exception('errorbadurl', 'repository_largefile');
        }

        $this->lastfinalurl = null;
        $this->lastcontenttype = null;
        $this->lastdispositionname = null;

        $tempdir = make_request_directory();
        $target = tempnam($tempdir, 'largefile_');
        if ($target === false) {
            throw new \moodle_exception('errordownloadfailed', 'repository_largefile');
        }
        // Always enforce a finite cap: when the caller imposes none (0, an unlimited
        // user or site), bound the fetch by the disk it lands on, so an oversize or
        // endless body cannot fill it — without an arbitrary ceiling that would stop
        // a legitimately huge (multi-gigabyte) backup. That ceiling is a snapshot,
        // so an unlimited fetch also re-checks free space as it streams (below):
        // several concurrent fetches would otherwise each be allowed the same space.
        $disk = (object) ['unlimited' => $maxbytes <= 0, 'dir' => $tempdir, 'lastcheck' => 0.0, 'full' => false];
        $maxbytes = $disk->unlimited ? self::unlimited_ceiling($tempdir) : $maxbytes;
        $fh = fopen($target, 'wb');
        if ($fh === false) {
            @unlink($target);
            throw new \moodle_exception('errordownloadfailed', 'repository_largefile');
        }

        $curl = new \curl($securityhelper ? ['securityhelper' => $securityhelper] : []);
        $curl->setHeader('Accept: application/octet-stream, */*');
        $options = [
            'CURLOPT_FILE' => $fh,
            'CURLOPT_FOLLOWLOCATION' => 1,
            'CURLOPT_MAXREDIRS' => 5,
            'CURLOPT_CONNECTTIMEOUT' => 30,
            'CURLOPT_TIMEOUT' => 600,
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_USERAGENT' => self::FETCH_USER_AGENT,
        ];
        // Abort the transfer as soon as it exceeds the cap (by declared size or
        // bytes received so far), rather than only checking after the whole body is
        // on disk; also abort promptly when the caller signals cancellation, so a
        // fetch for a dialogue the user closed does not keep streaming.
        $options['CURLOPT_NOPROGRESS'] = 0;
        $options['CURLOPT_PROGRESSFUNCTION'] = function ($ch, $dltotal, $dlnow) use ($maxbytes, $iscancelled, $disk) {
            if ($iscancelled !== null && $iscancelled()) {
                return 1;
            }
            if ($dltotal > $maxbytes || $dlnow > $maxbytes) {
                return 1;
            }
            // An unlimited fetch shares the disk with every other transfer, so the
            // reserve is enforced live (throttled: a stat every couple of seconds),
            // not just against the free space measured when this fetch began.
            if ($disk->unlimited) {
                $now = microtime(true);
                if ($now - $disk->lastcheck >= 2.0) {
                    $disk->lastcheck = $now;
                    if (self::disk_below_reserve($disk->dir)) {
                        $disk->full = true;
                        return 1;
                    }
                }
            }
            return 0;
        };
        $result = $curl->download_one($url, null, $options);
        fclose($fh);

        $httpcode = (int) ($curl->info['http_code'] ?? 0);
        $this->lastfinalurl = $curl->info['url'] ?? $url;
        $this->lastcontenttype = strtolower((string) ($curl->info['content_type'] ?? ''));
        // Moodle's \curl exposes captured response headers as an array of raw
        // lines ($curl->rawresponse); join them so Content-Disposition can be read
        // whichever shape this Moodle version returns.
        $raw = $curl->rawresponse ?? '';
        $this->lastdispositionname = $this->disposition_filename(is_array($raw) ? implode("\n", $raw) : (string) $raw);

        // CURLE_ABORTED_BY_CALLBACK (42): the progress callback stopped the transfer,
        // either because the disk ran low or because the body was oversize. Report
        // which, not a generic failure.
        if ((int) ($curl->errno ?? 0) === 42) {
            @unlink($target);
            if ($disk->full) {
                throw new \moodle_exception('errordownloaddiskfull', 'repository_largefile');
            }
            throw new \moodle_exception('errordownloadtoobig', 'repository_largefile');
        }
        if ($result !== true || !empty($curl->errno)) {
            @unlink($target);
            throw new \moodle_exception('errordownloadfailed', 'repository_largefile');
        }
        if ($httpcode >= 400) {
            @unlink($target);
            throw new \moodle_exception('errordownloadhttp', 'repository_largefile', '', $httpcode);
        }
        if (filesize($target) === 0) {
            @unlink($target);
            throw new \moodle_exception('errordownloadempty', 'repository_largefile');
        }
        if ($maxbytes > 0 && filesize($target) > $maxbytes) {
            @unlink($target);
            throw new \moodle_exception('errordownloadtoobig', 'repository_largefile');
        }

        return [
            'path' => $target,
            'filename' => $this->derive_filename($url),
            'contenttype' => $this->lastcontenttype ?? '',
        ];
    }

    /**
     * The size ceiling for a download with no caller-imposed limit: the free space
     * on the filesystem the download is written to, less a reserve, so the transfer
     * can be as large as the disk can actually hold but can never fill it. Falls
     * back to a fixed 2 GB ceiling when free space cannot be measured.
     *
     * @param string $dir A directory on the filesystem the download is written to.
     * @return int The ceiling in bytes (always positive).
     */
    public static function unlimited_ceiling(string $dir): int {
        $free = @disk_free_space($dir);
        if ($free === false || !is_finite($free)) {
            return self::DEFAULT_MAXBYTES;
        }
        $ceiling = (int) min($free, (float) PHP_INT_MAX) - self::DISK_RESERVE;
        // A nearly full disk still gets the small fixed ceiling rather than zero or
        // a negative cap, which the caller would read as "unlimited".
        return $ceiling > 0 ? $ceiling : self::DEFAULT_MAXBYTES;
    }

    /**
     * Whether the filesystem holding $dir has dropped below the disk reserve, so a
     * running unlimited fetch must stop. Unknown free space (an unmeasurable
     * directory) does not count as low: such a fetch is already bounded by the fixed
     * fallback ceiling.
     *
     * @param string $dir A directory on the filesystem being written to.
     * @return bool True when free space is known and below the reserve.
     */
    public static function disk_below_reserve(string $dir): bool {
        $free = @disk_free_space($dir);
        return $free !== false && is_finite($free) && $free < self::DISK_RESERVE;
    }

    /**
     * Best-effort filename for the downloaded body: the Content-Disposition name
     * if the server gave one, otherwise the last path segment of the effective
     * (post-redirect) URL, falling back to a generic name.
     *
     * @param string $url The originally requested URL.
     * @return string A cleaned, safe filename.
     */
    private function derive_filename(string $url): string {
        if ($this->lastdispositionname !== null && $this->lastdispositionname !== '') {
            $name = $this->lastdispositionname;
        } else {
            $path = (string) parse_url($this->lastfinalurl ?? $url, PHP_URL_PATH);
            $name = rawurldecode(basename($path));
        }
        $name = clean_param($name, PARAM_FILE);
        return $name !== '' ? $name : 'download';
    }

    /**
     * Extract a filename from the Content-Disposition header of a raw response, if
     * present. Handles both the plain filename= and the RFC 5987 filename*= forms.
     *
     * @param string $rawheaders The raw response headers curl captured.
     * @return string|null The filename, or null if none was advertised.
     */
    private function disposition_filename(string $rawheaders): ?string {
        if ($rawheaders === '') {
            return null;
        }
        // RFC 5987: filename*=UTF-8''name — takes precedence when present.
        if (preg_match('/filename\*=(?:[^\']*\'[^\']*\')?([^;\r\n]+)/i', $rawheaders, $m)) {
            $name = rawurldecode(trim($m[1], " \"'"));
            if ($name !== '') {
                return $name;
            }
        }
        if (preg_match('/filename="?([^";\r\n]+)"?/i', $rawheaders, $m)) {
            $name = trim($m[1]);
            if ($name !== '') {
                return $name;
            }
        }
        return null;
    }
}
