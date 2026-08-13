<?php

namespace App\Filament\Support;

use App\Enums\BillingRecordStatus;
use App\Enums\JobOrderStatus;
use App\Enums\QuotationStatus;
use App\Filament\Resources\BillingRecords\BillingRecordResource;
use App\Filament\Resources\OpticalOrders\OpticalOrderResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\BillingRecord;
use App\Models\JobOrder;
use App\Models\Quotation;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Str;

final class WorkflowRail
{
    public static function forQuotation(): Section
    {
        return self::make(
            quotation: [
                'content' => fn (Quotation $record): string => self::stageContent(
                    $record->quotation_number,
                    self::quotationStatus($record),
                ),
                'url' => fn (Quotation $record): string => QuotationResource::getUrl('edit', ['record' => $record]),
            ],
            order: [
                'content' => fn (Quotation $record): string => self::stageContent(
                    $record->jobOrder?->job_order_number,
                    self::jobOrderStatus($record->jobOrder),
                ),
                'url' => fn (Quotation $record): ?string => $record->jobOrder
                    ? OpticalOrderResource::getUrl('edit', ['record' => $record->jobOrder])
                    : null,
            ],
            billing: [
                'content' => fn (Quotation $record): string => self::stageContent(
                    $record->billingRecord?->billing_record_number,
                    self::billingStatus($record->billingRecord),
                ),
                'url' => fn (Quotation $record): ?string => $record->billingRecord
                    ? BillingRecordResource::getUrl('edit', ['record' => $record->billingRecord])
                    : null,
            ],
        );
    }

    public static function forOpticalOrder(): Section
    {
        return self::make(
            quotation: [
                'content' => fn (JobOrder $record): string => $record->quotation === null
                    ? 'No source quotation'
                    : self::stageContent(
                        $record->quotation->quotation_number,
                        self::quotationStatus($record->quotation),
                    ),
                'url' => fn (JobOrder $record): ?string => $record->quotation
                    ? QuotationResource::getUrl('edit', ['record' => $record->quotation])
                    : null,
            ],
            order: [
                'content' => fn (JobOrder $record): string => self::stageContent(
                    $record->job_order_number,
                    self::jobOrderStatus($record),
                ),
                'url' => fn (JobOrder $record): string => OpticalOrderResource::getUrl('edit', ['record' => $record]),
            ],
            billing: [
                'content' => fn (JobOrder $record): string => self::stageContent(
                    $record->billingRecord?->billing_record_number,
                    self::billingStatus($record->billingRecord),
                ),
                'url' => fn (JobOrder $record): ?string => $record->activeBillingRecord
                    ? BillingRecordResource::getUrl('edit', ['record' => $record->activeBillingRecord])
                    : ($record->billingRecord
                        ? BillingRecordResource::getUrl('edit', ['record' => $record->billingRecord])
                        : null),
            ],
        );
    }

    public static function forBilling(): Section
    {
        return self::make(
            quotation: [
                'content' => fn (BillingRecord $record): string => $record->quotation === null
                    ? 'No source quotation'
                    : self::stageContent(
                        $record->quotation->quotation_number,
                        self::quotationStatus($record->quotation),
                    ),
                'url' => fn (BillingRecord $record): ?string => $record->quotation
                    ? QuotationResource::getUrl('edit', ['record' => $record->quotation])
                    : null,
            ],
            order: [
                'content' => fn (BillingRecord $record): string => $record->jobOrder === null
                    ? 'No optical order'
                    : self::stageContent(
                        $record->jobOrder->job_order_number,
                        self::jobOrderStatus($record->jobOrder),
                    ),
                'url' => fn (BillingRecord $record): ?string => $record->jobOrder
                    ? OpticalOrderResource::getUrl('edit', ['record' => $record->jobOrder])
                    : null,
            ],
            billing: [
                'content' => fn (BillingRecord $record): string => self::stageContent(
                    $record->billing_record_number,
                    self::billingStatus($record),
                ),
                'url' => fn (BillingRecord $record): string => BillingRecordResource::getUrl('edit', ['record' => $record]),
            ],
        );
    }

    /**
     * @param  array{content: callable, url: callable}  $quotation
     * @param  array{content: callable, url: callable}  $order
     * @param  array{content: callable, url: callable}  $billing
     */
    private static function make(array $quotation, array $order, array $billing): Section
    {
        return Section::make('Sale Workflow')
            ->schema([
                Grid::make(3)->schema([
                    Placeholder::make('workflow_quotation')
                        ->label('Quotation')
                        ->content($quotation['content'])
                        ->url($quotation['url']),
                    Placeholder::make('workflow_order')
                        ->label('Optical Order')
                        ->content($order['content'])
                        ->url($order['url']),
                    Placeholder::make('workflow_billing')
                        ->label('Billing Record')
                        ->content($billing['content'])
                        ->url($billing['url']),
                ]),
            ])
            ->columnSpanFull();
    }

    private static function stageContent(?string $number, ?string $status): string
    {
        if ($number === null) {
            return 'Created after confirmation';
        }

        return $status === null ? $number : "{$number} [{$status}]";
    }

    private static function quotationStatus(?Quotation $quotation): ?string
    {
        return $quotation?->status instanceof QuotationStatus
            ? Str::headline($quotation->status->value)
            : null;
    }

    private static function jobOrderStatus(?JobOrder $order): ?string
    {
        if ($order?->status === null) {
            return null;
        }

        return match ($order->status) {
            JobOrderStatus::Queued => 'Confirmed',
            JobOrderStatus::InProgress => 'Processing',
            JobOrderStatus::ReadyForDispensing => 'Ready',
            JobOrderStatus::Dispensed => 'Dispensed',
            JobOrderStatus::Cancelled => 'Cancelled',
        };
    }

    private static function billingStatus(?BillingRecord $billingRecord): ?string
    {
        return $billingRecord?->status instanceof BillingRecordStatus
            ? $billingRecord->status->getLabel()
            : null;
    }
}
