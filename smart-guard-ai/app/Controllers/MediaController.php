<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/** Les images et extraits sont servis uniquement après contrôle des droits et du magasin. */
final class MediaController extends Controller
{
    private function safe(string $root, string $rel): string
    {
        $base = realpath(STORAGE_PATH . '/' . $root);
        $file = realpath(STORAGE_PATH . '/' . $root . '/' . $rel);
        if (!$base || !$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
            throw new HttpException(404, 'Fichier introuvable.');
        }
        return $file;
    }

    public function productImage(Request $r): void
    {
        $img = Database::fetch('SELECT i.file_path FROM product_images i JOIN products p ON p.id = i.product_id WHERE i.id = :id AND p.store_id = :s',
            ['id' => (int) $r->param('id'), 's' => $this->storeId()]);
        if (!$img) {
            throw new HttpException(404);
        }
        Response::file($this->safe('product_images', $img['file_path']), 'image/jpeg');
    }

    private function findRecord(int $id, string $kind): array
    {
        $rec = Database::fetch('SELECT v.* FROM video_records v JOIN cameras c ON c.id = v.camera_id WHERE v.id = :id AND c.store_id = :s AND v.kind = :k AND v.deleted_at IS NULL',
            ['id' => $id, 's' => $this->storeId(), 'k' => $kind]);
        if (!$rec) {
            throw new HttpException(404, 'Média introuvable ou expiré.');
        }
        return $rec;
    }

    public function record(Request $r): void
    {
        $rec = $this->findRecord((int) $r->param('id'), 'snapshot');
        Response::file($this->safe(dirname($rec['file_path']), basename($rec['file_path'])), 'image/jpeg');
    }

    public function clipFrame(Request $r): void
    {
        $rec = $this->findRecord((int) $r->param('id'), 'extrait');
        $n = (int) $r->param('frame');
        if ($n < 0 || $n >= (int) $rec['frame_count']) {
            throw new HttpException(404);
        }
        Response::file($this->safe(dirname($rec['file_path']) . '/' . basename($rec['file_path']), sprintf('frame_%03d.jpg', $n)), 'image/jpeg');
    }
}
