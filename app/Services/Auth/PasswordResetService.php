<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\UserRepository;
use App\Services\Audit\SecurityLogger;
use App\Services\Notifications\MailerInterface;
use App\Services\Notifications\SmsSenderInterface;
use App\Support\Phone;

/**
 * Réinitialisation du mot de passe par lien à usage unique (e-mail) ou SMS.
 * Seule l'empreinte SHA-256 du jeton est stockée ; la réponse est identique que le compte existe ou non.
 */
class PasswordResetService
{
    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly MailerInterface $mailer,
        private readonly SmsSenderInterface $sms,
        private readonly SecurityLogger $security,
        private readonly Config $config,
    ) {
    }

    public function request(string $login, Request $request): void
    {
        $normalized = str_contains($login, '@') ? mb_strtolower(trim($login)) : (Phone::normalize($login) ?? '');
        $user = $normalized !== '' ? $this->users->findForAuth($normalized) : null;
        if ($user === null || !in_array($user['status'], ['ACTIVE', 'PENDING'], true)) {
            $this->security->log('password_reset.unknown', null, $request);
            return;
        }
        $channel = str_contains($normalized, '@') ? 'EMAIL' : 'SMS';
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $minutes = (int) $this->config->get('security.password_reset_minutes', 30);

        $this->db->transaction(function (Database $db) use ($user, $token, $channel, $minutes, $request) {
            // Un seul lien valide à la fois
            $db->query('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [(int) $user['id']]);
            $db->insert('password_resets', [
                'user_id' => (int) $user['id'],
                'token_hash' => hash('sha256', $token),
                'channel' => $channel,
                'expires_at' => date('Y-m-d H:i:s', time() + $minutes * 60),
                'ip_address' => $request->ip(),
            ]);
        });

        $link = rtrim((string) $this->config->get('app.url'), '/') . url('/mot-de-passe/reinitialiser/' . $token);
        if ($channel === 'EMAIL') {
            $this->mailer->send(
                (string) $user['email'],
                'JOB NIGER — Réinitialisation de votre mot de passe',
                "Bonjour {$user['first_name']},\n\nPour choisir un nouveau mot de passe, ouvrez ce lien (valable {$minutes} minutes) :\n{$link}\n\n"
                . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe actuel reste valable.\n\nL'équipe JOB NIGER"
            );
        } else {
            $this->sms->send((string) $user['phone'], "JOB NIGER : lien de réinitialisation (valable {$minutes} min) : {$link} — Ne le partagez avec personne.");
        }
        $this->security->log('password_reset.requested', (int) $user['id'], $request, ['channel' => $channel]);
    }

    /** @return array<string, mixed>|null demande valide associée au jeton */
    public function findValid(string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token)) {
            return null;
        }
        return $this->db->fetch(
            'SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()',
            [hash('sha256', $token)]
        );
    }

    public function reset(string $token, string $newPassword, Request $request): bool
    {
        return $this->db->transaction(function (Database $db) use ($token, $newPassword, $request) {
            $reset = $db->fetch(
                'SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() FOR UPDATE',
                [hash('sha256', $token)]
            );
            if ($reset === null) {
                return false;
            }
            $userId = (int) $reset['user_id'];
            $this->users->updatePassword($userId, $this->hasher->hash($newPassword));
            $db->query('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);
            $this->security->log('password_reset.completed', $userId, $request);
            return true;
        });
    }
}
