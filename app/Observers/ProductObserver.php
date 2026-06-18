<?php

namespace App\Observers;

use App\Actions\Audit\CreateAuditLog;
use App\Models\Product;

class ProductObserver
{
    public function created(Product $product): void
    {
        app(CreateAuditLog::class)->handle($product, 'product.created');
    }

    public function updated(Product $product): void
    {
        app(CreateAuditLog::class)->handle(
            subject: $product,
            action: 'product.updated',
            metadata: array_intersect_key($product->getChanges(), array_flip(['name', 'slug', 'category_id'])),
        );
    }

    public function deleted(Product $product): void
    {
        app(CreateAuditLog::class)->handle(
            subject: $product,
            action: 'product.deleted',
            metadata: ['name' => $product->name],
        );
    }
}
