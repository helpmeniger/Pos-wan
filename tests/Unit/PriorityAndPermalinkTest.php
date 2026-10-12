<?php
declare(strict_types=1);

namespace Verifia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Verifia\Analysis\PriorityScorer;
use Verifia\Ingest\PublicationIngestor;

final class PriorityAndPermalinkTest extends TestCase
{
    private static function i(string $code, int $w): array
    {
        return ['code' => $code, 'label' => $code, 'weight' => $w];
    }

    public function testLevels(): void
    {
        $this->assertSame('low', PriorityScorer::score([], 0, [])['priority']);
        $this->assertSame('normal', PriorityScorer::score([], 2, [])['priority']);
        $this->assertSame('high', PriorityScorer::score([self::i('image_reuse', 4)], 0, [])['priority']);
        $this->assertSame('high', PriorityScorer::score([self::i('share_call', 2), self::i('conspiracy', 2)], 1, [])['priority']);
        $crit = PriorityScorer::score([self::i('share_call', 2), self::i('conspiracy', 2), self::i('temporal_inconsistency', 2), self::i('sensational', 2)], 1, []);
        $this->assertSame('critical', $crit['priority']);
    }

    public function testCriticalRequiresSeveralImportantIndicators(): void
    {
        // Un seul indice fort, même lourd, ne suffit pas pour « critique »
        $r = PriorityScorer::score([self::i('image_reuse', 4), self::i('contradicted_claim', 4)], 1, []);
        $this->assertSame('high', $r['priority']);
    }

    public function testSatireKeepsImageReuse(): void
    {
        $r = PriorityScorer::score([self::i('image_reuse', 4)], 0, ['satire' => true]);
        $this->assertSame('high', $r['priority'], 'La réutilisation d\'image reste signalée même pour une satire');
    }

    public function testAtLeast(): void
    {
        $this->assertTrue(PriorityScorer::atLeast('critical', 'high'));
        $this->assertFalse(PriorityScorer::atLeast('normal', 'high'));
    }

    public function testConfidenceBounded(): void
    {
        $this->assertLessThanOrEqual(0.95, PriorityScorer::confidence(10000, 50, true));
        $this->assertGreaterThanOrEqual(0.3, PriorityScorer::confidence(0, 0, false));
    }

    public function testPermalinkNormalization(): void
    {
        $this->assertSame(
            'https://facebook.com/page/posts/123',
            PublicationIngestor::normalizePermalink('https://www.facebook.com/page/posts/123/?__cft__[0]=abc&__tn__=R&utm_source=x')
        );
        $this->assertSame('https://example.org/a?id=5', PublicationIngestor::normalizePermalink('http://m.example.org/a?utm_medium=y&id=5'));
        $this->assertNull(PublicationIngestor::normalizePermalink('javascript:alert(1)'));
        $this->assertNull(PublicationIngestor::normalizePermalink(''));
    }
}
