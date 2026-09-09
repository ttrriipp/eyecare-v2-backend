<?php

namespace App\Filament\Support;

use Filament\Livewire\Sidebar;
use Illuminate\Contracts\View\View;

/**
 * Re-renders Filament's navigation so resource badges reflect current data.
 */
class RealtimeSidebar extends Sidebar
{
    public function render(): View
    {
        return view('filament.admin.realtime-sidebar');
    }
}
