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

// Service worker for the Large file repository. Its only job is to own the
// Background Fetch uploads registered by the upload dialogue so they continue
// after the page is closed, and to tell any open page when one finishes so it can
// refresh the file picker. The chunk requests are ordinary same-origin POSTs the
// server handles on its own; this worker does not intercept or proxy them.

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

// A background upload finished: nudge any open page to refresh its picker listing.
self.addEventListener('backgroundfetchsuccess', (event) => {
    event.waitUntil((async () => {
        const pages = await self.clients.matchAll({includeUncontrolled: true});
        pages.forEach((page) => page.postMessage({
            type: 'repository_largefile_bgcomplete',
            id: event.registration.id,
        }));
    })());
});

// A background upload failed (network, quota, server error): leave the partial
// token for the scheduled cleanup task to remove; notify open pages so they can
// tell the user.
self.addEventListener('backgroundfetchfail', (event) => {
    event.waitUntil((async () => {
        const pages = await self.clients.matchAll({includeUncontrolled: true});
        pages.forEach((page) => page.postMessage({
            type: 'repository_largefile_bgfail',
            id: event.registration.id,
        }));
    })());
});

// The user tapped the browser's background-fetch notification: focus an open page,
// or open the site root if none is available.
self.addEventListener('backgroundfetchclick', (event) => {
    event.waitUntil((async () => {
        const pages = await self.clients.matchAll({type: 'window'});
        if (pages.length) {
            return pages[0].focus();
        }
        // This worker lives at <wwwroot>/repository/largefile/sw.js, so '../../'
        // resolves to the Moodle root (wwwroot) rather than the plugin directory,
        // which has no landing page.
        return self.clients.openWindow(new URL('../../', self.location).href);
    })());
});
