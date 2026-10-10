<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Request;
use App\Services\PrintService;

final class PrinterController extends Controller
{
    public function index(): void
    {
        view('printers/index', [
            'title' => 'Imprimantes',
            'printers' => DB::all('SELECT p.*, r.name AS register FROM printers p LEFT JOIN cash_registers r ON r.id = p.register_id ORDER BY p.id'),
            'registers' => DB::all('SELECT id, name FROM cash_registers WHERE active = 1 ORDER BY name'),
            'usages' => PrintService::usages(),
            'edit' => ($id = Request::int('edit')) ? DB::one('SELECT * FROM printers WHERE id = ?', [$id]) : null,
        ]);
    }

    private function data(): array
    {
        $driver = Request::str('driver');
        if (!isset(PrintService::DRIVERS[$driver])) {
            throw new BusinessException('Type de connexion invalide.');
        }
        $name = Request::str('name');
        if ($name === '' || mb_strlen($name) > 100) {
            throw new BusinessException('Nom de l\'imprimante obligatoire.');
        }
        $usages = array_values(array_intersect(array_keys(PrintService::usages()), (array) Request::input('usages', [])));
        if (!$usages) {
            throw new BusinessException('Choisissez au moins un usage (tickets, cuisine, bar…).');
        }
        $reg = Request::int('register_id') ?: null;
        if ($reg && !DB::value('SELECT 1 FROM cash_registers WHERE id = ?', [$reg])) {
            throw new BusinessException('Caisse invalide.');
        }
        return [
            'name' => $name, 'driver' => $driver, 'address' => PrintService::validateAddress($driver, Request::str('address')) ?: null,
            'paper' => in_array(Request::str('paper'), ['58', '80', 'a4'], true) ? Request::str('paper') : '80',
            'usages' => implode(',', $usages), 'register_id' => $reg, 'copies' => max(1, min(5, Request::int('copies', 1))),
            'cut' => Request::input('cut') === '1' ? 1 : 0, 'open_drawer' => Request::input('open_drawer') === '1' ? 1 : 0,
            'active' => Request::input('active', '1') === '1' ? 1 : 0,
        ];
    }

    public function store(): void
    {
        $id = $this->attempt(fn() => DB::insert('printers', $this->data()), '/printers');
        Audit::log('printer.create', 'printer', $id);
        flash('success', 'Imprimante ajoutée. Utilisez « Tester » pour vérifier la connexion.');
        redirect('/printers');
    }

    public function update(int $id): void
    {
        $this->findOr404('printers', $id);
        $this->attempt(fn() => DB::update('printers', $this->data(), 'id = ?', [$id]), '/printers?edit=' . $id);
        Audit::log('printer.update', 'printer', $id);
        flash('success', 'Imprimante mise à jour.');
        redirect('/printers');
    }

    public function delete(int $id): void
    {
        $p = $this->findOr404('printers', $id);
        DB::run('DELETE FROM printers WHERE id = ?', [$id]);
        Audit::log('printer.delete', 'printer', $id, ['name' => $p['name']]);
        flash('success', 'Imprimante supprimée.');
        redirect('/printers');
    }

    public function test(int $id): void
    {
        $p = $this->findOr404('printers', $id);
        if ($p['driver'] === 'browser') {
            flash('info', 'Imprimante « navigateur » : utilisez le bouton Imprimer de n\'importe quel ticket pour la tester.');
            redirect('/printers');
        }
        $this->attempt(fn() => PrintService::send($p, PrintService::testPage($p)), '/printers');
        flash('success', 'Page de test envoyée à « ' . $p['name'] . ' ».');
        redirect('/printers');
    }
}
