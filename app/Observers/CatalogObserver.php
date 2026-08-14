<?php

namespace App\Observers;

use App\Actions\Audit\CreateAuditLog;
use App\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CatalogObserver
{
    /**
     * @var list<string>
     */
    private const AUDITED_FIELDS = [
        'name',
        'slug',
        'price',
        'is_active',
        'supplier_contact',
        'product_type',
        'brand_id',
        'category_id',
        'product_id',
        'sku',
        'compare_at_price',
        'cost_price',
    ];

    public function created(Model $record): void
    {
        app(CreateAuditLog::class)->handle(
            subject: $record,
            action: AuditEvent::CatalogCreated,
            metadata: [
                'catalog_type' => $this->catalogType($record),
                'record_id' => $record->getKey(),
            ],
            actorId: auth()->id(),
        );
    }

    public function updated(Model $record): void
    {
        $changes = array_intersect_key(
            $record->getChanges(),
            array_flip(self::AUDITED_FIELDS),
        );

        if ($changes === []) {
            return;
        }

        $action = array_key_exists('is_active', $changes)
            ? ((bool) $record->is_active ? AuditEvent::CatalogActivated : AuditEvent::CatalogDeactivated)
            : AuditEvent::CatalogUpdated;

        app(CreateAuditLog::class)->handle(
            subject: $record,
            action: $action,
            metadata: [
                'catalog_type' => $this->catalogType($record),
                'record_id' => $record->getKey(),
                'changed_fields' => array_keys($changes),
                'before' => $this->safeState($record->getOriginal(), $changes),
                'after' => $this->safeState($record->getAttributes(), $changes),
            ],
            actorId: auth()->id(),
        );
    }

    public function deleted(Model $record): void
    {
        app(CreateAuditLog::class)->handle(
            subject: $record,
            action: AuditEvent::CatalogDeleted,
            metadata: [
                'catalog_type' => $this->catalogType($record),
                'record_id' => $record->getKey(),
            ],
            actorId: auth()->id(),
        );
    }

    private function catalogType(Model $record): string
    {
        return Str::snake(class_basename($record));
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function safeState(array $state, array $changes): array
    {
        $safeFields = array_diff(array_keys($changes), ['supplier_contact']);

        return array_intersect_key($state, array_flip($safeFields));
    }
}
