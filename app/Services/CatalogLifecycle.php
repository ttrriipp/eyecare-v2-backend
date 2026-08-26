<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\LensCategory;
use App\Models\LensOption;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CatalogLifecycle
{
    public static function activate(Model $record): void
    {
        self::authorize();

        if (method_exists($record, 'restore') && $record->trashed()) {
            $record->restore();
        }

        $record->forceFill(['is_active' => true])->save();
    }

    public static function deactivate(Model $record): void
    {
        self::authorize();

        $record->forceFill(['is_active' => false])->save();
    }

    public static function delete(Model $record): void
    {
        self::authorize();

        if (self::isReferenced($record)) {
            throw ValidationException::withMessages([
                'record' => ['This catalog record cannot be deleted because it has been referenced. Deactivate it instead.'],
            ]);
        }

        if (method_exists($record, 'forceDelete')) {
            $record->forceDelete();

            return;
        }

        $record->delete();
    }

    public static function isReferenced(Model $record): bool
    {
        return match (true) {
            $record instanceof Brand => DB::table('products')
                ->where('brand_id', $record->getKey())
                ->exists(),
            $record instanceof ProductCategory => DB::table('products')
                ->where('category_id', $record->getKey())
                ->exists(),
            $record instanceof Product => DB::table('product_variants')
                ->where('product_id', $record->getKey())
                ->exists(),
            $record instanceof ProductVariant => self::variantIsReferenced($record),
            $record instanceof LensCategory => self::existsInAnyTable(
                'lens_category_id',
                $record->getKey(),
                ['quotation_items', 'job_order_items'],
            ),
            $record instanceof LensOption => self::existsInAnyTable(
                'lens_option_id',
                $record->getKey(),
                ['quotation_items', 'job_order_items'],
            ),
            $record instanceof Service => self::serviceIsReferenced($record),
            default => throw new InvalidArgumentException('Unsupported catalog record: '.get_class($record)),
        };
    }

    public static function referenceLabel(Model $record): string
    {
        return match (true) {
            $record instanceof Brand => 'brand',
            $record instanceof ProductCategory => 'product category',
            $record instanceof Product => 'product',
            $record instanceof ProductVariant => 'variant',
            $record instanceof LensCategory => 'lens package',
            $record instanceof LensOption => 'lens option',
            $record instanceof Service => 'service',
            default => 'catalog record',
        };
    }

    private static function variantIsReferenced(ProductVariant $variant): bool
    {
        return self::existsInAnyTable(
            'product_variant_id',
            $variant->getKey(),
            [
                'quotation_items',
                'job_order_items',
                'inventory_movements',
                'frame_ratings',
            ],
        );
    }

    private static function serviceIsReferenced(Service $service): bool
    {
        return self::existsInAnyTable(
            'service_id',
            $service->getKey(),
            ['quotation_items', 'billing_record_items'],
        ) || DB::table('visit_ratings')
            ->where(function (QueryBuilder $query) use ($service): void {
                $query
                    ->whereJsonContains('service_ids', $service->getKey())
                    ->orWhereJsonContains('service_ids', (string) $service->getKey());
            })
            ->exists();
    }

    /**
     * @param  array<int, string>  $tableNames
     */
    private static function existsInAnyTable(string $column, int|string $value, array $tableNames): bool
    {
        foreach ($tableNames as $tableName) {
            if (DB::table($tableName)->where($column, $value)->exists()) {
                return true;
            }
        }

        return false;
    }

    private static function authorize(): void
    {
        if (! auth()->user()?->isAdmin()) {
            throw new AuthorizationException('Only administrators can manage catalog lifecycle.');
        }
    }
}
