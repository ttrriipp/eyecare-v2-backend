<?php

namespace App\Filament\Resources\SmsNotifications;

use App\Filament\Resources\SmsNotifications\Pages\ListSmsNotifications;
use App\Filament\Resources\SmsNotifications\Tables\SmsNotificationsTable;
use App\Models\SmsNotification;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SmsNotificationResource extends Resource
{
    protected static ?string $model = SmsNotification::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static ?string $navigationLabel = 'SMS Log';

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 20;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = SmsNotification::query()
            ->whereHas('status', fn (Builder $query): Builder => $query->where('name', 'failed'))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return SmsNotificationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmsNotifications::route('/'),
        ];
    }
}
