<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Clinic Hours Section --}}
        <form wire:submit="saveClinicHours" class="space-y-4">
            <x-filament::section>
                <x-slot name="heading">
                    Clinic Hours
                </x-slot>

                <x-slot name="description">
                    Set the clinic's operating hours for each day of the week.
                </x-slot>

                <div class="space-y-3">
                    @foreach($this->getClinicHours() as $day => $hours)
                        <div class="flex items-center gap-4 p-3 rounded-lg border border-gray-200 dark:border-gray-700">
                            <div class="w-28 font-medium text-sm">
                                {{ $hours['name'] }}
                            </div>

                            <x-filament::input.wrapper class="w-24">
                                <x-filament::input
                                    type="toggle"
                                    name="clinic_hours[{{ $day }}][enabled]"
                                    :checked="$hours['enabled']"
                                    wire:model="clinic_hours.{{ $day }}.enabled"
                                />
                            </x-filament::input.wrapper>

                            @if($hours['enabled'])
                                <div class="flex items-center gap-2">
                                    <x-filament::input.wrapper>
                                        <x-filament::input
                                            type="time"
                                            name="clinic_hours[{{ $day }}][open_time]"
                                            :value="$hours['open_time']"
                                            wire:model="clinic_hours.{{ $day }}.open_time"
                                        />
                                    </x-filament::input.wrapper>

                                    <span class="text-gray-500">to</span>

                                    <x-filament::input.wrapper>
                                        <x-filament::input
                                            type="time"
                                            name="clinic_hours[{{ $day }}][close_time]"
                                            :value="$hours['close_time']"
                                            wire:model="clinic_hours.{{ $day }}.close_time"
                                        />
                                    </x-filament::input.wrapper>
                                </div>
                            @else
                                <span class="text-sm text-gray-500">Closed</span>
                            @endif
                        </div>
                    @endforeach
                </div>

                <x-slot name="footer">
                    <x-filament::button type="submit" color="primary">
                        Save Clinic Hours
                    </x-filament::button>
                </x-slot>
            </x-filament::section>
        </form>

        {{-- Provider Hours Section --}}
        <form wire:submit="saveProviderHours" class="space-y-4">
            <x-filament::section>
                <x-slot name="heading">
                    Optometrist Hours
                </x-slot>

                <x-slot name="description">
                    Set individual optometrist availability. Hours must fit within clinic hours.
                </x-slot>

                @if(count($this->getOptometrists()) > 0)
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Select Optometrist
                        </label>
                        <select
                            wire:model.live="selectedOptometristId"
                            class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700"
                        >
                            @foreach($this->getOptometrists() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if($selectedOptometristId)
                        <div class="space-y-3">
                            @foreach($this->getProviderHours() as $day => $hours)
                                <div class="flex items-center gap-4 p-3 rounded-lg border border-gray-200 dark:border-gray-700">
                                    <div class="w-28 font-medium text-sm">
                                        {{ $hours['name'] }}
                                    </div>

                                    <x-filament::input.wrapper class="w-24">
                                        <x-filament::input
                                            type="toggle"
                                            name="provider_hours[{{ $day }}][enabled]"
                                            :checked="$hours['enabled']"
                                            wire:model="provider_hours.{{ $day }}.enabled"
                                        />
                                    </x-filament::input.wrapper>

                                    @if($hours['enabled'])
                                        <div class="flex items-center gap-2">
                                            <x-filament::input.wrapper>
                                                <x-filament::input
                                                    type="time"
                                                    name="provider_hours[{{ $day }}][start_time]"
                                                    :value="$hours['start_time']"
                                                    wire:model="provider_hours.{{ $day }}.start_time"
                                                />
                                            </x-filament::input.wrapper>

                                            <span class="text-gray-500">to</span>

                                            <x-filament::input.wrapper>
                                                <x-filament::input
                                                    type="time"
                                                    name="provider_hours[{{ $day }}][end_time]"
                                                    :value="$hours['end_time']"
                                                    wire:model="provider_hours.{{ $day }}.end_time"
                                                />
                                            </x-filament::input.wrapper>
                                        </div>
                                    @else
                                        <span class="text-sm text-gray-500">Not available</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                @else
                    <p class="text-sm text-gray-500">No optometrists found.</p>
                @endif

                <x-slot name="footer">
                    <x-filament::button type="submit" color="primary">
                        Save Provider Hours
                    </x-filament::button>
                </x-slot>
            </x-filament::section>
        </form>
    </div>
</x-filament-panels::page>
