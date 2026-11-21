<?php
declare(strict_types=1);

namespace Addons\Modeles;

use App\Controllers\Controller;
use App\Core\HttpException;
use App\Core\Request;

final class ModeleController extends Controller
{
    public function index(): void
    {
        $models = Modeles::all();
        view('modeles::index', [
            'title' => 'Modèles de tickets et factures', 'models' => $models,
            'byType' => ['ticket' => array_values(array_filter($models, fn($m) => $m['type'] === 'ticket')), 'a4' => array_values(array_filter($models, fn($m) => $m['type'] === 'a4'))],
            'aff' => Modeles::assignments(), 'autres' => Modeles::others(), 'autresDispo' => Modeles::availableOthers(),
        ]);
    }

    public function assign(): void
    {
        $this->attempt(fn() => Modeles::saveAssignments((array) Request::input('doc', []), (array) Request::input('autre', [])), '/modeles');
        flash('success', 'Choix des modèles enregistré. Les prochains documents imprimés suivent ces modèles.');
        redirect('/modeles');
    }

    public function create(): void
    {
        $type = Request::query('type') === 'a4' ? 'a4' : 'ticket';
        $base = (string) Request::query('base', '');
        if (!isset(Modeles::BASES[$type][$base])) {
            $base = array_key_first(Modeles::BASES[$type]);
        }
        $this->form(['id' => null, 'nom' => '', 'type' => $type, 'base' => $base, 'options' => Modeles::defaults($type, $base)]);
    }

    public function edit(int $id): void
    {
        $m = Modeles::find($id) ?? throw new HttpException(404);
        $this->form($m);
    }

    private function form(array $m): void
    {
        view('modeles::edit', ['title' => $m['id'] ? 'Modèle « ' . $m['nom'] . ' »' : 'Nouveau modèle', 'm' => $m, 'aff' => Modeles::assignments()]);
    }

    public function store(): void
    {
        $type = Request::str('type') === 'a4' ? 'a4' : 'ticket';
        $id = $this->attempt(fn() => Modeles::save(null, Request::str('nom'), $type, Request::str('base'), (array) Request::input('o', [])), '/modeles/nouveau?type=' . $type);
        flash('success', 'Modèle créé. Choisissez-le pour un document dans la liste des modèles.');
        redirect('/modeles/' . $id);
    }

    public function update(int $id): void
    {
        $m = Modeles::find($id) ?? throw new HttpException(404);
        $this->attempt(fn() => Modeles::save($id, Request::str('nom'), $m['type'], Request::str('base'), (array) Request::input('o', [])), '/modeles/' . $id);
        flash('success', 'Modèle enregistré.');
        redirect('/modeles/' . $id);
    }

    public function duplicate(int $id): void
    {
        $new = $this->attempt(fn() => Modeles::duplicate($id), '/modeles');
        flash('success', 'Copie créée : personnalisez-la.');
        redirect('/modeles/' . $new);
    }

    public function delete(int $id): void
    {
        $freed = $this->attempt(fn() => Modeles::delete($id), '/modeles');
        flash('success', 'Modèle supprimé.' . ($freed ? ' Ces documents reprennent le modèle des Paramètres : ' . implode(', ', $freed) . '.' : ''));
        redirect('/modeles');
    }

    public function export(int $id): void
    {
        $d = $this->attempt(fn() => Modeles::export($id), '/modeles');
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $d['nom']) ?: 'modele');
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="modele-' . trim($name, '-') . '.json"');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function import(): void
    {
        $f = $_FILES['fichier'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > 64 * 1024) {
            $this->fail('Choisissez un fichier de modèle (.json, 64 Ko au plus).', '/modeles');
        }
        $id = $this->attempt(fn() => Modeles::import((string) file_get_contents($f['tmp_name'])), '/modeles');
        flash('success', 'Modèle importé.');
        redirect('/modeles/' . $id);
    }

    /** Aperçu d'un modèle non enregistré (formulaire en cours de saisie), avec un document fictif. */
    public function preview(): void
    {
        $type = Request::str('type') === 'a4' ? 'a4' : 'ticket';
        $base = Request::str('base');
        if (!isset(Modeles::BASES[$type][$base])) {
            throw new HttpException(400);
        }
        $m = ['id' => 0, 'nom' => 'Aperçu', 'type' => $type, 'base' => $base, 'options' => Modeles::normalize($type, $base, (array) Request::input('o', []))];
        $this->renderPreview($m, Request::str('kind'));
    }

    public function previewSaved(int $id): void
    {
        $m = Modeles::find($id) ?? throw new HttpException(404);
        $this->renderPreview($m, (string) Request::query('kind', ''));
    }

    private function renderPreview(array $m, string $kind): void
    {
        $kinds = $m['type'] === 'a4' ? ['invoice', 'quote', 'delivery'] : ['sale', 'credit'];
        $kind = in_array($kind, $kinds, true) ? $kind : $kinds[0];
        view('modeles::apercu', [
            'title' => 'Aperçu', 'doc' => Modeles::sampleDoc($m['type'], $kind), 'modele' => $m,
            'bodyClass' => $m['type'] === 'a4' ? 'a4' : (setting('ticket_width', '80') === '58' ? 't58' : 't80'), 'backUrl' => url('/modeles'),
        ], 'layouts/print');
    }
}
