<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function numbers(): array
    {
        return [
            'local espacé' => ['90 12 34 56', '+22790123456'],
            'local tirets' => ['96-11-22-33', '+22796112233'],
            'international +' => ['+227 90123456', '+22790123456'],
            'international 00' => ['0022790123456', '+22790123456'],
            'fixe 20' => ['20 73 00 00', '+22720730000'],
            'trop court' => ['9012345', null],
            'autre pays' => ['+22670123456', null],
            'commence par 0' => ['01234567', null],
            'lettres' => ['abc', null],
        ];
    }

    #[DataProvider('numbers')]
    public function testNormalize(string $input, ?string $expected): void
    {
        $this->assertSame($expected, Phone::normalize($input));
    }

    public function testFormatAndMask(): void
    {
        $this->assertSame('+227 90 12 34 56', Phone::format('+22790123456'));
        $this->assertSame('+227 90 •• •• 56', Phone::mask('+22790123456'));
    }
}
