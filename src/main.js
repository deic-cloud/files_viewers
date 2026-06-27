/**
 * files_viewers — registers document-viewer handlers with the core Viewer app.
 *
 * The Viewer drains window._oca_viewer_handlers at init (each entry is a handler
 * object passed to registerHandler). We push there if the Viewer isn't up yet,
 * or register directly if it already is — covering both script-load orders.
 */
import IpynbViewer from './views/IpynbViewer.vue'
import EpubViewer from './views/EpubViewer.vue'
import ComicViewer from './views/ComicViewer.vue'

const handlers = [
	{
		id: 'files_viewers-ipynb',
		group: 'documents',
		mimes: ['application/x-ipynb+json'],
		component: IpynbViewer,
		theme: 'default',
	},
	{
		id: 'files_viewers-epub',
		group: 'documents',
		mimes: ['application/epub+zip'],
		component: EpubViewer,
		theme: 'default',
	},
	{
		// CBZ (zip) + CBR (rar) comics. CBZ via JSZip; CBR via a pure-JS unrar
		// (RAR ≤ 3) on the main thread — RAR5 isn't supported (no non-WASM decoder)
		// and is reported gracefully.
		id: 'files_viewers-comic',
		group: 'documents',
		mimes: ['application/comicbook+zip', 'application/comicbook+rar'],
		component: ComicViewer,
		theme: 'default',
	},
]

window._oca_viewer_handlers = window._oca_viewer_handlers || []
handlers.forEach((handler) => {
	if (window.OCA && window.OCA.Viewer && typeof window.OCA.Viewer.registerHandler === 'function') {
		window.OCA.Viewer.registerHandler(handler)
	} else {
		window._oca_viewer_handlers.push(handler)
	}
})
