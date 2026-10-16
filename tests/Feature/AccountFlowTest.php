<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\HttpTestCase;

final class AccountFlowTest extends HttpTestCase
{
    public function testWorkerRegistersSeesDashboardAndLogsOut(): void
    {
        [$c] = $this->registeredClient('WORKER');
        $dash = $c->get('/espace/prestataire');
        $this->assertSame(200, $dash['status']);
        $this->assertStringContainsString('Bonjour Fati', $dash['body']);
        $this->assertStringContainsString('Bienvenue sur JOB NIGER', $dash['body']);
        $this->assertSame(403, $c->get('/espace/demandeur')['status'], 'Un prestataire n\'accède pas à l\'espace demandeur');

        $c->get('/espace/prestataire');
        $logout = $c->request('POST', '/deconnexion', ['_token' => $c->csrf()]);
        $this->assertSame(303, $logout['status']);
        $after = $c->get('/espace/prestataire');
        $this->assertSame(302, $after['status']);
        $this->assertStringEndsWith('/connexion', $after['headers']['location']);
    }

    public function testClientLoginRedirectsToClientSpace(): void
    {
        [$c, $phone] = $this->registeredClient('CLIENT', ['birth_date' => '']);
        $c->get('/espace/demandeur');
        $c->request('POST', '/deconnexion', ['_token' => $c->csrf()]);

        $login = $c->submit('/connexion', '/connexion', ['login' => $phone, 'password' => 'motdepasse1']);
        $this->assertSame(303, $login['status']);
        $this->assertStringEndsWith('/espace/demandeur', $login['headers']['location']);
    }

    public function testIntendedUrlIsRestoredAfterLogin(): void
    {
        [$c, $phone] = $this->registeredClient('WORKER');
        $c->get('/espace/prestataire');
        $c->request('POST', '/deconnexion', ['_token' => $c->csrf()]);
        $c->get('/notifications');                       // → redirection vers /connexion, URL mémorisée
        $login = $c->submit('/connexion', '/connexion', ['login' => $phone, 'password' => 'motdepasse1']);
        $this->assertStringEndsWith('/notifications', $login['headers']['location']);
    }

    public function testPasswordChangeKeepsCurrentSession(): void
    {
        [$c, $phone] = $this->registeredClient('WORKER');
        $res = $c->submit('/mot-de-passe/changer', '/mot-de-passe/changer', [
            'current_password' => 'motdepasse1', 'password' => 'nouveauMdp42', 'password_confirmation' => 'nouveauMdp42',
        ]);
        $this->assertSame(303, $res['status']);
        $this->assertSame(200, $c->get('/espace/prestataire')['status'], 'La session courante reste ouverte');
        $other = $this->client();
        $ok = $other->submit('/connexion', '/connexion', ['login' => $phone, 'password' => 'nouveauMdp42']);
        $this->assertStringEndsWith('/espace/prestataire', $ok['headers']['location']);
    }

    public function testForgotPasswordAnswerDoesNotRevealAccounts(): void
    {
        [, $phone] = $this->registeredClient('WORKER');
        $a = $this->client();
        $known = $a->follow($a->submit('/mot-de-passe/oublie', '/mot-de-passe/oublie', ['login' => $phone]));
        $b = $this->client();
        $unknown = $b->follow($b->submit('/mot-de-passe/oublie', '/mot-de-passe/oublie', ['login' => '+22799887766']));
        $msg = 'Si un compte correspond à ces informations';
        $this->assertStringContainsString($msg, $known['body']);
        $this->assertStringContainsString($msg, $unknown['body']);
    }
}
