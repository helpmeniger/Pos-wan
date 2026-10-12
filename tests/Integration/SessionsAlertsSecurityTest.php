<?php
declare(strict_types=1);

namespace Verifia\Tests\Integration;

use Verifia\Alerts\AlertService;
use Verifia\Auth;
use Verifia\Database;
use Verifia\DataPurge;
use Verifia\Ingest\PublicationIngestor as I;
use Verifia\Monitor\MonitorApi;
use Verifia\Queue\JobQueue;
use Verifia\Analysis\AnalysisEngine;
use Verifia\Sessions\SessionService;
use Verifia\Settings;
use Verifia\Tests\DatabaseTestCase;

final class SessionsAlertsSecurityTest extends DatabaseTestCase
{
    public function testSessionLifecycle(): void
    {
        $id = SessionService::create(['start_url' => 'https://www.facebook.com/', 'name' => 'FB', 'scroll_delay_ms' => 10, 'max_posts' => 0], null);
        $s = SessionService::get($id);
        $this->assertSame('facebook', $s['adapter']);
        $this->assertSame(500, (int) $s['scroll_delay_ms'], 'Valeurs bornées');
        $this->assertSame(1, (int) $s['max_posts']);
        foreach (['start' => 'running', 'pause' => 'paused', 'resume' => 'running', 'stop' => 'stopped', 'close' => 'closed'] as $action => $state) {
            [$ok] = SessionService::action($id, $action, null);
            $this->assertTrue($ok, $action);
            $this->assertSame($state, SessionService::get($id)['desired_state']);
        }
        [$ok] = SessionService::action($id, 'start', null);
        $this->assertFalse($ok, 'Une session fermée ne redémarre pas sans être rouverte');
        SessionService::update(SessionService::create(['start_url' => 'https://x.com/home'], null), ['name' => 'Renommée'], null);
        $this->assertSame(1, (int) Database::value("SELECT COUNT(*) FROM monitoring_sessions WHERE name = 'Renommée'"));
    }

