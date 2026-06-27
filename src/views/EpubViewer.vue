<template>
	<div class="files-viewers-epub">
		<div v-if="error" class="files-viewers-msg">{{ error }}</div>
		<div ref="area" class="files-viewers-epub-area"></div>

		<!-- Table of contents -->
		<div v-if="tocOpen" class="files-viewers-epub-tocbg" @click.self="tocOpen = false">
			<nav class="files-viewers-epub-toc">
				<ul>
					<li v-for="(item, i) in toc" :key="i">
						<button class="files-viewers-epub-toc-item" @click="goTo(item.href)">{{ label(item) }}</button>
						<ul v-if="item.subitems && item.subitems.length">
							<li v-for="(sub, j) in item.subitems" :key="j">
								<button class="files-viewers-epub-toc-item is-sub" @click="goTo(sub.href)">{{ label(sub) }}</button>
							</li>
						</ul>
					</li>
				</ul>
			</nav>
		</div>

		<!-- Reader controls -->
		<div v-if="!error" class="files-viewers-epub-bar">
			<button v-if="toc.length"
				class="files-viewers-epub-ico"
				:class="{ active: tocOpen }"
				title="Table of contents"
				@click="tocOpen = !tocOpen">☰</button>
			<button class="files-viewers-epub-ico" :disabled="!ready" title="Previous page" @click="prev">‹</button>
			<span class="files-viewers-epub-loc">{{ locLabel || '…' }}</span>
			<button class="files-viewers-epub-ico" :disabled="!ready" title="Next page" @click="next">›</button>
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
		// book/rendition/lastLocation are deliberately NOT reactive — they are large
		// objects and Vue 2 would try to deep-observe them.
		return {
			error: '',
			ready: false,
			done: false,
			locLabel: '',
			toc: [],
			tocOpen: false,
		}
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
				// two-page spread on wide windows, auto-collapsing to one on narrow.
				spread: 'auto',
				// sandbox the section iframes: the e-book can't run scripts
				allowScriptedContent: false,
				// Use epub.js's default render (srcdoc): document.write (method:'write')
				// produces a no-DOCTYPE Quirks-Mode document, which broke layout so the
				// view never settled on the cover (blank page). srcdoc renders in
				// Standards mode. NC's CSP allows it (no frame-src violation observed).
			})

			// Fit images (notably full-page covers) to one page. In Standards mode the
			// definite vh cap + height:auto override the book's fragile inline height
			// without collapsing the image (injected <style>, allowed by 'unsafe-inline').
			this.rendition.themes.default({
				'img, svg': {
					height: 'auto !important',
					'max-width': '100% !important',
					'max-height': '95vh !important',
				},
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
				} else if (e.key === 'Escape' && this.tocOpen) {
					this.tocOpen = false
				}
			}
			document.addEventListener('keyup', this.keyHandler)

			// Table of contents for quick navigation.
			this.book.loaded.navigation
				.then((nav) => { this.toc = (nav && Array.isArray(nav.toc)) ? nav.toc : [] })
				.catch(() => {})

			await this.rendition.display()
			this.ready = true
			this.doneOnce()

			// NOTE: we deliberately do NOT call book.locations.generate() for a
			// reading-% readout. generate() walks every section (section.load +
			// section.unload), and unloading the section the rendition is currently
			// showing blanks the live view — which opened the book on a blank page
			// and churned through the front matter. It was also what made the viewer
			// sluggish. The page indicator falls back to in-chapter "i/n".
		} catch (e) {
			this.error = 'Could not open e-book: ' + (e && e.message ? e.message : e)
		} finally {
			// Safety net — idempotent; the success path already dismissed the spinner
			// once the first page was displayed.
			this.doneOnce()
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
		label(item) {
			return (item && item.label ? String(item.label).trim() : '') || '—'
		},
		prev() {
			if (this.rendition) { this.rendition.prev() }
		},
		next() {
			if (this.rendition) { this.rendition.next() }
		},
		goTo(href) {
			if (this.rendition && href) { this.rendition.display(href) }
			this.tocOpen = false
		},
		doneOnce() {
			if (!this.done) {
				this.done = true
				this.doneLoading()
			}
		},
		updateLocation(location) {
			const start = location && location.start ? location.start : null
			if (!start) {
				return
			}
			const parts = []
			// Rough overall progress from spine position. (A precise page-based %
			// needs book.locations.generate(), which reloads/unloads every section
			// and blanks the live view — so we avoid it.)
			const spine = this.book && this.book.spine
			const len = spine && (spine.length || (spine.spineItems && spine.spineItems.length))
			if (len && typeof start.index === 'number') {
				parts.push(Math.round((start.index / Math.max(1, len - 1)) * 100) + '%')
			}
			// page within the current chapter
			if (start.displayed && start.displayed.total) {
				parts.push(start.displayed.page + '/' + start.displayed.total)
			}
			this.locLabel = parts.join(' · ')
		},
	},
}
</script>

