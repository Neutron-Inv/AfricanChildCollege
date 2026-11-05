<?php

namespace App\Models\Scopes;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filters by school_id when a tenant is set. With no tenant (platform mode) nothing is filtered.
 */
class SchoolScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $id = Tenant::id();

        if ($id !== null) {
            $builder->where($model->qualifyColumn('school_id'), $id);
        }
    }
}
