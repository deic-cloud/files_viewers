<template>
	<div class="files-viewers-muse">
		<div v-if="error" class="files-viewers-msg">{{ error }}</div>
		<div ref="stage" class="files-viewers-muse-stage" :class="'fit-' + fitMode">
			<img v-if="pageUrl"
				:src="pageUrl"
				class="files-viewers-muse-img"
				:style="zoomStyle"
				:alt="filename || 'Score'" />
		</div>
		<div v-if="!error && pageUrl" class="files-viewers-muse-bar">
			<button class="files-viewers-muse-ico" title="Zoom out" :disabled="zoom <= 0.25" @click="zoomOut">−</button>
			<span class="files-viewers-muse-loc">{{ Math.round(zoom * 100) + '%' }}</span>
			<button class="files-viewers-muse-ico" title="Zoom in" :disabled="zoom >= 6" @click="zoomIn">+</button>
			<button class="files-viewers-muse-ico"
				:title="fitMode === 'height' ? 'Fit width (scroll)' : 'Fit whole page'"
				@click="toggleFit">⤢</button>
		</div>
	</div>
</template>

<script>
import JSZip from 'jszip'

// A MuseScore file (.mscz) is a ZIP whose native score body is MuseScore's own
// XML (.mscx) — there is no pure-JS engraver for that, so we can't re-render the
// score client-side. But MuseScore embeds a rendered PNG preview of the first
// page (Thumbnails/thumbnail.png), which we extract and show. Full multi-page
// engraving would need MuseScore's CLI server-side; this is the self-contained,
// CSP-clean client-side preview. Same JSZip path as the CBZ comic viewer.
function pickThumbnail(zip) {
	const pngs = []
	zip.forEach((path, file) => {
		if (!file.dir && /\.png$/i.test(path)) { pngs.push({ path: path.toLowerCase(), file }) }
	})
	if (!pngs.length) { return null }
	// Prefer the canonical Thumbnails/thumbnail.png, then any PNG under a
	// thumbnails folder, then any PNG at all.
	return (pngs.find((e) => /(^|\/)thumbnail\.png$/.test(e.path))
		|| pngs.find((e) => e.path.includes('thumbnail'))
		|| pngs[0]).file
}

export default {
	name: 'MuseScoreViewer',

	data() {
		return { error: '', pageUrl: '', fitMode: 'height', zoom: 1 }
	},

	computed: {
		src() {
			return this.source ?? this.davPath
		},
		zoomStyle() {
			// Only apply an explicit scale once the user zooms; at 1× let the
			// fit-mode CSS size the image so it fills the stage cleanly.
			return this.zoom === 1 ? {} : { transform: 'scale(' + this.zoom + ')', transformOrigin: 'center top' }
		},
	},

	async mounted() {
		try {
			const res = await fetch(this.src)
			if (!res.ok) { throw new Error('HTTP ' + res.status) }
			const buf = await res.arrayBuffer()

			const zip = await JSZip.loadAsync(buf)
			const thumb = pickThumbnail(zip)
			if (!thumb) {
				throw new Error('This MuseScore file has no embedded preview image (it was saved without preview images). In-browser rendering shows the embedded preview only.')
			}
			const data = await thumb.async('uint8array')
			if (this._gone) { return } // viewer closed during extraction
			this._url = URL.createObjectURL(new Blob([data], { type: 'image/png' }))
			this.pageUrl = this._url
		} catch (e) {
			this.error = 'Could not open score: ' + (e && e.message ? e.message : e)
		} finally {
			this.doneLoading()
		}
	},

	beforeDestroy() {
		this._gone = true
		if (this._url) { try { URL.revokeObjectURL(this._url) } catch (e) { /* noop */ } }
	},

	methods: {
		zoomIn() { this.zoom = Math.min(6, Math.round((this.zoom + 0.25) * 100) / 100) },
		zoomOut() { this.zoom = Math.max(0.25, Math.round((this.zoom - 0.25) * 100) / 100) },
		toggleFit() {
			this.fitMode = this.fitMode === 'height' ? 'width' : 'height'
			this.zoom = 1
		},
	},
}
</script>

<style scoped>
.files-viewers-muse {
	box-sizing: border-box;
	width: 100%;
	height: 100%;
	display: flex;
	flex-direction: column;
	background: var(--color-main-background, #fff);
}

.files-viewers-muse-stage {
	flex: 1 1 auto;
	min-height: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	overflow: auto;
	/* a light backdrop so the white score page stands out */
	background: var(--color-background-dark, #f0f0f0);
}

/* fit the whole page within the viewport */
.files-viewers-muse-stage.fit-height .files-viewers-muse-img {
	max-width: 100%;
	max-height: 100%;
	object-fit: contain;
}

/* fill the width and scroll down the page */
.files-viewers-muse-stage.fit-width {
	align-items: flex-start;
}

.files-viewers-muse-stage.fit-width .files-viewers-muse-img {
	width: 100%;
	height: auto;
}

.files-viewers-muse-img {
	background: #fff;
	box-shadow: 0 1px 6px rgba(0, 0, 0, 0.25);
}

/* control bar — matches the comic/epub viewers */
.files-viewers-muse-bar {
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

.files-viewers-muse-ico {
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

.files-viewers-muse-ico:hover {
	background: var(--color-background-hover, #ececec);
}

.files-viewers-muse-ico:disabled {
	opacity: 0.35;
	cursor: default;
	background: transparent;
}

.files-viewers-muse-ico:focus,
.files-viewers-muse-ico:focus-visible,
.files-viewers-muse-ico:active {
	background: transparent !important;
	box-shadow: none !important;
}

.files-viewers-muse-loc {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 56px;
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
