<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Services\OrderStateMachine as SM;
use Tests\TestCase;

final class StateMachineTest extends TestCase
{
    public function testRoleRestrictions(): void
    {
        $this->assertTrue(SM::can('ride', 'arrived_pickup', 'in_progress', 'provider'));
        $this->assertFalse(SM::can('ride', 'arrived_pickup', 'in_progress', 'customer'), 'Le client ne démarre pas la course');
        $this->assertFalse(SM::can('ride', 'in_progress', 'cancelled', 'customer'), 'Course en cours : pas d\'annulation client');
        $this->assertFalse(SM::can('ride', 'completed', 'in_progress', 'provider'), 'Une course terminée ne peut pas être rouverte');
        $this->assertFalse(SM::can('ride', 'searching', 'assigned', 'provider'), 'L\'affectation est faite par le système');
        $this->assertTrue(SM::can('merchant', 'pending', 'accepted', 'merchant'));
        $this->assertFalse(SM::can('merchant', 'pending', 'accepted', 'provider'));
        $this->assertFalse(SM::can('courier', 'delivered', 'in_delivery', 'provider'));
        $this->assertTrue(SM::can('courier', 'paid', 'disputed', 'customer'));
    }

    public function testTerminalStatesHaveNoExit(): void
    {
        foreach (['ride', 'courier', 'merchant'] as $flow) {
            foreach (['cancelled', 'expired', 'refused', 'refunded'] as $s) {
                $this->assertSame([], array_keys(SM::TRANSITIONS[$flow][$s] ?? []), "$flow/$s doit être terminal");
            }
        }
    }

    public function testInvalidTransitionThrows(): void
    {
        $order = ['id' => 1, 'flow' => 'ride', 'status' => 'searching', 'version' => 1];
        $this->assertThrows(fn () => SM::transition($order, 'completed', 'provider', 1), HttpException::class);
    }
}
