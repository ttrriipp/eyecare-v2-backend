<?php

namespace App\Filament\Resources\Conversations;

use App\Filament\Resources\Conversations\Pages\ConversationChatPage;
use App\Models\Conversation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ConversationResource extends Resource
{
    protected static ?string $model = Conversation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Messages';

    protected static ?string $pluralModelLabel = 'Messages';

    protected static ?int $navigationSort = 40;

    protected static string|UnitEnum|null $navigationGroup = 'Patients';

    public static function getNavigationBadge(): ?string
    {
        $count = Conversation::query()
            ->whereNull('inbox_archived_at')
            ->whereNotNull('account_user_id')
            ->whereHas('messages', function (Builder $query): void {
                $query->whereColumn('sender_id', '=', 'conversations.account_user_id')
                    ->where(function (Builder $query): void {
                        $query->whereNull('conversations.staff_last_read_at')
                            ->orWhereColumn('messages.created_at', '>', 'conversations.staff_last_read_at');
                    });
            })
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ConversationChatPage::route('/'),
        ];
    }
}
