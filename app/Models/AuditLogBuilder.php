<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

class AuditLogBuilder extends Builder
{
    public function update(array $values): never
    {
        throw new \LogicException('Audit logs are immutable.');
    }

    public function delete(): never
    {
        throw new \LogicException('Audit logs are immutable.');
    }

    public function forceDelete(): never
    {
        throw new \LogicException('Audit logs are immutable.');
    }

    /**
     * @param  array<int, array<string, mixed>>|array<string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    public function upsert(array $values, $uniqueBy, $update = null): never
    {
        throw new \LogicException('Audit logs are immutable.');
    }
}
