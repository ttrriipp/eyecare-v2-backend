<?php

namespace App\Filament\Resources\Prescriptions\Pages;

use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Models\Prescription;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ViewPrescription extends ViewRecord
{
    protected static string $resource = PrescriptionResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        $record = $this->getRecord();

        if (! $record->isCurrentVersion()) {
            return new HtmlString(
                '<span class="text-sm font-medium text-warning-600 dark:text-warning-400">⚠ Superseded — use the current version for printing and fulfillment.</span>'
            );
        }

        if (! $record->expires_at) {
            return null;
        }

        $daysUntilExpiry = (int) now()->diffInDays($record->expires_at, false);

        if ($daysUntilExpiry < 0) {
            return new HtmlString(
                '<span class="text-sm font-medium text-danger-600 dark:text-danger-400">⚠ Expired '.abs($daysUntilExpiry).' days ago</span>'
            );
        }

        if ($daysUntilExpiry <= 30) {
            return new HtmlString(
                '<span class="text-sm font-medium text-warning-600 dark:text-warning-400">⚠ Expires in '.$daysUntilExpiry.' day'.($daysUntilExpiry !== 1 ? 's' : '').'</span>'
            );
        }

        return null;
    }

    public function content(Schema $schema): Schema
    {
        $components = [
            $this->getFormContentComponent(),
        ];

        $previousPrescription = $this->getRecord()->previousPrescription;

        if ($previousPrescription !== null) {
            $components[] = Section::make('Previous Version ('.$previousPrescription->prescribed_at->format('M j, Y').')')
                ->description('This finalized record supersedes the version below.')
                ->collapsed()
                ->collapsible()
                ->schema([
                    Grid::make(6)->schema([
                        Placeholder::make('prev_od_sphere')->label('OD Sph')->content($previousPrescription->od_sphere ?? '—'),
                        Placeholder::make('prev_od_cylinder')->label('OD Cyl')->content($previousPrescription->od_cylinder ?? '—'),
                        Placeholder::make('prev_od_axis')->label('OD Axis')->content($previousPrescription->od_axis ?? '—'),
                        Placeholder::make('prev_os_sphere')->label('OS Sph')->content($previousPrescription->os_sphere ?? '—'),
                        Placeholder::make('prev_os_cylinder')->label('OS Cyl')->content($previousPrescription->os_cylinder ?? '—'),
                        Placeholder::make('prev_os_axis')->label('OS Axis')->content($previousPrescription->os_axis ?? '—'),
                        Placeholder::make('prev_od_add')->label('OD Add')->content($previousPrescription->od_add ?? '—'),
                        Placeholder::make('prev_os_add')->label('OS Add')->content($previousPrescription->os_add ?? '—'),
                        Placeholder::make('prev_pd')->label('PD')->content($previousPrescription->pd ?? '—'),
                        Placeholder::make('prev_notes')->label('Notes')->content($previousPrescription->notes ?? '—'),
                    ]),
                ]);
        }

        $components[] = $this->getRelationManagersContentComponent();

        return $schema->components($components);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('amendPrescription')
                ->label('Amend Prescription')
                ->icon('heroicon-o-document-plus')
                ->color('warning')
                ->visible(fn (): bool => auth()->user()?->can('amend', $this->getRecord()) === true
                    && ! Prescription::query()
                        ->withTrashed()
                        ->where('previous_prescription_id', $this->getRecord()->id)
                        ->exists())
                ->url(fn (): string => PrescriptionResource::getUrl('amend', [
                    'previous' => $this->getRecord()->id,
                ])),

            Action::make('viewCurrentPrescription')
                ->label('View Current Version')
                ->icon('heroicon-o-arrow-right-circle')
                ->color('warning')
                ->visible(fn (): bool => ! $this->getRecord()->isCurrentVersion())
                ->url(fn (): string => PrescriptionResource::getUrl('view', [
                    'record' => $this->getRecord()->currentVersion(),
                ])),

            Action::make('print_prescription')
                ->label('Print Prescription')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->isCurrentVersion())
                ->url(fn () => route('pdf.prescription', $this->getRecord()))
                ->openUrlInNewTab(),

            Action::make('print_card')
                ->label('Print Card')
                ->icon('heroicon-o-credit-card')
                ->color('gray')
                ->tooltip('Wallet-size prescription card')
                ->visible(fn (): bool => $this->getRecord()->isCurrentVersion())
                ->url(fn () => route('pdf.prescription.card', $this->getRecord()))
                ->openUrlInNewTab(),
        ];
    }
}
