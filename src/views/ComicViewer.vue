<template>
	<div class="files-viewers-comic">
		<div v-if="error" class="files-viewers-msg">{{ error }}</div>
		<div ref="stage" class="files-viewers-comic-stage" :class="'fit-' + fitMode">
			<img v-if="pageUrl"
				:src="pageUrl"
				class="files-viewers-comic-img"
				:alt="'Page ' + (index + 1)" />
		</div>
		<div v-if="!error && total" class="files-viewers-comic-bar">
			<button class="files-viewers-comic-ico" :disabled="index <= 0" title="Previous page" @click="prev">‹</button>
			<span class="files-viewers-comic-loc">{{ (index + 1) + ' / ' + total }}</span>
			<button class="files-viewers-comic-ico" :disabled="index >= total - 1" title="Next page" @click="next">›</button>
			<button class="files-viewers-comic-ico"
				:title="fitMode === 'height' ? 'Fit width (scroll)' : 'Fit whole page'"
				@click="toggleFit">⤢</button>
		</div>
	</div>
</template>

<script>
import JSZip from 'jszip'

const IMG_RE = /\.(jpe?g|png|gif|webp|bmp|avif)$/i

function mimeFor(name) {
	const ext = (name.split('.').pop() || '').toLowerCase()
	switch (ext) {
	case 'jpg': case 'jpeg': return 'image/jpeg'
	case 'png': return 'image/png'
	case 'gif': return 'image/gif'
	case 'webp': return 'image/webp'
	case 'bmp': return 'image/bmp'
	case 'avif': return 'image/avif'
	default: return 'application/octet-stream'
	}
}

export default {
	name: 'ComicViewer',

	data() {
		// _entries / _urls are NOT reactive (binary data / blob URLs).
		return { error: '', index: 0, total: 0, pageUrl: '', fitMode: 'height' }
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
			const buf = await res.arrayBuffer()

			// CBZ = a ZIP of page images. (CBR = RAR needs a WASM decompressor, which
			// NC's CSP blocks — handled separately.)
			const zip = await JSZip.loadAsync(buf)
			const entries = []
			zip.forEach((path, file) => {
				if (!file.dir && IMG_RE.test(path)) { entries.push(file) }
			})
			// natural sort so 2.jpg < 10.jpg
			entries.sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' }))
			this._entries = entries
			this._urls = {}
			this.total = entries.length
			if (!this.total) {
				throw new Error('No page images found in this archive')
			}

			this.index = Math.min(this.loadPosition(), this.total - 1)
			await this.showPage(this.index)

			this.keyHandler = (e) => {
				if (e.key === 'ArrowLeft') { this.prev() } else if (e.key === 'ArrowRight') { this.next() }
			}
			document.addEventListener('keyup', this.keyHandler)
		} catch (e) {
			this.error = 'Could not open comic: ' + (e && e.message ? e.message : e)
		} finally {
			this.doneLoading()
		}
	},

	beforeDestroy() {
		if (this.keyHandler) {
			document.removeEventListener('keyup', this.keyHandler)
		}
		if (this._urls) {
			Object.values(this._urls).forEach((u) => { try { URL.revokeObjectURL(u) } catch (e) { /* noop */ } })
		}
	},

	methods: {
		async urlFor(i) {
			if (this._urls[i]) { return this._urls[i] }
			const data = await this._entries[i].async('uint8array')
			const url = URL.createObjectURL(new Blob([data], { type: mimeFor(this._entries[i].name) }))
			this._urls[i] = url
			return url
		},
		async showPage(i) {
			this.pageUrl = await this.urlFor(i)
			if (this.$refs.stage) { this.$refs.stage.scrollTop = 0 }
			// prefetch the next page so paging feels instant
			if (i + 1 < this.total) { this.urlFor(i + 1).catch(() => {}) }
		},
		prev() {
			if (this.index > 0) { this.index--; this.savePosition(this.index); this.showPage(this.index) }
		},
		next() {
			if (this.index < this.total - 1) { this.index++; this.savePosition(this.index); this.showPage(this.index) }
		},
		toggleFit() {
			this.fitMode = this.fitMode === 'height' ? 'width' : 'height'
		},
		// --- last-read page, per comic, in localStorage (no temp files / DB) ---
		storageKey() {
			return 'files_viewers:comic:' + (this.fileid || this.filename || this.src || '')
		},
		loadPosition() {
			try { return parseInt(window.localStorage.getItem(this.storageKey()), 10) || 0 } catch (e) { return 0 }
		},
		savePosition(i) {
			try { window.localStorage.setItem(this.storageKey(), String(i)) } catch (e) { /* quota/private */ }
		},
	},
}
</script>

<style scoped>
.files-viewers-comic {
	box-sizing: border-box;
	width: 100%;
	height: 100%;
	display: flex;
	flex-direction: column;
	background: var(--color-main-background, #fff);
}

.files-viewers-comic-stage {
	flex: 1 1 auto;
	min-height: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	overflow: auto;
}

/* fit the whole page within the viewport */
.files-viewers-comic-stage.fit-height .files-viewers-comic-img {
	max-width: 100%;
	max-height: 100%;
	object-fit: contain;
}

/* fill the width and scroll down the page */
.files-viewers-comic-stage.fit-width {
	align-items: flex-start;
}

.files-viewers-comic-stage.fit-width .files-viewers-comic-img {
	width: 100%;
	height: auto;
}

/* compact control bar, themed to match the NC UI (mirrors the epub viewer) */
.files-viewers-comic-bar {
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

.files-viewers-comic-ico {
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
	color: var(--color-main-text, #222) !important;
	cursor: pointer;
}

.files-viewers-comic-ico:hover {
	background: var(--color-background-hover, #ececec);
}

.files-viewers-comic-ico:disabled {
	opacity: 0.35;
	cursor: default;
	background: transparent;
}

.files-viewers-comic-ico:focus,
.files-viewers-comic-ico:focus-visible,
.files-viewers-comic-ico:active {
	background: transparent !important;
	box-shadow: none !important;
}

.files-viewers-comic-loc {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 72px;
	height: 28px;
	padding: 0 10px;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #767676);
}

.files-viewers-msg {
	padding: 24px 16px;
	color: var(--color-error-text, #8a0000);
}
</style>
