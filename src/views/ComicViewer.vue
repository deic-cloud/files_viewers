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
// Low-level pure-JS RAR decompressor (RAR ≤ 3). Driven over a local MessageChannel
// on the main thread — no Web Worker, no WASM, so nothing the NC CSP blocks.
import { connect as rarConnect, disconnect as rarDisconnect } from 'bitjs-unrar'

const IMG_RE = /\.(jpe?g|png|gif|webp|bmp|avif)$/i

// RAR signature: "Rar!\x1A\x07" then 0x00 (RAR4) or 0x01 0x00 (RAR5).
function isRar5(buf) {
	const b = new Uint8Array(buf, 0, Math.min(8, buf.byteLength))
	return b.length >= 8 && b[0] === 0x52 && b[1] === 0x61 && b[2] === 0x72 && b[3] === 0x21
		&& b[4] === 0x1a && b[5] === 0x07 && b[6] === 0x01
}

// Extract a RAR (≤ v3) entirely in-memory on the main thread. Returns
// [{ name, data: Uint8Array }]. bitjs talks over a MessagePort, so we hand it one
// end of a MessageChannel and collect the 'extract'/'finish' messages ourselves.
function unrarEntries(buf) {
	return new Promise((resolve, reject) => {
		const files = []
		let settled = false
		const mc = new MessageChannel()
		const onErr = () => finish(true)
		const finish = (failed, err) => {
			if (settled) { return }
			settled = true
			window.removeEventListener('error', onErr)
			try { rarDisconnect() } catch (e) { /* noop */ }
			try { mc.port1.close(); mc.port2.close() } catch (e) { /* noop */ }
			if (failed) { reject(err || new Error('Could not extract this comic (it may be encrypted or use an unsupported RAR feature)')) } else { resolve(files) }
		}
		mc.port1.onmessage = (e) => {
			const d = e.data
			if (!d || !d.type) { return }
			if (d.type === 'extract' && d.unarchivedFile) {
				files.push({ name: d.unarchivedFile.filename, data: d.unarchivedFile.fileData })
			} else if (d.type === 'finish') {
				finish(false)
			} else if (d.type === 'error') {
				finish(true, new Error(d.msg || 'unrar error'))
			}
		}
		// bitjs decompresses synchronously inside its port handler; an unexpected
		// throw surfaces as an uncaught error on the window, so catch that too.
		window.addEventListener('error', onErr)
		try {
			rarConnect(mc.port2)
			const copy = buf.slice(0)
			mc.port1.postMessage({ file: copy }, [copy])
		} catch (e) {
			finish(true, e)
		}
		// Last-resort backstop only. Decompression runs synchronously on the main
		// thread, so this can't interrupt it — it just bounds a genuine no-response
		// case. Generous so it doesn't pre-empt a large (but progressing) comic.
		window.setTimeout(() => finish(true, new Error('Timed out extracting the comic')), 300000)
	})
}

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

			const byName = (a, b) => a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' })
			const mime = (this.mime || '').toLowerCase()
			const isRar = mime.indexOf('rar') !== -1 || /\.cbr$/i.test(this.filename || '')

			let entries
			if (isRar) {
				// CBR = RAR of page images. Pure-JS unrar handles RAR ≤ 3; RAR5 isn't
				// supported by any non-WASM decompressor, so bail out with a clear note.
				if (isRar5(buf)) {
					throw new Error('This comic uses the RAR5 format, which this viewer can’t open. Please use a CBZ (zip) comic, or re-save it as CBZ / older RAR.')
				}
				const files = (await unrarEntries(buf)).filter((f) => IMG_RE.test(f.name)).sort(byName)
				// RAR is extracted whole, so the page bytes are already in memory.
				entries = files.map((f) => ({ name: f.name, get: () => Promise.resolve(f.data) }))
			} else {
				// CBZ = a ZIP of page images (pure JS via JSZip; decompressed lazily).
				const zip = await JSZip.loadAsync(buf)
				const zEntries = []
				zip.forEach((path, file) => {
					if (!file.dir && IMG_RE.test(path)) { zEntries.push(file) }
				})
				zEntries.sort(byName)
				entries = zEntries.map((file) => ({ name: file.name, get: () => file.async('uint8array') }))
			}

			this._entries = entries
			this._urls = {}
			this.total = entries.length
			if (!this.total) {
				throw new Error('No page images found in this archive')
			}

			if (this._gone) { return } // viewer closed during extraction
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
		this._gone = true
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
			const data = await this._entries[i].get()
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
