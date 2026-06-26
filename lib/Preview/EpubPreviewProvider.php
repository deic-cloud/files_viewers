<?php

declare(strict_types=1);

namespace OCA\FilesViewers\Preview;

use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IImage;
use OCP\Image;
use OCP\Preview\IProviderV2;

/**
 * Gives .epub files a book icon in the Files list — same approach as the
 * Jupyter icon: app-registered preview providers are active regardless of the
 * enabledPreviewProviders whitelist, so this works out of the box with the
 * bundled icon and nothing outside the app dir.
 */
class EpubPreviewProvider implements IProviderV2 {
	public function getMimeType(): string {
		return '/application\/epub\+zip/';
	}

	public function isAvailable(FileInfo $file): bool {
		return true;
	}

	public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage {
		$icon = dirname(__DIR__, 2) . '/img/epub.png';
		$image = new Image();
		$image->loadFromFile($icon);
		if (!$image->valid()) {
			return null;
		}
		if ($maxX > 0 && $maxY > 0) {
			$image->scaleDownToFit($maxX, $maxY);
		}
		return $image;
	}
}
