<?php

namespace App\Actions\JobOrders;

use App\Enums\FrameSource;
use App\Models\JobOrderEyewearSpecification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SaveEyewearSpecification
{
    /**
     * Save dispensing measurements, lens construction, and lab instructions.
     *
     * Accepts either binocular distance PD or both monocular values.
     * Near PD and fitting/segment heights are required only for applicable
     * lens designs. Rejects cross-order item references and never mutates
     * the clinical Prescription.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(
        JobOrderEyewearSpecification $specification,
        array $data,
        User $editor,
    ): JobOrderEyewearSpecification {
        $validated = $this->validate($data);

        return DB::transaction(function () use ($specification, $validated): JobOrderEyewearSpecification {
            $locked = JobOrderEyewearSpecification::query()
                ->lockForUpdate()
                ->findOrFail($specification->id);

            // If approved, clear approval on edit
            $wasApproved = $locked->isApproved();

            $updateData = [];

            // Frame source
            if (isset($validated['frame_source'])) {
                $updateData['frame_source'] = FrameSource::from($validated['frame_source']);
            }

            // Lens construction snapshots
            foreach (['lens_design_snapshot', 'lens_material_snapshot', 'refractive_index_snapshot'] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updateData[$field] = $validated[$field];
                }
            }

            if (isset($validated['lens_options_snapshot'])) {
                $updateData['lens_options_snapshot'] = $validated['lens_options_snapshot'];
            }

            // PD measurements
            if (isset($validated['distance_pd_mode'])) {
                $updateData['distance_pd_mode'] = $validated['distance_pd_mode'];
            }

            foreach ([
                'distance_pd_binocular', 'distance_pd_od', 'distance_pd_os',
                'near_pd_binocular', 'near_pd_od', 'near_pd_os',
                'fitting_height_od', 'fitting_height_os',
                'segment_height_od', 'segment_height_os',
            ] as $field) {
                if (array_key_exists($field, $validated)) {
                    $updateData[$field] = $validated[$field];
                }
            }

            // Lab instructions
            if (array_key_exists('lab_instructions', $validated)) {
                $updateData['lab_instructions'] = $validated['lab_instructions'];
            }

            // Clear approval if specification was approved
            if ($wasApproved) {
                $updateData['approved_by'] = null;
                $updateData['approved_at'] = null;
            }

            $locked->update($updateData);

            return $locked->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data): array
    {
        $validator = Validator::make($data, [
            'frame_source' => ['sometimes', 'string', 'in:catalog,patient_supplied'],
            'lens_design_snapshot' => ['required', 'string', 'max:255'],
            'lens_material_snapshot' => ['sometimes', 'nullable', 'string', 'max:255'],
            'refractive_index_snapshot' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lens_options_snapshot' => ['sometimes', 'nullable', 'array'],
            'distance_pd_mode' => ['required', 'string', 'in:binocular,monocular'],
            'distance_pd_binocular' => ['required_if:distance_pd_mode,binocular', 'nullable', 'numeric', 'min:40', 'max:90'],
            'distance_pd_od' => ['required_if:distance_pd_mode,monocular', 'nullable', 'numeric', 'min:20', 'max:45'],
            'distance_pd_os' => ['required_if:distance_pd_mode,monocular', 'nullable', 'numeric', 'min:20', 'max:45'],
            'near_pd_binocular' => ['sometimes', 'nullable', 'numeric', 'min:40', 'max:90'],
            'near_pd_od' => ['sometimes', 'nullable', 'numeric', 'min:20', 'max:45'],
            'near_pd_os' => ['sometimes', 'nullable', 'numeric', 'min:20', 'max:45'],
            'fitting_height_od' => ['sometimes', 'nullable', 'numeric', 'min:5', 'max:40'],
            'fitting_height_os' => ['sometimes', 'nullable', 'numeric', 'min:5', 'max:40'],
            'segment_height_od' => ['sometimes', 'nullable', 'numeric', 'min:5', 'max:40'],
            'segment_height_os' => ['sometimes', 'nullable', 'numeric', 'min:5', 'max:40'],
            'lab_instructions' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $validator->after(function ($validator) use ($data): void {
            // PD validation: require either binocular or both monocular values
            $mode = $data['distance_pd_mode'] ?? 'binocular';
            if ($mode === 'binocular') {
                if (isset($data['distance_pd_od']) || isset($data['distance_pd_os'])) {
                    $validator->errors()->add(
                        'distance_pd_mode',
                        'Use binocular PD or monocular PDs, not both.',
                    );
                }
            }

            if ($mode === 'monocular') {
                if (isset($data['distance_pd_binocular'])) {
                    $validator->errors()->add(
                        'distance_pd_mode',
                        'Use binocular PD or monocular PDs, not both.',
                    );
                }
            }
        });

        return $validator->validate();
    }
}
