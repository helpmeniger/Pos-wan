<?php
declare(strict_types=1);

namespace Addons\Hotel;

use Addons\Hotel\HotelService as HS;
use App\Controllers\Controller;
use App\Core\Audit;
use App\Core\BusinessException;
use App\Core\DB;
use App\Core\Request;

/** Configuration de l'hôtel : types de chambres et tarifs, chambres, prestations, réglages. */
final class SetupController extends Controller
{
    public function index(): void
    {
        view('hotel::setup', ['title' => 'Chambres, tarifs et réglages',
            'types' => DB::all('SELECT t.*, (SELECT COUNT(*) FROM hotel_rooms r WHERE r.type_id = t.id AND r.active = 1) AS rooms FROM hotel_room_types t ORDER BY t.sort_order, t.name'),
            'rooms' => DB::all('SELECT r.*, t.name AS type_name FROM hotel_rooms r JOIN hotel_room_types t ON t.id = r.type_id ORDER BY r.active DESC, r.sort_order, r.number'),
            'extras' => DB::all('SELECT * FROM hotel_extras ORDER BY active DESC, sort_order, name'), 'tab' => (string) Request::query('tab', 'types')]);
    }

    private function fields(array $keys): array
    {
        $d = [];
        foreach ($keys as $k) {
            $d[$k] = Request::input($k);
        }
        return $d;
    }

    public function typeSave(): void
    {
        $id = Request::int('id') ?: null;
        $this->attempt(fn() => HS::saveType($this->fields(['name', 'code', 'capacity', 'base_price', 'weekend_price', 'amenities', 'color', 'active']), $id), '/hotel/configuration?tab=types');
        flash('success', t('Type de chambre enregistré.'));
        redirect('/hotel/configuration', ['tab' => 'types']);
    }

    public function roomSave(): void
    {
        $id = Request::int('id') ?: null;
        $this->attempt(fn() => HS::saveRoom($this->fields(['number', 'floor', 'type_id', 'beds', 'note', 'active']) + (Request::str('sort_order') !== '' ? ['sort_order' => Request::int('sort_order')] : []), $id), '/hotel/configuration?tab=chambres');
        flash('success', t('Chambre enregistrée.'));
        redirect('/hotel/configuration', ['tab' => 'chambres']);
    }

    public function extraSave(): void
    {
        $id = Request::int('id') ?: null;
        $this->attempt(fn() => HS::saveExtra($this->fields(['name', 'kind', 'price', 'icon', 'active']), $id), '/hotel/configuration?tab=prestations');
        flash('success', t('Prestation enregistrée.'));
        redirect('/hotel/configuration', ['tab' => 'prestations']);
    }

    public function settingsSave(): void
    {
        $this->attempt(function () {
            foreach (['hotel_heure_arrivee', 'hotel_heure_depart'] as $k) {
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', Request::str($k))) {
                    throw new BusinessException('Heure invalide (format HH:MM).');
                }
            }
            $tax = (float) str_replace([' ', ','], ['', '.'], Request::str('hotel_taxe_sejour', '0'));
            $pct = (float) str_replace([' ', ','], ['', '.'], Request::str('hotel_depart_tardif_pourcent', '50'));
            if ($tax < 0 || $tax > 1000000 || $pct < 0 || $pct > 100) {
                throw new BusinessException('Taxe de séjour ou pourcentage de départ tardif invalide.');
            }
            setting_set('hotel_heure_arrivee', Request::str('hotel_heure_arrivee'));
            setting_set('hotel_heure_depart', Request::str('hotel_heure_depart'));
            setting_set('hotel_taxe_sejour', (string) round($tax, 2));
            setting_set('hotel_depart_tardif_pourcent', (string) round($pct, 2));
            setting_set('hotel_piece_obligatoire', Request::input('hotel_piece_obligatoire') === '1' ? '1' : '0');
            setting_set('hotel_exiger_propre', Request::input('hotel_exiger_propre') === '1' ? '1' : '0');
            setting_set('hotel_conditions', mb_substr(Request::str('hotel_conditions'), 0, 1000));
            Audit::log('hotel.settings');
        }, '/hotel/configuration?tab=reglages');
        flash('success', t('Réglages de l\'hôtel enregistrés.'));
        redirect('/hotel/configuration', ['tab' => 'reglages']);
    }
}
