<template>
	<div class="files-viewers-ipynb">
		<div v-if="launchUrl" class="files-viewers-nb-actions">
			<a class="button primary" :href="launchUrl">{{ openLabel }}</a>
		</div>
		<div v-if="error" class="files-viewers-msg">{{ error }}</div>
		<div ref="nb" class="files-viewers-nb"></div>
	</div>
</template>

<script>
import { renderNotebook } from '../notebook-render.js'

// The Containers app's address, when the user has it (see LoadViewerListener).
function notebookLauncher() {
	const el = document.getElementById('initial-state-files_viewers-notebook_launcher')
	if (!el) {
		return ''
	}
	try {
		return JSON.parse(atob(el.value)) || ''
	} catch (e) {
		return ''
	}
}

export default {
	name: 'IpynbViewer',

	data() {
		return { error: '', launchUrl: '' }
	},

	computed: {
		src() {
			return this.source ?? this.davPath
		},
		openLabel() {
			return window.t ? window.t('files_viewers', 'Open in Jupyter') : 'Open in Jupyter'
		},
	},

	async mounted() {
		try {
			const res = await fetch(this.src)
			if (!res.ok) {
				throw new Error('HTTP ' + res.status)
			}
			const json = JSON.parse(await res.text())
			// Offer to run it: the Containers app starts a notebook server with a
			// kernel for it (it knows which image has which kernels).
			const launcher = notebookLauncher()
			if (launcher && this.filename) {
				const kernel = (json.metadata && json.metadata.kernelspec && json.metadata.kernelspec.name) || ''
				this.launchUrl = launcher + '?notebook=' + encodeURIComponent(this.filename) + '&kernel=' + encodeURIComponent(kernel)
			}
			this.$refs.nb.innerHTML = renderNotebook(json)
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

.files-viewers-nb-actions {
	position: sticky;
	top: 0;
	z-index: 1;
	display: flex;
	justify-content: flex-end;
	max-width: 980px;
	margin: 0 auto 8px;
}

.files-viewers-msg {
	max-width: 980px;
	margin: 0 auto;
	color: var(--color-error-text, #8a0000);
}
</style>
