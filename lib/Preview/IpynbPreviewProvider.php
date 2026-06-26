<?php

declare(strict_types=1);

namespace OCA\FilesViewers\Preview;

use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IImage;
use OCP\Image;
use OCP\Preview\IProviderV2;

/**
 * Gives .ipynb files a Jupyter icon in the Files list.
 *
 * NC34's modern Files UI shows a generated preview thumbnail or a generic file
 * icon — there is no app hook for a per-mimetype icon. So we register a preview
 * provider that returns the (bundled, BSD-licensed) Jupyter logomark as the
 * thumbnail. App-registered providers are active regardless of the
 * enabledPreviewProviders whitelist, so this works out of the box — nothing
 * outside the app dir.
 */
class IpynbPreviewProvider implements IProviderV2 {
	public function getMimeType(): string {
		return '/application\/x-ipynb\+json/';
	}

	public function isAvailable(FileInfo $file): bool {
		return true;
	}

	public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage {
		// Static icon — independent of the notebook's content.
		$logo = dirname(__DIR__, 2) . '/img/jupyter.png';
		$image = new Image();
		$image->loadFromFile($logo);
		if (!$image->valid()) {
			return null;
		}
		if ($maxX > 0 && $maxY > 0) {
			$image->scaleDownToFit($maxX, $maxY);
		}
		return $image;
	}
}
