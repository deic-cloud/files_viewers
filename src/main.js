/**
 * files_viewers — registers document-viewer handlers with the core Viewer app.
 *
 * The Viewer drains window._oca_viewer_handlers at init (each entry is a handler
 * object passed to registerHandler). We push there if the Viewer isn't up yet,
 * or register directly if it already is — covering both script-load orders.
 */
import IpynbViewer from './views/IpynbViewer.vue'

const handlers = [
	{
		id: 'files_viewers-ipynb',
		group: 'documents',
		mimes: ['application/x-ipynb+json'],
		component: IpynbViewer,
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