<style scoped>
.files-viewers-epub {
	position: relative;
	box-sizing: border-box;
	width: 100%;
	height: 100%;
	display: flex;
	flex-direction: column;
	/* The reading surface stays a white "page" regardless of the NC theme — the
	   book's pages are transparent and its text is dark, so a themed (dark) area
	   would show through as a black page with invisible text. The controls below
	   still follow the NC theme. */
	background: #fff;
}

.files-viewers-epub-area {
	flex: 1 1 auto;
	min-height: 0;
	background: #fff;
}

/* compact control bar, themed to match the NC UI */
.files-viewers-epub-bar {
	flex: 0 0 auto;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	height: 36px;
	padding: 0 8px;
	background: var(--color-main-background, #fff);
	border-top: 1px solid var(--color-border, #e1e4e8);
	color: var(--color-main-text, #222);
}

.files-viewers-epub-ico {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 34px;
	height: 28px;
	font-size: 18px;
	line-height: 1;
	border: none;
	border-radius: 6px;
	background: transparent;
	color: var(--color-main-text, #222);
	cursor: pointer;
}

.files-viewers-epub-ico:hover {
	background: var(--color-background-hover, #ececec);
}

.files-viewers-epub-ico:disabled {
	opacity: 0.35;
	cursor: default;
	background: transparent;
}

.files-viewers-epub-ico.active {
	background: var(--color-background-dark, #dcdcdc);
}

.files-viewers-epub-loc {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 110px;
	height: 28px;
	padding: 0 10px;
	font-size: 13px;
	line-height: 1;
	color: var(--color-text-maxcontrast, #767676);
}

/* table-of-contents overlay */
.files-viewers-epub-tocbg {
	position: absolute;
	inset: 0;
	z-index: 5;
	background: rgba(0, 0, 0, 0.2);
}

.files-viewers-epub-toc {
	position: absolute;
	left: 0;
	top: 0;
	bottom: 0;
	width: 320px;
	max-width: 80%;
	overflow-y: auto;
	padding: 8px 0;
	background: var(--color-main-background, #fff);
	border-right: 1px solid var(--color-border, #e1e4e8);
	box-shadow: 2px 0 8px rgba(0, 0, 0, 0.15);
}

.files-viewers-epub-toc ul {
	list-style: none;
	margin: 0;
	padding: 0;
}

.files-viewers-epub-toc-item {
	display: block;
	width: 100%;
	text-align: left;
	padding: 7px 16px;
	border: none;
	background: transparent;
	color: var(--color-main-text, #222);
	font-size: 14px;
	cursor: pointer;
}

.files-viewers-epub-toc-item:hover {
	background: var(--color-background-hover, #ececec);
}

.files-viewers-epub-toc-item.is-sub {
	padding-left: 32px;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #767676);
}

.files-viewers-msg {
	padding: 24px 16px;
	color: var(--color-error-text, #8a0000);
}
</style>
