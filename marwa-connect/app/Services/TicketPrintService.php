<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;

/**
 * Mise en forme des tickets pour l'impression (HTML) et l'export PDF.
 */
final class TicketPrintService
{
    public const FORMATS = [
        'single' => ['label' => 'Ticket individuel', 'cols' => 1, 'rows' => 1, 'page' => [90, 60]],
        'sheet10' => ['label' => 'Planche de 10 (A4)', 'cols' => 2, 'rows' => 5, 'page' => [210, 297]],
        'sheet20' => ['label' => 'Planche de 20 (A4)', 'cols' => 4, 'rows' => 5, 'page' => [210, 297]],
        'sheet40' => ['label' => 'Planche de 40 (A4)', 'cols' => 5, 'rows' => 8, 'page' => [210, 297]],
        'thermal58' => ['label' => 'Thermique 58 mm', 'cols' => 1, 'rows' => 1, 'page' => [58, 70]],
        'thermal80' => ['label' => 'Thermique 80 mm', 'cols' => 1, 'rows' => 1, 'page' => [80, 75]],
        'a4' => ['label' => 'Format A4 (3 × 8)', 'cols' => 3, 'rows' => 8, 'page' => [210, 297]],
    ];
    public const FIELDS = [
        'logo' => 'Logo / nom', 'offer' => "Nom de l'offre", 'username' => 'Identifiant', 'password' => 'Mot de passe',
        'duration' => 'Durée', 'price' => 'Prix', 'ssid' => 'Réseau Wi-Fi', 'instructions' => 'Instructions', 'serial' => 'N° de série', 'dns' => 'Adresse de connexion',
    ];

    public static function template(?int $id): array
    {
        $tpl = $id ? Database::fetch('SELECT * FROM ticket_print_templates WHERE id = :id', ['id' => $id]) : null;
        $tpl ??= Database::fetch('SELECT * FROM ticket_print_templates ORDER BY is_default DESC, id LIMIT 1');
        $tpl['fields'] = json_decode((string) $tpl['fields'], true) ?: array_keys(self::FIELDS);
        $tpl['options'] = json_decode((string) ($tpl['options'] ?? ''), true) ?: [];
        return $tpl;
    }

    /** Prépare les données imprimables (mots de passe déchiffrés côté serveur uniquement). */
    public static function prepare(array $ticketIds): array
    {
        if (!$ticketIds) {
            return [];
        }
        $ids = implode(',', array_map('intval', $ticketIds));
        $rows = Database::fetchAll(
            "SELECT t.*, o.name AS offer_name, o.price AS offer_price, o.validity_value, o.validity_unit, o.time_limit_seconds AS ot, o.data_limit_bytes AS od,
                    r.wifi_ssid, r.hotspot_dns_name
               FROM tickets t JOIN offers o ON o.id = t.offer_id JOIN routers r ON r.id = t.router_id
              WHERE t.id IN ({$ids}) ORDER BY t.id"
        );
        $company = (string) setting('company.name', 'MARWA CONNECT');
        $defaultSsid = (string) setting('tickets.wifi_ssid', '');
        $instructions = (string) setting('tickets.login_instructions', '');
        $out = [];
        foreach ($rows as $r) {
            $offer = ['validity_value' => $r['validity_value'], 'validity_unit' => $r['validity_unit'], 'time_limit_seconds' => $r['ot'], 'data_limit_bytes' => $r['od']];
            $out[] = [
                'id' => (int) $r['id'],
                'company' => $company,
                'offer' => $r['offer_name'],
                'username' => $r['username'],
                'password' => Crypto::decrypt($r['password_enc']),
                'same' => false,
                'duration' => TicketService::durationLabel($offer),
                'price' => money($r['price']),
                'ssid' => $r['wifi_ssid'] ?: $defaultSsid,
                'dns' => $r['hotspot_dns_name'],
                'instructions' => $instructions,
                'serial' => '#' . str_pad((string) $r['id'], 6, '0', STR_PAD_LEFT),
            ];
        }
        foreach ($out as &$t) {
            $t['same'] = $t['username'] === $t['password'];
        }
        return $out;
    }

