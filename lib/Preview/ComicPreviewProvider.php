<?php

declare(strict_types=1);

namespace OCA\FilesViewers\Preview;

use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IImage;
use OCP\Image;
use OCP\Preview\IProviderV2;

/**
 * Gives .cbz/.cbr files a comic icon in the Files list — same out-of-the-box
 * preview-provider approach as the Jupyter and e-book icons (the bundled icon,
 * nothing outside the app dir, not gated by enabledPreviewProviders).
 */
class ComicPreviewProvider implements IProviderV2 {
	public function getMimeType(): string {
		return '/application\/comicbook\+(zip|rar)/';
	}

	public function isAvailable(FileInfo $file): bool {
		return true;
	}

	public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage {
		$icon = dirname(__DIR__, 2) . '/img/comic.png';
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
