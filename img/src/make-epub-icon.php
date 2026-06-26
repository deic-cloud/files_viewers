<?php
// .epub file icon: Frederik's book glyph (img/src/book.png, red book on a
// transparent background) trimmed to its bounds and centred on a white,
// rounded-corner tile. Rendered at 2x then downscaled for anti-aliasing.

$srcPath = __DIR__ . '/book.png';
$outPath = $argv[1] ?? 'epub.png';

$src = imagecreatefrompng($srcPath);
$sw = imagesx($src);
$sh = imagesy($src);

// trim the transparent margin: find the bounding box of visible pixels
$minx = $sw; $miny = $sh; $maxx = 0; $maxy = 0;
for ($y = 0; $y < $sh; $y += 2) {
	for ($x = 0; $x < $sw; $x += 2) {
		$alpha = (imagecolorat($src, $x, $y) >> 24) & 0x7F; // 0 opaque … 127 transparent
		if ($alpha < 100) {
			if ($x < $minx) { $minx = $x; }
			if ($x > $maxx) { $maxx = $x; }
			if ($y < $miny) { $miny = $y; }
			if ($y > $maxy) { $maxy = $y; }
		}
	}
}
$bw = $maxx - $minx + 1;
$bh = $maxy - $miny + 1;

$S = 512;
$im = imagecreatetruecolor($S, $S);
imagesavealpha($im, true);
imagealphablending($im, false);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); // transparent
imagealphablending($im, true);

$white  = imagecolorallocate($im, 255, 255, 255);
$border = imagecolorallocate($im, 207, 211, 216); // #cfd3d8 (same as the .ipynb tile)

function fillRoundedRect($im, $x1, $y1, $x2, $y2, $r, $color) {
	imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
	imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
	imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
}

// white rounded tile with a faint border so it's visible on white backgrounds
$m = 10; $r = 60; $bw2 = 4;
fillRoundedRect($im, $m, $m, $S - $m, $S - $m, $r, $border);
fillRoundedRect($im, $m + $bw2, $m + $bw2, $S - $m - $bw2, $S - $m - $bw2, $r - $bw2, $white);

// place the book, scaled to fill most of the tile height, centred
$targetH = (int) round($S * 0.86);
$targetW = (int) round($bw * ($targetH / $bh));
$dx = (int) round(($S - $targetW) / 2);
$dy = (int) round(($S - $targetH) / 2);
imagecopyresampled($im, $src, $dx, $dy, $minx, $miny, $targetW, $targetH, $bw, $bh);

$final = imagescale($im, 256, 256, IMG_BICUBIC);
imagesavealpha($final, true);
imagepng($final, $outPath);
echo "wrote $outPath (book bbox {$bw}x{$bh} -> {$targetW}x{$targetH})\n";
