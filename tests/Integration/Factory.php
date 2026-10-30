<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Container;
use App\Core\Request;
use App\Services\Auth\RegistrationService;
use App\Services\Jobs\JobPostService;

/** Création rapide de comptes et d'offres pour les tests. */
final class Factory
{
    private static int $n = 0;

    public static function phone(): string
    {
        self::$n++;
        return '+2279' . str_pad((string) ((getmypid() * 31 + self::$n * 7907 + random_int(0, 9999)) % 10000000), 7, '0', STR_PAD_LEFT);
    }

    public static function user(Container $app, string $type = 'WORKER', array $extra = []): int
    {
        return $app->get(RegistrationService::class)->register(array_merge([
            'account_type' => $type, 'first_name' => $type === 'WORKER' ? 'Moussa' : 'Hadiza', 'last_name' => 'Test', 'phone' => self::phone(),
            'email' => null, 'city_id' => 1, 'birth_date' => $type === 'WORKER' ? '1998-04-02' : null, 'password' => 'motdepasse1',
            'business_name' => 'Restaurant Test', 'business_kind' => 'RESTAURANT',
        ], $extra), new Request('POST', '/', server: ['REMOTE_ADDR' => '10.0.0.' . random_int(1, 250)]));
    }

    public static function post(Container $app, int $recruiterId, array $extra = []): array
    {
        $sub = (int) $app->get(\App\Core\Database::class)->fetchValue("SELECT id FROM job_subcategories WHERE slug = 'serveur'");
        $uuid = $app->get(JobPostService::class)->create($recruiterId, array_merge([
            'title' => 'Serveur pour restaurant', 'subcategory_id' => $sub, 'contract_type' => 'EMPLOI_TEMPORAIRE',
            'description' => 'Service en salle, accueil des clients, encaissement. Repas offert.', 'city_id' => 1,
            'neighborhood_id' => null, 'address' => 'Plateau', 'positions' => 1, 'pay_rate' => 90000, 'pay_unit' => 'MOIS',
            'start_date' => date('Y-m-d', strtotime('+3 days')), 'end_date' => null, 'application_deadline' => null,
            'daily_start_time' => '08:00', 'daily_end_time' => '16:00', 'schedule_notes' => 'Du lundi au samedi',
        ], $extra));
        return $uuid;
    }
}
