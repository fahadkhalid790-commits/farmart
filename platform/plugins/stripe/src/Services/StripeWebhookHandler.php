<?php

namespace Botble\Stripe\Services;

use Botble\Base\Facades\BaseHelper;
use Botble\Ecommerce\Enums\OrderHistoryActionEnum;
use Botble\Ecommerce\Enums\OrderStatusEnum;
use Botble\Ecommerce\Models\Order;
use Botble\Ecommerce\Models\OrderHistory;
use Botble\Payment\Enums\PaymentStatusEnum;
use Botble\Payment\Models\Payment;
use Illuminate\Support\Collection;
use Stripe\Event;

class StripeWebhookHandler
{
    /**
     * Dispatch incoming Stripe event to the correct handler.
     */
    public function handle(Event $event): void
    {
        match ($event->type) {
            'payment_intent.succeeded'       => $this->handlePaymentIntentSucceeded($event),
            'payment_intent.payment_failed'  => $this->handlePaymentIntentFailed($event),
            'payment_intent.canceled'        => $this->handlePaymentIntentCanceled($event),
            'charge.refunded'                => $this->handleChargeRefunded($event),
            'charge.dispute.created'         => $this->handleDisputeCreated($event),
            'charge.dispute.closed'          => $this->handleDisputeClosed($event),
            default                          => null,
        };
    }

    // ──────────────────────────────────────────────────────────────────────────
    // payment_intent.succeeded  (existing behaviour, moved here)
    // ──────────────────────────────────────────────────────────────────────────

