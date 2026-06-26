<template>
	<div class="files-viewers-epub">
		<div v-if="error" class="files-viewers-msg">{{ error }}</div>
		<div ref="area" class="files-viewers-epub-area"></div>
		<div v-if="!error" class="files-viewers-epub-nav">
			<button class="files-viewers-epub-btn"
				:disabled="!ready"
				title="Previous page"
				@click="prev">‹</button>
			<span class="files-viewers-epub-loc">{{ locLabel }}</span>
			<button class="files-viewers-epub-btn"
				:disabled="!ready"
				title="Next page"
				@click="next">›</button>
		</div>
	</div>
</template>

<script>
import ePub from 'epubjs'

// epub.js's archive readers (getText/getBase64) strip the FIRST character of the
// path — they assume a leading "/". So any path handed to them must keep its
// leading slash. Resolve an href (relative to a section or stylesheet) to that
// form, dropping any origin epub.js may have prepended.
function archivePath(href, baseFile) {
	const base = 'http://epub.local/'
		+ String(baseFile || '').replace(/^https?:\/\/[^/]+/i, '').replace(/^\/+/, '')
	return new URL(href, base).pathname // e.g. "/OEBPS/pgepub.css" (leading slash kept)
}

// Inline a stylesheet's url(...) assets (embedded fonts, images) as data: URIs
// read from the epub archive, so they load under NC's CSP (font-src/img-src both
// allow data:). Returns the rewritten CSS text.
async function inlineCssAssets(css, cssPath, archive) {
	const dir = cssPath.replace(/[^/]*$/, '') // directory of the stylesheet
	const re = /url\(\s*(['"]?)([^'")]+)\1\s*\)/gi
	const map = new Map()
	let m
	while ((m = re.exec(css)) !== null) {
		const raw = m[2].trim()
		if (raw && !/^(data:|https?:|blob:|#)/i.test(raw)) {
			map.set(raw, null)
		}
	}
	await Promise.all(Array.from(map.keys()).map(async (raw) => {
		try {
			const p = decodeURIComponent(new URL(raw, 'http://epub.local' + dir).pathname)
			const dataUrl = await archive.getBase64(p) // returns "data:<mime>;base64,…"
			if (dataUrl) { map.set(raw, dataUrl) }
		} catch (e) { /* leave the url as-is */ }
	}))
	return css.replace(re, (full, q, raw) => {
		const data = map.get(raw.trim())
		return data ? 'url("' + data + '")' : full
	})
}

export default {
	name: 'EpubViewer',

	data() {
		// book/rendition are deliberately NOT reactive — they are large objects
		// and Vue 2 would try to deep-observe them.
		return { error: '', ready: false, locLabel: '' }
	},

	computed: {
		src() {
			return this.source ?? this.davPath
		},
	},

	async mounted() {
		try {
			// epub.js can fetch by URL itself, but going through fetch() keeps us on
			// the same code path as the other viewers (and works on public shares).
			const res = await fetch(this.src)
			if (!res.ok) {
				throw new Error('HTTP ' + res.status)
			}
			const buf = await res.arrayBuffer()

			// Load everything through the in-memory archive. The book is fully
			// self-contained, so it should never touch the network — but epub.js has
			// a path-resolution quirk that can re-resolve the package path to a
			// doubled directory (…/OEBPS/OEBPS/content.opf) and fire a spurious HTTP
			// request; NC answers with a 404 HTML page, which epub.js then tries to
			// parse as XML ("not well-formed"). Routing requests through the archive
			// makes a mis-resolved path miss in memory instead of hitting the network
			// (and for an archived book the network path never succeeded anyway — the
			// files live in the zip, not on the server). book.load already reads the
			// archive directly when archived; this only catches the stray fallbacks.
			let bookRef = null
			const archiveOnlyRequest = (url, type) => {
				if (bookRef && bookRef.archive) {
					return bookRef.archive.request(url, type)
				}
				return Promise.reject(new Error('files_viewers: epub network request blocked'))
			}
			this.book = ePub(buf, { requestMethod: archiveOnlyRequest })
			bookRef = this.book

			// epub.js injects a <base href> into each section so relative URLs
			// resolve. NC's CSP is base-uri 'none', which blocks that tag and logs a
			// violation. epub.js already rewrites the section's resources to absolute
			// blob: URLs, so the <base> is vestigial here — strip it in a spine
			// content hook (runs on the section DOM before it's serialised into the
			// iframe), so no <base> is ever written and the violation never fires.
			// The book's CSS, fonts and images are bundled inside the .epub; epub.js
			// would serve them as blob: URLs, which NC's CSP blocks. Inline them
			// instead: read each stylesheet from the archive into a <style> element
			// (allowed by 'unsafe-inline') and rewrite its url(...) assets to data:
			// URIs (allowed by font-src/img-src). CSP-clean, keeps full styling +
			// embedded fonts. Falls back to dropping a link that can't be resolved.
			this.book.spine.hooks.content.register(async (doc, section) => {
				try {
					// <base href>: blocked by base-uri 'none' (vestigial here).
					doc.querySelectorAll('base').forEach((b) => b.remove())
				} catch (e) { /* noop */ }

				const baseFile = section.canonical || section.url || section.href || ''
				const archive = this.book.archive
				const links = Array.from(doc.querySelectorAll('link[rel="stylesheet"]'))
				for (const link of links) {
					const href = link.getAttribute('href') || ''
					try {
						let css = null
						let cssPath = null
						if (/^(blob:|https?:)/i.test(href)) {
							const r = await fetch(href)
							if (r.ok) { css = await r.text() }
						} else if (href && archive) {
							cssPath = archivePath(href, baseFile)
							css = await archive.getText(cssPath)
						}
						if (css) {
							if (cssPath && archive) {
								css = await inlineCssAssets(css, cssPath, archive)
							}
							const style = doc.createElement('style')
							style.textContent = css
							link.parentNode.replaceChild(style, link)
						} else {
							link.remove()
						}
					} catch (e) {
						try { link.remove() } catch (e2) { /* noop */ }
					}
				}
			})

			this.rendition = this.book.renderTo(this.$refs.area, {
				width: '100%',
				height: '100%',
				flow: 'paginated',
				spread: 'auto',
				// sandbox the section iframes: the e-book can't run scripts
				allowScriptedContent: false,
				// NC's CSP is default-src 'none' with no frame-src, which blocks the
				// default "srcdoc" iframe. Writing into an about:blank iframe instead
				// renders without needing a frame-src allowance (CSP-clean). The book's
				// own CSS/fonts (loaded as blob:) may still be stripped by style-src;
				// text + images (blob: is allowed by img-src) render.
				method: 'write',
			})

			this.rendition.on('relocated', (location) => {
				this.ready = true
				this.updateLocation(location)
			})

			this.keyHandler = (e) => {
				if (e.key === 'ArrowLeft') {
					this.prev()
				} else if (e.key === 'ArrowRight') {
					this.next()
				}
			}
			document.addEventListener('keyup', this.keyHandler)

			await this.rendition.display()
			this.ready = true

			// Generate locations in the background so we can show reading progress.
			// Non-blocking and best-effort — a failure here must not break reading.
			this.book.ready
				.then(() => this.book.locations.generate(1600))
				.then(() => this.updateLocation(this.rendition.currentLocation()))
				.catch(() => {})
		} catch (e) {
			this.error = 'Could not open e-book: ' + (e && e.message ? e.message : e)
		} finally {
			// tell the Viewer the content is ready (Mime mixin)
			this.doneLoading()
		}
	},

	beforeDestroy() {
		if (this.keyHandler) {
			document.removeEventListener('keyup', this.keyHandler)
		}
		try { if (this.rendition) { this.rendition.destroy() } } catch (e) { /* noop */ }
		try { if (this.book) { this.book.destroy() } } catch (e) { /* noop */ }
	},

	methods: {
		prev() {
			if (this.rendition) {
				this.rendition.prev()
			}
		},
		next() {
			if (this.rendition) {
				this.rendition.next()
			}
		},
		updateLocation(location) {
			const start = location && location.start ? location.start : null
			if (!start) {
				return
			}
			try {
				if (this.book.locations && this.book.locations.length()) {
					const pct = Math.round(this.book.locations.percentageFromCfi(start.cfi) * 100)
					this.locLabel = pct + '%'
					return
				}
			} catch (e) { /* locations not ready yet */ }
			// fall back to the chapter/page label epub.js provides
			this.locLabel = start.displayed ? (start.displayed.page + ' / ' + start.displayed.total) : ''
		},
	},
}
</script>

<style scoped>
.files-viewers-epub {
	box-sizing: border-box;
	width: 100%;
	height: 100%;
	display: flex;
	flex-direction: column;
	background: #fff;
}

.files-viewers-epub-area {
	flex: 1 1 auto;
	min-height: 0;
}

.files-viewers-epub-nav {
	flex: 0 0 auto;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 20px;
	padding: 8px;
	background: #f6f8fa;
	border-top: 1px solid #e1e4e8;
}

.files-viewers-epub-btn {
	min-width: 44px;
	height: 32px;
	font-size: 20px;
	line-height: 1;
	border: 1px solid #d0d4d8;
	border-radius: 6px;
	background: #fff;
	color: #1a1a1a;
	cursor: pointer;
}

.files-viewers-epub-btn:disabled {
	opacity: 0.4;
	cursor: default;
}

.files-viewers-epub-loc {
	min-width: 64px;
	text-align: center;
	color: #555;
	font-size: 13px;
}

.files-viewers-msg {
	padding: 24px 16px;
	color: var(--color-error-text, #8a0000);
}
</style>
