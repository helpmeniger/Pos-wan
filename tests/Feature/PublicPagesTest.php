<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\HttpTestCase;

final class PublicPagesTest extends HttpTestCase
{
    public function testPublicPagesRender(): void
    {
        $c = $this->client();
        foreach (['/', '/categories', '/categories/agriculture-elevage', '/a-propos', '/aide', '/contact',
                  '/conditions-utilisation', '/confidentialite', '/connexion', '/inscription', '/mot-de-passe/oublie'] as $path) {
            $res = $c->get($path);
            $this->assertSame(200, $res['status'], $path);
            $this->assertStringContainsString('JOB NIGER', $res['body'], $path);
        }
    }

    public function testCommissionExampleUsesConfiguredRule(): void
    {
        $body = $this->client()->get('/a-propos')['body'];
        $this->assertStringContainsString('(10 %)', $body);
        $this->assertMatchesRegularExpression('/9\x{202F}000\x{00A0}FCFA/u', $body);
    }

    public function testUnknownPagesAre404(): void
    {
        $c = $this->client();
        $this->assertSame(404, $c->get('/categories/inexistante')['status']);
        $this->assertSame(404, $c->get('/page-qui-nexiste-pas')['status']);
        $api = $c->get('/api/v1/inconnu');
        $this->assertSame(404, $api['status']);
        $this->assertStringContainsString('application/json', $api['headers']['content-type'] ?? '');
    }

    public function testApiReferentials(): void
    {
        $c = $this->client();
        $health = json_decode($c->get('/api/v1/health')['body'], true);
        $this->assertSame('ok', $health['status']);
        $cities = json_decode($c->get('/api/v1/cities')['body'], true);
        $this->assertCount(8, $cities['data']);
        $niamey = $cities['data'][0]['id'];
        $nb = json_decode($c->get("/api/v1/cities/{$niamey}/neighborhoods")['body'], true);
        $this->assertNotEmpty($nb['data']);
        $cats = json_decode($c->get('/api/v1/categories')['body'], true);
        $this->assertCount(21, $cats['data']);
    }

    public function testContactFormCreatesSupportTicket(): void
    {
        $c = $this->client();
        $res = $c->submit('/contact', '/contact', [
            'name' => 'Ibrahim Test', 'phone' => '91 22 33 44', 'category' => 'AUTRE',
            'subject' => 'Question sur les missions', 'message' => 'Bonjour, quand les annonces seront-elles disponibles ?',
        ]);
        $this->assertSame(303, $res['status']);
        $page = $c->follow($res);
        $this->assertMatchesRegularExpression('/référence JN-S-\d{4}-\d{6}/u', $page['body']);
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM support_tickets WHERE contact_name = 'Ibrahim Test'"));
    }

    public function testValidationErrorsAreShownWithOldInput(): void
    {
        $c = $this->client();
        $res = $c->submit('/inscription', '/inscription', ['account_type' => 'WORKER', 'first_name' => 'Abdou', 'phone' => '123'], ['Referer' => self::$base . '/inscription']);
        $this->assertSame(303, $res['status']);
        $page = $c->follow($res);
        $this->assertStringContainsString('Le numéro de téléphone n&apos;est pas valide', $page['body']);
        $this->assertStringContainsString('value="Abdou"', $page['body'], 'Les saisies sont conservées');
        $this->assertStringNotContainsString('motdepasse', $page['body']);
    }
}
