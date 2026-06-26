<?php
// .epub file icon, matching the NC filetype style used for the .ipynb icon:
// a square tile with slightly rounded corners holding a simple book glyph.
// Rendered at 2x then downscaled for anti-aliasing.

$outPath = $argv[1] ?? 'epub.png';

$S = 512;
$im = imagecreatetruecolor($S, $S);
imagesavealpha($im, true);
imagealphablending($im, false);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagealphablending($im, true);

$fill    = imagecolorallocate($im, 243, 244, 246); // #f3f4f6 tile
$border  = imagecolorallocate($im, 207, 211, 216); // #cfd3d8
$pages   = imagecolorallocate($im, 255, 255, 255);
$pageEdge = imagecolorallocate($im, 205, 209, 214);
$cover   = imagecolorallocate($im, 74, 120, 181);  // #4a78b5
$spine   = imagecolorallocate($im, 58, 95, 143);   // #3a5f8a darker
$line    = imagecolorallocate($im, 255, 255, 255);

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

// page block (white), offset down-right so it peeks out behind the cover
imagefilledrectangle($im, 196, 150, 372, 398, $pages);
imagerectangle($im, 196, 150, 372, 398, $pageEdge);
// a few page lines on the exposed right/bottom edge
imagesetthickness($im, 2);
imageline($im, 360, 158, 360, 390, $pageEdge);
imageline($im, 366, 162, 366, 386, $pageEdge);
imagesetthickness($im, 1);

// cover (front), overlapping the page block
imagefilledrectangle($im, 150, 132, 340, 388, $cover);
// spine accent on the left of the cover
imagefilledrectangle($im, 150, 132, 178, 388, $spine);
// a couple of title lines on the cover
imagesetthickness($im, 6);
imageline($im, 206, 196, 312, 196, $line);
imageline($im, 206, 226, 290, 226, $line);
imagesetthickness($im, 1);

$final = imagescale($im, 256, 256, IMG_BICUBIC);
imagesavealpha($final, true);
imagepng($final, $outPath);
echo "wrote $outPath (" . imagesx($final) . "x" . imagesy($final) . ")\n";
