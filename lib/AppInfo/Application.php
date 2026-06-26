<?php

declare(strict_types=1);

namespace OCA\FilesViewers\AppInfo;

use OCA\FilesViewers\Listener\LoadViewerListener;
use OCA\FilesViewers\Preview\EpubPreviewProvider;
use OCA\FilesViewers\Preview\IpynbPreviewProvider;
use OCA\Viewer\Event\LoadViewer;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'files_viewers';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// LoadViewer is dispatched by the Viewer app on the Files page and on
		// public-share pages. The ::class string resolves without autoloading,
		// so this stays safe even if the Viewer app is absent (the event just
		// never fires) — keeping us independently installable.
		$context->registerEventListener(LoadViewer::class, LoadViewerListener::class);

		// Give .ipynb files a Jupyter icon in the Files list. NC34's modern UI
		// shows a generated preview or a generic icon — there is no app hook for
		// a per-mimetype icon, so we register a preview provider that returns the
		// bundled Jupyter logomark. App-registered providers are NOT gated by the
		// enabledPreviewProviders whitelist, so this is fully out-of-the-box.
		$context->registerPreviewProvider(IpynbPreviewProvider::class, '/application\/x-ipynb\+json/');
		$context->registerPreviewProvider(EpubPreviewProvider::class, '/application\/epub\+zip/');
	}

	public function boot(IBootContext $context): void {
		// Register our file types programmatically so the app works out of the
		// box — no config/mimetypemapping.json and no occ command needed.
		// registerType() is public on the concrete detector (OC\Files\Type\Detection)
		// though not on the IMimeTypeDetector interface, hence the method_exists guard.
		// Effect: files scanned/uploaded while this app is enabled get the right
		// mime (the Viewer handler binds to it). Pre-existing files need a rescan.
		try {
			$detector = $context->getServerContainer()->get(\OCP\Files\IMimeTypeDetector::class);
			if (method_exists($detector, 'registerType')) {
				// CRUCIAL: force the default mappings to load *before* we add ours.
				// registerType() populates the detector's internal map, and the
				// detector's loadMappings() bails out early when that map is already
				// non-empty — so if we register first, the defaults never load and
				// the detector knows ONLY .ipynb, making every other file detect as
				// application/octet-stream (no icons, no previews). getAllMappings()
				// triggers the default load; our type is then layered on top.
				if (method_exists($detector, 'getAllMappings')) {
					$detector->getAllMappings();
				}
				$detector->registerType('ipynb', 'application/x-ipynb+json');
				// .epub / .cbr / .cbz are already in NC's default mapping.
			}
		} catch (\Throwable $e) {
			// Non-fatal: viewing still works for already-correctly-typed files.
		}
	}
}