    public static function pdf(array $tickets, array $tpl): string
    {
        $fmt = self::FORMATS[$tpl['format']] ?? self::FORMATS['sheet20'];
        [$pw, $ph] = $fmt['page'];
        $pdf = new PdfWriter($pw, $ph);
        $fields = array_flip($tpl['fields']);
        $accent = $tpl['options']['accent'] ?? '#D7192A';
        $margin = $fmt['cols'] > 1 ? 8 : 2;
        $cellW = ($pw - 2 * $margin) / $fmt['cols'];
        $cellH = ($ph - 2 * $margin) / $fmt['rows'];
        $perPage = $fmt['cols'] * $fmt['rows'];
        $small = $fmt['cols'] >= 4;
        foreach (array_values($tickets) as $i => $t) {
            if ($i % $perPage === 0) {
                $pdf->addPage();
            }
            $pos = $i % $perPage;
            $x = PdfWriter::mm($margin + ($pos % $fmt['cols']) * $cellW);
            $y = PdfWriter::mm($margin + intdiv($pos, $fmt['cols']) * $cellH);
            $w = PdfWriter::mm($cellW);
            $h = PdfWriter::mm($cellH);
            $pad = PdfWriter::mm(1.5);
            $pdf->rect($x + 1, $y + 1, $w - 2, $h - 2, null, '#9AA3B5', 0.5, true);
            $cx = $x + $w / 2;
            $cy = $y + $pad + 2;
            $fs = $small ? 0.8 : 1.0;
            if (isset($fields['logo'])) {
                $pdf->rect($x + 1, $y + 1, $w - 2, 11 * $fs + 4, $accent, null);
                $pdf->text($cx, $y + 10 * $fs + 1, mb_strtoupper($t['company']), 8 * $fs, true, '#FFFFFF', 'C');
                $cy = $y + 14 * $fs + 8;
            }
            if (isset($fields['offer'])) {
                $pdf->text($cx, $cy + 8 * $fs, $t['offer'], 9 * $fs, true, '#0B1A33', 'C');
                $cy += 12 * $fs;
            }
            $line = static function (string $label, string $value, bool $big = false) use ($pdf, $x, $w, $pad, &$cy, $fs): void {
                $pdf->text($x + $pad + 2, $cy + 8 * $fs, $label, 7 * $fs, false, '#6B7590');
                $pdf->text($x + $w - $pad - 2, $cy + 8 * $fs, $value, ($big ? 10 : 8) * $fs, $big, '#000000', 'R');
                $cy += ($big ? 12 : 10) * $fs;
            };
            if ($t['same'] && isset($fields['username'])) {
                $line('Code', $t['username'], true);
            } else {
                isset($fields['username']) && $line('Identifiant', $t['username'], true);
                isset($fields['password']) && $line('Mot de passe', $t['password'], true);
            }
            isset($fields['duration']) && $line('Durée', $t['duration']);
            isset($fields['price']) && $line('Prix', $t['price']);
            isset($fields['ssid']) && $t['ssid'] && $line('Wi-Fi', $t['ssid']);
            isset($fields['dns']) && $t['dns'] && $line('Connexion', $t['dns']);
            if (isset($fields['instructions']) && $t['instructions'] && !$small && $cy + 16 < $y + $h) {
                $pdf->paragraph($x + $pad + 2, $cy + 8, $w - 2 * $pad - 4, $t['instructions'], 6.5, '#444444', true);
            }
            if (isset($fields['serial'])) {
                $pdf->text($x + $w - $pad - 2, $y + $h - 5, $t['serial'], 5.5, false, '#9AA3B5', 'R');
            }
        }
        return $pdf->output();
    }
}
