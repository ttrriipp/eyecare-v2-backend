<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;

class ReportChartWidget extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '300px';

    public string $chartType = 'bar';

    /**
     * @var array<string, mixed>
     */
    public array $chartData = [];

    /**
     * @var array<string, mixed>
     */
    public array $chartOptions = [];

    public function mount(
        string $chartType = 'bar',
        array $chartData = [],
        ?string $chartHeading = null,
        ?string $chartDescription = null,
        array $chartOptions = [],
    ): void {
        $this->chartType = $chartType;
        $this->chartData = $chartData;
        $this->chartOptions = $chartOptions;
        $this->heading = $chartHeading;
        $this->description = $chartDescription;

        parent::mount();
    }

    protected function getData(): array
    {
        return $this->chartData;
    }

    protected function getType(): string
    {
        return $this->chartType;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return $this->chartOptions;
    }
}
