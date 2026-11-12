<?php
declare(strict_types=1);

namespace Addons\Pay\Drivers;

use Addons\Pay\GatewayResult;
use Addons\Pay\PaymentDriver;
use Addons\Pay\Secrets;

abstract class AbstractDriver implements PaymentDriver
{
    protected array $target;
    protected array $config;

    public function __construct(array $target)
    {
        $this->target = $target;
        $this->config = is_array($target['config'] ?? null) ? $target['config'] : (json_decode((string) ($target['config_json'] ?? ''), true) ?: []);
    }

    public static function capabilities(): array
    {
        return [];
    }

    protected function ownerType(): string
    {
        return isset($this->target['connection']) ? 'terminal' : 'connector';
    }

    protected function secret(string $k): ?string
    {
        return !empty($this->target['id']) ? Secrets::get($this->ownerType(), (int) $this->target['id'], $k) : null;
    }

    protected function timeout(): int
    {
        return max(1, min(300, (int) ($this->target['timeout_s'] ?? 30)));
    }

    protected function prod(): bool
    {
        return ($this->target['environment'] ?? 'test') === 'prod';
    }

    private function no(string $fn): GatewayResult
    {
        return GatewayResult::unsupported($fn, static::info()['label'] ?? static::class);
    }

    public function testConnection(): GatewayResult { return $this->no('testConnection'); }
    public function initiate(array $p): GatewayResult { return $this->no('initiate'); }
    public function getStatus(array $tx): GatewayResult { return $this->no('getStatus'); }
    public function cancel(array $tx): GatewayResult { return $this->no('cancel'); }
    public function refund(array $tx, array $r): GatewayResult { return $this->no('refund'); }
    public function getRefundStatus(array $tx, array $refund): GatewayResult { return $this->no('getRefundStatus'); }
    public function parseWebhook(string $body, array $headers): ?array { return null; }
}
