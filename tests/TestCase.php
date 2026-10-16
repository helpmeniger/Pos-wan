<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Str;
use AssertionError;

/** Base minimale de test (aucune dépendance) : assertions + appels HTTP en mémoire. */
abstract class TestCase
{
    protected static ?Kernel $kernel = null;
    public int $assertions = 0;

    public function setUp(): void
    {
    }

    protected function kernel(): Kernel
    {
        return self::$kernel ??= new Kernel();
    }

    // ------------------------------------------------------------------ Assertions
    protected function assertTrue(mixed $v, string $msg = 'Échec : valeur fausse'): void
    {
        $this->assertions++;
        if ($v !== true) {
            throw new AssertionError($msg);
        }
    }

    protected function assertFalse(mixed $v, string $msg = 'Échec : valeur vraie'): void
    {
        $this->assertTrue($v === false, $msg);
    }

    protected function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new AssertionError(($msg ? $msg . ' — ' : '') . 'attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true));
        }
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $msg = ''): void
    {
        $this->assertions++;
        if ($expected != $actual) {
            throw new AssertionError(($msg ? $msg . ' — ' : '') . 'attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true));
        }
    }

    protected function assertStatus(int $expected, Response $r, string $msg = ''): void
    {
        $this->assertions++;
        if ($r->status !== $expected) {
            throw new AssertionError(($msg ? $msg . ' — ' : '') . "statut HTTP attendu $expected, obtenu {$r->status} : " . mb_substr($r->body, 0, 400));
        }
    }

    protected function assertThrows(callable $fn, string $class = \Throwable::class, string $msg = 'Une exception était attendue'): \Throwable
    {
        $this->assertions++;
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return $e;
            }
            throw new AssertionError($msg . ' — exception inattendue ' . $e::class . ' : ' . $e->getMessage());
        }
        throw new AssertionError($msg);
    }

    // ------------------------------------------------------------------ HTTP
    protected function call(string $method, string $path, array $body = [], ?string $token = null, array $headers = []): Response
    {
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        [$p, $q] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($q, $query);
        $req = Request::create($method, $p, $body, $headers, $query);
        $res = $this->kernel()->handle($req);
        Auth::actingAs(null);
        return $res;
    }

    protected function data(Response $r): mixed
    {
        $d = json_decode($r->body, true);
        return $d['data'] ?? $d;
    }

    // ------------------------------------------------------------------ Fabriques
    protected function user(string $role, array $attrs = []): array
    {
        static $n = 0;
        $n++;
        $id = Database::insert('users', $attrs + [
            'uuid' => Str::uuid(),
            'role_id' => (int) Database::value('SELECT id FROM roles WHERE code = :c', ['c' => $role]),
            'full_name' => ucfirst($role) . ' Test ' . $n,
            'phone' => '+22796' . str_pad((string) (random_int(0, 99) * 10000 + $n), 6, '0', STR_PAD_LEFT),
            'email' => $role . $n . '.' . bin2hex(random_bytes(3)) . '@test.invalid',
            'password_hash' => password_hash('Secret123', PASSWORD_BCRYPT, ['cost' => 4]),
            'status' => 'active',
        ]);
        if (in_array($role, ['client', 'business'], true)) {
            Database::insert('customer_profiles', ['user_id' => $id]);
        }
        return Database::first('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    protected function token(int $userId): string
    {
        return Auth::issueToken($userId, 'test');
    }

    /** Prestataire validé, en ligne, positionné à (lat,lng). */
    protected function provider(string $type = 'driver', string $category = 'taxi', float $lat = 13.5140, float $lng = 2.1100, bool $online = true): array
    {
        $u = $this->user($type);
        Database::insert('provider_profiles', ['user_id' => $u['id'], 'provider_type' => $type, 'verification_status' => 'verified', 'is_online' => $online ? 1 : 0,
            'license_expires_at' => date('Y-m-d', strtotime('+1 year'))]);
        Database::insert('vehicles', ['provider_id' => $u['id'], 'category' => $category, 'make' => 'Test', 'model' => 'T', 'color' => 'Blanc',
            'plate' => 'T-' . $u['id'] . '-' . random_int(100, 999), 'capacity' => in_array($category, ['moto', 'moto_taxi', 'bicycle'], true) ? 1 : 4]);
        Database::execute('INSERT INTO provider_locations (provider_id, lat, lng, updated_at) VALUES (:p, :la, :ln, NOW())', ['p' => $u['id'], 'la' => $lat, 'ln' => $lng]);
        return $u;
    }

    protected function merchant(array $attrs = []): array
    {
        $owner = $this->user('merchant');
        $id = Database::insert('merchants', $attrs + ['owner_user_id' => $owner['id'], 'name' => 'Resto Test ' . $owner['id'], 'address_text' => 'Niamey', 'lat' => 13.5160, 'lng' => 2.1170,
            'city_id' => 1, 'status' => 'verified', 'is_open' => 1, 'delivery_mode' => 'marwa', 'delivery_radius_km' => 15]);
        return Database::first('SELECT * FROM merchants WHERE id = :id', ['id' => $id]) + ['_owner' => $owner];
    }

    protected function rideBody(array $extra = []): array
    {
        return $extra + [
            'service_type' => 'taxi', 'vehicle_category' => 'taxi', 'pricing_mode' => 'auto', 'payment_method' => 'cash',
            'pickup_text' => 'Départ test', 'pickup_lat' => 13.5137, 'pickup_lng' => 2.1098,
            'dropoff_text' => 'Arrivée test', 'dropoff_lat' => 13.4900, 'dropoff_lng' => 2.1600,
        ];
    }

    /** Remet tous les prestataires de test hors service pour isoler les scénarios d'attribution. */
    protected function isolateProviders(): void
    {
        Database::execute('UPDATE provider_profiles SET is_online = 0');
        // Les demandes restées ouvertes par les tests précédents seraient proposées aux nouveaux
        // prestataires en ligne (comportement voulu) : on les clôt pour isoler chaque test.
        Database::execute("UPDATE orders SET status = 'expired', dispatch_status = 'no_provider' WHERE provider_id IS NULL AND status IN ('searching','negotiating')");
        Database::execute("UPDATE orders SET dispatch_status = 'no_provider' WHERE provider_id IS NULL AND dispatch_status = 'searching'");
    }
}
