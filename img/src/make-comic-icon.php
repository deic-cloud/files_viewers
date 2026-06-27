<?php
// .cbz/.cbr file icon: a comic page — black-bordered colour panels with white
// gutters and a speech bubble — on the same rounded-grey tile as the other
// file-type icons. Rendered at 2x then downscaled for anti-aliasing.

$outPath = $argv[1] ?? 'comic.png';

$S = 512;
$im = imagecreatetruecolor($S, $S);
imagesavealpha($im, true);
imagealphablending($im, false);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagealphablending($im, true);

$fill   = imagecolorallocate($im, 243, 244, 246); // #f3f4f6 tile
$border = imagecolorallocate($im, 207, 211, 216); // #cfd3d8
$page   = imagecolorallocate($im, 255, 255, 255);
$ink    = imagecolorallocate($im, 34, 34, 34);     // panel outlines
$blue   = imagecolorallocate($im, 58, 110, 165);
$orange = imagecolorallocate($im, 232, 99, 58);
$green  = imagecolorallocate($im, 58, 165, 95);

function fillRoundedRect($im, $x1, $y1, $x2, $y2, $r, $color) {
	imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
	imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
	imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
}

function panel($im, $x1, $y1, $x2, $y2, $color, $ink) {
	imagefilledrectangle($im, $x1, $y1, $x2, $y2, $color);
	imagesetthickness($im, 6);
	imagerectangle($im, $x1, $y1, $x2, $y2, $ink);
	imagesetthickness($im, 1);
}

// tile + border
$m = 10; $r = 60; $bw = 5;
fillRoundedRect($im, $m, $m, $S - $m, $S - $m, $r, $border);
fillRoundedRect($im, $m + $bw, $m + $bw, $S - $m - $bw, $S - $m - $bw, $r - $bw, $fill);

// the comic page
imagefilledrectangle($im, 138, 92, 374, 420, $page);

// panels (white gutters = the page showing between them)
panel($im, 158, 112, 354, 236, $blue, $ink);          // top: wide
panel($im, 158, 256, 250, 400, $orange, $ink);        // bottom-left
panel($im, 262, 256, 354, 400, $green, $ink);         // bottom-right

// speech bubble in the top panel
$bx = 256; $by = 168; $rx = 62; $ry = 34;
imagefilledellipse($im, $bx, $by, $rx * 2, $ry * 2, $page);
imagefilledpolygon($im, [$bx - 28, $by + 18, $bx - 6, $by + 18, $bx - 40, $by + 56], $page);
imagesetthickness($im, 5);
imageellipse($im, $bx, $by, $rx * 2, $ry * 2, $ink);
imagesetthickness($im, 1);

$final = imagescale($im, 256, 256, IMG_BICUBIC);
imagesavealpha($final, true);
imagepng($final, $outPath);
echo "wrote $outPath (" . imagesx($final) . "x" . imagesy($final) . ")\n";
