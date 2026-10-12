<?php
declare(strict_types=1);

namespace Verifia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Verifia\Analysis\PriorityScorer;
use Verifia\Analysis\TextAnalyzer;

final class TextAnalyzerTest extends TestCase
{
    private TextAnalyzer $a;

    protected function setUp(): void
    {
        $this->a = new TextAnalyzer(new \DateTimeImmutable('2026-10-12', new \DateTimeZone('UTC')));
    }

    private function codes(array $r): array
    {
        return array_column($r['indicators'], 'code');
    }

    public function testDetectsManipulationIndicators(): void
    {
        $r = $this->a->analyze("URGENT !!! Ce qu'on vous cache : le vaccin contre le paludisme a tué 300 enfants à Zinder. Partagez avant que ce soit supprimé !");
        $codes = $this->codes($r);
        $this->assertContains('sensational', $codes);
        $this->assertContains('conspiracy', $codes);
        $this->assertContains('share_call', $codes);
        $this->assertContains('punctuation', $codes);
        $this->assertContains('no_source', $codes);
        $this->assertSame('sante', $r['category']);
        $this->assertNotEmpty($r['claims']);
        $this->assertContains('Zinder', $r['places']);
    }

    public function testNeutralNewsIsLowOrNormal(): void
    {
        $r = $this->a->analyze("La rentrée scolaire aura lieu le 1er octobre selon le ministère de l'Éducation. Les inscriptions sont ouvertes dans toutes les écoles.");
        $this->assertSame('education', $r['category']);
        $this->assertNotContains('sensational', $this->codes($r));
        $this->assertNotEmpty($r['sources'], 'L\'attribution « selon le ministère » doit être reconnue comme source citée');
        $p = PriorityScorer::score($r['indicators'], count($r['claims']), $r['flags']);
        $this->assertContains($p['priority'], ['low', 'normal']);
    }

    public function testOpinionIsNeverTreatedAsMisleadingByStyleAlone(): void
    {
        $r = $this->a->analyze('À mon avis, cette réforme est INCROYABLE et scandaleuse !!! Je pense que le gouvernement se trompe totalement.');
        $this->assertTrue($r['flags']['opinion']);
        $p = PriorityScorer::score($r['indicators'], count($r['claims']), $r['flags']);
        $this->assertNotSame('critical', $p['priority']);
        $this->assertNotSame('high', $p['priority']);
    }

    public function testSatireIsClassifiedAndCapped(): void
    {
        $r = $this->a->analyze('#satire URGENT : le président a annoncé que les lundis seront supprimés en 2027. Partagez avant que ce soit supprimé ! Ce qu\'on vous cache !!!');
        $this->assertSame('satire', $r['category']);
        $p = PriorityScorer::score($r['indicators'], count($r['claims']), $r['flags']);
        $this->assertSame('normal', $p['priority'], 'Une satire déclarée ne dépasse pas la priorité normale sans indice fort');
    }

    public function testKnownSatireSiteFlag(): void
    {
        $r = $this->a->analyze('Le ministre a déclaré que la pluie sera désormais payante dès 2027.', [], true);
        $this->assertSame('satire', $r['category']);
    }

    public function testTemporalInconsistency(): void
    {
        $r = $this->a->analyze("Aujourd'hui à Niamey, des inondations ont fait 12 morts. Photo prise le 15/08/2020.");
        $this->assertContains('temporal_inconsistency', $this->codes($r));
        $this->assertSame(2020, $r['dates'][0]['year']);
        $this->assertSame('2020-08-15', $r['dates'][0]['iso']);
    }

    public function testDateExtractionFrenchMonths(): void
    {
        $d = $this->a->extractDates('Le sommet du 12 mars 2021 et celui de juin 2023.');
        $this->assertSame(['2021-03-12', '2023-06-01'], array_column($d, 'iso'));
    }

    public function testQuestionsAreNotClaims(): void
    {
        $claims = $this->a->extractClaims("Le gouvernement a-t-il vraiment signé l'accord de 2024 ?");
        $this->assertSame([], $claims);
    }

    public function testStatisticClaimType(): void
    {
        $claims = $this->a->extractClaims("Selon l'INS, l'inflation a augmenté de 12 % au Niger en 2024.");
        $this->assertSame('statistic', $claims[0]['type']);
    }

    public function testAdvertisingCategory(): void
    {
        $r = $this->a->analyze('Promo exceptionnelle : achetez maintenant, livraison gratuite à Niamey ! Offre spéciale sur les téléphones.');
        $this->assertSame('publicite', $r['category']);
    }

    public function testRumorMarker(): void
    {
        $r = $this->a->analyze('Il paraît que le directeur de la banque aurait été arrêté hier soir, information non confirmée.');
        $this->assertSame('rumeur', $r['category']);
        $this->assertContains('rumor_marker', $this->codes($r));
    }

    public function testEnglishTextLanguage(): void
    {
        $r = $this->a->analyze('The minister announced that the bridge was closed and this is the official statement for the press.');
        $this->assertSame('en', $r['language']);
    }
}
