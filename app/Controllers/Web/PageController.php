<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use App\Repositories\SettingRepository;
use App\Services\Auth\AuthService;
use App\Support\Money;

/** Pages d'information et formulaire de contact (crée un ticket de support). */
final class PageController extends Controller
{
    public function __construct(
        View $view,
        Session $session,
        private readonly Database $db,
        private readonly SettingRepository $settings,
        private readonly AuthService $auth,
    ) {
        parent::__construct($view, $session);
    }

    public function about(Request $request): Response
    {
        // Exemple calculé à partir de la règle de commission globale active (aucune valeur codée en dur)
        $rule = $this->db->fetch(
            "SELECT client_rate, worker_rate FROM commission_rules
             WHERE scope = 'GLOBAL' AND is_active = 1 AND valid_from <= NOW() AND (valid_to IS NULL OR valid_to > NOW())
             ORDER BY priority DESC, id DESC LIMIT 1"
        ) ?? ['client_rate' => '0.00', 'worker_rate' => '0.00'];
        $service = Money::xof(10000);
        $workerCommission = $service->percentBps(Money::rateToBps((string) $rule['worker_rate']));
        $clientCommission = $service->percentBps(Money::rateToBps((string) $rule['client_rate']));
        return $this->render('public/about', [
            'title' => 'Présentation de JOB NIGER',
            'example' => [
                'service' => $service->amount(),
                'worker_rate' => rtrim(rtrim((string) $rule['worker_rate'], '0'), '.'),
                'client_rate' => rtrim(rtrim((string) $rule['client_rate'], '0'), '.'),
                'worker_commission' => $workerCommission->amount(),
                'client_commission' => $clientCommission->amount(),
                'worker_net' => $service->subtract($workerCommission)->amount(),
                'client_total' => $service->add($clientCommission)->amount(),
            ],
        ]);
    }

    public function help(Request $request): Response
    {
        return $this->render('public/help', ['title' => 'Centre d\'aide']);
    }

    public function terms(Request $request): Response
    {
        return $this->legal('CGU', 'Conditions générales d\'utilisation', 'public/legal/terms', (string) $this->settings->get('legal.cgu_version'));
    }

    public function privacy(Request $request): Response
    {
        return $this->legal('PRIVACY', 'Politique de confidentialité', 'public/legal/privacy', (string) $this->settings->get('legal.privacy_version'));
    }

    private function legal(string $code, string $title, string $fallbackView, string $version): Response
    {
        // Un document publié par l'administration prime sur le brouillon intégré
        $doc = $this->db->fetch(
            'SELECT title, content, version, published_at FROM legal_documents
             WHERE code = ? AND published_at IS NOT NULL ORDER BY published_at DESC LIMIT 1',
            [$code]
        );
        return $this->render('public/legal/document', [
            'title' => $title,
            'document' => $doc,
            'fallbackView' => $fallbackView,
            'version' => $doc['version'] ?? $version,
        ]);
    }

    public function contact(Request $request): Response
    {
        return $this->render('public/contact', ['title' => 'Contact', 'user' => $this->auth->user()]);
    }

    public function sendContact(Request $request): Response
    {
        $data = Validator::validate($request->all(), [
            'name' => 'required|string|min:2|max:160',
            'phone' => 'nullable|phone',
            'email' => 'nullable|email',
            'category' => 'required|in:COMPTE,MISSION,PAIEMENT,VERSEMENT,TECHNIQUE,SECURITE,AUTRE',
            'subject' => 'required|string|min:4|max:200',
            'message' => 'required|string|min:10|max:4000',
        ], ['name' => 'nom', 'phone' => 'téléphone', 'email' => 'e-mail', 'category' => 'sujet', 'subject' => 'objet', 'message' => 'message']);

        if ($data['phone'] === null && $data['email'] === null && $this->auth->user() === null) {
            throw new \App\Core\ValidationException(['phone' => ['Indiquez un téléphone ou un e-mail pour que nous puissions vous répondre.']], $request->all());
        }

        $user = $this->auth->user();
        $reference = $this->db->transaction(function (Database $db) use ($data, $user) {
            $ticketId = $db->insert('support_tickets', [
                'reference' => 'TMP-' . bin2hex(random_bytes(6)),
                'user_id' => $user['id'] ?? null,
                'contact_name' => $data['name'],
                'contact_phone' => $data['phone'],
                'contact_email' => $data['email'],
                'category' => $data['category'],
                'subject' => $data['subject'],
                'priority' => $data['category'] === 'SECURITE' ? 'HAUTE' : 'NORMALE',
            ]);
            $reference = sprintf('JN-S-%s-%06d', date('Y'), $ticketId);
            $db->update('support_tickets', ['reference' => $reference], ['id' => $ticketId]);
            $db->insert('support_ticket_messages', ['ticket_id' => $ticketId, 'author_id' => $user['id'] ?? null, 'body' => $data['message']]);
            return $reference;
        });

        return $this->redirect('/contact', "Votre message a bien été envoyé (référence {$reference}). Nous vous répondrons dans les meilleurs délais.");
    }
}
