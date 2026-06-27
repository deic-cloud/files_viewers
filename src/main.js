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
		// CBZ comics (a ZIP of page images). CBR (RAR) needs a WASM decompressor
		// that NC's CSP blocks, so it isn't registered here yet.
		id: 'files_viewers-comic',
		group: 'documents',
		mimes: ['application/comicbook+zip'],
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
