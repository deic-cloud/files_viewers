<?php
// .ipynb file icon in the current Nextcloud filetype style: a square tile with
// slightly rounded corners (no folded corner), and the Jupyter logomark large
// and clearly visible in the centre. Rendered at 2x then downscaled for AA.

$logoPath = $argv[1] ?? 'jupyter-logomark.png';
$outPath  = $argv[2] ?? 'jupyter.png';

$S = 512; // 2x render (final 256)
$im = imagecreatetruecolor($S, $S);
imagesavealpha($im, true);
imagealphablending($im, false);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); // transparent
imagealphablending($im, true);

$fill   = imagecolorallocate($im, 243, 244, 246); // #f3f4f6 light tile
$border = imagecolorallocate($im, 207, 211, 216); // #cfd3d8

function fillRoundedRect($im, $x1, $y1, $x2, $y2, $r, $color) {
	imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
	imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
	imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
}

// tile with a thin border: draw border-coloured rounded rect, then inset fill
$m = 10; $r = 60; $bw = 5;
fillRoundedRect($im, $m, $m, $S - $m, $S - $m, $r, $border);
fillRoundedRect($im, $m + $bw, $m + $bw, $S - $m - $bw, $S - $m - $bw, $r - $bw, $fill);

// Jupyter logomark, large and centred
$logo = imagecreatefrompng($logoPath);
$lw = imagesx($logo); $lh = imagesy($logo);
$targetH = (int)round($S * 0.62);              // clearly visible
$targetW = (int)round($lw * ($targetH / $lh));
$lx = (int)(($S - $targetW) / 2);
$ly = (int)(($S - $targetH) / 2);
imagecopyresampled($im, $logo, $lx, $ly, 0, 0, $targetW, $targetH, $lw, $lh);

$final = imagescale($im, 256, 256, IMG_BICUBIC);
imagesavealpha($final, true);
imagepng($final, $outPath);
echo "wrote $outPath (" . imagesx($final) . "x" . imagesy($final) . ")\n";
