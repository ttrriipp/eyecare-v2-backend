<?php

namespace App\Filament\Resources\Quotations\Schemas;

use App\Filament\Resources\OpticalOrders\Schemas\OpticalOrderCreationForm;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

class QuotationCreationForm
{
    /**
     * @return array<int, Section>
     */
    public static function components(
        ?Closure $patientIdResolver = null,
        ?Closure $prescriptionEyewearResolver = null,
        bool $dedicatedPrescriptionEyewear = false,
    ): array {
        $patientIdResolver ??= fn (Get $get): ?int => filled($get('patient_id'))
            ? (int) $get('patient_id')
            : null;

        $prescriptionEyewearResolver ??= fn (Get $get): bool => collect(
            $get('items') ?? $get('../../items') ?? [],
        )->contains(fn (array $item): bool => ($item['item_kind'] ?? null) === 'lens');

        return [
            Section::make('Quotation Details')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        DatePicker::make('valid_until')
                            ->label('Valid Until')
                            ->native(false)
                            ->minDate(today())
                            ->suffixIcon('heroicon-o-calendar-days'),
                        TextInput::make('discount_amount')
                            ->label('Discount')
                            ->prefix('₱')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->default(0)
                            ->disabled(fn (): bool => auth()->user()?->isAdmin() !== true)
                            ->dehydrated()
                            ->live(onBlur: true),
                    ]),
                ]),

            ...($dedicatedPrescriptionEyewear
                ? [OpticalOrderCreationForm::prescriptionEyewearSection($prescriptionEyewearResolver)]
                : [self::guidedSummarySection($prescriptionEyewearResolver)]),

            OpticalOrderCreationForm::itemsSection(
                prescriptionEyewearResolver: $prescriptionEyewearResolver,
                dedicatedPrescriptionEyewear: $dedicatedPrescriptionEyewear,
                includeServices: true,
                excludeFramesFromOtherItems: $dedicatedPrescriptionEyewear,
            ),

            Section::make('Summary and Notes')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 3])->schema([
                        Placeholder::make('estimated_subtotal')
                            ->label('Subtotal')
                            ->content(fn (Get $get): string => '₱'.number_format(
                                OpticalOrderCreationForm::subtotal($get, $prescriptionEyewearResolver, $dedicatedPrescriptionEyewear),
                                2,
                            )),
                        Placeholder::make('estimated_discount')
                            ->label('Discount')
                            ->content(fn (Get $get): string => '₱'.number_format((float) ($get('discount_amount') ?? 0), 2)),
                        Placeholder::make('estimated_total')
                            ->label('Estimated Total')
                            ->content(fn (Get $get): string => '₱'.number_format(
                                max(
                                    OpticalOrderCreationForm::subtotal($get, $prescriptionEyewearResolver, $dedicatedPrescriptionEyewear)
                                        - (float) ($get('discount_amount') ?? 0),
                                    0,
                                ),
                                2,
                            )),
                    ]),
                    Textarea::make('notes')
                        ->label('Patient Notes')
                        ->maxLength(2000)
                        ->columnSpanFull(),
                ]),
        ];
    }

    private static function guidedSummarySection(Closure $prescriptionEyewearResolver): Section
    {
        return Section::make('Prescription Eyewear Build')
            ->schema([
                Grid::make(['default' => 1, 'md' => 3])->schema([
                    Placeholder::make('frame_selection')
                        ->label('Frame')
                        ->content(fn (Get $get): string => collect($get('items') ?? [])
                            ->contains(fn (array $item): bool => (
                                (($item['item_kind'] ?? null) === 'catalog'
                                    && ($item['catalog_product_type'] ?? null) === 'frame')
                                || ($prescriptionEyewearResolver($get)
                                    && ($item['item_kind'] ?? null) === 'custom_product')
                            ))
                            ? 'Selected ✓'
                            : 'Not selected'),
                    Placeholder::make('lens_package_selection')
                        ->label('Lens package')
                        ->content(fn (Get $get): string => collect($get('items') ?? [])
                            ->contains(fn (array $item): bool => ($item['item_kind'] ?? null) === 'lens')
                            ? 'Selected ✓'
                            : 'Not selected'),
                    Placeholder::make('lens_options_selection')
                        ->label('Lens options')
                        ->content(fn (Get $get): string => sprintf(
                            '%d selected',
                            collect($get('items') ?? [])->filter(
                                fn (array $item): bool => ($item['item_kind'] ?? null) === 'lens_option',
                            )->count(),
                        )),
                ]),
            ])
            ->visible(fn (Get $get): bool => $prescriptionEyewearResolver($get));
    }
}
