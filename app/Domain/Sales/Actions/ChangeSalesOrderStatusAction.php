<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\SalesOrderStatus;
use App\Domain\Sales\Models\SalesOrder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The only thing that moves a sales order's status, with the stamps beside the
 * moves that earn them — the same arrangement quotes have, for the same reason.
 */
class ChangeSalesOrderStatusAction
{
    /**
     * @throws RuntimeException when the move is not one the current status allows
     */
    public function __invoke(SalesOrder $order, SalesOrderStatus $target, ?Carbon $at = null): SalesOrder
    {
        $current = $order->status();
        $at ??= Carbon::now();

        if ($current === $target) {
            return $order;
        }

        if (! $current->canTransitionTo($target)) {
            throw new RuntimeException(
                'A '.strtolower($current->label()).' order cannot be marked '.strtolower($target->label()).'.'
            );
        }

        $order->forceFill([
            'status' => $target->value,
            ...match ($target) {
                SalesOrderStatus::Confirmed => ['confirmed_at' => $at],
                SalesOrderStatus::Fulfilled => ['fulfilled_at' => $at],
                SalesOrderStatus::Cancelled => ['cancelled_at' => $at],
                default => [],
            },
        ])->save();

        return $order->fresh() ?? $order;
    }
}
