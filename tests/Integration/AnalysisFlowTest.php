<?php
declare(strict_types=1);

namespace Verifia\Tests\Integration;

use Verifia\Alerts\AlertService;
use Verifia\Analysis\AnalysisEngine;
use Verifia\Database;
use Verifia\Ingest\PublicationIngestor as I;
use Verifia\Queue\JobQueue;
use Verifia\Reports\ReportService;
use Verifia\Sessions\SessionService;
use Verifia\Settings;
use Verifia\Tests\DatabaseTestCase;

final class AnalysisFlowTest extends DatabaseTestCase
{
    private function drain(): array
    {
        $engine = new AnalysisEngine();
        $out = [];
        while ($j = JobQueue::claim('test')) {
            $out[(int) $j['publication_id']] = $engine->analyze((int) $j['publication_id']);
            JobQueue::complete((int) $j['id']);
        }
        return $out;
    }

    public function testOldImagePresentedAsCurrentRaisesAlertWithReference(): void
    {
        $s = SessionService::create(['start_url' => 'https://social.example/feed', 'name' => 'Réseau A'], null);
        $png = self::pngBase64(99);
        $old = I::ingest(['text' => 'Inondations à Agadez en 2023 : vue du quartier sinistré.', 'permalink' => 'https://social.example/p/1', 'published_label' => '14 août 2023', 'images' => [['data_base64' => $png]]], $s, null);
        Database::run('UPDATE detected_publications SET first_seen_at = ? WHERE id = ?', ['2023-08-14 10:00:00', $old['publication_id']]);
        $this->drain();
        $new = I::ingest(['text' => "Aujourd'hui à Niamey, la ville est sous les eaux. Regardez cette photo !", 'permalink' => 'https://social.example/p/2', 'images' => [['data_base64' => self::resizedJpegBase64($png, 240, 180)]]], $s, null);
        $res = $this->drain()[$new['publication_id']];

        $codes = array_column($res['indicators'], 'code');
        $this->assertContains('image_reuse', $codes);
        $this->assertContains('image_presented_as_recent', $codes);
        $this->assertContains($res['priority'], ['high', 'critical']);
        $this->assertNotNull($res['alert_id']);
        $reuse = $res['indicators'][array_search('image_reuse', $codes, true)];
        $this->assertSame($old['publication_id'], $reuse['ref_publication_id']);
        $this->assertStringContainsString('pas nécessairement celle de la prise de vue', $reuse['excerpt']);
        $this->assertSame('perceptual', Database::value('SELECT match_type FROM image_matches'));

        $detail = AlertService::detail($res['alert_id']);
        $timeline = AlertService::imageTimeline($detail['publication']['images'][0], $detail['publication']);
        $this->assertSame('detection_verifia', $timeline[0]['kind'], 'L\'occurrence de 2023 apparaît en premier dans la chronologie');
        $this->assertSame('2023-08-14 10:00:00', $timeline[0]['date']);
        $this->assertSame(1, (int) Database::value('SELECT alerts_count FROM monitoring_sessions WHERE id = ?', [$s]));
    }

    public function testExactSameImageMatch(): void
    {
        $png = self::pngBase64(5);
        I::ingest(['text' => 'Première publication avec la photo du stade.', 'images' => [['data_base64' => $png]]], null, null);
        I::ingest(['text' => 'Deuxième publication, autre texte, même photo du stade.', 'images' => [['data_base64' => $png]]], null, null);
        $this->drain();
        $this->assertSame('exact', Database::value('SELECT match_type FROM image_matches'));
        $this->assertSame(0, (int) Database::value('SELECT distance FROM image_matches'));
    }

