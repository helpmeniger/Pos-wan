<?php
/**
 * Fausse caméra IP pour les tests (php -S 127.0.0.1:PORT tests/fake-camera.php).
 *   /snap.jpg            image fixe JPEG
 *   /move.jpg            scène animée : un article (FAKE_CAM_IMAGE ou rectangle) traverse l'image
 *   /auth.jpg            image protégée (identifiant « cam », mot de passe « S3cret! »)
 *   /mjpeg               flux MJPEG (multipart/x-mixed-replace)
 *   /redirect, /text     cas d'erreur
 */
declare(strict_types=1);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function scene(bool $move): string
{
    $W = 640; $H = 480;
    $im = imagecreatetruecolor($W, $H);
    for ($y = 0; $y < $H; $y += 40) {
        for ($x = 0; $x < $W; $x += 40) {
            $c = (($x + $y) / 40) % 2 ? 120 : 135;
            imagefilledrectangle($im, $x, $y, $x + 39, $y + 39, imagecolorallocate($im, $c, $c, $c));
        }
    }
    if ($move) {
        // Passage toutes les 8 s : 5 s de traversée de gauche à droite, puis 3 s sans article
        $t = fmod(microtime(true), 8.0);
        if ($t < 5.0) {
            $size = 200;
            $x = (int) (-$size + ($W + $size) * ($t / 5.0));
            $img = getenv('FAKE_CAM_IMAGE');
            $src = $img && is_file($img) ? @imagecreatefromjpeg($img) : null;
            if ($src) {
                imagecopyresampled($im, $src, $x, 140, 0, 0, $size, $size, imagesx($src), imagesy($src));
            } else {
                imagefilledrectangle($im, $x, 140, $x + $size, 140 + $size, imagecolorallocate($im, 230, 40, 40));
            }
        }
    }
    ob_start();
    imagejpeg($im, null, 85);
    return (string) ob_get_clean();
}

switch ($path) {
    case '/snap.jpg':
    case '/move.jpg':
        header('Content-Type: image/jpeg');
        echo scene($path === '/move.jpg');
        break;
    case '/auth.jpg':
        if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'cam' || ($_SERVER['PHP_AUTH_PW'] ?? '') !== 'S3cret!') {
            header('WWW-Authenticate: Basic realm="cam"');
            http_response_code(401);
            echo 'auth';
            break;
        }
        header('Content-Type: image/jpeg');
        echo scene(false);
        break;
    case '/mjpeg':
        header('Content-Type: multipart/x-mixed-replace; boundary=frame');
        for ($i = 0; $i < 40; $i++) {
            $j = scene(true);
            echo "--frame\r\nContent-Type: image/jpeg\r\nContent-Length: " . strlen($j) . "\r\n\r\n" . $j . "\r\n";
            @ob_flush(); flush();
            usleep(100000);
        }
        break;
    case '/redirect':
        header('Location: http://example.com/');
        http_response_code(302);
        break;
    default:
        header('Content-Type: text/plain');
        echo 'pas une image';
}
