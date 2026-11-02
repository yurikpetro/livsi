<?php

namespace Tests;

use App\Services\DeliveryQuoteService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery\MockInterface;

abstract class TestCase extends BaseTestCase
{
    /** Изолирует расчёт доставки в тестах оплаты, резервов и писем. */
    protected function mockCalculatedDelivery(): void
    {
        $this->mock(DeliveryQuoteService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('testing')->andReturn(false);
            $mock->shouldReceive('accept')->with('calculated-delivery', \Mockery::any(), \Mockery::any(), \Mockery::any())
                ->andReturn(['price' => 0, 'point' => ['id' => '42', 'city' => 'Москва', 'address' => 'Москва, улица 1']]);
        });
    }
}
