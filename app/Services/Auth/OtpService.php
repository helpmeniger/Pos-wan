<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Repositories\UserRepository;
use App\Services\Audit\SecurityLogger;
use App\Services\Notifications\SmsSenderInterface;

/**
 * Codes à usage unique (6 chiffres) envoyés par SMS. Code haché, durée de validité courte,
 * nombre d'essais limité, renvoi limité par la table rate_limits.
 */
class OtpService
{
    public function __construct(
        private readonly Database $db,
        private readonly SmsSenderInterface $sms,
        private readonly RateLimiter $limiter,
        private readonly UserRepository $users,
        private readonly SecurityLogger $security,
        private readonly Config $config,
    ) {
    }

    /** @return array{ok: bool, error?: string, dev_delivery?: bool} */
    public function sendPhoneVerification(int $userId, string $phone, Request $request): array
    {
        if (!$this->limiter->attempt('otp_send:user:' . $userId, 'otp_send')) {
            return ['ok' => false, 'error' => 'Trop de codes demandés. Réessayez dans une heure.'];
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $minutes = (int) $this->config->get('security.otp_minutes', 10);
        $this->db->query(
            "UPDATE otp_codes SET consumed_at = NOW() WHERE user_id = ? AND purpose = 'PHONE_VERIFY' AND consumed_at IS NULL",
            [$userId]
        );
        $this->db->insert('otp_codes', [
            'user_id' => $userId,
            'phone' => $phone,
            'purpose' => 'PHONE_VERIFY',
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => date('Y-m-d H:i:s', time() + $minutes * 60),
        ]);
        $this->sms->send($phone, "JOB NIGER : votre code de vérification est {$code} (valable {$minutes} min). Ne le communiquez à personne.");
        $this->security->log('otp.sent', $userId, $request, ['purpose' => 'PHONE_VERIFY']);
        return ['ok' => true, 'dev_delivery' => !$this->sms->isRealDelivery()];
    }

    /** @return array{ok: bool, error?: string} */
    public function verifyPhone(int $userId, string $code, Request $request): array
    {
        $max = (int) $this->config->get('security.otp_max_attempts', 5);
        return $this->db->transaction(function (Database $db) use ($userId, $code, $max, $request) {
            $otp = $db->fetch(
                "SELECT * FROM otp_codes WHERE user_id = ? AND purpose = 'PHONE_VERIFY' AND consumed_at IS NULL
                 AND expires_at > NOW() ORDER BY id DESC LIMIT 1 FOR UPDATE",
                [$userId]
            );
            if ($otp === null) {
                return ['ok' => false, 'error' => 'Aucun code valide : demandez un nouveau code.'];
            }
            if ((int) $otp['attempts'] >= $max) {
                $db->query('UPDATE otp_codes SET consumed_at = NOW() WHERE id = ?', [(int) $otp['id']]);
                return ['ok' => false, 'error' => 'Nombre d\'essais dépassé : demandez un nouveau code.'];
            }
            if (!preg_match('/^\d{6}$/', $code) || !password_verify($code, (string) $otp['code_hash'])) {
                $db->query('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?', [(int) $otp['id']]);
                $this->security->log('otp.failed', $userId, $request);
                return ['ok' => false, 'error' => 'Code incorrect.'];
            }
            $db->query('UPDATE otp_codes SET consumed_at = NOW() WHERE id = ?', [(int) $otp['id']]);
            $this->users->markPhoneVerified($userId);
            $this->security->log('otp.verified', $userId, $request);
            return ['ok' => true];
        });
    }
}
