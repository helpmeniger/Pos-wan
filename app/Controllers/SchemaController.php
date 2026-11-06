<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\SchemaSync;

/** Système → Vérification de la base : structure attendue / réelle, réparation sans perte. */
final class SchemaController extends Controller
{
    public function index(): void
    {
        view('system/database', ['title' => 'Vérification de la base de données', 'c' => SchemaSync::check(), 'last' => SchemaSync::lastReport(),
            'version' => (string) config('app.version'), 'db' => (string) \App\Core\DB::value('SELECT DATABASE()')]);
    }

    public function repair(): void
    {
        try {
            $r = SchemaSync::repair();
        } catch (\Throwable $e) {
            \App\Core\Logger::error('Réparation de la base impossible : ' . $e->getMessage());
            $this->fail('Réparation impossible : ' . $e->getMessage(), '/system/database');
        }
        $n = count($r['created']) + count($r['columns']) + count($r['enums']) + count($r['migrations']);
        flash($r['errors'] ? 'warning' : 'success', $n === 0 && !$r['errors'] ? 'La base était déjà complète : rien à réparer.'
            : sprintf('Base synchronisée : %d migration(s), %d table(s) créée(s), %d colonne(s) ajoutée(s), %d liste(s) de valeurs élargie(s)%s.', count($r['migrations']), count($r['created']), count($r['columns']), count($r['enums']), $r['errors'] ? ', ' . count($r['errors']) . ' erreur(s) (détail ci-dessous)' : ''));
        redirect('/system/database');
    }
}
