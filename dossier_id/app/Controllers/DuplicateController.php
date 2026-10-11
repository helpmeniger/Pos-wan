<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Services\Access;
use App\Services\DuplicateService;

/**
 * Doublons potentiels (analyse des informations administratives) et
 * comparaison VISUELLE manuelle des photographies par l'agent.
 * Aucune comparaison automatique de visages n'est effectuée.
 */
final class DuplicateController extends Controller
{
    public function index(): string
    {
        Auth::require('persons.view');
        Auth::require('duplicates.review');
        $pairs = DuplicateService::scan(Access::personSqlFilter('a'), Access::personSqlFilter('b'));
        Audit::log('duplicate.scan', null, null, ['paires' => count($pairs)]);
        $reviews = Database::all(
            'SELECT r.*, a.file_number AS a_number, b.file_number AS b_number, u.full_name
             FROM duplicate_reviews r JOIN persons a ON a.id = r.person_a_id JOIN persons b ON b.id = r.person_b_id
             LEFT JOIN users u ON u.id = r.reviewed_by
             WHERE ' . Access::personSqlFilter('a') . ' AND ' . Access::personSqlFilter('b') . '
             ORDER BY r.reviewed_at DESC LIMIT 20'
        );
        return $this->view('duplicates/index', ['title' => 'Doublons potentiels', 'pairs' => $pairs, 'reviews' => $reviews]);
    }

    public function compare(): string
    {
        Auth::require('persons.view');
        Auth::require('photos.view');
        $aNum = strtoupper($this->query('a_number'));
        $bNum = strtoupper($this->query('b_number'));
        if ($aNum !== '' && $bNum !== '') {
            $a = Database::value('SELECT id FROM persons WHERE file_number = :n', ['n' => $aNum]);
            $b = Database::value('SELECT id FROM persons WHERE file_number = :n', ['n' => $bNum]);
            if (!$a || !$b) {
                $this->back('duplicates.compare', [], 'warning', 'Numéro de dossier introuvable.');
            }
            $this->redirect('duplicates.compare', ['a' => $a, 'b' => $b]);
        }
        if (!isset($_GET['a'], $_GET['b'])) {
            return $this->view('duplicates/compare', ['title' => 'Comparaison visuelle', 'a' => null, 'b' => null]);
        }
        $aId = $this->id('a');
        $bId = $this->id('b');
        if ($aId === $bId) {
            throw new HttpException(422, 'Sélectionnez deux fiches différentes.');
        }
        $a = Access::personOrFail($aId);
        $b = Access::personOrFail($bId);
        Audit::log('duplicate.compare', 'person', $aId, ['autre_fiche' => $bId]);
        $photos = fn (int $id) => Database::all(
            "SELECT * FROM photos WHERE person_id = :p AND status = 'active' ORDER BY is_primary DESC, uploaded_at DESC",
            ['p' => $id]
        );
        [$lo, $hi] = $aId < $bId ? [$aId, $bId] : [$bId, $aId];
        return $this->view('duplicates/compare', [
            'title' => 'Comparaison visuelle',
            'a' => $a, 'b' => $b,
            'a_photos' => $photos($aId), 'b_photos' => $photos($bId),
            'match' => DuplicateService::score($a, $b),
            'review' => Database::one('SELECT r.*, u.full_name FROM duplicate_reviews r LEFT JOIN users u ON u.id = r.reviewed_by
                                       WHERE person_a_id = :a AND person_b_id = :b', ['a' => $lo, 'b' => $hi]),
        ]);
    }

    public function review(): never
    {
        Auth::require('duplicates.review');
        $aId = $this->id('a');
        $bId = $this->id('b');
        if ($aId === $bId) {
            throw new HttpException(422, 'Fiches identiques.');
        }
        Access::personOrFail($aId);
        Access::personOrFail($bId);
        $decision = $this->input('decision');
        $comment = $this->input('comment');
        if (!in_array($decision, ['distinctes', 'doublon_confirme'], true)) {
            throw new HttpException(422, 'Décision invalide.');
        }
        if (mb_strlen($comment) < 5) {
            $this->back('duplicates.compare', ['a' => $aId, 'b' => $bId], 'danger', 'Un commentaire justifiant la décision est obligatoire (vérifications effectuées).');
        }
        [$lo, $hi] = $aId < $bId ? [$aId, $bId] : [$bId, $aId];
        Database::run(
            'INSERT INTO duplicate_reviews (person_a_id, person_b_id, decision, comment, reviewed_by, reviewed_at)
             VALUES (:a, :b, :d, :c, :u, NOW())
             ON DUPLICATE KEY UPDATE decision = VALUES(decision), comment = VALUES(comment), reviewed_by = VALUES(reviewed_by), reviewed_at = NOW()',
            ['a' => $lo, 'b' => $hi, 'd' => $decision, 'c' => mb_substr($comment, 0, 500), 'u' => $this->userId()]
        );
        Audit::log('duplicate.review', 'person', $lo, ['autre_fiche' => $hi, 'decision' => $decision]);
        $msg = $decision === 'doublon_confirme'
            ? 'Doublon confirmé. Archivez la fiche redondante avec le motif « doublon de … » après avoir reporté les informations utiles.'
            : 'Les fiches ont été déclarées distinctes.';
        $this->back('duplicates.compare', ['a' => $aId, 'b' => $bId], 'success', $msg);
    }
}
