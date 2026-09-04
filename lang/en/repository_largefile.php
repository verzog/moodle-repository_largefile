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
 * Language strings for repository_largefile.
 *
 * @package    repository_largefile
 * @copyright  2026 SCCA
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['addfile'] = 'Add file';
$string['addpeer'] = 'Add peer';
$string['chooselargefile'] = 'Choose a large file';
$string['cleanup_task'] = 'Clean up stale chunked uploads and expired shares';
$string['configplugin'] = 'Large file repository settings';
$string['createshare'] = 'Create share';
$string['deletepeerconfirm'] = 'Delete this peer? Existing shares to it will stop working.';
$string['editpeer'] = 'Edit peer';
$string['errorbadurl'] = 'That does not look like a valid http(s) download URL.';
$string['errorchunktoolarge'] = 'The server rejected an upload chunk as too large. Ask an administrator to lower the '
    . '"Chunk size (MB)" setting, then upload the file again.';
$string['errordownloadempty'] = 'The URL returned an empty response.';
$string['errordownloadfailed'] = 'The file could not be downloaded from that URL.';
$string['errordownloadhttp'] = 'The server returned HTTP status {$a} for that URL.';
$string['errordownloadtoobig'] = 'The file at that URL is larger than the site upload limit.';
$string['erroremptyfile'] = 'The selected file is empty.';
$string['errorpeerbadurl'] = 'Enter the peer\'s site URL as a full http(s) address, for example https://peer.example.org.';
$string['errorsecrettooshort'] = 'Use a longer shared secret (at least 24 characters). Generate a random one and paste '
    . 'the same value on both sites.';
$string['errorsharedecrypt'] = 'The shared backup could not be decrypted. The pairing secret may be wrong, or the file '
    . 'was corrupted or tampered with in transit.';
$string['errorshareencrypt'] = 'The file could not be encrypted for sharing.';
$string['errorshareexpired'] = 'This share has expired or reached its download limit.';
$string['errorsharefetch'] = 'The shared backup could not be fetched from the peer. Check that the share link has not '
    . 'expired, that the peer site is reachable, and that the peer\'s Site URL is set correctly here (a share on a '
    . 'private or internal address is only reachable once the peer\'s Site URL is registered).';
$string['errorsharehostmismatch'] = 'The share link\'s host does not match this peer\'s registered site URL. Import the '
    . 'link from the peer it belongs to, or correct the peer\'s site URL.';
$string['errorshareintegrity'] = 'The decrypted backup did not match its expected checksum, so it was discarded.';
$string['errorshareinvalidurl'] = 'That is not a valid share link.';
$string['errorsharenofile'] = 'The shared file is no longer available on the sending site.';
$string['errorsharenopeer'] = 'The selected peer no longer exists.';
$string['errorsharereplay'] = 'This request has already been used. Try again.';
$string['errorsharesig'] = 'The request could not be authenticated (bad signature or unknown share).';
$string['errorsharestale'] = 'The request has expired. Check that both sites\' clocks are correct, then try again.';
$string['errortransferstalled'] = 'The transfer was interrupted too many times and has been abandoned.';
$string['errortransferunknown'] = 'This transfer has an unknown type and cannot be run.';
$string['erroruploadfailed'] = 'The upload could not be completed after several attempts. Check your connection and try '
    . 'uploading the file again.';
$string['eventbackupimported'] = 'Shared backup imported';
$string['eventsharecreated'] = 'Backup share created';
$string['eventsharedownloaded'] = 'Backup share downloaded';
$string['eventtransfercompleted'] = 'Scheduled transfer completed';
$string['expiresnever'] = 'Never';
$string['importbackground'] = 'Import in the background';
$string['importbackground_help'] = 'Recommended for large backups. The import runs on the server rather than in your '
    . 'browser, so it is not cut off by a web server request timeout (a 504 error). It is queued on the Transfers page and '
    . 'saved to your private backup area when it finishes, ready to restore.';
$string['importbutton'] = 'Fetch and import';
$string['importpeer'] = 'From peer';
$string['importqueued'] = 'The import has been queued and will run in the background. Follow its progress on the Transfers page.';
$string['importshared'] = 'Import a shared backup';
$string['importshared_desc'] = 'Fetch a backup a trusted peer has shared with this site. It is downloaded over an '
    . 'authenticated, encrypted channel, its checksum is verified, and it is placed in your private backup area ready to restore.';
$string['importsuccess'] = 'The shared backup "{$a}" was fetched, verified and saved to your private backup area. Restore it from '
    . 'any course under "Restore" → "User private backup area".';
$string['importurl'] = 'Share link';
$string['importurl_help'] = 'Paste the share link the sending site gave you. It identifies a single share; your request '
    . 'is signed with this peer\'s shared secret so the link alone cannot fetch the file.';
