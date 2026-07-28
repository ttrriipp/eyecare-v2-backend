@props([
    'displayValue' => null,
    'emptyText' => '—',
    'field',
    'label',
    'options' => [],
    'placeholder' => null,
    'readOnly' => false,
    'required' => false,
    'rows' => 3,
    'type' => 'text',
    'value' => null,
])

@php
    $errorKey = "formData.{$field}";
    $hasError = $errors->has($errorKey);
    $inputId = 'health-record-'.str_replace('_', '-', $field);
    $resolvedDisplayValue = filled($displayValue) ? $displayValue : (filled($value) ? $value : $emptyText);
@endphp

<div {{ $attributes->only('class') }}>
    @if($readOnly)
        <p class="text-sm font-medium text-gray-700 dark:text-gray-200">
            {{ $label }}
        </p>
        <p class="mt-2 min-h-10 whitespace-pre-wrap rounded-lg bg-gray-50 px-3 py-2 text-sm leading-6 text-gray-950 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-white dark:ring-white/10">
            {{ $resolvedDisplayValue }}
        </p>
    @else
        <label for="{{ $inputId }}" class="text-sm font-medium text-gray-700 dark:text-gray-200">
            {{ $label }}
            @if($required)
                <span class="text-danger-600 dark:text-danger-400" aria-hidden="true">*</span>
                <span class="sr-only">(required)</span>
            @endif
        </label>

        <div class="mt-2">
            <x-filament::input.wrapper :valid="! $errors->has($errorKey)">
                @if($type === 'textarea')
                    <textarea
                        id="{{ $inputId }}"
                        wire:model="formData.{{ $field }}"
                        rows="{{ $rows }}"
                        placeholder="{{ $placeholder }}"
                        aria-describedby="{{ $hasError ? "{$inputId}-error" : '' }}"
                        aria-invalid="{{ $hasError ? 'true' : 'false' }}"
                        class="block w-full resize-y border-0 bg-transparent px-3 py-2 text-base leading-6 text-gray-950 outline-none placeholder:text-gray-400 focus:ring-0 disabled:text-gray-500 dark:text-white dark:placeholder:text-gray-500 sm:text-sm"
                    ></textarea>
                @elseif($type === 'select')
                    <x-filament::input.select
                        id="{{ $inputId }}"
                        wire:model="formData.{{ $field }}"
                        :aria-describedby="$hasError ? $inputId.'-error' : null"
                        :aria-invalid="$hasError"
                    >
                        <option value="">Select {{ strtolower($label) }}</option>
                        @foreach($options as $optionValue => $optionLabel)
                            <option value="{{ $optionValue }}">{{ $optionLabel }}</option>
                        @endforeach
                    </x-filament::input.select>
                @else
                    <x-filament::input
                        id="{{ $inputId }}"
                        type="{{ $type }}"
                        wire:model="formData.{{ $field }}"
                        placeholder="{{ $placeholder }}"
                        :aria-describedby="$hasError ? $inputId.'-error' : null"
                        :aria-invalid="$hasError"
                    />
                @endif
            </x-filament::input.wrapper>
        </div>

        @error($errorKey)
            <p id="{{ $inputId }}-error" class="mt-1 text-sm text-danger-600 dark:text-danger-400" role="alert">
                {{ $message }}
            </p>
        @enderror
    @endif
</div>
