<?php
// Génère les images du site de test (motifs déterministes). Usage : php make_images.php <dossier>
$dir = $argv[1];
@mkdir($dir, 0775, true);
foreach ([1 => 101, 2 => 202, 3 => 303, 4 => 404] as $n => $seed) {
    $img = imagecreatetruecolor(640, 420);
    mt_srand($seed);
    for ($i = 0; $i < 30; $i++) {
        $c = imagecolorallocate($img, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
        imagefilledrectangle($img, mt_rand(0, 640), mt_rand(0, 420), mt_rand(0, 640), mt_rand(0, 420), $c);
    }
    imagepng($img, "$dir/img$n.png");
}
// Version « republiée » de img1 : recadrage léger, redimensionnement, JPEG
$src = imagecreatefrompng("$dir/img1.png");
$dst = imagecreatetruecolor(500, 328);
imagecopyresampled($dst, $src, 0, 0, 0, 0, 500, 328, 640, 420);
imagejpeg($dst, "$dir/img1_repost.jpg", 65);
echo "ok\n";