    public function testRejectsDangerousUrls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SessionService::create(['start_url' => 'javascript:alert(1)'], null);
    }

    public function testCapacityLimit(): void
    {
        Settings::set('max_concurrent_sessions', '2');
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = SessionService::create(['start_url' => "https://site$i.example/"], null);
        }
        $this->assertTrue(SessionService::action($ids[0], 'start', null)[0]);
        $this->assertTrue(SessionService::action($ids[1], 'start', null)[0]);
        [$ok, $msg] = SessionService::action($ids[2], 'start', null);
        $this->assertFalse($ok);
        $this->assertStringContainsString('Capacité atteinte', $msg);

        // Mémoire libre rapportée par le moteur : limite plus stricte
        Settings::set('max_concurrent_sessions', '10');
        Database::run("INSERT INTO service_heartbeats (service, pid, host_info_json, last_seen_at) VALUES ('monitor', 1, ?, ?)", [json_encode(['free_mem_mb' => 1600, 'open_browsers' => 2]), Database::now()]);
        $c = SessionService::capacity();
        $this->assertSame(2, $c['memory_limit']);
        $this->assertFalse(SessionService::action($ids[2], 'start', null)[0]);
    }

    public function testEmergencyStop(): void
    {
        $a = SessionService::create(['start_url' => 'https://a.example/'], null);
        $b = SessionService::create(['start_url' => 'https://b.example/'], null);
        SessionService::action($a, 'start', null);
        SessionService::action($b, 'start', null);
        SessionService::action($b, 'pause', null);
        $this->assertSame(2, SessionService::emergencyStop(null));
        $this->assertSame(0, (int) Database::value("SELECT COUNT(*) FROM monitoring_sessions WHERE desired_state <> 'stopped'"));
        $this->assertFalse(SessionService::action($a, 'start', null)[0], 'Aucun démarrage tant que l\'arrêt immédiat est actif');
        $poll = MonitorApi::poll(['host' => [], 'sessions' => []]);
        $this->assertTrue($poll['emergency_stop']);
        SessionService::releaseEmergencyStop(null);
        $this->assertTrue(SessionService::action($a, 'start', null)[0]);
    }

    public function testMonitorApiAuthorization(): void
    {
        $this->assertTrue(MonitorApi::authorize('127.0.0.1', 'test-token-0123456789'));
        $this->assertFalse(MonitorApi::authorize('127.0.0.1', 'mauvais'));
        $this->assertFalse(MonitorApi::authorize('192.168.1.20', 'test-token-0123456789'), 'Seule la boucle locale est autorisée');
        $this->assertFalse(MonitorApi::authorize('127.0.0.1', null));
    }

    public function testMonitorPollStateAndCommands(): void
    {
        $id = SessionService::create(['start_url' => 'https://a.example/'], null);
        SessionService::action($id, 'start', null);
        Database::run("UPDATE monitoring_sessions SET run_state = 'running' WHERE id = ?", [$id]);
        SessionService::action($id, 'scan_once', null);
        $r = MonitorApi::poll(['host' => ['pid' => 123, 'free_mem_mb' => 8000], 'sessions' => [['id' => $id, 'run_state' => 'running', 'connection_state' => 'captcha', 'status_message' => 'CAPTCHA détecté', 'current_url' => 'https://a.example/x']]]);
        $this->assertSame('running', $r['sessions'][0]['desired_state']);
        $this->assertSame('scan_once', $r['commands'][0]['command']);
        $this->assertSame([], MonitorApi::poll(['host' => [], 'sessions' => []])['commands'], 'Une commande n\'est délivrée qu\'une fois');
        $this->assertSame('captcha', SessionService::get($id)['connection_state']);
        $this->assertSame(1, (int) Database::value("SELECT COUNT(*) FROM system_events WHERE session_id = ? AND message LIKE 'Intervention manuelle%'", [$id]));
        // valeurs inconnues ignorées
        MonitorApi::poll(['sessions' => [['id' => $id, 'run_state' => 'hacked', 'connection_state' => 'x']]]);
        $this->assertSame('running', SessionService::get($id)['run_state']);
    }

    public function testMonitorIngestRespectsAnalysisSwitch(): void
    {
        $id = SessionService::create(['start_url' => 'https://a.example/', 'analysis_enabled' => false], null);
        $r = MonitorApi::ingest(['session_id' => $id, 'posts' => [['text' => 'Publication assez longue pour être analysée.']]]);
        $this->assertSame(0, $r['accepted']);
        SessionService::action($id, 'analysis_on', null);
        $r = MonitorApi::ingest(['session_id' => $id, 'posts' => [['text' => 'Publication assez longue pour être analysée.']]]);
        $this->assertSame(1, $r['accepted']);
    }

    public function testAlertFiltersAndSqlInjectionSafety(): void
    {
        $s = SessionService::create(['start_url' => 'https://a.example/', 'name' => 'A'], null);
        I::ingest(['text' => "URGENT !!! Ce qu'on vous cache : explosion à Zinder ce matin. Partagez avant que ce soit supprimé ! Vidéo du 02/02/2019."], $s, null);
        I::ingest(['text' => "SCANDALE !!! La vérité cachée : le vaccin a tué 50 personnes à Maradi. Partagez massivement avant qu'il soit supprimé !"], null, null, 'manual');
        $e = new AnalysisEngine();
        while ($j = JobQueue::claim('t')) {
            $e->analyze((int) $j['publication_id']);
            JobQueue::complete((int) $j['id']);
        }
        $this->assertSame(2, AlertService::search([])['total']);
        $this->assertSame(1, AlertService::search(['session_id' => $s])['total']);
        $this->assertSame(1, AlertService::search(['q' => 'Zinder'])['total']);
        $this->assertSame(0, AlertService::search(['q' => "' OR 1=1 -- "])['total']);
        $this->assertSame(0, AlertService::search(['q' => '%'])['total'], 'Les jokers LIKE sont échappés');
        $this->assertSame(2, AlertService::search(['priority' => "critical' OR '1'='1"])['total'], 'Valeur de priorité invalide ignorée, pas injectée');
        $this->assertSame(2, AlertService::search(['date_from' => '2000-01-01', 'date_to' => '2999-12-31'])['total']);
        $this->assertSame(0, AlertService::search(['date_from' => '2999-01-01'])['total']);
        $first = AlertService::search(['session_id' => $s])['items'][0];
        $byCat = AlertService::search(['category' => $first['category']]);
        $this->assertContains($first['id'], array_column($byCat['items'], 'id'));
        $this->assertSame(0, AlertService::search(['category' => 'satire'])['total']);
        $this->assertSame(1, AlertService::search(['site' => 'a.example'])['total']);

        $id = (int) AlertService::search(['session_id' => $s])['items'][0]['id'];
        $uid = Auth::createUser('analyste1', 'Analyste Un', 'motdepasse-solide', 'analyst');
        $this->assertTrue(AlertService::assign($id, $uid, null));
        $this->assertSame('in_review', Database::value('SELECT status FROM alerts WHERE id = ?', [$id]));
        $this->assertFalse(AlertService::assign($id, 9999, null), 'Utilisateur inexistant refusé');
        $this->assertFalse(AlertService::setStatus($id, 'deleted', null));
        $this->assertTrue(AlertService::setStatus($id, 'ignored', null));
        $this->assertSame(1, AlertService::search([])['total'], 'Les alertes ignorées sont masquées par défaut…');
        $this->assertSame(2, AlertService::search(['include_ignored' => 1])['total'], '… mais jamais supprimées');
        $this->assertGreaterThanOrEqual(3, (int) Database::value('SELECT COUNT(*) FROM alert_history WHERE alert_id = ?', [$id]));
    }

    public function testUserCreationRules(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Auth::createUser('bob', 'Bob', 'court', 'analyst');
    }

    public function testLoginLockout(): void
    {
        Auth::createUser('carol', 'Carol', 'motdepasse-solide', 'viewer');
        $_SESSION = [];
        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse(Auth::attempt('carol', 'faux')[0]);
        }
        [$ok, $msg] = Auth::attempt('carol', 'motdepasse-solide');
        $this->assertFalse($ok, 'Compte verrouillé après 3 échecs');
        $this->assertStringContainsString('verrouillé', $msg);
        $this->assertStringNotContainsString('motdepasse', (string) Database::value('SELECT password_hash FROM users WHERE username = ?', ['carol']));
    }

    public function testDataWipeKeepsAccountsAndSettings(): void
    {
        Auth::createUser('admin2', 'Admin', 'motdepasse-solide', 'admin');
        $s = SessionService::create(['start_url' => 'https://a.example/'], null);
        I::ingest(['text' => 'Une publication à supprimer avec une image.', 'images' => [['data_base64' => self::pngBase64(8)]]], $s, null);
        $path = json_decode((string) Database::value('SELECT media_json FROM publication_content'), true)[0]['path'];
        DataPurge::everything(null);
        $this->assertSame(0, (int) Database::value('SELECT COUNT(*) FROM detected_publications'));
        $this->assertSame(0, (int) Database::value('SELECT COUNT(*) FROM monitoring_sessions'));
        $this->assertSame(1, (int) Database::value('SELECT COUNT(*) FROM users'));
        $this->assertFileDoesNotExist(\Verifia\Config::storagePath($path));
    }

    public function testRetentionPurgeNeverDeletesAlertedPublications(): void
    {
        Settings::set('retention_days', '30');
        $keep = I::ingest(['text' => "URGENT !!! Ce qu'on vous cache : coup de force à Niamey ce matin. Partagez avant que ce soit supprimé ! Photo du 05/05/2019."], null, null)['publication_id'];
        $drop = I::ingest(['text' => 'Le marché de Katako ouvrira normalement demain matin.'], null, null)['publication_id'];
        $e = new AnalysisEngine();
        while ($j = JobQueue::claim('t')) {
            $e->analyze((int) $j['publication_id']);
            JobQueue::complete((int) $j['id']);
        }
        Database::run('UPDATE detected_publications SET last_seen_at = ?', [gmdate('Y-m-d H:i:s', time() - 40 * 86400)]);
        $this->assertSame(1, DataPurge::expired());
        $this->assertNotNull(Database::value('SELECT id FROM detected_publications WHERE id = ?', [$keep]));
        $this->assertNull(Database::value('SELECT id FROM detected_publications WHERE id = ?', [$drop]));
    }
}
