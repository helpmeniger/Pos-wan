<?php
declare(strict_types=1);

namespace Verifia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Verifia\Analysis\ImageHasher;
use Verifia\Analysis\SimHash;
use Verifia\Analysis\TextNormalizer;
use Verifia\Tests\DatabaseTestCase;

final class HashingTest extends TestCase
{
    public function testHammingHandlesSignBit(): void
    {
        $this->assertSame(0, SimHash::hamming(PHP_INT_MIN, PHP_INT_MIN));
        $this->assertSame(1, SimHash::hamming(PHP_INT_MIN, 0));
        $this->assertSame(64, SimHash::hamming(-1, 0));
        $this->assertSame(2, SimHash::hamming(0b1010, 0b0101 ^ 0b1111 ^ 0b0011));
    }

    public function testSimHashSimilarity(): void
    {
        $a = 'Le ministre de la santé a annoncé ce matin la fermeture de tous les hôpitaux publics de la capitale pour une durée indéterminée.';
        $b = 'Le ministre de la santé a annoncé ce matin la fermeture de tous les hôpitaux publics de la capitale pour une durée de deux semaines.';
        $c = 'Le championnat de football reprendra la semaine prochaine avec douze équipes engagées et un nouveau calendrier.';
        $ha = SimHash::hash($a);
        $this->assertSame($ha, SimHash::hash($a));
        $this->assertLessThan(SimHash::hamming($ha, SimHash::hash($c)), SimHash::hamming($ha, SimHash::hash($b)));
        $this->assertGreaterThan(0.6, TextNormalizer::jaccard($a, $b));
        $this->assertLessThan(0.1, TextNormalizer::jaccard($a, $c));
    }

    public function testNormalizationRemovesAccentsAndPunctuation(): void
    {
        $this->assertSame('etat d urgence a l hopital', TextNormalizer::forComparison("État d'urgence à l'HÔPITAL !!!"));
    }

    public function testPerceptualHashRobustToResizeAndRecompression(): void
    {
        $png = (new \ReflectionMethod(DatabaseTestCase::class, 'pngBase64'))->invoke(null, 42);
        $jpg = (new \ReflectionMethod(DatabaseTestCase::class, 'resizedJpegBase64'))->invoke(null, $png, 200, 150);
        $other = (new \ReflectionMethod(DatabaseTestCase::class, 'pngBase64'))->invoke(null, 7);
        $h1 = ImageHasher::fromString(base64_decode($png));
        $h2 = ImageHasher::fromString(base64_decode($jpg));
        $h3 = ImageHasher::fromString(base64_decode($other));
        $this->assertNotSame($h1['sha256'], $h2['sha256'], 'Fichiers différents');
        $this->assertLessThanOrEqual(6, SimHash::hamming($h1['dhash'], $h2['dhash']), 'Même image redimensionnée : empreinte proche');
        $this->assertGreaterThan(10, SimHash::hamming($h1['dhash'], $h3['dhash']), 'Images différentes : empreintes éloignées');
        $this->assertSame(320, $h1['width']);
    }

    public function testInvalidImageRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        ImageHasher::fromString('pas une image');
    }
}
