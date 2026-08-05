<?php

namespace App\Filament\Clusters\Availability;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class AvailabilityCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Availability';

    protected static ?int $navigationSort = 30;

    protected static string|UnitEnum|null $navigationGroup = 'Today';
}