    public function testAlertsSurviveSessionCloseAndDeletion(): void
    {
        $s = SessionService::create(['start_url' => 'https://social.example/', 'name' => 'Session éphémère'], null);
        I::ingest(['text' => "URGENT !!! Ce qu'on vous cache : le ministre a été arrêté ce matin à Tahoua. Partagez avant que ce soit supprimé ! Photo du 12/03/2019."], $s, null);
        $res = array_values($this->drain())[0];
        $this->assertSame('critical', $res['priority']);
        SessionService::action($s, 'close', null);
        $this->assertSame(1, AlertService::search([])['total'], 'Alerte toujours listée après fermeture');
        Database::run('DELETE FROM monitoring_sessions WHERE id = ?', [$s]);
        $a = AlertService::detail($res['alert_id']);
        $this->assertNotNull($a);
        $this->assertNull($a['session_id']);
        $this->assertSame('Session éphémère', $a['session_name'], 'Le nom de la session reste lisible');
    }

    public function testContradictedClaimPropagatesToNewPublications(): void
    {
        $text = 'Le ministère de la Santé a interdit la vente de paracétamol dans toutes les pharmacies du pays.';
        $p1 = I::ingest(['text' => $text, 'permalink' => 'https://a.example/1'], null, null)['publication_id'];
        $this->drain();
        Database::run("UPDATE claims SET verdict = 'contradicted' WHERE publication_id = ?", [$p1]);
        $p2 = I::ingest(['text' => $text, 'permalink' => 'https://b.example/9'], null, null)['publication_id'];
        $res = $this->drain()[$p2];
        $codes = array_column($res['indicators'], 'code');
        $this->assertContains('contradicted_claim', $codes);
        $this->assertContains('similar_contradicted', $codes);
        $this->assertNotNull($res['alert_id']);
    }

    public function testMinimumAlertPrioritySetting(): void
    {
        Settings::set('alert_min_priority', 'normal');
        I::ingest(['text' => 'Le gouvernement a signé hier un accord de coopération avec le Bénin sur le transport.'], null, null);
        $res = array_values($this->drain())[0];
        $this->assertSame('normal', $res['priority']);
        $this->assertNotNull($res['alert_id']);
        Settings::set('alert_min_priority', 'high');
        I::ingest(['text' => 'Le gouvernement a signé hier un accord de coopération avec le Togo sur l\'énergie solaire.'], null, null);
        $this->assertNull(array_values($this->drain())[0]['alert_id']);
    }

    public function testNoAutomaticExternalSourcesAreInvented(): void
    {
        I::ingest(['text' => "URGENT : le pont Kennedy s'est effondré ce matin, des dizaines de morts. Partagez !!!"], null, null);
        $this->drain();
        $this->assertSame(0, (int) Database::value('SELECT COUNT(*) FROM evidence_sources'), 'Aucune source n\'est générée automatiquement');
        $this->assertStringContainsString('Aucune recherche externe', (string) Database::value('SELECT limitations FROM analysis_results'));
        $this->assertSame(0, (int) Database::value('SELECT COUNT(*) FROM claims WHERE external_search_done = 1'));
    }

    public function testPdfReport(): void
    {
        I::ingest(['text' => "URGENT !!! Ce qu'on vous cache : l'armée a quitté Diffa hier. Partagez avant que ce soit supprimé ! Image du 01/02/2018.", 'images' => [['data_base64' => self::pngBase64(3)]]], null, null);
        $res = array_values($this->drain())[0];
        $r = ReportService::forAlert($res['alert_id'], null);
        $this->assertSame('pdf', $r['format']);
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($r['path']));
        $this->assertSame(1, (int) Database::value('SELECT COUNT(*) FROM reports'));
        $html = ReportService::html(AlertService::detail($res['alert_id']));
        $this->assertStringContainsString('ne signifie pas que la publication est fausse', $html);
    }

    public function testReanalysisKeepsHumanStatus(): void
    {
        $pid = I::ingest(['text' => "URGENT !!! On nous cache la vérité : le barrage de Kandadji a cédé ce matin. Partagez avant que ce soit supprimé ! Photo de 2019."], null, null)['publication_id'];
        $res = array_values($this->drain())[0];
        AlertService::setStatus($res['alert_id'], 'verified', null);
        JobQueue::push($pid, 5);
        $this->drain();
        $this->assertSame('verified', Database::value('SELECT status FROM alerts WHERE id = ?', [$res['alert_id']]));
        $this->assertSame(1, (int) Database::value('SELECT COUNT(*) FROM alerts'));
    }
}
