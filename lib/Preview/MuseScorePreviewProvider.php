<?php

declare(strict_types=1);

namespace OCA\FilesViewers\Preview;

use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\IImage;
use OCP\Image;
use OCP\Preview\IProviderV2;

/**
 * Gives .mscz files a real preview in the Files list: MuseScore embeds a
 * rendered PNG of the first page inside the .mscz zip (Thumbnails/thumbnail.png),
 * so we extract and return that. Unlike the other providers here (which return a
 * static icon), this is the score's own preview. Falls back to null — a generic
 * icon — if the file has no embedded thumbnail or can't be read.
 */
class MuseScorePreviewProvider implements IProviderV2 {
	/** Don't slurp anything absurd into memory for a preview. */
	private const MAX_BYTES = 64 * 1024 * 1024;

	public function getMimeType(): string {
		return '/application\/x-musescore/';
	}

	public function isAvailable(FileInfo $file): bool {
		return $file->getSize() > 0 && $file->getSize() <= self::MAX_BYTES && class_exists(\ZipArchive::class);
	}

	public function getThumbnail(File $file, int $maxX, int $maxY): ?IImage {
		$png = $this->extractThumbnailPng($file);
		if ($png === null) {
			return null;
		}
		$image = new Image();
		$image->loadFromData($png);
		if (!$image->valid()) {
			return null;
		}
		if ($maxX > 0 && $maxY > 0) {
			$image->scaleDownToFit($maxX, $maxY);
		}
		return $image;
	}

	/** Read the embedded first-page PNG out of the .mscz zip, or null. */
	private function extractThumbnailPng(File $file): ?string {
		$tmp = tempnam(sys_get_temp_dir(), 'mscz');
		if ($tmp === false) {
			return null;
		}
		try {
			file_put_contents($tmp, $file->getContent());
			$zip = new \ZipArchive();
			if ($zip->open($tmp) !== true) {
				return null;
			}
			try {
				$entry = $this->pickThumbnailEntry($zip);
				if ($entry === null) {
					return null;
				}
				$data = $zip->getFromName($entry);
				return $data === false ? null : $data;
			} finally {
				$zip->close();
			}
		} catch (\Throwable $e) {
			return null;
		} finally {
			@unlink($tmp);
		}
	}

	/**
	 * Prefer the canonical Thumbnails/thumbnail.png, then any PNG whose name
	 * mentions "thumbnail", then any PNG in the archive.
	 */
	private function pickThumbnailEntry(\ZipArchive $zip): ?string {
		$pngs = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if ($name !== false && preg_match('/\.png$/i', $name)) {
				$pngs[] = $name;
			}
		}
		if (empty($pngs)) {
			return null;
		}
		foreach ($pngs as $name) {
			if (preg_match('#(^|/)thumbnail\.png$#i', $name)) {
				return $name;
			}
		}
		foreach ($pngs as $name) {
			if (stripos($name, 'thumbnail') !== false) {
				return $name;
			}
		}
		return $pngs[0];
	}
}
