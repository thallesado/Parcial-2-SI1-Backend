<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\Webhook;

class StripeInscripcionService
{
    public function __construct()
    {
        Stripe::setApiKey((string) config('services.stripe.secret'));
    }

    public function amount(): float
    {
        return (float) config('services.stripe.payment_amount', 100);
    }

    public function amountInCents(): int
    {
        return (int) config('services.stripe.payment_amount_cents', 10000);
    }

    public function currency(): string
    {
        return strtolower((string) config('services.stripe.currency', 'usd'));
    }

    public function productName(): string
    {
        return (string) config('services.stripe.product_name', 'Pago Postulacion Universitaria');
    }

    public function timeoutMinutes(): int
    {
        return (int) config('services.stripe.payment_timeout_minutes', 10);
    }

    public function createPaymentIntent(array $payload, string $token, Carbon $expiresAt): PaymentIntent
    {
        return PaymentIntent::create([
            'amount' => $this->amountInCents(),
            'currency' => $this->currency(),
            'payment_method_types' => ['card'],
            'description' => $this->productName(),
            'receipt_email' => $payload['correo'] ?? null,
            'metadata' => [
                'token_inscripcion' => $token,
                'gestion_id' => $payload['gestion_id'] ?? '',
                'ci' => $payload['ci'] ?? '',
                'correo' => $payload['correo'] ?? '',
                'expira_en' => $expiresAt->toIso8601String(),
            ],
        ]);
    }

    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return PaymentIntent::retrieve($paymentIntentId);
    }

    public function cancelPaymentIntent(string $paymentIntentId): PaymentIntent
    {
        $paymentIntent = PaymentIntent::retrieve($paymentIntentId);

        return $paymentIntent->cancel();
    }

    public function constructWebhookEvent(string $payload, string $signature): Event
    {
        return Webhook::constructEvent(
            $payload,
            $signature,
            (string) config('services.stripe.webhook_secret')
        );
    }

    public function canCancelStatus(?string $status): bool
    {
        return in_array($status, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'requires_capture'], true);
    }

    public function statusLabel(?string $status): string
    {
        return match ($status) {
            'succeeded' => 'PAGADA',
            'canceled' => 'CANCELADA',
            'processing' => 'PROCESANDO',
            'requires_payment_method' => 'PENDIENTE_METODO',
            'requires_confirmation' => 'PENDIENTE_CONFIRMACION',
            'requires_action' => 'REQUIERE_ACCION',
            default => strtoupper((string) $status ?: 'PENDIENTE'),
        };
    }
}
