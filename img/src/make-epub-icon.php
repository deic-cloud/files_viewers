<?php
// .epub file icon: an OPEN book that fills most of the tile, so it reads as a
// book by silhouette even at small list sizes (the previous closed-book design
// looked like a document and its white page edges vanished when small).
// Rendered at 2x then downscaled for anti-aliasing.

$outPath = $argv[1] ?? 'epub.png';

$S = 512;
$im = imagecreatetruecolor($S, $S);
imagesavealpha($im, true);
imagealphablending($im, false);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagealphablending($im, true);

$fill   = imagecolorallocate($im, 243, 244, 246); // #f3f4f6 tile
$border = imagecolorallocate($im, 207, 211, 216); // #cfd3d8
$cover  = imagecolorallocate($im, 58, 95, 143);   // #3a5f8a cover/binding (dark blue)
$page   = imagecolorallocate($im, 255, 255, 255);
$pageEdge = imagecolorallocate($im, 168, 190, 220); // light blue page shadow
$lines  = imagecolorallocate($im, 150, 170, 200);

function fillRoundedRect($im, $x1, $y1, $x2, $y2, $r, $color) {
	imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
	imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
	imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
}

// tile + border
$m = 10; $r = 60; $bw = 5;
fillRoundedRect($im, $m, $m, $S - $m, $S - $m, $r, $border);
fillRoundedRect($im, $m + $bw, $m + $bw, $S - $m - $bw, $S - $m - $bw, $r - $bw, $fill);

$cx = 256;
// Cover/binding (dark blue) — drawn larger so it shows as a border around the
// pages and as the central spine; fills the tile generously.
$coverL = [$cx, 96,  70, 150,  70, 416,  $cx, 388];
$coverR = [$cx, 96,  442, 150,  442, 416,  $cx, 388];
imagefilledpolygon($im, $coverL, $cover);
imagefilledpolygon($im, $coverR, $cover);

// Pages (white), inset from the cover so the blue cover edge + center spine show.
$insTop = 116; $insOut1 = 96; $insOut2 = 396; $insBot = 372; $gap = 16;
$pageL = [$cx - $gap, $insTop,  $insOut1, 168,  $insOut1, $insOut2,  $cx - $gap, $insBot];
$pageR = [$cx + $gap, $insTop,  416, 168,  416, $insOut2,  $cx + $gap, $insBot];
imagefilledpolygon($im, $pageL, $page);
imagefilledpolygon($im, $pageR, $page);

// text lines on each page
imagesetthickness($im, 7);
for ($k = 0; $k < 4; $k++) {
	$y = 200 + $k * 40;
	imageline($im, 120, $y + 6, $cx - 36, $y - 6, $lines);   // left page (slight tilt)
	imageline($im, $cx + 36, $y - 6, 392, $y + 6, $lines);   // right page
}
imagesetthickness($im, 1);

$final = imagescale($im, 256, 256, IMG_BICUBIC);
imagesavealpha($final, true);
imagepng($final, $outPath);
echo "wrote $outPath (" . imagesx($final) . "x" . imagesy($final) . ")\n";
