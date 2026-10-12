<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Session;
use App\Services\UpdateService;

/** Configuration → Mise à jour du logiciel (sélection du dossier de la nouvelle version). */
final class UpdateController extends Controller
{
    public function index(): void
    {
        $toBytes = static function (string $v): int {
            $n = (int) $v;
            return match (strtolower(substr(trim($v), -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
        };
        view('update/index', [
            'title' => 'Mise à jour du logiciel',
            'limits' => [
                'files' => max(1, min(50, (int) ini_get('max_file_uploads') - 1)),
                'bytes' => max(256 * 1024, (int) (min($toBytes((string) ini_get('post_max_size')), $toBytes((string) ini_get('upload_max_filesize')) * 4) * 0.8)),
                'file' => $toBytes((string) ini_get('upload_max_filesize')),
            ],
            'codeBackups' => UpdateService::codeBackups(),
            'zip' => class_exists(\ZipArchive::class),
            'scripts' => ['js/update.js'],
        ]);
    }

    private function token(): string
    {
        $t = Request::str('token');
        if ($t === '' || !hash_equals((string) Session::get('update_token', ''), $t)) {
            json_response(['ok' => false, 'error' => 'Session de mise à jour expirée : recommencez.'], 422);
        }
        return $t;
    }

    private function checkPassword(): void
    {
        $hash = (string) DB::value('SELECT password_hash FROM users WHERE id = ?', [$this->user()['id']]);
        if (!password_verify(Request::str('password'), $hash)) {
            Audit::log('system.update.denied', null, null, ['reason' => 'mot de passe']);
            throw new BusinessException('Mot de passe incorrect.');
        }
    }

    public function start(): void
    {
        $this->jsonAttempt(function () {
            $this->checkPassword();
            $t = UpdateService::start();
            Session::set('update_token', $t);
            return ['token' => $t];
        });
    }

    public function upload(): void
    {
        $t = $this->token();
        $this->jsonAttempt(fn() => UpdateService::receive($t, (array) ($_POST['paths'] ?? []), (array) ($_FILES['files'] ?? [])));
    }

    public function check(): void
    {
        $t = $this->token();
        $this->jsonAttempt(fn() => UpdateService::inspect($t));
    }

    public function apply(): void
    {
        $t = $this->token();
        try {
            $r = UpdateService::apply($t, Request::input('accept') === true || Request::str('accept') === '1');
        } catch (BusinessException $e) {
            json_response(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Logger::error('Mise à jour impossible : ' . $e->getMessage(), ['file' => $e->getFile() . ':' . $e->getLine()]);
            json_response(['ok' => false, 'error' => 'Mise à jour interrompue : ' . $e->getMessage()], 500);
        }
        Session::forget('update_token');
        flash('success', 'POS Wan mis à jour : version ' . $r['from'] . ' → ' . $r['to'] . ' (' . $r['files'] . ' fichiers). Sauvegardes : ' . $r['db_backup'] . ' et ' . $r['code_backup'] . '. La base de données a été mise à jour automatiquement.');
        json_response(['ok' => true] + $r);
    }

    public function rollback(): void
    {
        $this->attempt(function () {
            $this->checkPassword();
            $n = UpdateService::restoreCode(Request::str('name'));
            flash('success', 'Code précédent remis en place (' . $n . ' fichiers). La base de données n\'a pas été modifiée.');
        }, '/update');
        redirect('/update');
    }
}