$string['largefile:import'] = 'Import a backup shared by a trusted peer';
$string['largefile:share'] = 'Manage trusted peers and publish backup shares';
$string['largefile:view'] = 'Use the Large file repository in the file picker';
$string['managepeers'] = 'Trusted peers';
$string['managepeers_desc'] = 'A trusted peer is another site running this plugin that you may share backups with. Pair '
    . 'the two sites by generating a strong shared secret and entering the same value on both, exchanged out of band.';
$string['manageshares'] = 'Backup shares';
$string['manageshares_desc'] = 'Publish a backup to a trusted peer as an encrypted, expiring, download-limited link. The '
    . 'file is encrypted at rest and only the paired peer, signing with the shared secret, can fetch it.';
$string['nopeers'] = 'No trusted peers yet.';
$string['nopeersforshare'] = 'Add a trusted peer first, exchanging the shared secret with the other site out of band.';
$string['notransfers'] = 'No transfers have been queued yet.';
$string['nouploadsinprogress'] = 'No uploads are currently in progress.';
$string['peerdeleted'] = 'Peer deleted.';
$string['peername'] = 'Peer name';
$string['peersaved'] = 'Peer saved.';
$string['peersecret'] = 'Shared secret';
$string['peersecret_help'] = 'A long random string known to both sites, exchanged out of band (not over email in the '
    . 'clear). It is stored encrypted and never travels in a link — it only signs requests. Leave blank when editing to '
    . 'keep the current secret.';
$string['peerurl'] = 'Site URL';
$string['peerurl_help'] = 'The peer site\'s address, for example https://peer.example.org. Its host is the one address '
    . 'allowed past this site\'s outgoing request block when importing that peer\'s share, so a peer on a private or '
    . 'internal network can be reached without weakening protection for any other host. A share link is only accepted if '
    . 'its host matches this URL.';
$string['pendingpublications'] = 'Backups being published';
$string['pluginname'] = 'Large file (URL or chunked upload)';
$string['pluginname_help'] = 'Bring in a file that is too big for a normal upload: fetch it from a URL server-side, '
    . 'or upload it from your computer in small chunks that are not limited by this server\'s PHP upload size.';
$string['privacy:chunkspath'] = 'Chunked uploads';
$string['privacy:metadata:core_files'] = 'A staged upload\'s bytes are streamed through the file API into a data export.';
$string['privacy:metadata:repository_largefile_chunks'] = 'Temporary records of large files uploaded in chunks before '
    . 'they are handed to the file picker.';
$string['privacy:metadata:repository_largefile_chunks:contextid'] = 'The context in which the file was uploaded.';
$string['privacy:metadata:repository_largefile_chunks:filename'] = 'The name of the uploaded file.';
$string['privacy:metadata:repository_largefile_chunks:lastmodified'] = 'The time the upload was last modified.';
$string['privacy:metadata:repository_largefile_chunks:userid'] = 'The user who uploaded the file.';
$string['privacy:metadata:repository_largefile_shares'] = 'Backups published to a peer as an encrypted share.';
$string['privacy:metadata:repository_largefile_shares:filename'] = 'The name of the shared file.';
$string['privacy:metadata:repository_largefile_shares:timecreated'] = 'The time the share was created.';
$string['privacy:metadata:repository_largefile_shares:userid'] = 'The user who created the share.';
$string['privacy:metadata:repository_largefile_transfers'] = 'Queued server-side transfers (URL or peer-share imports).';
$string['privacy:metadata:repository_largefile_transfers:error'] = 'The failure message, if the transfer failed.';
$string['privacy:metadata:repository_largefile_transfers:filename'] = 'The target file name of the transfer.';
$string['privacy:metadata:repository_largefile_transfers:payload'] = 'The transfer parameters, such as the source URL or share link.';
$string['privacy:metadata:repository_largefile_transfers:result'] = 'Where the finished transfer was saved.';
$string['privacy:metadata:repository_largefile_transfers:status'] = 'The current status of the transfer.';
$string['privacy:metadata:repository_largefile_transfers:timecreated'] = 'The time the transfer was queued.';
$string['privacy:metadata:repository_largefile_transfers:type'] = 'The kind of transfer (URL or peer-share import).';
$string['privacy:metadata:repository_largefile_transfers:userid'] = 'The user the transfer runs for.';
$string['revokeshare'] = 'Revoke';
$string['revokeshareconfirm'] = 'Revoke this share? The peer will no longer be able to download it.';
$string['selectuploaded'] = 'Select uploaded file';
$string['setting:chunksize'] = 'Chunk size (MB)';
$string['setting:chunksize_help'] = 'Size of each chunk sent to the server when uploading a large file, in megabytes. '
    . 'Lower this if large uploads fail — some web servers, reverse proxies and firewalls reject large request bodies.';
$string['setting:state0duration'] = 'Keep unused upload tokens for';
$string['setting:state0duration_help'] = 'How long an upload token that was generated but never used is kept before the '
    . 'cleanup task removes it.';
