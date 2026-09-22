<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('product_variants', ['product_id', 'name'], 'unique')) {
            return;
        }

        $this->normalizeAndDisambiguateVariantNames();

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unique(['product_id', 'name'], 'product_variants_product_id_name_unique');
        });
    }

    private function normalizeAndDisambiguateVariantNames(): void
    {
        $variants = DB::table('product_variants')
            ->whereNotNull('name')
            ->orderBy('product_id')
            ->orderBy('id')
            ->get(['id', 'product_id', 'name']);

        /**
         * Reserve every normalized name before assigning suffixes so an existing
         * variant such as "Blue (2)" is not renamed just because a duplicate
         * "Blue" row is encountered first.
         *
         * @var array<int|string, array<string, true>> $reservedNames
         */
        $reservedNames = [];

        foreach ($variants as $variant) {
            $baseName = $this->baseVariantName((string) $variant->name);
            $reservedNames[$variant->product_id][$this->nameKey($baseName)] = true;
        }

        /** @var array<int|string, array<string, true>> $seenBaseNames */
        $seenBaseNames = [];

        /** @var array<int|string, array<string, true>> $assignedNames */
        $assignedNames = [];

        foreach ($variants as $variant) {
            $productId = $variant->product_id;
            $baseName = $this->baseVariantName((string) $variant->name);
            $baseNameKey = $this->nameKey($baseName);
            $variantName = $baseName;

            if (isset($seenBaseNames[$productId][$baseNameKey])) {
                $suffix = 2;

                do {
                    $variantName = $this->variantNameWithSuffix($baseName, $suffix);
                    $variantNameKey = $this->nameKey($variantName);
                    $suffix++;
                } while (
                    isset($reservedNames[$productId][$variantNameKey])
                    || isset($assignedNames[$productId][$variantNameKey])
                );
            }

            $seenBaseNames[$productId][$baseNameKey] = true;
            $assignedNames[$productId][$this->nameKey($variantName)] = true;

            if ($variantName !== $variant->name) {
                DB::table('product_variants')
                    ->where('id', $variant->id)
                    ->update(['name' => $variantName]);
            }
        }
    }

    private function baseVariantName(string $name): string
    {
        $normalizedName = trim(preg_replace('/\s+/', ' ', $name) ?? '');

        return $normalizedName !== '' ? $normalizedName : 'Variant';
    }

    private function nameKey(string $name): string
    {
        return mb_strtolower($name);
    }

    private function variantNameWithSuffix(string $baseName, int $suffix): string
    {
        $suffixText = sprintf(' (%d)', $suffix);
        $maximumBaseLength = max(1, 255 - mb_strlen($suffixText));

        return mb_substr($baseName, 0, $maximumBaseLength).$suffixText;
    }

    public function down(): void
    {
        if (Schema::hasIndex('product_variants', 'product_variants_product_id_name_unique')) {
            Schema::table('product_variants', function (Blueprint $table): void {
                $table->dropUnique('product_variants_product_id_name_unique');
            });
        }
    }
};
