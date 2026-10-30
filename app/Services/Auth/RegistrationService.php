<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Database;
use App\Core\Request;
use App\Core\ValidationException;
use App\Repositories\CityRepository;
use App\Repositories\SettingRepository;
use App\Repositories\UserRepository;
use App\Services\Audit\SecurityLogger;
use App\Services\Notifications\NotificationService;
use App\Support\Uuid;

/**
 * Inscription d'un prestataire, d'un demandeur particulier ou d'un demandeur professionnel.
 * Tout est créé dans une seule transaction : compte, rôle, profil, consentements, organisation.
 */
class RegistrationService
{
    public const MIN_AGE = 16;

    public const ACCOUNT_TYPES = [
        'WORKER' => 'Je cherche du travail',
        'CLIENT' => 'Je recrute (particulier)',
        'BUSINESS' => 'Je recrute pour une entreprise ou une organisation',
    ];

    public const BUSINESS_KINDS = [
        'COMMERCE' => 'Commerce / boutique', 'SUPERMARCHE' => 'Supermarché', 'RESTAURANT' => 'Restaurant / fast-food',
        'HOTEL' => 'Hôtel / auberge', 'ENTREPRISE' => 'Entreprise', 'PME' => 'PME', 'STARTUP' => 'Startup',
        'ONG' => 'ONG', 'ASSOCIATION' => 'Association', 'ECOLE' => 'Établissement scolaire', 'UNIVERSITE' => 'Université',
        'CENTRE_FORMATION' => 'Centre de formation', 'EVENEMENTIEL' => 'Événementiel', 'CONSTRUCTION' => 'Construction / BTP',
        'AGRICULTURE' => 'Agriculture', 'ELEVAGE' => 'Élevage', 'GARAGE' => 'Garage / atelier', 'TRANSPORT' => 'Transport',
        'IMMOBILIER' => 'Agence immobilière', 'ADMINISTRATION' => 'Administration / organisme autorisé', 'AUTRE' => 'Autre',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly CityRepository $cities,
        private readonly SettingRepository $settings,
        private readonly PasswordHasher $hasher,
        private readonly PermissionService $permissions,
        private readonly NotificationService $notifications,
        private readonly SecurityLogger $security,
    ) {
    }

    /**
     * @param array<string, mixed> $data données validées (voir AuthController::register)
     * @return int identifiant du nouvel utilisateur
     */
    public function register(array $data, Request $request): int
    {
        $errors = [];
        if ($this->users->phoneExists($data['phone'])) {
            $errors['phone'][] = 'Ce numéro de téléphone est déjà associé à un compte.';
        }
        if (!empty($data['email'] ?? null) && $this->users->emailExists($data['email'])) {
            $errors['email'][] = 'Cette adresse e-mail est déjà associée à un compte.';
        }
        if (!$this->cities->exists((int) $data['city_id'])) {
            $errors['city_id'][] = 'Veuillez choisir une ville de la liste.';
        }
        if (!empty($data['birth_date'])) {
            $age = (new \DateTimeImmutable($data['birth_date']))->diff(new \DateTimeImmutable('today'))->y;
            if ($age < self::MIN_AGE) {
                $errors['birth_date'][] = 'L\'inscription est réservée aux personnes d\'au moins ' . self::MIN_AGE . ' ans.';
            } elseif ($age > 100) {
                $errors['birth_date'][] = 'Veuillez vérifier la date de naissance.';
            }
        }
        if ($errors) {
            throw new ValidationException($errors, $data);
        }

        $type = $data['account_type'];
        $cguVersion = (string) $this->settings->get('legal.cgu_version', '0.1');
        $privacyVersion = (string) $this->settings->get('legal.privacy_version', '0.1');

        $userId = $this->db->transaction(function (Database $db) use ($data, $type, $request, $cguVersion, $privacyVersion) {
            $userId = $db->insert('users', [
                'uuid' => Uuid::v4(),
                'primary_type' => $type === 'WORKER' ? 'WORKER' : 'CLIENT',
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'],
                'email' => ($data['email'] ?? null) ?: null,
                'password_hash' => $this->hasher->hash($data['password']),
                'password_changed_at' => date('Y-m-d H:i:s'),
                'birth_date' => ($data['birth_date'] ?? null) ?: null,
                'city_id' => (int) $data['city_id'],
                'status' => 'ACTIVE',
            ]);
            $this->permissions->grantRole($userId, $type === 'WORKER' ? 'WORKER' : 'CLIENT');
            $db->insert('user_profiles', [
                'user_id' => $userId,
                'display_name' => trim($data['first_name'] . ' ' . mb_substr($data['last_name'], 0, 1) . '.'),
                'is_available' => $type === 'WORKER' ? 1 : 0,
            ]);
            if ($type === 'WORKER') {
                $db->insert('worker_service_areas', ['user_id' => $userId, 'city_id' => (int) $data['city_id']]);
            }
            foreach ([['CGU', $cguVersion], ['PRIVACY', $privacyVersion], ['COMMISSION_TERMS', $cguVersion]] as [$doc, $version]) {
                $db->insert('user_consents', [
                    'user_id' => $userId, 'document' => $doc, 'version' => $version,
                    'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
                ]);
            }
            if ($type === 'BUSINESS') {
                $businessId = $db->insert('businesses', [
                    'uuid' => Uuid::v4(),
                    'owner_user_id' => $userId,
                    'name' => $data['business_name'],
                    'kind' => $data['business_kind'],
                    'city_id' => (int) $data['city_id'],
                    'phone' => $data['phone'],
                    'email' => ($data['email'] ?? null) ?: null,
                    'legal_rep_name' => $data['first_name'] . ' ' . $data['last_name'],
                ]);
                $db->insert('business_members', ['business_id' => $businessId, 'user_id' => $userId, 'member_role' => 'OWNER']);
                $planId = $db->fetchValue("SELECT id FROM subscription_plans WHERE code = 'GRATUIT'");
                if ($planId !== null) {
                    $db->insert('business_subscriptions', [
                        'business_id' => $businessId, 'plan_id' => (int) $planId, 'status' => 'ACTIF',
                        'billing_cycle' => 'GRATUIT', 'price' => '0.00', 'starts_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
            $this->notifications->notify(
                $userId,
                'account.welcome',
                'Bienvenue sur JOB NIGER !',
                $type === 'WORKER'
                    ? 'Complétez votre profil (métiers, disponibilités, zones) pour recevoir des propositions de missions.'
                    : 'Vous pourrez bientôt publier vos missions et trouver des prestataires près de chez vous.',
            );
            return $userId;
        });

        $this->security->log('account.registered', $userId, $request, ['type' => $type]);
        return $userId;
    }
}
