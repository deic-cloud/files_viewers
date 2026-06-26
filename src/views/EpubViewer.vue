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

			this.book = ePub(buf)

			// epub.js injects a <base href> into each section so relative URLs
			// resolve. NC's CSP is base-uri 'none', which blocks that tag and logs a
			// violation. epub.js already rewrites the section's resources to absolute
			// blob: URLs, so the <base> is vestigial here — strip it in a spine
			// content hook (runs on the section DOM before it's serialised into the
			// iframe), so no <base> is ever written and the violation never fires.
			this.book.spine.hooks.content.register((doc) => {
				try {
					// <base href>: blocked by base-uri 'none' (vestigial — resources are
					// rewritten to absolute blob: URLs).
					doc.querySelectorAll('base').forEach((b) => b.remove())
					// The book's own CSS is loaded as <link href="blob:…">, which NC's
					// CSP (style-src 'self' 'unsafe-inline') blocks anyway, so it isn't
					// applied — strip the links so no violation is logged. Inline <style>
					// blocks in the XHTML still apply. Full book styling (these external
					// CSS files) would need inlining the CSS or a blob: CSP allowance —
					// a deliberate choice to make during the layout pass.
					doc.querySelectorAll('link[rel="stylesheet"]').forEach((l) => l.remove())
				} catch (e) { /* noop */ }
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
