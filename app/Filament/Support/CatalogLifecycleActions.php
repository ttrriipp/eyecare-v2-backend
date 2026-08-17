<?php

namespace App\Filament\Support;

use App\Services\CatalogLifecycle;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class CatalogLifecycleActions
{
    /**
     * @return array<int, Action>
     */
    public static function recordActions(string $noun = 'catalog record'): array
    {
        return [
            self::activate(noun: $noun),
            self::deactivate(noun: $noun),
        ];
    }

    public static function statusFilter(): SelectFilter
    {
        return SelectFilter::make('catalog_status')
            ->label('Status')
            ->options([
                'active' => 'Active',
                'inactive' => 'Inactive',
                'all' => 'All',
            ])
            ->default('active')
            ->query(function (Builder $query, array $data): Builder {
                $status = $data['value'] ?? 'active';
                $deletedColumn = "{$query->getModel()->getTable()}.deleted_at";
                $hasSoftDeletes = method_exists($query->getModel(), 'trashed');

                return match ($status) {
                    'inactive' => $query->where(function (Builder $statusQuery) use ($hasSoftDeletes, $deletedColumn): void {
                        $statusQuery->where('is_active', false);

                        if ($hasSoftDeletes) {
                            $statusQuery->orWhereNotNull($deletedColumn);
                        }
                    }),
                    'all' => $query,
                    default => $query
                        ->where('is_active', true)
                        ->when($hasSoftDeletes, fn (Builder $activeQuery): Builder => $activeQuery->whereNull($deletedColumn)),
                };
            });
    }

    public static function bulkActions(): BulkActionGroup
    {
        return BulkActionGroup::make([
            self::deactivateBulk(),
            self::activateBulk(),
        ]);
    }

    public static function activate(?Closure $recordResolver = null, string $noun = 'catalog record'): Action
    {
        return Action::make('activate')
            ->label('Activate')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible($recordResolver
                ? fn (): bool => self::isAdmin() && self::isInactive($recordResolver())
                : fn (Model $record): bool => self::isAdmin() && self::isInactive($record))
            ->action($recordResolver
                ? function () use ($recordResolver): void {
                    CatalogLifecycle::activate($recordResolver());
                }
                : function (Model $record): void {
                    CatalogLifecycle::activate($record);
                })
            ->successNotificationTitle(ucfirst($noun).' activated');
    }

    public static function deactivate(?Closure $recordResolver = null, string $noun = 'catalog record'): Action
    {
        return Action::make('deactivate')
            ->label('Deactivate')
            ->icon('heroicon-o-eye-slash')
            ->color('warning')
            ->requiresConfirmation()
            ->visible($recordResolver
                ? fn (): bool => self::isAdmin() && ! self::isInactive($recordResolver())
                : fn (Model $record): bool => self::isAdmin() && ! self::isInactive($record))
            ->action($recordResolver
                ? function () use ($recordResolver): void {
                    CatalogLifecycle::deactivate($recordResolver());
                }
                : function (Model $record): void {
                    CatalogLifecycle::deactivate($record);
                })
            ->successNotificationTitle(ucfirst($noun).' deactivated');
    }

    private static function deactivateBulk(): BulkAction
    {
        return BulkAction::make('deactivate')
            ->label('Deactivate Selected')
            ->icon('heroicon-o-eye-slash')
            ->color('warning')
            ->requiresConfirmation()
            ->action(function (Collection $records): void {
                $records->each(function (Model $record): void {
                    CatalogLifecycle::deactivate($record);
                });
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function activateBulk(): BulkAction
    {
        return BulkAction::make('activate')
            ->label('Activate Selected')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->action(function (Collection $records): void {
                $records->each(function (Model $record): void {
                    CatalogLifecycle::activate($record);
                });
            })
            ->deselectRecordsAfterCompletion();
    }

    private static function isAdmin(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    private static function isInactive(Model $record): bool
    {
        return ! $record->is_active
            || (method_exists($record, 'trashed') && $record->trashed());
    }
}
