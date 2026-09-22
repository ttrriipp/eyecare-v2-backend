<?php

namespace App\Services\Payments;

use App\Enums\OrderPaymentMethod;
use App\Models\ClinicPaymentMethod;
use App\Models\JobOrder;
use Illuminate\Support\Carbon;

final class PaymentInstructionCatalog
{
    /** @var list<array<string, mixed>>|null */
    private ?array $activeMethodsCache = null;

    /**
     * Return the currently configured online payment methods.
     *
     * Once an administrator has created a database configuration, that
     * configuration becomes authoritative. This lets an administrator turn
     * every method off without an old environment fallback reappearing.
     *
     * @return list<array<string, mixed>>
     */
    public function activeMethods(): array
    {
        if ($this->activeMethodsCache !== null) {
            return $this->activeMethodsCache;
        }

        $configuredMethods = ClinicPaymentMethod::query()
            ->orderBy('id')
            ->get();

        if ($configuredMethods->isNotEmpty()) {
            return $this->activeMethodsCache = $configuredMethods
                ->filter(fn (ClinicPaymentMethod $method): bool => $method->is_active && $method->isConfigured())
                ->map(fn (ClinicPaymentMethod $method): array => $this->fromModel($method))
                ->filter(fn (array $method): bool => filled($method['method'] ?? null))
                ->values()
                ->all();
        }

        return $this->activeMethodsCache = $this->legacyMethods();
    }

    /**
     * Snapshot methods and order-specific values at acceptance time.
     *
     * @return array{methods: list<array<string, mixed>>}|null
     */
    public function snapshot(
        string $orderReference,
        float $amount,
        ?Carbon $expiresAt,
    ): ?array {
        $methods = collect($this->activeMethods())
            ->map(fn (array $method): array => [
                ...$method,
                'amount' => number_format($amount, 2, '.', ''),
                'order_reference' => $orderReference,
                'payment_expires_at' => $expiresAt?->toIso8601String(),
            ])
            ->values()
            ->all();

        return $methods === [] ? null : ['methods' => $methods];
    }

    /**
     * Resolve an order's immutable instructions, falling back for older
     * pending orders created before the snapshot column existed.
     *
     * @return list<array<string, mixed>>
     */
    public function forOrder(JobOrder $order): array
    {
        if ($order->payment_instructions !== null) {
            return $this->normalizeSnapshot($order->payment_instructions);
        }

        $billing = $order->billingRecord;
        $amount = $billing !== null
            ? (float) $billing->balance_due
            : (float) $order->total_amount;

        return collect($this->activeMethods())
            ->map(fn (array $method): array => [
                ...$method,
                'amount' => number_format($amount, 2, '.', ''),
                'order_reference' => $order->job_order_number,
                'payment_expires_at' => $order->payment_expires_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    public function supports(JobOrder $order, OrderPaymentMethod $method): bool
    {
        $methods = $this->forOrder($order);

        if ($methods === []) {
            // Older tests/data can contain a pending order created before
            // payment instructions were configured. Keep the original GCash
            // proof contract usable while still rejecting new methods.
            return $method === OrderPaymentMethod::GCash
                && ! ClinicPaymentMethod::query()->exists();
        }

        return collect($methods)->contains(
            fn (array $instruction): bool => ($instruction['method'] ?? null) === $method->value,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    private function normalizeSnapshot(array $snapshot): array
    {
        if (isset($snapshot['methods']) && is_array($snapshot['methods'])) {
            $methods = $snapshot['methods'];
        } elseif (isset($snapshot['method'])) {
            $methods = [$snapshot];
        } else {
            $methods = array_is_list($snapshot) ? $snapshot : [];
        }

        return collect($methods)
            ->filter(fn (mixed $method): bool => is_array($method) && filled($method['method'] ?? null))
            ->map(fn (array $method): array => $this->normalizeMethod($method))
            ->filter(fn (array $method): bool => filled($method['method'] ?? null))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function fromModel(ClinicPaymentMethod $method): array
    {
        $paymentMethod = $method->method instanceof OrderPaymentMethod
            ? $method->method
            : OrderPaymentMethod::tryFrom((string) $method->method);

        if ($paymentMethod === null) {
            return [];
        }

        return $this->normalizeMethod([
            'method' => $paymentMethod->value,
            'label' => $method->label ?: $paymentMethod->label(),
            'clinic_account_name' => (string) $method->account_name,
            'clinic_account_number' => (string) $method->account_number,
            'bank_name' => $method->bank_name,
            'qr_image_path' => $method->qr_image_path,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function legacyMethods(): array
    {
        $methods = [];

        if (filled(config('payments.gcash_account_name')) && filled(config('payments.gcash_account_number'))) {
            $methods[] = $this->normalizeMethod([
                'method' => OrderPaymentMethod::GCash->value,
                'label' => OrderPaymentMethod::GCash->label(),
                'clinic_account_name' => (string) config('payments.gcash_account_name'),
                'clinic_account_number' => (string) config('payments.gcash_account_number'),
                'bank_name' => null,
                'qr_image_path' => config('payments.gcash_qr_image_path'),
            ]);
        }

        if (
            filled(config('payments.bank_name'))
            && filled(config('payments.bank_account_name'))
            && filled(config('payments.bank_account_number'))
        ) {
            $methods[] = $this->normalizeMethod([
                'method' => OrderPaymentMethod::BankTransfer->value,
                'label' => OrderPaymentMethod::BankTransfer->label(),
                'clinic_account_name' => (string) config('payments.bank_account_name'),
                'clinic_account_number' => (string) config('payments.bank_account_number'),
                'bank_name' => (string) config('payments.bank_name'),
                'qr_image_path' => config('payments.bank_qr_image_path'),
            ]);
        }

        return $methods;
    }

    /**
     * @param  array<string, mixed>  $method
     * @return array<string, mixed>
     */
    private function normalizeMethod(array $method): array
    {
        $paymentMethod = OrderPaymentMethod::tryFrom((string) ($method['method'] ?? ''));

        return [
            'method' => $paymentMethod?->value,
            'label' => (string) ($method['label'] ?? $paymentMethod?->label() ?? ''),
            'clinic_account_name' => (string) ($method['clinic_account_name'] ?? ''),
            'clinic_account_number' => (string) ($method['clinic_account_number'] ?? ''),
            'bank_name' => filled($method['bank_name'] ?? null) ? (string) $method['bank_name'] : null,
            'qr_image_path' => $this->safePath($method['qr_image_path'] ?? null),
            'amount' => $method['amount'] ?? null,
            'order_reference' => $method['order_reference'] ?? null,
            'payment_expires_at' => $method['payment_expires_at'] ?? null,
        ];
    }

    private function safePath(mixed $path): ?string
    {
        if (! is_string($path) || blank($path)) {
            return null;
        }

        return str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/')
            ? null
            : ltrim($path, '/');
    }
}
