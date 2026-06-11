<?php

namespace App\Filament\Resources\Conversations\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->columns([
                TextColumn::make('sender.name')
                    ->label('Sender'),
                TextColumn::make('body')
                    ->label('Message')
                    ->wrap()
                    ->limit(120),
                TextColumn::make('attachments_count')
                    ->label('Attachments')
                    ->counts('attachments'),
                TextColumn::make('created_at')
                    ->label('Sent')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                Action::make('reply')
                    ->label('Reply')
                    ->schema([
                        Textarea::make('body')
                            ->label('Message')
                            ->required()
                            ->maxLength(5000)
                            ->rows(4),
                    ])
                    ->action(function (array $data): void {
                        $this->getOwnerRecord()->messages()->create([
                            'sender_id' => Auth::id(),
                            'body' => $data['body'],
                        ]);
                    })
                    ->successNotificationTitle('Reply sent'),
            ])
            ->defaultSort('created_at', 'asc');
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('sender.name')
                    ->label('Sender'),
                TextEntry::make('body')
                    ->label('Message')
                    ->columnSpanFull(),
                RepeatableEntry::make('attachments')
                    ->label('Attachments')
                    ->schema([
                        TextEntry::make('original_name')->label('File'),
                        TextEntry::make('mime_type')->label('Type'),
                        TextEntry::make('file_size')
                            ->label('Size')
                            ->formatStateUsing(fn (int $state): string => number_format($state / 1024, 1).' KB'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
