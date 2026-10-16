<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config;
use App\Core\Database;
use App\Services\DispatchService;
use Tests\TestCase;

/** Attribution unique : plusieurs prestataires acceptent au même instant, un seul l'obtient. */
final class ConcurrencyTest extends TestCase
{
    public function testOnlyOneProviderWinsSimultaneousAcceptance(): void
    {
        if (!function_exists('pcntl_fork')) {
            echo "      (pcntl indisponible : test de concurrence ignoré — normal sous Windows)\n";
            $this->assertTrue(true);
            return;
        }
        $this->isolateProviders();
        $client = $this->user('client');
        $providers = [];
        for ($i = 0; $i < 5; $i++) {
            $providers[] = $this->provider('driver', 'taxi', 13.5140 + $i * 0.0005, 2.1100);
        }
        $o = $this->data($this->call('POST', '/api/orders', $this->rideBody(), $this->token((int) $client['id'])));
        $this->assertSame(5, (int) Database::value("SELECT COUNT(*) FROM dispatch_offers WHERE order_id = :o AND status = 'pending'", ['o' => $o['id']]));

        $c = Config::get('database');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
        $start = microtime(true) + 0.4;
        $pids = [];
        foreach ($providers as $i => $p) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                Database::setPdo(Database::connect($dsn, $c['user'], $c['password']));
                while (microtime(true) < $start) {
                    usleep(500);
                }
                try {
                    DispatchService::acceptByProvider((int) $o['id'], (int) $p['id']);
                    exit(0);
                } catch (\Throwable $e) {
                    exit(3);
                }
            }
            $pids[] = $pid;
        }
        $wins = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wexitstatus($status) === 0) {
                $wins++;
            }
        }
        Database::setPdo(Database::connect($dsn, $c['user'], $c['password']));
        $this->assertSame(1, $wins, 'Exactement un prestataire obtient la commande');
        $this->assertSame(1, (int) Database::value('SELECT COUNT(*) FROM assignments WHERE order_id = :o AND active_order_id IS NOT NULL', ['o' => $o['id']]));
        $this->assertSame(1, (int) Database::value('SELECT COUNT(*) FROM provider_profiles WHERE current_order_id = :o', ['o' => $o['id']]));
        $this->assertSame('assigned', Database::value('SELECT status FROM orders WHERE id = :o', ['o' => $o['id']]));
    }
}
