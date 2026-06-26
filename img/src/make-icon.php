<?php
// Generate a document-style .ipynb file icon: a white page with a folded
// corner and the Jupyter logomark on it, on a square transparent canvas.
// Rendered at 2x then downscaled for anti-aliasing.

$logoPath = $argv[1] ?? 'jupyter-logo-src.png';
$outPath  = $argv[2] ?? 'jupyter.png';

$S = 512;                 // 2x render size (final 256)
$im = imagecreatetruecolor($S, $S);
imagesavealpha($im, true);
imagealphablending($im, false);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127)); // transparent
imagealphablending($im, true);

// colours
$white  = imagecolorallocate($im, 255, 255, 255);
$border = imagecolorallocate($im, 196, 200, 205);
$fold   = imagecolorallocate($im, 228, 231, 235);
$line   = imagecolorallocate($im, 222, 225, 229); // suggested text lines

// page geometry (2x coords)
$L = 120; $R = 392; $T = 70; $B = 460; $F = 74; // left/right/top/bottom + fold size

// page body (top-right corner cut for the fold)
$body = [$L,$T, $R-$F,$T, $R,$T+$F, $R,$B, $L,$B];
imagefilledpolygon($im, $body, $white);
imagepolygon($im, $body, $border);

// the dog-ear fold triangle
$tri = [$R-$F,$T, $R,$T+$F, $R-$F,$T+$F];
imagefilledpolygon($im, $tri, $fold);
imagepolygon($im, $tri, $border);

// a couple of faint "notebook" lines near the top of the page
imagesetthickness($im, 3);
imageline($im, $L+34, $T+44, $R-$F-20, $T+44, $line);
imageline($im, $L+34, $T+74, $R-110,   $T+74, $line);
imagesetthickness($im, 1);

// composite the Jupyter logomark, scaled to fit, centred in the lower page
$logo = imagecreatefrompng($logoPath);
$lw = imagesx($logo); $lh = imagesy($logo);
$targetW = 188;
$targetH = (int)round($lh * ($targetW / $lw));
$lx = (int)($L + (($R - $L) - $targetW) / 2);
$ly = (int)($T + (($B - $T) - $targetH) / 2 + 34); // nudge down, below the lines
imagecopyresampled($im, $logo, $lx, $ly, 0, 0, $targetW, $targetH, $lw, $lh);

// downscale to 256 for anti-aliasing
$final = imagescale($im, 256, 256, IMG_BICUBIC);
imagesavealpha($final, true);
imagepng($final, $outPath);
echo "wrote $outPath (" . imagesx($final) . "x" . imagesy($final) . ")\n";
