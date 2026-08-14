<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\PermissionRegistrar;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * No DeleteAction — see RoleResource for why the three roles are
     * structural rather than data.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * The registrar caches the permission table for 24 hours. Spatie clears
     * that cache itself when a role's permissions change through the model,
     * which Filament's relationship handling does — this is belt and braces
     * for the case where a future action writes the pivot directly.
     *
     * Without it the symptom is the worst kind: the form saves, the
     * checkboxes show the new state on reload because they read the database,
     * and authorization keeps answering from the old cache. §3.5's whole
     * point is that the change takes effect without a deploy.
     */
    protected function afterSave(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
