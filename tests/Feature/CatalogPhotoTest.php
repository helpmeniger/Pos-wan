<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use Tests\TestCase;

/** Photos du catalogue : ajout multiple, limite, principale, suppression, propriété, affichage public. */
final class CatalogPhotoTest extends TestCase
{
    private function upload(string $path, string $token, string $file): Response
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mwimg');
        copy($file, $tmp);
        $req = new Request('POST', $path, [], [], ['authorization' => 'Bearer ' . $token], ['file' => ['name' => 'p.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]],
            ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'tests']);
        $res = $this->kernel()->handle($req);
        Auth::actingAs(null);
        @unlink($tmp);
        return $res;
    }

    private function jpeg(int $w, int $h): string
    {
        $f = sys_get_temp_dir() . "/mw_test_{$w}x{$h}.jpg";
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 100, 50));
        imagejpeg($im, $f, 90);
        return $f;
    }

    public function testMultiplePhotosLimitMainAndOwnership(): void
    {
        $m = $this->merchant();
        $tm = $this->token((int) $m['_owner']['id']);
        $pid = (int) $this->data($this->call('POST', '/api/merchant/products', ['name' => 'Plat photo', 'price' => 1500], $tm))['id'];
        $this->assertTrue($pid > 0, 'Création de produit sans ordre d\'affichage');
        Database::execute("UPDATE settings SET v = '3' WHERE k = 'catalog.max_photos_per_product'");
        \App\Core\Settings::flush();

        $img = $this->jpeg(2400, 1600);
        for ($i = 0; $i < 3; $i++) {
            $this->assertStatus(201, $this->upload("/api/merchant/products/{$pid}/photos", $tm, $img), 'photo ' . ($i + 1));
        }
        $this->assertStatus(422, $this->upload("/api/merchant/products/{$pid}/photos", $tm, $img), 'Limite de photos par produit');

        $photos = $this->data($this->call('GET', '/api/merchant/catalog', [], $tm))['products'][0]['photos'];
        $this->assertSame(3, count($photos));
        $first = (int) Database::value('SELECT photo_file_id FROM products WHERE id = :p', ['p' => $pid]);
        $this->assertTrue($first > 0, 'La première photo devient la photo principale');
        [$w] = getimagesize(MARWA_ROOT . '/storage/uploads/' . Database::value('SELECT path FROM files WHERE id = :f', ['f' => $first]));
        $this->assertSame(1600, $w, 'Image redimensionnée côté serveur');

        $r = $this->call('POST', "/api/merchant/products/{$pid}/photos/{$photos[2]['id']}/main", [], $tm);
        $this->assertStatus(200, $r);
        $this->assertSame($photos[2]['id'], $this->data($r)['photos'][0]['id'], 'Nouvelle photo principale en premier');

        // Un autre commerce ne peut pas toucher à ces photos
        $other = $this->merchant();
        $to = $this->token((int) $other['_owner']['id']);
        $this->assertStatus(404, $this->call('DELETE', "/api/merchant/products/{$pid}/photos/{$photos[0]['id']}", [], $to));
        $this->assertStatus(404, $this->upload("/api/merchant/products/{$pid}/photos", $to, $img));

        $this->assertStatus(200, $this->call('DELETE', "/api/merchant/products/{$pid}/photos/{$photos[2]['id']}", [], $tm));
        $public = $this->data($this->call('GET', '/api/merchants/' . $m['id']));
        $prod = array_values(array_filter($public['products'], fn ($p) => $p['id'] === $pid))[0];
        $this->assertSame(2, count($prod['photos']), 'Galerie publique à jour');
        $this->assertSame($prod['photo_url'], $prod['photos'][0], 'Photo principale réattribuée après suppression');

        // Un fichier qui n'est pas une image est refusé
        $txt = tempnam(sys_get_temp_dir(), 'mwtxt');
        file_put_contents($txt, '<?php echo 1;');
        $this->assertStatus(422, $this->upload("/api/merchant/products/{$pid}/photos", $tm, $txt));
        Database::execute("UPDATE settings SET v = '6' WHERE k = 'catalog.max_photos_per_product'");
        \App\Core\Settings::flush();
    }
}
