<?php
// .epub file icon: a standing closed book, front cover facing us, with the
// page edges showing as a white band near the bottom — the classic, instantly
// readable "book" shape (cf. the reference Frederik linked). Bold cover colour
// + bottom page band read as a book even at small list sizes. Kept on the same
// rounded-grey tile as the .ipynb icon for a consistent set.
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
$cover   = imagecolorallocate($im, 230, 77, 56);   // #e64d38 book cover (warm red)
$coverDk = imagecolorallocate($im, 196, 60, 42);   // darker red (cover lip under pages)
$page    = imagecolorallocate($im, 255, 255, 255); // page edges
$lines   = imagecolorallocate($im, 198, 202, 208); // faint page lines

function fillRoundedRect($im, $x1, $y1, $x2, $y2, $r, $color) {
	imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
	imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
	imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
}

// rounded TOP corners only (square bottom)
function fillTopRoundedRect($im, $x1, $y1, $x2, $y2, $r, $color) {
	imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y1 + $r, $color);
	imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2, $color);
	imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
}

// tile + border
$m = 10; $r = 60; $bw = 5;
fillRoundedRect($im, $m, $m, $S - $m, $S - $m, $r, $border);
fillRoundedRect($im, $m + $bw, $m + $bw, $S - $m - $bw, $S - $m - $bw, $r - $bw, $fill);

// book geometry — portrait, centred, filling most of the tile
$bx1 = 150; $bx2 = 362; $btop = 96; $bbot = 416;
$pageTop = 344; $lip = 24; // white page band + a red cover lip beneath it

// cover (rounded top)
fillTopRoundedRect($im, $bx1, $btop, $bx2, $bbot, 30, $cover);

// white page band near the bottom, inset from the cover sides
$pin = 14;
imagefilledrectangle($im, $bx1 + $pin, $pageTop, $bx2 - $pin, $bbot - $lip, $page);
// thin red lip of the cover below the pages (book "stands" on the cover edge)
imagefilledrectangle($im, $bx1 + $pin, $bbot - $lip, $bx2 - $pin, $bbot, $coverDk);
// page lines
imagesetthickness($im, 5);
imageline($im, $bx1 + $pin + 24, 366, $bx2 - $pin - 24, 366, $lines);
imageline($im, $bx1 + $pin + 24, 384, $bx2 - $pin - 24, 384, $lines);
imagesetthickness($im, 1);

$final = imagescale($im, 256, 256, IMG_BICUBIC);
imagesavealpha($final, true);
imagepng($final, $outPath);
echo "wrote $outPath (" . imagesx($final) . "x" . imagesy($final) . ")\n";
