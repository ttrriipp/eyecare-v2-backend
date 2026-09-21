<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Schemas\VariantForm;
use App\Models\Product;
use App\Models\ProductVariant;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    public function form(Schema $schema): Schema
    {
        $schema = ProductForm::configure($schema);
        $productComponents = $schema->getComponents(withHidden: true);

        return $schema->components([
            Wizard::make([
                Step::make('Product')
                    ->afterValidation(function (Get $get, Set $set): void {
                        if (filled($get('variants'))) {
                            return;
                        }

                        $preparedData = ProductForm::prepareFormDataBeforeSave([
                            'product_type' => $get('product_type'),
                            'frame_default_attributes' => $get('frame_default_attributes'),
                            'frame_other_details' => $get('frame_other_details'),
                            'generic_default_details' => $get('generic_default_details'),
                        ]);
                        $defaultAttributes = is_array($preparedData['default_variant_attributes'] ?? null)
                            ? $preparedData['default_variant_attributes']
                            : [];

                        $set('variants', [
                            VariantForm::defaultFormState($get('product_type'), $defaultAttributes),
                        ]);
                    })
                    ->schema($productComponents),
                Step::make('Variants')
                    ->schema([
                        Repeater::make('variants')
                            ->label('Product variants')
                            ->model(ProductVariant::class)
                            ->schema(function (Get $get): array {
                                $productType = $get('product_type');
                                $preparedData = ProductForm::prepareFormDataBeforeSave([
                                    'product_type' => $productType,
                                    'frame_default_attributes' => $get('frame_default_attributes'),
                                    'frame_other_details' => $get('frame_other_details'),
                                    'generic_default_details' => $get('generic_default_details'),
                                ]);
                                $defaultAttributes = is_array($preparedData['default_variant_attributes'] ?? null)
                                    ? $preparedData['default_variant_attributes']
                                    : [];

                                return VariantForm::schema(
                                    $productType,
                                    defaultAttributes: $defaultAttributes,
                                );
                            })
                            ->defaultItems(0)
                            ->addActionLabel('Add variant')
                            ->collapsible()
                            ->collapsed(false)
                            ->itemLabel(fn (array $state): ?string => filled($state['name'] ?? null)
                                ? (string) $state['name']
                                : null)
                            ->columnSpanFull(),
                    ]),
            ])
                ->columnSpanFull()
                ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                    <div class="flex items-center gap-x-3">
                        <x-filament::button
                            type="submit"
                            size="sm"
                        >
                            Create
                        </x-filament::button>

                        <x-filament::button
                            type="submit"
                            size="sm"
                            wire:click="createAndCreateAnother"
                            x-on:click="setTimeout(() => $wire.set('currentWizardStep', 0), 100)"
                        >
                            Create & create another
                        </x-filament::button>

                        <x-filament::button
                            type="button"
                            size="sm"
                            color="gray"
                            tag="a"
                            href="{{ route('filament.admin.resources.products.index') }}"
                        >
                            Cancel
                        </x-filament::button>
                    </div>
BLADE))),
        ]);
    }

    protected function getCreateFormAction(): Action
    {
        $action = parent::getCreateFormAction();

        $action->hidden();

        return $action;
    }

    protected function getCreateAnotherFormAction(): Action
    {
        $action = parent::getCreateAnotherFormAction();

        $action->hidden();

        return $action;
    }

    protected function getCancelFormAction(): Action
    {
        $action = parent::getCancelFormAction();

        $action->hidden();

        return $action;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = ProductForm::prepareFormDataBeforeSave($data);

        if (filter_var($data['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && ! self::hasActiveVariant($data['variants'] ?? null)) {
            throw ValidationException::withMessages([
                'data.is_active' => ['Add at least one active variant before activating the product.'],
            ]);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $variants = is_array($data['variants'] ?? null) ? $data['variants'] : [];
        unset($data['variants']);

        $product = parent::handleRecordCreation($data);

        if (! $product instanceof Product) {
            return $product;
        }

        foreach ($variants as $variantData) {
            if (! is_array($variantData)) {
                continue;
            }

            if (! array_key_exists('is_active', $variantData)) {
                $variantData['is_active'] = true;
            }

            if ($product->product_type === 'frame') {
                $variantData = VariantForm::prepareFrameFormDataBeforeSave($variantData);
            }

            $defaultAttributes = is_array($product->default_variant_attributes)
                ? $product->default_variant_attributes
                : [];
            $variantAttributes = is_array($variantData['attributes'] ?? null)
                ? array_filter(
                    $variantData['attributes'],
                    fn (mixed $value): bool => $value !== null && $value !== '',
                )
                : [];

            $variantData['attributes'] = array_merge($defaultAttributes, $variantAttributes);

            $product->variants()->create($variantData);
        }

        return $product;
    }

    protected function afterCreate(): void
    {
        Notification::make()
            ->title('Product created')
            ->body('Product details saved. You can add variants now or later from the product page.')
            ->success()
            ->persistent()
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return ProductResource::getUrl('edit', ['record' => $this->record]);
    }

    private static function hasActiveVariant(mixed $variants): bool
    {
        if (! is_array($variants)) {
            return false;
        }

        foreach ($variants as $variant) {
            if (is_array($variant)
                && filter_var($variant['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
                return true;
            }
        }

        return false;
    }
}
