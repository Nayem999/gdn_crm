<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\PurchaseOrderStatus;
use App\Domain\Sales\Models\PurchaseOrder;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The only thing that moves a purchase order's status.
 */
class ChangePurchaseOrderStatusAction
{
    /**
     * @throws RuntimeException when the move is not one the current status allows
     */
    public function __invoke(PurchaseOrder $order, PurchaseOrderStatus $target, ?Carbon $at = null): PurchaseOrder
    {
        $current = $order->status();
        $at ??= Carbon::now();

        if ($current === $target) {
            return $order;
        }

        if (! $current->canTransitionTo($target)) {
            throw new RuntimeException(
                'A '.strtolower($current->label()).' purchase order cannot be marked '.strtolower($target->label()).'.'
            );
        }

        $order->forceFill([
            'status' => $target->value,
            ...match ($target) {
                PurchaseOrderStatus::Ordered => ['ordered_at' => $at],
                PurchaseOrderStatus::Received => ['received_at' => $at],
                PurchaseOrderStatus::Cancelled => ['cancelled_at' => $at],
                default => [],
            },
        ])->save();

        return $order->fresh() ?? $order;
    }
}
