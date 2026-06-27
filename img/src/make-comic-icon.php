<?php
// .cbz/.cbr file icon, pop-art style: a red rounded tile with a halftone dot
// pattern and a white comic "starburst" speech bubble (black outline).
// Rendered at 2x then downscaled for anti-aliasing.

$outPath = $argv[1] ?? 'comic.png';

$S = 512;
$im = imagecreatetruecolor($S, $S);
imagesavealpha($im, true);
imagealphablending($im, false);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagealphablending($im, true);

$red    = imagecolorallocate($im, 226, 64, 47);    // #e2402f base
$dot    = imagecolorallocate($im, 199, 50, 38);    // #c73226 halftone dots
$white  = imagecolorallocate($im, 255, 255, 255);
$ink    = imagecolorallocate($im, 26, 26, 26);     // #1a1a1a outline

$m = 10; $r = 60;
$x1 = $m; $y1 = $m; $x2 = $S - $m; $y2 = $S - $m;

function fillRoundedRect($im, $x1, $y1, $x2, $y2, $r, $color) {
	imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
	imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
	imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
	imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
}

// is (x,y) inside the rounded rect (so dots stay within the tile)?
function insideRounded($x, $y, $x1, $y1, $x2, $y2, $r) {
	if ($x >= $x1 + $r && $x <= $x2 - $r) { return ($y >= $y1 && $y <= $y2); }
	if ($y >= $y1 + $r && $y <= $y2 - $r) { return ($x >= $x1 && $x <= $x2); }
	$cx = ($x < $x1 + $r) ? $x1 + $r : $x2 - $r;
	$cy = ($y < $y1 + $r) ? $y1 + $r : $y2 - $r;
	return (($x - $cx) ** 2 + ($y - $cy) ** 2) <= $r * $r;
}

// red base
fillRoundedRect($im, $x1, $y1, $x2, $y2, $r, $red);

// halftone dots (offset rows), clipped to the rounded tile
$step = 40; $dr = 8; $row = 0;
for ($y = $y1 + 20; $y <= $y2 - 20; $y += $step) {
	$xoff = ($row % 2) ? $step / 2 : 0;
	for ($x = $x1 + 20 + $xoff; $x <= $x2 - 20; $x += $step) {
		if (insideRounded($x, $y, $x1 + $dr + 2, $y1 + $dr + 2, $x2 - $dr - 2, $y2 - $dr - 2, $r - $dr - 2)) {
			imagefilledellipse($im, (int)$x, (int)$y, $dr * 2, $dr * 2, $dot);
		}
	}
	$row++;
}

// white comic starburst (jagged explosion) with black outline
$cx = 256; $cy = 256;
$N = 10; $Ro = 168; $Ri = 116;
$pts = [];
for ($i = 0; $i < $N * 2; $i++) {
	$ang = M_PI * $i / $N - M_PI / 2;
	$rad = ($i % 2 === 0) ? $Ro : $Ri;
	$jit = ((($i * 37) % 19) - 9) * 1.4; // deterministic jitter for a hand-drawn feel
	$pts[] = (int)round($cx + cos($ang) * ($rad + $jit));
	$pts[] = (int)round($cy + sin($ang) * ($rad + $jit));
}
imagefilledpolygon($im, $pts, $white);
imagesetthickness($im, 10);
imagepolygon($im, $pts, $ink);
imagesetthickness($im, 1);

// a bold "!" in the burst (a comic staple; reads even when small)
$font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
if (is_file($font)) {
	$box = imagettfbbox(150, 0, $font, '!');
	// centre on both axes using the full glyph bbox (accounts for side bearing)
	$tx = (int) round($cx - ($box[0] + $box[2]) / 2);
	$ty = (int) round($cy - ($box[1] + $box[7]) / 2);
	imagettftext($im, 150, 0, $tx, $ty, $ink, $font, '!');
}

$final = imagescale($im, 256, 256, IMG_BICUBIC);
imagesavealpha($final, true);
imagepng($final, $outPath);
echo "wrote $outPath (" . imagesx($final) . "x" . imagesy($final) . ")\n";