    protected function handlePaymentIntentSucceeded(Event $event): void
    {
        /** @var \Stripe\PaymentIntent $paymentIntent */
        $paymentIntent = $event->data->object; // @phpstan-ignore-line

        $payment = $this->findPaymentByChargeId($paymentIntent->id);

        if (! $payment) {
            return;
        }

        $payment->status = PaymentStatusEnum::COMPLETED;
        $payment->save();

        do_action(PAYMENT_ACTION_PAYMENT_PROCESSED, [
            'charge_id' => $payment->charge_id,
            'order_id'  => $payment->order_id,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // payment_intent.payment_failed
    // ──────────────────────────────────────────────────────────────────────────

    protected function handlePaymentIntentFailed(Event $event): void
    {
        /** @var \Stripe\PaymentIntent $paymentIntent */
        $paymentIntent = $event->data->object; // @phpstan-ignore-line

        $payment = $this->findPaymentByChargeId($paymentIntent->id);

        if (! $payment) {
            return;
        }

        $payment->status = PaymentStatusEnum::FAILED;
        $payment->save();

        $failureMessage = $paymentIntent->last_payment_error?->message
            ?? __('Payment failed via Stripe webhook.');

        $this->getOrdersByPayment($payment)->each(function (Order $order) use ($failureMessage): void {
            if ($order->status->getValue() !== OrderStatusEnum::CANCELED) {
                $order->status = OrderStatusEnum::CANCELED;
                $order->save();

                $this->logOrderHistory(
                    $order,
                    OrderHistoryActionEnum::CANCEL_ORDER,
                    __('[Stripe] Payment failed: :message', ['message' => $failureMessage])
                );
            }
        });
    }

    // ──────────────────────────────────────────────────────────────────────────
    // payment_intent.canceled
    // ──────────────────────────────────────────────────────────────────────────

    protected function handlePaymentIntentCanceled(Event $event): void
    {
        /** @var \Stripe\PaymentIntent $paymentIntent */
        $paymentIntent = $event->data->object; // @phpstan-ignore-line

        $payment = $this->findPaymentByChargeId($paymentIntent->id);

        if (! $payment) {
            return;
        }

        $payment->status = PaymentStatusEnum::CANCELED;
        $payment->save();

        $this->getOrdersByPayment($payment)->each(function (Order $order): void {
            if ($order->status->getValue() !== OrderStatusEnum::CANCELED) {
                $order->status = OrderStatusEnum::CANCELED;
                $order->save();

                $this->logOrderHistory(
                    $order,
                    OrderHistoryActionEnum::CANCEL_ORDER,
                    __('[Stripe] Payment intent was canceled by Stripe.')
                );
            }
        });
    }

    // ──────────────────────────────────────────────────────────────────────────
    // charge.refunded  (full or partial)
    // ──────────────────────────────────────────────────────────────────────────

    protected function handleChargeRefunded(Event $event): void
    {
        /** @var \Stripe\Charge $charge */
        $charge = $event->data->object; // @phpstan-ignore-line

        // Stripe stores the charge ID on the charge object, but our Payment
        // records may be stored under the PaymentIntent ID (charge_id column).
        $payment = $this->findPaymentByChargeId($charge->payment_intent ?? $charge->id)
            ?? $this->findPaymentByChargeId($charge->id);

        if (! $payment) {
            return;
        }

        $amountRefunded  = $charge->amount_refunded / 100;  // Stripe uses cents
        $totalAmount     = $charge->amount / 100;
        $isFullRefund    = $charge->refunded;                // true when fully refunded

        $payment->refunded_amount = $amountRefunded;
        $payment->status          = $isFullRefund
            ? PaymentStatusEnum::REFUNDED
            : PaymentStatusEnum::REFUNDING;
        $payment->save();

        $orderStatus  = $isFullRefund ? OrderStatusEnum::RETURNED : OrderStatusEnum::PARTIAL_RETURNED;
        $historyNote  = $isFullRefund
            ? __('[Stripe] Full refund of :amount processed.', ['amount' => format_price($amountRefunded)])
            : __('[Stripe] Partial refund of :amount (of :total) processed.', [
                'amount' => format_price($amountRefunded),
                'total'  => format_price($totalAmount),
            ]);

        $this->getOrdersByPayment($payment)->each(
            function (Order $order) use ($orderStatus, $historyNote): void {
                $order->status = $orderStatus;
                $order->save();

                $this->logOrderHistory($order, OrderHistoryActionEnum::REFUND, $historyNote);
            }
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // charge.dispute.created
    // ──────────────────────────────────────────────────────────────────────────

    protected function handleDisputeCreated(Event $event): void
    {
        /** @var \Stripe\Dispute $dispute */
        $dispute = $event->data->object; // @phpstan-ignore-line

        $payment = $this->findPaymentByChargeId($dispute->payment_intent ?? $dispute->charge);

        if (! $payment) {
            return;
        }

        $payment->status = PaymentStatusEnum::FRAUD;
        $payment->save();

        $reason = $dispute->reason ?? 'unknown';
        $note   = __('[Stripe] Dispute opened. Reason: :reason. Amount: :amount.', [
            'reason' => $reason,
            'amount' => format_price($dispute->amount / 100),
        ]);

        $this->getOrdersByPayment($payment)->each(
            fn (Order $order) => $this->logOrderHistory($order, OrderHistoryActionEnum::UPDATE_STATUS, $note)
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // charge.dispute.closed
    // ──────────────────────────────────────────────────────────────────────────

    protected function handleDisputeClosed(Event $event): void
    {
        /** @var \Stripe\Dispute $dispute */
        $dispute = $event->data->object; // @phpstan-ignore-line

        $payment = $this->findPaymentByChargeId($dispute->payment_intent ?? $dispute->charge);

        if (! $payment) {
            return;
        }

        // Stripe dispute statuses: won | lost | needs_response | under_review | charge_refunded | warning_*
        $paymentStatus = match ($dispute->status) {
            'won'             => PaymentStatusEnum::COMPLETED,
            'lost',
            'charge_refunded' => PaymentStatusEnum::REFUNDED,
            default           => $payment->status, // leave unchanged for in-progress statuses
        };

        $payment->status = $paymentStatus;
        $payment->save();

        $note = __('[Stripe] Dispute closed with status: :status.', ['status' => $dispute->status]);

        $this->getOrdersByPayment($payment)->each(
            fn (Order $order) => $this->logOrderHistory($order, OrderHistoryActionEnum::UPDATE_STATUS, $note)
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    protected function findPaymentByChargeId(?string $chargeId): ?Payment
    {
        if (! $chargeId) {
            return null;
        }

        return Payment::query()
            ->where('charge_id', $chargeId)
            ->where('payment_channel', STRIPE_PAYMENT_METHOD_NAME)
            ->first();
    }

    /**
     * Returns all Orders linked to the given Payment record.
     *
     * @return Collection<int, Order>
     */
    protected function getOrdersByPayment(Payment $payment): Collection
    {
        return Order::query()
            ->where('payment_id', $payment->id)
            ->get();
    }

    protected function logOrderHistory(Order $order, string $action, string $description): void
    {
        try {
            OrderHistory::query()->create([
                'order_id'    => $order->id,
                'user_id'     => 0,          // 0 = system (no admin user)
                'action'      => $action,
                'description' => $description,
            ]);
        } catch (\Throwable $e) {
            BaseHelper::logError($e);
        }
    }
}
