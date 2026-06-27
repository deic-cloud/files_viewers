<template>
	<div class="files-viewers-epub">
		<div v-if="error" class="files-viewers-msg">{{ error }}</div>
		<div ref="area" class="files-viewers-epub-area"></div>

		<!-- Table of contents -->
		<div v-if="tocOpen" class="files-viewers-epub-tocbg" @click.self="tocOpen = false">
			<nav class="files-viewers-epub-toc">
				<div class="files-viewers-epub-toc-head">
					<span class="files-viewers-epub-toc-title">Contents</span>
					<button class="files-viewers-epub-toc-close" title="Close contents" @click="tocOpen = false">✕</button>
				</div>
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
			<span class="files-viewers-epub-readout">
				<span v-if="chapterLabel" class="files-viewers-epub-chap">{{ chapterLabel }}</span>
				<button class="files-viewers-epub-pages"
					title="Click to switch between page in chapter and page in book"
					@click="togglePageMode">{{ pagesLabel || '…' }}</button>
				<span v-if="pctLabel" class="files-viewers-epub-pct">{{ pctLabel }}</span>
			</span>
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
			chapterLabel: '',
			pagesLabel: '',
			pctLabel: '',
			toc: [],
			tocOpen: false,
			pageMode: 'chapter', // 'chapter' (j/m) | 'book' (i/n) — toggled by clicking j/m
			locationsReady: false,
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
				// Skip the heavy per-section work while the locations page-map is being
				// generated in the background — generate() only measures text, so font/CSS
				// inlining ×(all sections) would just be slow. Applies to rendered sections.
				if (this._generating) { return }
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

			// Theme the book to match the NC UI: in dark mode the page becomes
			// light-on-dark, in light mode dark-on-light. The section iframe is a
			// separate document and can't see NC's CSS variables, so read the concrete
			// computed colours from the host page and inject them. Plus fit images
			// (covers) to one page (definite vh cap + height:auto avoids the collapse).
			const cs = getComputedStyle(document.body)
			const ncBg = (cs.getPropertyValue('--color-main-background') || '').trim() || '#ffffff'
			const ncFg = (cs.getPropertyValue('--color-main-text') || '').trim() || '#1a1a1a'
			this.rendition.themes.default({
				body: {
					background: ncBg + ' !important',
					color: ncFg + ' !important',
				},
				'img, svg': {
					height: 'auto !important',
					'max-width': '100% !important',
					'max-height': '95vh !important',
				},
			})

			this.rendition.on('relocated', (location) => {
				this.ready = true
				this.updateLocation(location)
				// Remember where the reader is, so re-opening this book resumes here
				// (localStorage only — no temp files, no DB).
				if (location && location.start && location.start.cfi) {
					this.savePosition(location.start.cfi)
				}
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
				.then((nav) => {
					this.toc = (nav && Array.isArray(nav.toc)) ? nav.toc : []
					this._chapterMap = this.buildChapterMap(this.toc)
				})
				.catch(() => {})

			// Resume at the last-read page for this book (localStorage). Fall back to
			// the start (cover) if there's no saved position or the CFI is stale.
			const savedCfi = this.loadPosition()
			let opened = false
			if (savedCfi) {
				try { await this.rendition.display(savedCfi); opened = true } catch (e) { /* stale CFI */ }
			}
			if (!opened) { await this.rendition.display() }
			this.ready = true
			this.doneOnce()

			// NOTE: we deliberately do NOT call book.locations.generate() for a precise
			// book-page count — it walks every section (load + unload), blanks the live
			// view and is slow. "Book" progress is approximated from spine position.
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
			// Keep the TOC open after jumping — it closes only via the ✕ or a click
			// in the book (the backdrop), so you can browse chapters freely.
		},
		doneOnce() {
			if (!this.done) {
				this.done = true
				this.doneLoading()
			}
		},
		buildChapterMap(toc) {
			// flat map: section basename -> chapter title, for the readout label
			const map = {}
			const walk = (items) => {
				for (const it of (items || [])) {
					if (it && it.href) {
						const key = it.href.split('#')[0].split('/').pop()
						if (key && !(key in map)) { map[key] = String(it.label || '').trim() }
					}
					if (it && it.subitems) { walk(it.subitems) }
				}
			}
			walk(toc)
			return map
		},
		togglePageMode() {
			this.pageMode = this.pageMode === 'book' ? 'chapter' : 'book'
			// Book-page counts need the locations page-map. Build it ON DEMAND (only
			// when the reader first asks for book pages) and in the background — never
			// on open — so normal reading stays fast and unaffected.
			if (this.pageMode === 'book' && !this.locationsReady && !this._generating) {
				this.generateLocations()
			}
			if (this.lastLocation) { this.updateLocation(this.lastLocation) }
		},
		generateLocations() {
			this._generating = true
			this.book.ready
				.then(() => this.book.locations.generate(1600))
				.then(() => {
					this._generating = false
					this.locationsReady = true
					if (this.lastLocation) { this.updateLocation(this.lastLocation) }
				})
				.catch(() => { this._generating = false })
		},
		updateLocation(location) {
			this.lastLocation = location
			const start = location && location.start ? location.start : null
			if (!start) {
				return
			}
			// chapter title (if the TOC has one for this section)
			const base = start.href ? start.href.split('#')[0].split('/').pop() : ''
			this.chapterLabel = (this._chapterMap && this._chapterMap[base]) || ''

			// j/m — page within the current chapter (always available)
			const j = start.displayed ? start.displayed.page : null
			const m = start.displayed ? start.displayed.total : null

			if (this.pageMode === 'book') {
				if (this.locationsReady) {
					try {
						const i = (this.book.locations.locationFromCfi(start.cfi) || 0) + 1
						const n = this.book.locations.length()
						this.pagesLabel = i + '/' + n
					} catch (e) { this.pagesLabel = (j && m) ? (j + '/' + m) : '' }
				} else {
					this.pagesLabel = '…' // page-map still being built
				}
			} else {
				this.pagesLabel = (j && m) ? (j + '/' + m) : ''
			}

			// x% — exact once the page-map exists, otherwise a spine-based estimate
			let pct = null
			let approx = false
			if (this.locationsReady) {
				try { pct = Math.round(this.book.locations.percentageFromCfi(start.cfi) * 100) } catch (e) { pct = null }
			}
			if (pct === null || isNaN(pct)) {
				const spine = this.book && this.book.spine
				const len = spine && (spine.length || (spine.spineItems && spine.spineItems.length))
				if (len && typeof start.index === 'number') {
					pct = Math.round((start.index / Math.max(1, len - 1)) * 100)
					approx = true
				}
			}
			this.pctLabel = (pct === null || isNaN(pct)) ? '' : ((approx ? '~' : '') + pct + '%')
		},
		// --- last-read position, per book, in localStorage (no temp files / DB) ---
		storageKey() {
			return 'files_viewers:epub:' + (this.fileid || this.filename || this.src || '')
		},
		loadPosition() {
			try { return window.localStorage.getItem(this.storageKey()) || null } catch (e) { return null }
		},
		savePosition(cfi) {
			try { window.localStorage.setItem(this.storageKey(), cfi) } catch (e) { /* quota/private mode */ }
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
	/* Follow the NC theme. The book's <body> is given the same concrete colours
	   via rendition.themes (see mounted), so the page and this surround match —
	   light-on-dark in dark mode, dark-on-light in light mode. */
	background: var(--color-main-background, #fff);
}

.files-viewers-epub-area {
	flex: 1 1 auto;
	min-height: 0;
	background: var(--color-main-background, #fff);
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
	/* !important: a bare <button> otherwise inherits NC core's tinted button
	   colour, which doesn't follow the light/dark theme. */
	color: var(--color-main-text, #222) !important;
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

.files-viewers-epub-readout {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	max-width: 60vw;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #767676);
}

.files-viewers-epub-chap {
	max-width: 38vw;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.files-viewers-epub-pages {
	height: 28px;
	padding: 2px 8px;
	border: none;
	border-radius: 6px;
	background: transparent;
	font-size: 13px;
	font-weight: normal;
	line-height: 1;
	/* !important: a bare <button> otherwise takes NC core's non-theme colour. */
	color: var(--color-main-text, #222) !important;
	cursor: pointer;
}

.files-viewers-epub-pages:hover {
	background: var(--color-background-hover, #ececec);
}

/* Suppress NC core's blue focus/active button background + glow on our toolbar
   buttons (clicking the page readout shouldn't flash blue). */
.files-viewers-epub-ico:focus,
.files-viewers-epub-ico:focus-visible,
.files-viewers-epub-ico:active,
.files-viewers-epub-pages:focus,
.files-viewers-epub-pages:focus-visible,
.files-viewers-epub-pages:active {
	background: transparent !important;
	box-shadow: none !important;
}

.files-viewers-epub-pct {
	color: var(--color-text-maxcontrast, #767676);
}

/* table-of-contents overlay — transparent backdrop so the book stays visible;
   it only catches clicks to close the panel. */
.files-viewers-epub-tocbg {
	position: absolute;
	inset: 0;
	z-index: 5;
	background: transparent;
}

.files-viewers-epub-toc {
	position: absolute;
	left: 0;
	top: 0;
	bottom: 0;
	width: 320px;
	max-width: 80%;
	overflow-y: auto;
	/* frosted, semi-transparent panel so the book shows through behind it */
	background: var(--color-main-background, #fff);
	background: color-mix(in srgb, var(--color-main-background, #fff) 85%, transparent);
	backdrop-filter: blur(8px);
	-webkit-backdrop-filter: blur(8px);
	border-right: 1px solid var(--color-border, #e1e4e8);
	box-shadow: 2px 0 8px rgba(0, 0, 0, 0.15);
}

.files-viewers-epub-toc-head {
	position: sticky;
	top: 0;
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 6px 8px 6px 16px;
	background: var(--color-main-background, #fff);
	border-bottom: 1px solid var(--color-border, #e1e4e8);
}

.files-viewers-epub-toc-title {
	font-size: 13px;
	font-weight: bold;
	color: var(--color-main-text, #222);
}

.files-viewers-epub-toc-close {
	width: 28px;
	height: 28px;
	border: none;
	border-radius: 6px;
	background: transparent;
	font-size: 14px;
	line-height: 1;
	color: var(--color-main-text, #222) !important;
	cursor: pointer;
}

.files-viewers-epub-toc-close:hover {
	background: var(--color-background-hover, #ececec);
}

.files-viewers-epub-toc-close:focus,
.files-viewers-epub-toc-close:active {
	background: transparent !important;
	box-shadow: none !important;
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

<!-- NOT scoped: targets the host Viewer modal (outside this component). -->
<style>
/* The empty strip below the toolbar was the modal-container running full height
   under the header; clicking it (backdrop) closed the book. Constrain it to the
   area below the header. */
#viewer .modal-wrapper .modal-container {
	height: calc(100% - var(--header-height)) !important;
}

#viewer .viewer__file-wrapper,
#viewer .viewer__file {
	height: 100% !important;
}

/* Hide the modal's file-to-file slideshow chevrons — they competed with our own
   discrete page chevrons. Book navigation is page-based via the toolbar. */
#viewer .modal-wrapper .prev,
#viewer .modal-wrapper .next {
	display: none !important;
}
</style>
