<?php

namespace App\Filament\Resources\Prescriptions\Pages;

use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Models\Prescription;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class EditPrescription extends EditRecord
{
    protected static string $resource = PrescriptionResource::class;

    public bool $showPrismBase = false;

    public function getSubheading(): string|Htmlable|null
    {
        $record = $this->getRecord();

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

    protected function getFormSchema(): array
    {
        $parentSchema = parent::getFormSchema();

        $previous = $this->getPreviousPrescription();

        if (! $previous) {
            return $parentSchema;
        }

        $comparisonSection = Section::make('Previous Prescription ('.$previous->prescribed_at->format('M j, Y').')')
            ->description('Read-only reference from the patient\'s last visit.')
            ->collapsed()
            ->collapsible()
            ->schema([
                Grid::make(6)->schema([
                    Placeholder::make('prev_od_sphere')->label('OD Sph')->content($previous->od_sphere ?? '—'),
                    Placeholder::make('prev_od_cylinder')->label('OD Cyl')->content($previous->od_cylinder ?? '—'),
                    Placeholder::make('prev_od_axis')->label('OD Axis')->content($previous->od_axis ?? '—'),
                    Placeholder::make('prev_os_sphere')->label('OS Sph')->content($previous->os_sphere ?? '—'),
                    Placeholder::make('prev_os_cylinder')->label('OS Cyl')->content($previous->os_cylinder ?? '—'),
                    Placeholder::make('prev_os_axis')->label('OS Axis')->content($previous->os_axis ?? '—'),
                    Placeholder::make('prev_od_add')->label('OD Add')->content($previous->od_add ?? '—'),
                    Placeholder::make('prev_od_prism')->label('OD Prism')->content($previous->od_prism ?? '—'),
                    Placeholder::make('prev_od_base')->label('OD Base')->content($previous->od_base ?? '—'),
                    Placeholder::make('prev_os_add')->label('OS Add')->content($previous->os_add ?? '—'),
                    Placeholder::make('prev_os_prism')->label('OS Prism')->content($previous->os_prism ?? '—'),
                    Placeholder::make('prev_os_base')->label('OS Base')->content($previous->os_base ?? '—'),
                    Placeholder::make('prev_pd')->label('PD')->content($previous->pd ?? '—'),
                    Placeholder::make('prev_notes')->label('Notes')->content($previous->notes ?? '—'),
                ]),
            ]);

        return [...$parentSchema, $comparisonSection];
    }

    public function content(Schema $schema): Schema
    {
        $components = [
            $this->getFormContentComponent(),
        ];

        $previous = $this->getPreviousPrescription();

        if ($previous) {
            $components[] = Section::make('Previous Prescription ('.$previous->prescribed_at->format('M j, Y').')')
                ->description('Read-only reference from the patient\'s last visit.')
                ->collapsed()
                ->collapsible()
                ->schema([
                    Grid::make(6)->schema([
                        Placeholder::make('prev_od_sphere')->label('OD Sph')->content($previous->od_sphere ?? '—'),
                        Placeholder::make('prev_od_cylinder')->label('OD Cyl')->content($previous->od_cylinder ?? '—'),
                        Placeholder::make('prev_od_axis')->label('OD Axis')->content($previous->od_axis ?? '—'),
                        Placeholder::make('prev_os_sphere')->label('OS Sph')->content($previous->os_sphere ?? '—'),
                        Placeholder::make('prev_os_cylinder')->label('OS Cyl')->content($previous->os_cylinder ?? '—'),
                        Placeholder::make('prev_os_axis')->label('OS Axis')->content($previous->os_axis ?? '—'),
                        Placeholder::make('prev_od_add')->label('OD Add')->content($previous->od_add ?? '—'),
                        Placeholder::make('prev_od_prism')->label('OD Prism')->content($previous->od_prism ?? '—'),
                        Placeholder::make('prev_od_base')->label('OD Base')->content($previous->od_base ?? '—'),
                        Placeholder::make('prev_os_add')->label('OS Add')->content($previous->os_add ?? '—'),
                        Placeholder::make('prev_os_prism')->label('OS Prism')->content($previous->os_prism ?? '—'),
                        Placeholder::make('prev_os_base')->label('OS Base')->content($previous->os_base ?? '—'),
                        Placeholder::make('prev_pd')->label('PD')->content($previous->pd ?? '—'),
                        Placeholder::make('prev_notes')->label('Notes')->content($previous->notes ?? '—'),
                    ]),
                ]);
        }

        $components[] = $this->getRelationManagersContentComponent();

        return $schema->components($components);
    }

    private function getPreviousPrescription(): ?Prescription
    {
        $record = $this->getRecord();

        return Prescription::query()
            ->where('customer_id', $record->customer_id)
            ->where('id', '!=', $record->id)
            ->orderByDesc('prescribed_at')
            ->first();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print_prescription')
                ->label('Print Prescription')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->url(fn () => route('pdf.prescription', $this->getRecord()))
                ->openUrlInNewTab(),

            Action::make('print_card')
                ->label('Print Card')
                ->icon('heroicon-o-credit-card')
                ->color('gray')
                ->tooltip('Wallet-size prescription card')
                ->url(fn () => route('pdf.prescription.card', $this->getRecord()))
                ->openUrlInNewTab(),

            DeleteAction::make()->visible(fn () => auth()->user()?->isAdmin() ?? false),
        ];
    }
}
