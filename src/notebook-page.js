/**
 * The public notebook page (NotebookController): fetches the shared notebook from
 * the share's public WebDAV and renders it with the Viewer handler's code.
 */
import { renderNotebook } from './notebook-render.js'
import './notebook-page.css'

const root = document.getElementById('files-viewers-notebook-page')
if (root && root.dataset.src) {
	const out = root.querySelector('.files-viewers-nb')
	const msg = root.querySelector('.files-viewers-msg')
	const loading = root.querySelector('.files-viewers-loading')
	const show = (text) => {
		if (loading) loading.hidden = true
		msg.textContent = text
		msg.hidden = false
	}
	fetch(root.dataset.src, { credentials: 'same-origin' })
		.then((r) => {
			if (r.status === 401 || r.status === 403) {
				throw new Error(root.dataset.msgProtected)
			}
			if (!r.ok) {
				throw new Error(root.dataset.msgMissing)
			}
			return r.text()
		})
		.then((text) => {
			out.innerHTML = renderNotebook(JSON.parse(text))
			if (loading) loading.hidden = true
		})
		.catch((e) => show(e && e.message ? e.message : root.dataset.msgMissing))
}
