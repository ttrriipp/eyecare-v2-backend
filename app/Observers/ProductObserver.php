<?php

namespace App\Observers;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use App\Models\Product;

class ProductObserver
{
    public function created(Product $product): void
    {
        app(CreateAuditLog::class)->handle($product, AuditEvent::ProductCreated, actorId: auth()->id());
    }

    public function updated(Product $product): void
    {
        $changes = array_intersect_key(
            $product->getChanges(),
            array_flip(['name', 'slug', 'category_id', 'brand_id', 'product_type', 'is_active']),
        );

        if ($changes === []) {
            return;
        }

        app(CreateAuditLog::class)->handle(
            subject: $product,
            action: array_key_exists('is_active', $changes)
                ? ((bool) $product->is_active ? AuditEvent::CatalogActivated : AuditEvent::CatalogDeactivated)
                : AuditEvent::ProductUpdated,
            metadata: [
                'changed_fields' => array_keys($changes),
                'before' => array_intersect_key($product->getOriginal(), array_flip(array_keys($changes))),
                'after' => array_intersect_key($product->getAttributes(), array_flip(array_keys($changes))),
            ],
            actorId: auth()->id(),
        );
    }

    public function deleted(Product $product): void
    {
        app(CreateAuditLog::class)->handle(
            subject: $product,
            action: AuditEvent::ProductDeleted,
            metadata: ['name' => $product->name],
            actorId: auth()->id(),
        );
    }
}
