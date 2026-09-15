<?php
$imgPath = 'C:\xampp\htdocs\MCATS\img\logo.png';
if (!file_exists($imgPath)) {
    die("Logo not found");
}
$im = imagecreatefrompng($imgPath);
if (!$im) {
    die("Failed to open image");
}
$w = imagesx($im);
$h = imagesy($im);
$colors = [];
for($x=0; $x<$w; $x+=5){
    for($y=0; $y<$h; $y+=5){
        $rgb = imagecolorat($im, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        
        $a = ($rgb >> 24) & 0x7F; // handle alpha
        if ($a > 100) continue; // skip transparent pixels

        $hex = sprintf('#%02x%02x%02x', $r, $g, $b);
        if(!isset($colors[$hex])) $colors[$hex]=0;
        $colors[$hex]++;
    }
}
arsort($colors);
print_r(array_slice($colors, 0, 15));
?>
