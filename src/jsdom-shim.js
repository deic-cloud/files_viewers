/**
 * Browser shim for notebookjs's `jsdom` dependency.
 *
 * notebookjs misdetects the environment inside a webpack bundle and takes its
 * Node branch:
 *     var dom = new (require('jsdom').JSDOM)()
 *     doc = dom.window.document          // expects a DOM document
 *     ... DOMPurify factory: lib(dom.window)
 *
 * We can't load real jsdom in the browser (and don't need to) — just give it
 * the real window, so doc === window.document and DOMPurify(window) works.
 */
function JSDOM() {
	this.window = (typeof window !== 'undefined') ? window : globalThis
}

module.exports = { JSDOM }
