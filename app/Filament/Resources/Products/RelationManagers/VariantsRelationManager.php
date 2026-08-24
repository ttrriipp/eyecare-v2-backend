<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Actions\ArAssets\DisableArAsset;
use App\Actions\ArAssets\PublishArAssetCandidate;
use App\Actions\ArAssets\RollbackArAsset;
use App\Enums\ArAssetStatus;
use App\Filament\Support\CatalogLifecycleActions;
use App\Filament\Support\StockActions;
use App\Models\ArAsset;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ArAssets\ArCalibration;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class VariantsRelationManager extends RelationManager
{
    use RestrictsFileUploadsToSchemaComponents;

    protected static string $relationship = 'variants';

    public function form(Schema $schema): Schema
    {
        $productType = $this->getOwnerRecord()->product_type;

        $components = [
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('sku')
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->placeholder('Auto-generated if blank'),
            TextInput::make('price')
                ->required()
                ->numeric()
                ->prefix('₱'),
            TextInput::make('compare_at_price')
                ->label('Compare at Price')
                ->numeric()
                ->prefix('₱'),
            TextInput::make('cost_price')
                ->label('Cost Price')
                ->numeric()
                ->prefix('₱'),
            TextInput::make('stock_quantity')
                ->required()
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->disabled()
                ->dehydrated(false),
            TextInput::make('low_stock_threshold')
                ->required()
                ->numeric()
                ->minValue(0)
                ->default(0),
            TextInput::make('target_stock_level')
                ->label('Target Stock Level')
                ->nullable()
                ->integer()
                ->minValue(0)
                ->gte('low_stock_threshold')
                ->default(null),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true),
        ];

        if ($productType === 'frame') {
            $components[] = Section::make('Frame Dimensions')
                ->schema([
                    TextInput::make('attributes.bridge')
                        ->label('Bridge (mm)')
                        ->numeric()
                        ->minValue(10)
                        ->maxValue(30),
                    TextInput::make('attributes.temple')
                        ->label('Temple (mm)')
                        ->numeric()
                        ->minValue(100)
                        ->maxValue(160),
                    TextInput::make('attributes.lens_width')
                        ->label('Lens Width (mm)')
                        ->numeric()
                        ->minValue(30)
                        ->maxValue(70),
                    TextInput::make('attributes.lens_height')
                        ->label('Lens Height (mm)')
                        ->numeric()
                        ->minValue(20)
                        ->maxValue(60),
                    TextInput::make('attributes.color')
                        ->label('Color')
                        ->maxLength(50),
                    TextInput::make('attributes.material')
                        ->label('Material')
                        ->maxLength(50),
                ])
                ->columns(3)
                ->columnSpanFull();
        } elseif ($productType === 'contact_lens') {
            $components[] = Section::make('Contact Lens Parameters')
                ->schema([
                    TextInput::make('attributes.power')
                        ->label('Power')
                        ->maxLength(20),
                    TextInput::make('attributes.base_curve')
                        ->label('Base Curve')
                        ->numeric()
                        ->minValue(7)
                        ->maxValue(12),
                    TextInput::make('attributes.diameter')
                        ->label('Diameter (mm)')
                        ->numeric()
                        ->minValue(10)
                        ->maxValue(20),
                    TextInput::make('attributes.cylinder')
                        ->label('Cylinder')
                        ->maxLength(20),
                    TextInput::make('attributes.axis')
                        ->label('Axis')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(180),
                    TextInput::make('attributes.add')
                        ->label('Add')
                        ->maxLength(20),
                    TextInput::make('attributes.color')
                        ->label('Color')
                        ->maxLength(50),
                    TextInput::make('attributes.pack_size')
                        ->label('Pack Size')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(999),
                ])
                ->columns(4)
                ->columnSpanFull();
        } else {
            $components[] = KeyValue::make('attributes')
                ->label('Attributes')
                ->columnSpanFull();
        }

        $components[] = FileUpload::make('images')
            ->disk('public')
            ->directory('variants')
            ->visibility('public')
            ->image()
            ->imageEditor()
            ->multiple()
            ->reorderable()
            ->appendFiles()
            ->maxSize(5120)
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->columnSpanFull();

        return $schema->components($components);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withoutGlobalScopes([SoftDeletingScope::class])
                ->with(['latestArAsset', 'publishedArAsset']))
            ->columns([
                ImageColumn::make('images')
                    ->label('Image')
                    ->state(fn ($record): ?string => collect($record->images)->first())
                    ->disk('public')
                    ->square()
                    ->size(40),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('sku')
                    ->searchable(),
                TextColumn::make('price')
                    ->money('PHP')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                TextColumn::make('ar_status')
                    ->label('3D status')
                    ->state(fn (ProductVariant $record): string => $this->arStatusLabel($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Published' => 'success',
                        'Awaiting physical approval' => 'warning',
                        'Ready to publish' => 'success',
                        'Upload received' => 'info',
                        'Validation failed', 'Rejected', 'Disabled' => 'danger',
                        default => 'gray',
                    })
                    ->visible(fn (): bool => $this->getOwnerRecord()->product_type === 'frame'),
                TextColumn::make('ar_current_version')
                    ->label('3D version')
                    ->state(fn (ProductVariant $record): ?string => $this->publishedArVersionLabel($record))
                    ->badge()
                    ->color(fn (?string $state): string => filled($state) ? 'success' : 'gray')
                    ->placeholder('—')
                    ->tooltip(fn (ProductVariant $record): ?string => $record->publishedArAsset?->url)
                    ->visible(fn (): bool => $this->getOwnerRecord()->product_type === 'frame'),
                TextColumn::make('ar_validation_error')
                    ->label('3D validation note')
                    ->state(fn (ProductVariant $record): ?string => $record->latestArAsset?->validation_error)
                    ->placeholder('—')
                    ->limit(120)
                    ->wrap()
                    ->tooltip(fn (ProductVariant $record): ?string => $record->latestArAsset?->validation_error)
                    ->visible(fn (): bool => $this->getOwnerRecord()->product_type === 'frame'),
                TextColumn::make('stock_quantity')
                    ->label('Qty')
                    ->sortable(),
                TextColumn::make('frame_dimensions')
                    ->label('Dimensions')
                    ->state(function ($record): ?string {
                        if ($record->product?->product_type !== 'frame') {
                            return null;
                        }

                        $attrs = $record->attributes ?? [];
                        $parts = [];

                        if (filled($attrs['lens_width'] ?? null)) {
                            $parts[] = "{$attrs['lens_width']}mm";
                        }
                        if (filled($attrs['bridge'] ?? null)) {
                            $parts[] = "{$attrs['bridge']} bridge";
                        }
                        if (filled($attrs['temple'] ?? null)) {
                            $parts[] = "{$attrs['temple']} temple";
                        }
                        if (filled($attrs['color'] ?? null)) {
                            $parts[] = $attrs['color'];
                        }
                        if (filled($attrs['material'] ?? null)) {
                            $parts[] = $attrs['material'];
                        }

                        return filled($parts) ? implode(' / ', $parts) : '—';
                    })
                    ->wrap()
                    ->limit(100)
                    ->visible(fn (): bool => $this->getOwnerRecord()->product_type === 'frame'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->filters([
                CatalogLifecycleActions::statusFilter(),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->manageArAssetAction(),
                    $this->viewArAssetHistoryAction(),
                    $this->disableArAssetAction(),
                    $this->rollbackArAssetAction(),
                    EditAction::make()
                        ->color('info'),
                    ...CatalogLifecycleActions::recordActions('variant'),
                    Action::make('adjustPrice')
                        ->label('Adjust Price')
                        ->icon('heroicon-o-currency-dollar')
                        ->color('warning')
                        ->schema([
                            TextInput::make('price')
                                ->label('Selling Price')
                                ->required()
                                ->numeric()
                                ->prefix('₱'),
                            TextInput::make('compare_at_price')
                                ->label('Compare at Price')
                                ->numeric()
                                ->prefix('₱')
                                ->helperText('Original price shown crossed out (sale indicator).'),
                            TextInput::make('cost_price')
                                ->label('Cost Price')
                                ->numeric()
                                ->prefix('₱')
                                ->helperText('Internal only — not shown to customers.'),
                        ])
                        ->fillForm(fn ($record): array => [
                            'price' => $record->price,
                            'compare_at_price' => $record->compare_at_price,
                            'cost_price' => $record->cost_price,
                        ])
                        ->action(fn (array $data, $record) => $record->update([
                            'price' => $data['price'],
                            'compare_at_price' => $data['compare_at_price'],
                            'cost_price' => $data['cost_price'],
                        ]))
                        ->successNotificationTitle('Prices updated'),
                    StockActions::receive(),
                    StockActions::writeOffDamaged(),
                ]),
            ])
            ->toolbarActions([
                CatalogLifecycleActions::bulkActions(),
            ]);
    }

    private function canManageAr(): bool
    {
        $user = auth()->user();

        return $this->getOwnerRecord()->product_type === 'frame'
            && $user instanceof User
            && $user->is_active
            && ($user->isAdmin() || $user->isStaff());
    }

    private function viewArAssetHistoryAction(): Action
    {
        return Action::make('viewArAssetHistory')
            ->label('3D version history')
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->modalHeading('3D model version history')
            ->modalContent(function (ProductVariant $record) {
                $assets = $record->arAssets()
                    ->with(['uploadedBy', 'approvedBy', 'publishedBy', 'disabledBy'])
                    ->latest('version')
                    ->get();

                return view('filament.products.variant-ar-history', [
                    'assets' => $assets,
                    'currentAssetId' => $record->published_ar_asset_id,
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->visible(fn (ProductVariant $record): bool => $this->canManageAr()
                && $record->latestArAsset !== null);
    }

    private function manageArAssetAction(): Action
    {
        return Action::make('manageArAsset')
            ->label('Manage 3D model')
            ->icon('heroicon-o-cube')
            ->modalIcon('heroicon-o-cube')
            ->modalWidth('4xl')
            ->modalHeading(fn (ProductVariant $record): string => $this->arManagementHeading($record))
            ->modalDescription(fn (ProductVariant $record): string => $this->arManagementDescription($record))
            ->modalSubmitActionLabel('Validate & publish')
            ->modalSubmitAction(fn (ProductVariant $record, Action $action): Action|bool => $this->hasMultipleActionableArAssets($record) || $this->hasLockedCalibrationGap($record) ? false : $action)
            ->schema(fn (ProductVariant $record): array => $this->arManagementSchema($record))
            ->fillForm(fn (ProductVariant $record): array => $this->arManagementForm($record))
            ->visible(fn (): bool => $this->canManageAr())
            ->action(function (array $data, ProductVariant $record): void {
                $actor = auth()->user();

                if (! $actor instanceof User) {
                    throw new AuthorizationException('Only active staff or administrators may manage AR assets.');
                }

                try {
                    app(PublishArAssetCandidate::class)->handle(
                        variant: $record,
                        file: ($data['file'] ?? null) instanceof UploadedFile ? $data['file'] : null,
                        calibration: $this->calibrationFromData($data),
                        physicalMatchConfirmed: $this->rawPhysicalMatchAttestation(),
                        actor: $actor,
                        measuredRenderedWidthMm: $this->rawMeasuredRenderedWidth(),
                    );
                } catch (ValidationException $exception) {
                    $message = collect($exception->errors())->flatten()->first();

                    Notification::make()
                        ->title('3D model was not published')
                        ->body(is_string($message) && $message !== '' ? $message : 'Correct the highlighted fields and try again.')
                        ->danger()
                        ->send();

                    throw $exception;
                }
            })
            ->successNotificationTitle('3D model published to the patient catalog');
    }

    private function disableArAssetAction(): Action
    {
        return Action::make('disableArAsset')
            ->label('Disable 3D model')
            ->icon('heroicon-o-eye-slash')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (ProductVariant $record): bool => $this->canManageAr()
                && $record->publishedArAsset !== null)
            ->action(function (ProductVariant $record): void {
                $asset = $record->publishedArAsset;

                if ($asset === null) {
                    throw ValidationException::withMessages(['asset' => 'This variant has no published 3D model.']);
                }

                /** @var User $actor */
                $actor = auth()->user();
                app(DisableArAsset::class)->handle($asset, $actor);
            })
            ->successNotificationTitle('3D model disabled; frame images remain available');
    }

    private function rollbackArAssetAction(): Action
    {
        return Action::make('rollbackArAsset')
            ->label('Rollback 3D model')
            ->icon('heroicon-o-arrow-uturn-left')
            ->schema([
                Select::make('target_asset_id')
                    ->label('Version to restore')
                    ->options(fn (ProductVariant $record): array => $this->rollbackOptionsFor($record))
                    ->required()
                    ->searchable()
                    ->helperText('Choose the exact previously published version to make active again.'),
            ])
            ->requiresConfirmation()
            ->modalHeading('Restore a previous 3D model')
            ->modalDescription('The selected immutable version will become active for patients. The current version will remain stored for future rollback.')
            ->modalSubmitActionLabel('Restore selected version')
            ->visible(fn (ProductVariant $record): bool => $this->canManageAr()
                && $this->rollbackableAssetsQuery($record)->exists())
            ->action(function (array $data, ProductVariant $record): void {
                $targetAssetId = $data['target_asset_id'] ?? null;
                $asset = is_numeric($targetAssetId)
                    ? $this->rollbackableAssetsQuery($record)
                        ->whereKey((int) $targetAssetId)
                        ->first()
                    : null;

                if ($asset === null) {
                    throw ValidationException::withMessages(['target_asset_id' => 'Choose a valid previous 3D model version to restore.']);
                }

                /** @var User $actor */
                $actor = auth()->user();
                app(RollbackArAsset::class)->handle($asset, $actor);
            })
            ->successNotificationTitle('Selected 3D model version restored');
    }

    /**
     * @return array<int, Component>
     */
    private function arManagementSchema(ProductVariant $record): array
    {
        if ($this->hasMultipleActionableArAssets($record)) {
            return [
                Section::make('Resolve pending models')
                    ->description('Multiple pending 3D model candidates were found for this variant. Resolve the duplicate versions before publishing another model.')
                    ->schema([
                        Placeholder::make('workflow_blocked')
                            ->label('Publication blocked')
                            ->content('No candidate was selected automatically. Use version history or administrator support to resolve the pending candidates, then try again.'),
                    ])
                    ->columnSpanFull(),
            ];
        }

        $asset = $this->actionableArAsset($record);

        if ($this->hasLockedCalibrationGap($record)) {
            return [
                Section::make('Calibration requires resolution')
                    ->description('This validated candidate has no usable saved calibration. It cannot be edited after validation.')
                    ->schema([
                        Placeholder::make('calibration_blocked')
                            ->label('Publication blocked')
                            ->content('Resolve this candidate through the administrator workflow before publishing it.'),
                    ])
                    ->columnSpanFull(),
            ];
        }

        $isNewUpload = $asset === null;
        $isCalibrationEditable = $asset === null || $asset->status === ArAssetStatus::Quarantined;

        return [
            Section::make('Model file')
                ->description($isNewUpload
                    ? 'Upload the GLB model that matches this frame. The file is kept private until publication succeeds.'
                    : "A pending version (v{$asset->version}) is already in this workflow. Finish it before starting another upload.")
                ->schema([
                    FileUpload::make('file')
                        ->label('GLB model')
                        ->storeFiles(false)
                        ->acceptedFileTypes(['.glb', 'model/gltf-binary', 'application/octet-stream'])
                        ->mimeTypeMap(['glb' => 'model/gltf-binary'])
                        ->extraInputAttributes([
                            'accept' => '.glb,model/gltf-binary,application/octet-stream',
                        ])
                        ->maxSize(10240)
                        ->rules(['file', 'extensions:glb'])
                        ->required($isNewUpload)
                        ->visible($isNewUpload),
                    Placeholder::make('pending_model')
                        ->label('Workflow state')
                        ->content($isNewUpload
                            ? 'No pending model. Upload a GLB to begin.'
                            : $this->arWorkflowStateDescription($asset))
                        ->visible(! $isNewUpload),
                ])
                ->columnSpanFull(),
            $this->calibrationSection(readOnly: ! $isCalibrationEditable),
            Checkbox::make('physical_match_confirmed')
                ->label('I compared this GLB with the physical frame and confirm that it represents this catalog variant, including its silhouette, bridge, material, color, and proportions.')
                ->accepted()
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function arManagementForm(ProductVariant $record): array
    {
        $asset = $this->actionableArAsset($record);
        $calibration = $this->calibrationFormFor($asset);

        if ($calibration === [] && ($asset === null || $asset->status === ArAssetStatus::Quarantined)) {
            $attributes = is_array($record->attributes) ? $record->attributes : [];
            $calibration = [
                'lens_width_mm' => $attributes['lens_width'] ?? null,
                'lens_height_mm' => $attributes['lens_height'] ?? null,
                'bridge_width_mm' => $attributes['bridge'] ?? null,
                'temple_length_mm' => $attributes['temple'] ?? null,
            ];
        }

        return [
            ...$calibration,
            'measured_rendered_width_mm' => null,
            'physical_match_confirmed' => false,
        ];
    }

    private function arManagementHeading(ProductVariant $record): string
    {
        if ($this->hasMultipleActionableArAssets($record)) {
            return 'Resolve pending 3D models';
        }

        if ($this->hasLockedCalibrationGap($record)) {
            return 'Resolve invalid 3D model calibration';
        }

        return match ($this->actionableArAsset($record)?->status) {
            ArAssetStatus::Quarantined => 'Finish and publish 3D model',
            ArAssetStatus::Validated => 'Approve and publish 3D model',
            ArAssetStatus::Approved => 'Finish publishing approved 3D model',
            default => 'Upload and publish 3D model',
        };
    }

    private function arManagementDescription(ProductVariant $record): string
    {
        if ($this->hasMultipleActionableArAssets($record)) {
            return 'Publication is blocked until the duplicate pending candidates are resolved.';
        }

        if ($this->hasLockedCalibrationGap($record)) {
            return 'Publication is blocked because this validated candidate has no usable saved calibration.';
        }

        return match ($this->actionableArAsset($record)?->status) {
            ArAssetStatus::Quarantined => 'The GLB is received privately. Confirm the physical calibration and publish it when the model matches the frame.',
            ArAssetStatus::Validated => 'The model passed file validation. Confirm the physical match, then approve and publish it in one step.',
            ArAssetStatus::Approved => 'The model is approved and remains private until this action publishes it to the patient catalog.',
            default => 'Upload one GLB, record the physical calibration, attest the match, and publish it in one controlled action.',
        };
    }

    private function arWorkflowStateDescription(ArAsset $asset): string
    {
        return match ($asset->status) {
            ArAssetStatus::Quarantined => "v{$asset->version} is received and awaiting calibration.",
            ArAssetStatus::Validated => "v{$asset->version} passed file validation and is awaiting physical approval.",
            ArAssetStatus::Approved => "v{$asset->version} is physically approved and ready to publish.",
            default => "v{$asset->version} is pending publication.",
        };
    }

    private function rawPhysicalMatchAttestation(): mixed
    {
        $schema = $this->getMountedActionSchema();
        $state = $schema?->getRawState();

        return is_array($state) ? ($state['physical_match_confirmed'] ?? null) : null;
    }

    private function rawMeasuredRenderedWidth(): mixed
    {
        $schema = $this->getMountedActionSchema();
        $state = $schema?->getRawState();

        return is_array($state) ? ($state['measured_rendered_width_mm'] ?? null) : null;
    }

    private function calibrationSection(bool $readOnly = false): Section
    {
        $preset = (array) config('ar.presets.round_frame.calibration', []);

        return Section::make('Physical dimensions and placement')
            ->description($readOnly
                ? 'Locked after validation. Reopen the workflow only to publish the approved model.'
                : 'Required before the model can be approved and published. Use the preset only when it matches this model and coordinate system.')
            ->schema([
                Select::make('calibration_preset')
                    ->label('Reviewed preset')
                    ->options([
                        'round_frame' => (string) config('ar.presets.round_frame.label', 'Current round-frame preset'),
                    ])
                    ->placeholder('Choose only when this model matches the preset')
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state) use ($preset): void {
                        if ($state !== 'round_frame') {
                            return;
                        }

                        $set('frame_width_mm', $preset['frame_width_mm'] ?? null);
                        $set('outer_frame_height_mm', $preset['outer_frame_height_mm'] ?? null);
                        $set('lens_width_mm', $preset['lens_width_mm'] ?? null);
                        $set('lens_height_mm', $preset['lens_height_mm'] ?? null);
                        $set('bridge_width_mm', $preset['bridge_width_mm'] ?? null);
                        $set('temple_length_mm', $preset['temple_length_mm'] ?? null);
                        $set('scale_x', $preset['scale']['x'] ?? null);
                        $set('scale_y', $preset['scale']['y'] ?? null);
                        $set('scale_z', $preset['scale']['z'] ?? null);
                        $set('anchor_x', $preset['anchor']['x'] ?? null);
                        $set('anchor_y', $preset['anchor']['y'] ?? null);
                        $set('anchor_z', $preset['anchor']['z'] ?? null);
                        $set('rotation_x', $preset['rotation_degrees']['x'] ?? null);
                        $set('rotation_y', $preset['rotation_degrees']['y'] ?? null);
                        $set('rotation_z', $preset['rotation_degrees']['z'] ?? null);
                    })
                    ->disabled($readOnly)
                    ->dehydrated(false),
                Grid::make(3)->schema([
                    TextInput::make('frame_width_mm')->label('Frame width (mm)')->numeric()->required(! $readOnly)->disabled($readOnly),
                    TextInput::make('outer_frame_height_mm')->label('Outer height (mm)')->numeric()->required(! $readOnly)->disabled($readOnly),
                    TextInput::make('lens_width_mm')->label('Lens width (mm)')->numeric()->required(! $readOnly)->disabled($readOnly),
                    TextInput::make('lens_height_mm')->label('Lens height (mm)')->numeric()->required(! $readOnly)->disabled($readOnly),
                    TextInput::make('bridge_width_mm')->label('Bridge width (mm)')->numeric()->required(! $readOnly)->disabled($readOnly),
                    TextInput::make('temple_length_mm')->label('Temple length (mm)')->numeric()->required(! $readOnly)->disabled($readOnly),
                    TextInput::make('scale_x')->label('Scale X')->numeric()->required(! $readOnly)->disabled($readOnly),
                    TextInput::make('scale_y')->label('Scale Y')->numeric()->required(! $readOnly)->disabled($readOnly),
                    TextInput::make('scale_z')->label('Scale Z')->numeric()->required(! $readOnly)->disabled($readOnly),
                    TextInput::make('anchor_x')->label('Anchor X')->numeric()->required(! $readOnly)->disabled($readOnly),
                    TextInput::make('anchor_y')->label('Anchor Y')->numeric()->required(! $readOnly)->disabled($readOnly),
                    TextInput::make('anchor_z')->label('Anchor Z')->numeric()->required(! $readOnly)->disabled($readOnly),
                    TextInput::make('rotation_x')->label('Rotation X°')->numeric()->required(! $readOnly)->disabled($readOnly),
                    TextInput::make('rotation_y')->label('Rotation Y°')->numeric()->required(! $readOnly)->disabled($readOnly),
                    TextInput::make('rotation_z')->label('Rotation Z°')->numeric()->required(! $readOnly)->disabled($readOnly),
                ]),
                TextInput::make('measured_rendered_width_mm')
                    ->label('Measured rendered width (mm)')
                    ->numeric()
                    ->minValue(0.000001)
                    ->nullable()
                    ->visible(! $readOnly)
                    ->helperText('Full transformed frame width at the current scale.'),
            ])
            ->columnSpanFull();
    }

    private function arStatusLabel(ProductVariant $record): string
    {
        $asset = $record->latestArAsset;

        if ($asset === null) {
            return '—';
        }

        return match ($asset->status) {
            ArAssetStatus::Quarantined => 'Upload received',
            ArAssetStatus::Validated => 'Awaiting physical approval',
            ArAssetStatus::Approved => 'Ready to publish',
            ArAssetStatus::Published => 'Published',
            ArAssetStatus::Rejected => filled($asset->validation_error) ? 'Validation failed' : 'Rejected',
            ArAssetStatus::Disabled => 'Disabled',
            ArAssetStatus::Superseded => $record->publishedArAsset !== null ? 'Published' : '—',
        };
    }

    private function publishedArVersionLabel(ProductVariant $record): ?string
    {
        $version = $record->publishedArAsset?->version;

        return $version === null ? null : "v{$version}";
    }

    /**
     * @return Builder<ArAsset>
     */
    private function rollbackableAssetsQuery(ProductVariant $record): Builder
    {
        return $record->arAssets()
            ->getQuery()
            ->whereIn('status', [ArAssetStatus::Disabled->value, ArAssetStatus::Superseded->value])
            ->whereNotNull('published_path');
    }

    /**
     * @return array<string, string>
     */
    private function rollbackOptionsFor(ProductVariant $record): array
    {
        return $this->rollbackableAssetsQuery($record)
            ->latest('version')
            ->get()
            ->mapWithKeys(function (ArAsset $asset): array {
                $status = $asset->status === ArAssetStatus::Disabled ? 'Disabled' : 'Superseded';
                $publishedAt = $asset->published_at?->format('M j, Y');
                $label = "v{$asset->version} · {$status}";

                if ($publishedAt !== null) {
                    $label .= " · published {$publishedAt}";
                }

                return [(string) $asset->getKey() => $label];
            })
            ->all();
    }

    private function actionableArAsset(ProductVariant $record): ?ArAsset
    {
        return $this->actionableArAssets($record)->first();
    }

    /**
     * @return Collection<int, ArAsset>
     */
    private function actionableArAssets(ProductVariant $record): Collection
    {
        return $record->arAssets()
            ->whereIn('status', [
                ArAssetStatus::Quarantined->value,
                ArAssetStatus::Validated->value,
                ArAssetStatus::Approved->value,
            ])
            ->latest('version')
            ->limit(2)
            ->get();
    }

    private function hasMultipleActionableArAssets(ProductVariant $record): bool
    {
        return $this->actionableArAssets($record)->count() > 1;
    }

    private function hasLockedCalibrationGap(ProductVariant $record): bool
    {
        $asset = $this->actionableArAsset($record);

        if ($asset === null
            || ! in_array($asset->status, [ArAssetStatus::Validated, ArAssetStatus::Approved], true)) {
            return false;
        }

        $calibration = $asset->calibration;

        if (! is_array($calibration) || $calibration === []) {
            return true;
        }

        try {
            app(ArCalibration::class)->normalize($calibration);
        } catch (ValidationException) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function calibrationFormFor(?ArAsset $asset): array
    {
        $calibration = $asset?->calibration;

        if (! is_array($calibration) || $calibration === []) {
            return [];
        }

        return [
            'frame_width_mm' => $calibration['frame_width_mm'] ?? null,
            'outer_frame_height_mm' => $calibration['outer_frame_height_mm'] ?? null,
            'lens_width_mm' => $calibration['lens_width_mm'] ?? null,
            'lens_height_mm' => $calibration['lens_height_mm'] ?? null,
            'bridge_width_mm' => $calibration['bridge_width_mm'] ?? null,
            'temple_length_mm' => $calibration['temple_length_mm'] ?? null,
            'scale_x' => $calibration['scale']['x'] ?? null,
            'scale_y' => $calibration['scale']['y'] ?? null,
            'scale_z' => $calibration['scale']['z'] ?? null,
            'anchor_x' => $calibration['anchor']['x'] ?? null,
            'anchor_y' => $calibration['anchor']['y'] ?? null,
            'anchor_z' => $calibration['anchor']['z'] ?? null,
            'rotation_x' => $calibration['rotation_degrees']['x'] ?? null,
            'rotation_y' => $calibration['rotation_degrees']['y'] ?? null,
            'rotation_z' => $calibration['rotation_degrees']['z'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function calibrationFromData(array $data): array
    {
        return [
            'frame_width_mm' => $data['frame_width_mm'] ?? null,
            'outer_frame_height_mm' => $data['outer_frame_height_mm'] ?? null,
            'lens_width_mm' => $data['lens_width_mm'] ?? null,
            'lens_height_mm' => $data['lens_height_mm'] ?? null,
            'bridge_width_mm' => $data['bridge_width_mm'] ?? null,
            'temple_length_mm' => $data['temple_length_mm'] ?? null,
            'scale' => [
                'x' => $data['scale_x'] ?? null,
                'y' => $data['scale_y'] ?? null,
                'z' => $data['scale_z'] ?? null,
            ],
            'anchor' => [
                'x' => $data['anchor_x'] ?? null,
                'y' => $data['anchor_y'] ?? null,
                'z' => $data['anchor_z'] ?? null,
            ],
            'rotation_degrees' => [
                'x' => $data['rotation_x'] ?? null,
                'y' => $data['rotation_y'] ?? null,
                'z' => $data['rotation_z'] ?? null,
            ],
        ];
    }
}
