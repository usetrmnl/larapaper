<?php

namespace App\Concerns;

use App\Models\Plugin;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;

trait ProvidesSelectablePlugins
{
    /**
     * The authenticated user's plugins, ordered by name, for a plugin selector.
     *
     * @return Collection<int, Plugin>
     */
    #[Computed]
    public function selectablePlugins(): Collection
    {
        return auth()->user()->plugins()->orderBy('name')->get();
    }
}
