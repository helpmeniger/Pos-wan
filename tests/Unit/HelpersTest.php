<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Geo;
use App\Core\Str;
use App\Core\ValidationException;
use App\Core\Validator;
use Tests\TestCase;

final class HelpersTest extends TestCase
{
    public function testPhoneNormalization(): void
    {
        $this->assertSame('+22790123456', Str::normalizePhone('90 12 34 56'));
        $this->assertSame('+22790123456', Str::normalizePhone('+227 90-12-34-56'));
        $this->assertSame('+22790123456', Str::normalizePhone('0022790123456'));
        $this->assertSame(null, Str::normalizePhone('12'));
    }

    public function testFrenchDateFormatAccepted(): void
    {
        $v = Validator::make(['d' => '25/12/2026', 'dt' => '25/12/2026 08:30'], ['d' => 'required|date', 'dt' => 'required|datetime']);
        $this->assertSame('2026-12-25', $v['d']);
        $this->assertSame('2026-12-25 08:30:00', $v['dt']);
        $this->assertThrows(fn () => Validator::make(['d' => '31/02/2026'], ['d' => 'required|date']), ValidationException::class);
    }

    public function testValidatorWhitelistsAndTypes(): void
    {
        $v = Validator::make(['a' => '12', 'evil' => 'x', 'b' => 'true'], ['a' => 'required|int|min:1', 'b' => 'bool']);
        $this->assertSame(['a' => 12, 'b' => true], $v);
        $e = $this->assertThrows(fn () => Validator::make(['p' => 'court'], ['p' => 'required|password']), ValidationException::class);
        $this->assertTrue(isset($e->errors['p']));
    }

    public function testGeo(): void
    {
        $ring = [[2.0, 13.4], [2.2, 13.4], [2.2, 13.6], [2.0, 13.6], [2.0, 13.4]];
        $this->assertTrue(Geo::pointInPolygon(13.5, 2.1, $ring));
        $this->assertFalse(Geo::pointInPolygon(13.7, 2.1, $ring));
        $d = Geo::distance(13.5137, 2.1098, 13.4900, 2.1600);
        $this->assertTrue($d > 5500 && $d < 6200, 'Distance orthodromique ≈ 5,9 km');
    }
}
