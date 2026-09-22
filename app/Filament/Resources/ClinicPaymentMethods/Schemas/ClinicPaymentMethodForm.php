<?php

namespace App\Filament\Resources\ClinicPaymentMethods\Schemas;

use App\Enums\OrderPaymentMethod;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ClinicPaymentMethodForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(2)->schema([
                    Section::make('Payment account')
                        ->schema([
                            Select::make('method')
                                ->label('Method')
                                ->options(OrderPaymentMethod::options())
                                ->required()
                                ->native(false)
                                ->live()
                                ->disabledOn('edit')
                                ->afterStateUpdated(function (Set $set, ?string $state): void {
                                    $set('label', OrderPaymentMethod::tryFrom($state ?? '')?->label());
                                }),
                            TextInput::make('label')
                                ->label('Display label')
                                ->required()
                                ->maxLength(80),
                            TextInput::make('account_name')
                                ->label('Account name')
                                ->required()
                                ->maxLength(160),
                            TextInput::make('account_number')
                                ->label('Account number')
                                ->required()
                                ->maxLength(120),
                            TextInput::make('bank_name')
                                ->label('Bank name')
                                ->maxLength(120)
                                ->required(fn (Get $get): bool => $get('method') === OrderPaymentMethod::BankTransfer->value)
                                ->visible(fn (Get $get): bool => $get('method') === OrderPaymentMethod::BankTransfer->value),
                            Toggle::make('is_active')
                                ->label('Available for new orders')
                                ->helperText('Deactivate this method instead of deleting it so existing order snapshots remain auditable.')
                                ->default(true),
                        ])
                        ->columns(2),
                    Section::make('Payment QR code')
                        ->description('Optional. Patients can use the account details if no QR code is provided.')
                        ->schema([
                            FileUpload::make('qr_image_path')
                                ->label('QR image')
                                ->disk((string) config('filesystems.payment_instructions_disk', 'payment_instructions'))
                                ->directory('payment-methods')
                                ->visibility('private')
                                ->image()
                                ->imageEditor()
                                ->preventFilePathTampering()
                                ->maxSize(2048)
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                ->helperText('JPG, PNG, or WebP up to 2 MB.'),
                        ]),
                ]),
            ]);
    }
}
