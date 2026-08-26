<?php

namespace App\Http\Controllers\Api;

use App\Actions\SavedFrames\SaveFrame;
use App\Http\Controllers\Controller;
use App\Http\Resources\SavedFrameResource;
use App\Models\ProductVariant;
use App\Models\SavedFrame;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedFrameController extends Controller
{
    public function save(Request $request, int $productVariant): JsonResponse
    {
        $account = $request->user();

        $variant = ProductVariant::query()
            ->with('product')
            ->find($productVariant);

        if ($variant === null) {
            abort(422, 'This frame variant cannot be saved.');
        }

        $savedFrame = app(SaveFrame::class)->handle($account, $variant);

        $savedFrame->load(['variant.product']);

        return response()->json([
            'data' => SavedFrameResource::make($savedFrame),
        ], 200);
    }

    public function remove(Request $request, int $productVariant): JsonResponse
    {
        $account = $request->user();

        SavedFrame::query()
            ->where('user_id', $account->id)
            ->where('product_variant_id', $productVariant)
            ->delete();

        return response()->json(null, 204);
    }
}
