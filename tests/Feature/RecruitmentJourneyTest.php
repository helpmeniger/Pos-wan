<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Auth\StaffAccountService;
use Tests\HttpTestCase;
use Tests\Support\HttpClient;

/**
 * Parcours réel dans le navigateur : inscription simple → offre → candidature → entretien → embauche
 * → acceptation → fiche de paie automatique → demande de paiement → versement → confirmation,
 * puis supervision par l'administrateur.
 */
final class RecruitmentJourneyTest extends HttpTestCase
{
    private static function uuidFrom(string $location): string
    {
        preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/', $location, $m);
        return $m[1] ?? '';
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(30, 20);
        ob_start();
        imagepng($img);
        $path = tempnam(sys_get_temp_dir(), 'jnpng') . '.png';
        file_put_contents($path, (string) ob_get_clean());
        return $path;
    }

    public function testCompleteJourneyFromJobOfferToPaidSalary(): void
    {
        // 1. Recruteur (entreprise) : inscription simple + document RCCM envoyé dès l'inscription
        $recruiter = $this->client();
        $recruiter->get('/inscription');
        $res = $recruiter->request('POST', '/inscription', [
            '_token' => $recruiter->csrf(), 'account_type' => 'BUSINESS', 'business_name' => 'Restaurant Le Fleuve', 'business_kind' => 'RESTAURANT',
            'first_name' => 'Hadiza', 'last_name' => 'Garba', 'phone' => self::uniquePhone(), 'city_id' => '1', 'password' => 'motdepasse1',
            'accept_terms' => '1', 'doc_type' => 'CNI', 'document' => new \CURLFile($this->png(), 'image/png', 'cni.png'),
        ], ['X-Forwarded-For' => '10.20.0.' . random_int(1, 250)]);
        $this->assertSame(303, $res['status']);
        $this->assertStringContainsString('document a été envoyé', $recruiter->follow($res)['body']);

        // 2. Publication d'une offre
        $res = $recruiter->submit('/recruteur/offres/nouvelle', '/recruteur/offres', [
            'title' => 'Serveur de salle', 'subcategory_id' => (string) $this->db()->fetchValue("SELECT id FROM job_subcategories WHERE slug = 'serveur'"),
            'contract_type' => 'EMPLOI_TEMPORAIRE', 'description' => 'Service en salle et accueil des clients, du lundi au samedi.',
            'city_id' => '1', 'address' => 'Plateau', 'positions' => '1', 'pay_rate' => '90 000', 'pay_unit' => 'MOIS',
            'start_date' => date('Y-m-d', strtotime('+2 days')), 'daily_start_time' => '08:00', 'daily_end_time' => '16:00',
        ]);
        $this->assertSame(303, $res['status'], substr(strip_tags($recruiter->lastBody), 0, 300));
        $postUuid = self::uuidFrom($res['headers']['location']);

        // 3. Candidat : voit l'offre et postule en un clic
        [$worker] = $this->registeredClient('WORKER', ['first_name' => 'Moussa']);
        $this->assertStringContainsString('Serveur de salle', $worker->get('/offres?q=Serveur')['body']);
        $worker->get('/offres/' . $postUuid);
        $apply = $worker->request('POST', '/offres/' . $postUuid . '/postuler', ['_token' => $worker->csrf(), 'message' => 'Disponible dès lundi.']);
        $this->assertStringEndsWith('/candidatures', $apply['headers']['location']);

        // 4. Recruteur : voit la candidature, propose un entretien en ligne
        $page = $recruiter->get('/recruteur/offres/' . $postUuid)['body'];
        $this->assertStringContainsString('Moussa', $page);
        preg_match('#/recruteur/candidatures/([0-9a-f-]{36})/entretien#', $page, $m);
        $appUuid = $m[1];
        $res = $recruiter->submit('/recruteur/candidatures/' . $appUuid . '/entretien', '/recruteur/candidatures/' . $appUuid . '/entretien', [
            'mode' => 'EN_LIGNE', 'date' => date('Y-m-d', strtotime('+1 day')), 'time' => '10:30', 'duration' => '30', 'meeting_link' => 'https://meet.google.com/abc-defg-hij',
        ]);
        $this->assertSame(303, $res['status']);

        // 5. Candidat : confirme sa présence
        $list = $worker->get('/candidatures')['body'];
        $this->assertStringContainsString('https://meet.google.com/abc-defg-hij', $list);
        preg_match('#/entretiens/([0-9a-f-]{36})/reponse#', $list, $m);
        $worker->request('POST', '/entretiens/' . $m[1] . '/reponse', ['_token' => $worker->csrf(), 'action' => 'confirm']);
        $this->assertSame('CONFIRME', $this->db()->fetchValue('SELECT status FROM interviews WHERE uuid = ?', [$m[1]]));

        // 6. Recruteur : embauche (poste, temps de travail, salaire) — début le 1er du mois dernier
        $start = date('Y-m-01', strtotime('first day of last month'));
        $res = $recruiter->submit('/recruteur/candidatures/' . $appUuid . '/embaucher', '/recruteur/candidatures/' . $appUuid . '/embaucher', [
            'position_title' => 'Serveur de salle', 'duties' => 'Service, encaissement', 'work_location' => 'Plateau', 'work_days' => 'Lundi au samedi',
            'daily_start_time' => '08:00', 'daily_end_time' => '16:00', 'hours_per_week' => '40', 'pay_period' => 'MENSUEL',
            'salary_amount' => '90000', 'payment_method' => 'MOBILE_MONEY', 'start_date' => $start,
        ]);
        $this->assertSame(303, $res['status'], substr(strip_tags($recruiter->lastBody), 0, 300));
        $employmentUuid = self::uuidFrom($res['headers']['location']);

        // 7. Employé : lit et accepte le contrat ; la fiche du mois écoulé est générée automatiquement
        $contract = $worker->get('/emplois/' . $employmentUuid)['body'];
        $this->assertStringContainsString('Lundi au samedi', $contract);
        $worker->request('POST', '/emplois/' . $employmentUuid . '/accepter', ['_token' => $worker->csrf(), 'confirm' => '1']);
        $contract = $worker->get('/emplois/' . $employmentUuid)['body'];
        preg_match('#/fiches-de-paie/([0-9a-f-]{36})"#', $contract, $m);
        $this->assertNotEmpty($m, 'Fiche de paie du mois écoulé générée');
        $payslip = $m[1];
        $this->assertMatchesRegularExpression('/81\x{202F}000\x{00A0}FCFA/u', $worker->get('/fiches-de-paie/' . $payslip)['body']);

        // 8. Demande de paiement Mobile Money → versement → confirmation de réception
        $worker->request('POST', '/fiches-de-paie/' . $payslip . '/demander-paiement', ['_token' => $worker->csrf(), 'method' => 'MOBILE_MONEY', 'payout_phone' => '96 11 22 33']);
        $recruiter->get('/fiches-de-paie/' . $payslip);
        $recruiter->request('POST', '/fiches-de-paie/' . $payslip . '/paiement-effectue', ['_token' => $recruiter->csrf(), 'reference' => 'MP261001.1234']);
        $worker->get('/fiches-de-paie/' . $payslip);
        $worker->request('POST', '/fiches-de-paie/' . $payslip . '/confirmer-reception', ['_token' => $worker->csrf()]);
        $this->assertSame('RECUE', $this->db()->fetchValue('SELECT status FROM payslips WHERE uuid = ?', [$payslip]));

        // 9. Fiche imprimable / PDF et archives pour les deux parties ; un tiers n'y a pas accès
        $this->assertStringContainsString('NET À PAYER', $worker->get('/fiches-de-paie/' . $payslip . '/imprimer')['body']);
        $this->assertStringContainsString('Serveur de salle', $recruiter->get('/archives')['body']);
        $this->assertStringContainsString('Serveur de salle', $worker->get('/archives')['body']);
        [$stranger] = $this->registeredClient('WORKER');
        $this->assertSame(404, $stranger->get('/fiches-de-paie/' . $payslip)['status']);
        $this->assertSame(404, $stranger->get('/emplois/' . $employmentUuid)['status']);
        [$otherRecruiter] = $this->registeredClient('CLIENT', ['birth_date' => '']);
        $this->assertSame(404, $otherRecruiter->get('/recruteur/offres/' . $postUuid)['status']);
        $this->assertSame(403, $worker->get('/recruteur/offres')['status'], 'Un candidat n\'accède pas à l\'espace recruteur');

        // 10. Administration : vérifie le document, surveille candidatures et finances
        $phone = self::uniquePhone();
        $this->service(StaffAccountService::class)->create($phone, 'Aminata', 'Admin', null, 'SUPER_ADMIN', 'AdminNiger2026');
        $admin = $this->client();
        $login = $admin->submit('/connexion', '/connexion', ['login' => $phone, 'password' => 'AdminNiger2026']);
        $this->assertStringEndsWith('/admin', $login['headers']['location']);
        foreach (['/admin', '/admin/utilisateurs', '/admin/verifications', '/admin/offres', '/admin/candidatures', '/admin/emplois',
                  '/admin/emplois/' . $employmentUuid, '/admin/finances', '/admin/fiches-de-paie/' . $payslip, '/admin/journaux',
                  '/admin/parametres', '/admin/support', '/admin/utilisateurs?q=Garba'] as $path) {
            $this->assertSame(200, $admin->get($path)['status'], $path);
        }
        $verifications = $admin->get('/admin/verifications')['body'];
        $this->assertStringContainsString('Hadiza Garba', $verifications);
        $requestId = (int) $this->db()->fetchValue(
            "SELECT r.id FROM verification_requests r JOIN business_members bm ON bm.user_id = r.user_id JOIN job_posts p ON p.client_user_id = r.user_id WHERE p.uuid = ? ORDER BY r.id DESC LIMIT 1",
            [$postUuid]
        );
        $this->assertStringContainsString('/admin/verifications/' . $requestId, $verifications);
        $admin->request('POST', '/admin/verifications/' . $requestId, ['_token' => $admin->csrf(), 'decision' => 'approve']);
        $this->assertStringContainsString('Recruteur vérifié', $worker->get('/offres/' . $postUuid)['body']);
        $csv = $admin->get('/admin/finances/export.csv');
        $this->assertStringContainsString('text/csv', $csv['headers']['content-type']);
        $this->assertStringContainsString('Serveur', $admin->get('/admin/emplois')['body']);
        $this->assertSame(403, $worker->get('/admin/finances')['status']);
    }

    public function testDisguisedScriptIsRefusedAtRegistrationButAccountIsCreated(): void
    {
        $c = new HttpClient(self::$base);
        $c->get('/inscription');
        $evil = tempnam(sys_get_temp_dir(), 'jnevil');
        file_put_contents($evil, '<?php echo "pirate"; ?>');
        $res = $c->request('POST', '/inscription', [
            '_token' => $c->csrf(), 'account_type' => 'WORKER', 'first_name' => 'Ali', 'last_name' => 'Test', 'phone' => self::uniquePhone(),
            'city_id' => '1', 'password' => 'motdepasse1', 'accept_terms' => '1', 'doc_type' => 'CNI',
            'document' => new \CURLFile($evil, 'image/jpeg', 'cni.jpg'),
        ], ['X-Forwarded-For' => '10.21.0.' . random_int(1, 250)]);
        $this->assertSame(303, $res['status']);
        $page = $c->follow($res)['body'];
        $this->assertStringContainsString('le document n&apos;a pas été accepté', $page);
        $this->assertStringContainsString('Format refusé', $page);
    }
}
