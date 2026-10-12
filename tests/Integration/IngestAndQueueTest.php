<?php
declare(strict_types=1);

namespace Verifia\Tests\Integration;

use Verifia\Database;
use Verifia\Ingest\PublicationIngestor as I;
use Verifia\Queue\JobQueue;
use Verifia\Sessions\SessionService;
use Verifia\Tests\DatabaseTestCase;

final class IngestAndQueueTest extends DatabaseTestCase
{
    private function post(string $suffix = '', array $extra = []): array
    {
        return $extra + ['text' => "Le gouvernement a annoncé une hausse de 20 % du prix du carburant à Niamey $suffix", 'permalink' => 'https://news.example/p/42?utm_source=fb'];
    }

    public function testCreateThenDeduplicateWithinAndAcrossSessions(): void
    {
        $s1 = SessionService::create(['start_url' => 'https://news.example/'], null);
        $s2 = SessionService::create(['start_url' => 'https://www.news.example/feed'], null);
        $a = I::ingest($this->post(), $s1, 'https://news.example/');
        $this->assertSame('created', $a['status']);
        $b = I::ingest($this->post(), $s1, 'https://news.example/');
        $this->assertSame('duplicate', $b['status']);
        $c = I::ingest($this->post('', ['permalink' => 'https://news.example/p/42']), $s2, 'https://news.example/feed');
        $this->assertSame('duplicate', $c['status']);
        $this->assertSame($a['publication_id'], $c['publication_id']);

        $pub = Database::one('SELECT * FROM detected_publications WHERE id = ?', [$a['publication_id']]);
        $this->assertSame(2, (int) $pub['occurrences'], 'Deux sessions distinctes = deux occurrences, la répétition dans la même session ne compte pas');
        $this->assertSame($s1, (int) $pub['first_session_id'], 'La session d\'origine est conservée');
        $this->assertSame(2, (int) Database::value('SELECT COUNT(*) FROM publication_occurrences WHERE publication_id = ?', [$a['publication_id']]));
        $this->assertSame(1, (int) Database::value('SELECT COUNT(*) FROM analysis_jobs'), 'Une seule analyse malgré trois détections');
        $this->assertSame(1, (int) Database::value('SELECT posts_seen FROM monitoring_sessions WHERE id = ?', [$s2]));
    }

    public function testSameSiteSameTextWithoutPermalinkIsDeduplicated(): void
    {
        $s = SessionService::create(['start_url' => 'https://blog.example/'], null);
        $r1 = I::ingest(['text' => 'Une explosion a été entendue près du marché central de Maradi ce matin.'], $s, null);
        $r2 = I::ingest(['text' => 'Une   EXPLOSION a été entendue près du marché central de Maradi ce matin !'], $s, null);
        $this->assertSame($r1['publication_id'], $r2['publication_id']);
    }

    public function testRejectsEmptyAndInvalidImages(): void
    {
        $this->assertSame('rejected', I::ingest(['text' => 'court'], null, null, 'manual')['status']);
        $r = I::ingest(['text' => 'Texte suffisamment long pour être analysé.', 'images' => [['data_base64' => base64_encode('<?php echo 1; ?>' . str_repeat('x', 100))]]], null, null, 'manual');
        $this->assertSame('created', $r['status']);
        $media = json_decode((string) Database::value('SELECT media_json FROM publication_content WHERE publication_id = ?', [$r['publication_id']]), true);
        $this->assertSame([], $media, 'Un fichier qui n\'est pas une image valide n\'est jamais enregistré');
    }

    public function testStoresValidImages(): void
    {
        $r = I::ingest(['text' => 'Photo du pont effondré à Dosso hier soir.', 'images' => [['data_base64' => self::pngBase64(1), 'alt' => 'pont']]], null, null, 'manual');
        $media = json_decode((string) Database::value('SELECT media_json FROM publication_content WHERE publication_id = ?', [$r['publication_id']]), true);
        $this->assertCount(1, $media);
        $this->assertFileExists(\Verifia\Config::storagePath($media[0]['path']));
        $this->assertSame(3, (int) Database::value('SELECT priority FROM analysis_jobs WHERE publication_id = ?', [$r['publication_id']]), 'Analyse manuelle = priorité haute');
    }

    public function testQueuePriorityExclusiveClaimRetryAndFailure(): void
    {
        $p1 = I::ingest(['text' => 'Publication ordinaire numéro un sur le marché.'], null, null)['publication_id'];
        $p2 = I::ingest(['text' => 'Publication manuelle prioritaire à analyser.'], null, null, 'manual')['publication_id'];
        $j = JobQueue::claim('w1');
        $this->assertSame($p2, (int) $j['publication_id'], 'La priorité la plus urgente est traitée en premier');
        $j2 = JobQueue::claim('w2');
        $this->assertSame($p1, (int) $j2['publication_id']);
        $this->assertNull(JobQueue::claim('w3'), 'Aucune tâche ne peut être réservée deux fois');

        $this->assertSame('retry', JobQueue::fail($j, 'erreur simulée'));
        $row = Database::one('SELECT * FROM analysis_jobs WHERE id = ?', [$j['id']]);
        $this->assertSame('pending', $row['status']);
        $this->assertGreaterThan(Database::now(), $row['available_at'], 'Délai avant nouvelle tentative');
        Database::run('UPDATE analysis_jobs SET available_at = ? WHERE id = ?', [Database::now(), $j['id']]);
        $j = JobQueue::claim('w1');
        JobQueue::fail($j, 'erreur 2');
        Database::run('UPDATE analysis_jobs SET available_at = ? WHERE id = ?', [Database::now(), $j['id']]);
        $j = JobQueue::claim('w1');
        $this->assertSame(3, (int) $j['attempts']);
        $this->assertSame('failed', JobQueue::fail($j, 'erreur 3'));
        $this->assertSame('failed', Database::value('SELECT status FROM detected_publications WHERE id = ?', [$p2]));
        $this->assertSame(1, (int) Database::value("SELECT COUNT(*) FROM system_events WHERE level = 'error' AND component = 'worker'"));
    }

    public function testStaleLocksAreRecovered(): void
    {
        I::ingest(['text' => 'Publication dont le worker va planter.'], null, null);
        $j = JobQueue::claim('w1');
        Database::run('UPDATE analysis_jobs SET locked_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 3600), $j['id']]);
        $this->assertSame(1, JobQueue::recoverStale());
        $this->assertNotNull(JobQueue::claim('w2'));
    }
}
