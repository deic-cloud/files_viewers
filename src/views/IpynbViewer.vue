<template>
	<div class="files-viewers-ipynb">
		<div v-if="error" class="files-viewers-msg">{{ error }}</div>
		<div ref="nb" class="files-viewers-nb"></div>
	</div>
</template>

<script>
import nb from 'notebookjs'
import { marked } from 'marked'
import hljs from 'highlight.js/lib/common'
import DOMPurify from 'dompurify'
import 'highlight.js/styles/github.css'

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
nb.highlighter = (text, pre, code, lang) => {
	let html = null
	try {
		html = (lang && hljs.getLanguage(lang))
			? hljs.highlight(text, { language: lang, ignoreIllegals: true }).value
			: hljs.highlightAuto(text).value
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

export default {
	name: 'IpynbViewer',

	data() {
		return { error: '' }
	},

	computed: {
		src() {
			return this.source ?? this.davPath
		},
	},

	async mounted() {
		try {
			const res = await fetch(this.src)
			if (!res.ok) {
				throw new Error('HTTP ' + res.status)
			}
			const json = JSON.parse(await res.text())
			const rendered = nb.parse(json).render()
			// External images are already turned into links upstream (nb.markdown /
			// nb.sanitizer), so nothing cross-origin is fetched. DOMPurify still runs
			// here to guard against XSS in cell outputs (the CSP also blocks scripts).
			this.$refs.nb.innerHTML = DOMPurify.sanitize(rendered.outerHTML, { ADD_TAGS: ['style'] })
		} catch (e) {
			this.error = 'Could not render notebook: ' + (e && e.message ? e.message : e)
		} finally {
			// tell the Viewer the content is ready (Mime mixin)
			this.doneLoading()
		}
	},
}
</script>

<style scoped>
.files-viewers-ipynb {
	box-sizing: border-box;
	width: 100%;
	height: 100%;
	margin: 0 auto;
	padding: 24px 16px;
	overflow: auto;
}

.files-viewers-msg {
	max-width: 980px;
	margin: 0 auto;
	color: var(--color-error-text, #8a0000);
}
</style>

<style>
/* Rendered notebook. NOT scoped (inserted via innerHTML) but kept under
   .files-viewers-nb so it can't leak. Rendered as a light "paper" page so it
   reads consistently regardless of the NC theme (matches the github code theme). */
.files-viewers-nb {
	max-width: 980px;
	margin: 0 auto;
	padding: 24px 28px;
	background: #fff;
	color: #1a1a1a;
	border-radius: 8px;
	line-height: 1.5;
}

.files-viewers-nb .nb-cell {
	margin-bottom: 14px;
}

.files-viewers-nb pre,
.files-viewers-nb code {
	font-family: monospace;
}

.files-viewers-nb .nb-input,
.files-viewers-nb .nb-output {
	padding: 8px 12px;
	border-radius: 4px;
	overflow-x: auto;
}

.files-viewers-nb .nb-input {
	background: #f6f8fa;
	border: 1px solid #e1e4e8;
}

.files-viewers-nb .nb-stderr,
.files-viewers-nb .nb-error {
	background: #ffecec;
	color: #8a0000;
	white-space: pre-wrap;
}

.files-viewers-nb .nb-stdout,
.files-viewers-nb .nb-text-output {
	white-space: pre-wrap;
}

.files-viewers-nb img,
.files-viewers-nb svg {
	max-width: 100%;
	height: auto;
}

/* placeholder shown in place of a CSP-blocked external image */
.files-viewers-nb .files-viewers-extimg {
	display: inline-block;
	margin: 2px 0;
	padding: 2px 8px;
	border: 1px dashed #c8ccd0;
	border-radius: 4px;
	color: #0b5cad;
	font-size: 13px;
	text-decoration: none;
	word-break: break-all;
}

.files-viewers-nb table {
	border-collapse: collapse;
	margin: 4px 0;
}

.files-viewers-nb th,
.files-viewers-nb td {
	border: 1px solid #e1e4e8;
	padding: 2px 8px;
}

.files-viewers-nb .nb-prompt {
	color: #999;
	font-family: monospace;
	font-size: 12px;
}
</style>
