/**
 * Notebook rendering shared by the Viewer handler (IpynbViewer.vue) and the public
 * notebook page (notebook-page.js): notebookjs + marked + highlight.js, sanitised
 * with DOMPurify, external images turned into links (CSP), styles in notebook.css.
 */
import nb from 'notebookjs'
import { marked } from 'marked'
import hljs from 'highlight.js/lib/common'
import DOMPurify from 'dompurify'
import 'highlight.js/styles/github.css'
import './notebook.css'

// Replace cross-origin <img> with a click-through link, operating on an HTML
// STRING. notebookjs builds live DOM during render() and the browser fetches
// any <img src> the instant the element exists (even detached) — which NC's CSP
// (img-src 'self' data: blob:) blocks, logging an error. So we must neutralise
// the URL BEFORE notebookjs creates the element, not after. DOMParser yields an
// inert document (no browsing context), so parsing here triggers no network
// request. data:/blob: and same-origin images are left alone (CSP allows them;
// matplotlib plots are data: URIs).
function neutralizeExternalImages(html) {
	const origin = window.location.origin
	const doc = new DOMParser().parseFromString(String(html), 'text/html')
	doc.querySelectorAll('img').forEach((img) => {
		const src = img.getAttribute('src') || ''
		if (!/^(https?:)?\/\//i.test(src)) {
			return
		}
		let external = true
		try {
			external = new URL(src, origin).origin !== origin
		} catch (e) {
			external = true
		}
		if (!external) {
			return
		}
		let label = img.getAttribute('alt') || ''
		if (!label) {
			try {
				const u = new URL(src, origin)
				label = u.pathname.split('/').filter(Boolean).pop() || u.hostname
			} catch (e) {
				label = src
			}
		}
		const a = doc.createElement('a')
		a.setAttribute('href', src)
		a.setAttribute('target', '_blank')
		a.setAttribute('rel', 'noopener noreferrer')
		a.setAttribute('class', 'files-viewers-extimg')
		a.textContent = '🔗 external image: ' + label + ' ↗'
		img.replaceWith(a)
	})
	return doc.body.innerHTML
}

// notebookjs delegates markdown + code highlighting to these (module-level, set once).
// Both hooks run on the HTML string before notebookjs sets innerHTML, so external
// images are turned into links before anything can be fetched.
nb.markdown = (md) => neutralizeExternalImages(marked.parse(md || ''))
nb.sanitizer = (html) => neutralizeExternalImages(html)
// notebookjs hands over text it has already escaped (< and > only), and also
// calls this for plain-text outputs (no <code> element). Only code cells are
// highlighted, and hljs gets the raw source back, or it escapes a second time
// ("-&gt;" on screen).
nb.highlighter = (text, pre, code, lang) => {
	if (!code) {
		return text
	}
	const raw = text.replace(/&lt;/g, '<').replace(/&gt;/g, '>')
	let html = null
	try {
		html = (lang && hljs.getLanguage(lang))
			? hljs.highlight(raw, { language: lang, ignoreIllegals: true }).value
			: hljs.highlightAuto(raw).value
	} catch (e) {
		html = null
	}
	if (html != null) {
		if (code) {
			code.innerHTML = html
		}
		return html
	}
	return text
}

/** The notebook (parsed .ipynb JSON) as sanitised HTML, for a .files-viewers-nb element. */
export function renderNotebook(json) {
	const rendered = nb.parse(json).render()
	// External images are already turned into links upstream (nb.markdown /
	// nb.sanitizer), so nothing cross-origin is fetched. DOMPurify still runs
	// here to guard against XSS in cell outputs (the CSP also blocks scripts).
	return DOMPurify.sanitize(rendered.outerHTML, { ADD_TAGS: ['style'] })
}
