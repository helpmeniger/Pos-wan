<?php
declare(strict_types=1);

namespace Addons\Archives;

use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Export;
use App\Core\Request;
use Addons\Archives\ArchiveService as S;

final class ArchiveController extends Controller
{
    /** Tous les dossiers visibles, avec leur chemin complet (listes de choix). */
    private function tree(): array
    {
        $rows = DB::all('SELECT * FROM arch_dossiers' . (can('archives.confidential') ? '' : ' WHERE confidential = 0') . ' ORDER BY name');
        $by = [];
        foreach ($rows as $r) {
            $by[(int) ($r['parent_id'] ?? 0)][] = $r;
        }
        $out = [];
        $walk = function (int $parent, string $prefix, int $depth) use (&$walk, &$out, $by) {
            foreach ($by[$parent] ?? [] as $d) {
                $out[] = ['id' => (int) $d['id'], 'label' => $prefix . $d['name'], 'depth' => $depth];
                if ($depth < 20) {
                    $walk((int) $d['id'], $prefix . $d['name'] . ' / ', $depth + 1);
                }
            }
        };
        $walk(0, '', 0);
        return $out;
    }

    public function index(): void
    {
        $vue = in_array(Request::query('vue'), ['echeances', 'corbeille'], true) ? (string) Request::query('vue') : 'dossiers';
        $dossierId = Request::int('dossier') ?: null;
        $q = Request::str('q');
        $cat = Request::str('categorie');
        $dossier = $dossierId ? $this->attempt(fn () => S::dossier($dossierId), '/archives') : null;
        $where = ['d.status = ?']; $params = [$vue === 'corbeille' ? 'corbeille' : 'actif'];
        if (!can('archives.confidential')) {
            $where[] = 'd.confidential = 0';
        }
        if ($vue === 'echeances') {
            $where[] = 'd.expires_on IS NOT NULL';
        } elseif ($vue === 'dossiers' && $q === '' && $cat === '') {
            $where[] = 'COALESCE(d.dossier_id, 0) = ?'; $params[] = (int) $dossierId; // contenu du dossier ouvert
        }
        if ($q !== '') {
            $where[] = '(d.title LIKE ? OR d.reference LIKE ? OR d.tags LIKE ? OR d.description LIKE ? OR EXISTS (SELECT 1 FROM arch_versions x WHERE x.document_id = d.id AND x.original_name LIKE ?))';
            array_push($params, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if (isset(S::CATEGORIES[$cat])) {
            $where[] = 'd.category = ?'; $params[] = $cat;
        }
        $from = 'FROM arch_documents d LEFT JOIN arch_dossiers f ON f.id = d.dossier_id LEFT JOIN arch_versions v ON v.document_id = d.id AND v.version = d.versions LEFT JOIN users u ON u.id = d.created_by WHERE ' . implode(' AND ', $where);
        $select = 'd.*, f.name AS dossier, v.id AS version_id, v.extension, v.size, v.original_name, u.full_name AS author';
        $order = $vue === 'echeances' ? 'd.expires_on, d.title' : ($vue === 'corbeille' ? 'd.deleted_at DESC' : 'd.created_at DESC, d.id DESC');
        if (Export::requested()) {
            Audit::log('archives.export', 'arch_documents', null, ['vue' => $vue]);
            Export::download('archives.csv', 'Registre des documents archivés', ['Titre', 'Référence', 'Catégorie', 'Dossier', 'Date', 'Échéance', 'Étiquettes', 'Fichier', 'Taille', 'Versions', 'Déposé le', 'Par'],
                array_map(fn ($r) => [$r['title'], (string) $r['reference'], t(S::CATEGORIES[$r['category']]), (string) $r['dossier'], $r['doc_date'] ? fdate($r['doc_date'], false) : '', $r['expires_on'] ? fdate($r['expires_on'], false) : '',
                    (string) $r['tags'], (string) $r['original_name'], S::humanSize((int) $r['size']), (int) $r['versions'], fdate($r['created_at'], false), (string) $r['author']], DB::all("SELECT $select $from ORDER BY $order", $params)), ['landscape' => true]);
        }
        $subs = $vue === 'dossiers' && $q === '' && $cat === '' ? DB::all('SELECT f.*, (SELECT COUNT(*) FROM arch_documents d WHERE d.dossier_id = f.id AND d.status = \'actif\') AS docs,
              (SELECT COUNT(*) FROM arch_dossiers s WHERE s.parent_id = f.id) AS subs FROM arch_dossiers f WHERE COALESCE(f.parent_id, 0) = ?' . (can('archives.confidential') ? '' : ' AND f.confidential = 0') . ' ORDER BY f.name', [(int) $dossierId]) : [];
        view('archives::index', ['title' => $vue === 'corbeille' ? 'Corbeille des archives' : ($vue === 'echeances' ? 'Échéances des documents' : 'Documents archivés'), 'vue' => $vue, 'dossier' => $dossier,
            'path' => S::path($dossierId), 'subs' => $subs, 'p' => paginate($from, $params, $select, $order, 40), 'q' => $q, 'cat' => $cat, 'stats' => S::stats(), 'tree' => $this->tree(),
            'editDossier' => Request::int('modifier') && $dossier ? $dossier : null, 'maxMb' => round(S::maxBytes() / 1048576, 1)]);
    }

    public function dossierSave(): void
    {
        $id = Request::int('id') ?: null;
        $back = '/archives' . (Request::int('parent_id') ? '?dossier=' . Request::int('parent_id') : '');
        $new = $this->attempt(fn () => S::saveDossier($_POST, $id), $back);
        flash('success', 'Dossier enregistré.');
        redirect('/archives', ['dossier' => $new]);
    }

    public function dossierDelete(int $id): void
    {
        $parent = (int) DB::value('SELECT parent_id FROM arch_dossiers WHERE id = ?', [$id]);
        $this->attempt(fn () => S::deleteDossier($id), '/archives?dossier=' . $id);
        flash('success', 'Dossier supprimé.');
        redirect('/archives', $parent ? ['dossier' => $parent] : []);
    }

    /** Dépôt d'un ou plusieurs fichiers dans le dossier ouvert. */
    public function deposit(): void
    {
        $dossier = Request::int('dossier_id');
        $back = '/archives' . ($dossier ? '?dossier=' . $dossier : '');
        $f = $_FILES['fichiers'] ?? null;
        if (!$f || !is_array($f['name'])) {
            $this->fail('Choisissez au moins un fichier. Si le fichier est très gros, il dépasse peut-être la limite du serveur (' . round(S::maxBytes() / 1048576, 1) . ' Mo).', $back);
        }
        $ok = 0; $errors = []; $dups = []; $last = 0;
        $many = count(array_filter($f['name'])) > 1;
        foreach ($f['name'] as $i => $name) {
            if ($name === '' || $f['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($f['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = "« $name » : " . (in_array($f['error'][$i], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'fichier trop gros pour le serveur.' : 'envoi interrompu.');
                continue;
            }
            try {
                $in = $_POST;
                if ($many) {
                    $in['title'] = ''; // plusieurs fichiers : chacun prend le nom de son fichier
                }
                $r = S::deposit($f['tmp_name'][$i], (string) $name, (int) $f['size'][$i], $in);
                $ok++; $last = $r['id'];
                if ($r['duplicate']) {
                    $dups[] = "« $name » a le même contenu que « {$r['duplicate']} »";
                }
            } catch (BusinessException $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($ok) {
            flash('success', "$ok document(s) archivé(s)." . ($dups ? ' Attention, doublon possible : ' . implode(' ; ', $dups) . '.' : ''));
        }
        if ($errors) {
            flash('danger', implode(' ', $errors));
        }
        redirect($ok === 1 && !$errors ? '/archives/documents/' . $last : $back);
    }

    public function show(int $id): void
    {
        $d = $this->attempt(fn () => S::document($id), '/archives');
        Audit::log('archives.consulte', 'arch_documents', $id, ['title' => $d['title']]);
        $versions = DB::all('SELECT v.*, u.full_name AS author FROM arch_versions v LEFT JOIN users u ON u.id = v.uploaded_by WHERE v.document_id = ? ORDER BY v.version DESC', [$id]);
        $integrity = null;
        if (Request::query('verifier') === '1' && $versions) {
            $integrity = $this->attempt(fn () => S::verify((int) $versions[0]['id']), '/archives/documents/' . $id);
            Audit::log('archives.verification', 'arch_documents', $id, ['ok' => $integrity]);
        }
        view('archives::document', ['title' => $d['title'], 'd' => $d, 'versions' => $versions, 'path' => S::path((int) $d['dossier_id'] ?: null), 'tree' => $this->tree(), 'integrity' => $integrity,
            'history' => DB::all("SELECT a.action, a.created_at, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.entity = 'arch_documents' AND a.entity_id = ? ORDER BY a.id DESC LIMIT 25", [$id]),
            'maxMb' => round(S::maxBytes() / 1048576, 1)]);
    }

    private function act(int $id, callable $fn, string $ok, ?string $to = null): void
    {
        $this->attempt($fn, '/archives/documents/' . $id);
        flash('success', $ok);
        redirect($to ?? '/archives/documents/' . $id);
    }

    public function update(int $id): void
    {
        $this->act($id, fn () => S::update($id, $_POST), 'Document mis à jour.');
    }

    public function version(int $id): void
    {
        $f = $_FILES['fichier'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            $this->fail('Choisissez le fichier de la nouvelle version (taille maximale : ' . round(S::maxBytes() / 1048576, 1) . ' Mo).', '/archives/documents/' . $id);
        }
        $this->act($id, fn () => S::addVersion($id, $f['tmp_name'], (string) $f['name'], (int) $f['size'], Request::str('note')), 'Nouvelle version archivée : les versions précédentes sont conservées.');
    }

    public function trash(int $id): void
    {
        $dos = (int) DB::value('SELECT dossier_id FROM arch_documents WHERE id = ?', [$id]);
        $this->act($id, fn () => S::trash($id), 'Document mis à la corbeille : il peut être restauré.', '/archives' . ($dos ? '?dossier=' . $dos : ''));
    }

    public function restore(int $id): void
    {
        $this->act($id, fn () => S::restore($id), 'Document restauré.');
    }

    public function purge(int $id): void
    {
        $this->act($id, fn () => S::purge($id, Request::str('confirmation')), 'Document supprimé définitivement.', '/archives?vue=corbeille');
    }

    /** Sert un fichier archivé : affichage (PDF, images) ou téléchargement ; jamais interprété par le navigateur. */
    public function file(int $id): void
    {
        $v = $this->attempt(fn () => S::file($id), '/archives');
        $inline = Request::query('voir') === '1' && isset(S::INLINE[$v['extension']]);
        Audit::log($inline ? 'archives.affiche' : 'archives.telecharge', 'arch_documents', (int) $v['document_id'], ['version' => $v['version']]);
        $name = preg_replace('/[^\w.\- ]+/u', '_', pathinfo($v['original_name'], PATHINFO_FILENAME)) . '.' . $v['extension'];
        header_remove('Content-Security-Policy');
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; object-src 'none'");
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . ($inline ? S::INLINE[$v['extension']] : 'application/octet-stream'));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
        header('Content-Length: ' . filesize($v['abs']));
        header('Cache-Control: private, no-store');
        readfile($v['abs']);
        exit;
    }
}
