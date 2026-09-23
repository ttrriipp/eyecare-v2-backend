<?php

namespace App\Services;

interface ReportsSmsOutcomeUncertainty
{
    public function outcomeMayBeUnknown(): bool;

    public function providerMessageId(): ?string;
}