$string['setting:state1duration'] = 'Keep unfinished uploads for';
$string['setting:state1duration_help'] = 'How long a partially uploaded file is kept before the cleanup task removes it.';
$string['setting:state2duration'] = 'Keep completed uploads for';
$string['setting:state2duration_help'] = 'How long a completed upload that was never selected is kept before the cleanup '
    . 'task removes it.';
$string['settings'] = 'Chunked upload settings';
$string['sharecreated'] = 'Share created.';
$string['sharedeleted'] = 'Share revoked.';
$string['sharedownloadscol'] = 'Downloads';
$string['shareexpirescol'] = 'Expires';
$string['shareexpiry'] = 'Expires after';
$string['shareexpiry_help'] = 'How long the share can be downloaded before it stops working. Leave the "Enable" box '
    . 'unticked for a share that never expires.';
$string['sharefile'] = 'File to share';
$string['sharefilecol'] = 'File';
$string['sharelinkcol'] = 'Share link';
$string['sharelinkinfo'] = 'Give this link to the receiving site\'s administrator. They also need this site set up as a '
    . 'trusted peer there, using the same shared secret.';
$string['sharemaxdownloads'] = 'Maximum downloads';
$string['sharemaxdownloads_help'] = 'How many times the share may be downloaded before it stops working. Use 0 for no '
    . 'limit. A one-time share (1) is the safest default.';
$string['sharepeer'] = 'Share with peer';
$string['sharepublishbackground'] = 'Create in the background';
$string['sharepublishbackground_help'] = 'Recommended for large backups. The backup is encrypted on the server rather '
    . 'than in your browser, so creating the share is not cut off by a web server request timeout (a 504 error). Its '
    . 'share link appears on the Transfers page when it is ready.';
$string['sharequeued'] = 'The share is being created in the background. It will appear under "Backups being published" '
    . 'below, and its link in the shares list, when it is ready.';
$string['sharesheading'] = 'Published shares';
$string['sharingmanagement'] = 'Backup sharing';
$string['tabupload'] = 'Upload a large file';
$string['taburl'] = 'From a URL';
$string['task:processtransfers'] = 'Run queued large-file transfers';
$string['tokenexpired'] = 'The upload session has expired. Close and reopen the file picker to start again.';
$string['transfercancelled'] = 'Transfer cancelled.';
$string['transfereta'] = 'about {$a} left';
$string['transfernew'] = 'Queue a new transfer';
$string['transferoutcome'] = 'Outcome';
$string['transferprogress'] = 'Progress';
$string['transferqueue'] = 'Queued transfers';
$string['transferqueued'] = 'Transfer queued.';
$string['transferrate'] = 'avg {$a}/s';
$string['transferremoved'] = 'Transfer removed.';
$string['transferrunningfor'] = 'running for {$a}';
$string['transfers'] = 'Transfers';
$string['transfers_desc'] = 'Queue a server-side file transfer to run unattended — now or at a quiet time — and watch every '
    . 'transfer and chunked upload happening across the site.';
$string['transferscheduledpast'] = 'Choose a time in the future.';
$string['transferscheduledtime'] = 'Run at';
$string['transferstalled'] = 'no progress for {$a}';
$string['transferstatus'] = 'Status';
$string['transferstatus_cancelled'] = 'Cancelled';
$string['transferstatus_completed'] = 'Completed';
$string['transferstatus_failed'] = 'Failed';
$string['transferstatus_running'] = 'Running';
$string['transferstatus_scheduled'] = 'Scheduled';
$string['transfertype'] = 'Type';
$string['transfertypepublish'] = 'Backup share (publish)';
$string['transfertypeshare'] = 'Peer share import';
$string['transfertypeurl'] = 'URL import';
$string['transferurl'] = 'File URL';
$string['transferuser'] = 'User';
$string['transferwhen'] = 'When to run';
$string['transferwhen_help'] = 'Run the transfer as soon as the next scheduled run picks it up, or schedule it for a quiet '
    . 'time such as overnight. Either way it runs on the server, so you can close the page.';
$string['transferwhenat'] = 'At a scheduled time';
$string['transferwhennow'] = 'As soon as possible';
$string['unlimited'] = 'Unlimited';
$string['uploaded'] = 'File uploaded';
$string['uploading'] = 'Uploading…';
$string['uploadinstructions'] = 'The file is uploaded in small chunks, so PHP\'s per-request upload size does not apply. '
    . 'Keep this window open until the upload finishes.';
$string['uploadnotfinished'] = 'The upload did not finish.';
$string['uploadsinprogress'] = 'Uploads in progress';
$string['url'] = 'File URL';
$string['url_help'] = 'Paste a direct http(s) download link (for example a signed S3 link). The site fetches it on the '
    . 'server, so it is not limited by the browser upload size. The site upload limit still applies.';
