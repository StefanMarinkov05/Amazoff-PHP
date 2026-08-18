<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Schemas;

use App\Support\PermissionCatalogue;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Role')
                    ->schema([
                        // The name is what RoleSeeder and User::STAFF_ROLES
                        // match on. Renaming a role would silently strip panel
                        // access from everyone holding it, so it is shown for
                        // orientation and not made editable.
                        TextInput::make('name')
                            ->disabled()
                            ->dehydrated(false),

                        Placeholder::make('administrator_note')
                            ->label('')
                            ->content('The administrator role holds no permissions. A Gate::before callback grants it every ability, so ticking boxes here would have no effect — see ADR-0006.')
                            ->visible(fn (?Role $record): bool => $record?->name === 'administrator'),
                    ])
                    ->columns(1),

                ...self::permissionSections(),
            ]);
    }

    /**
     * One collapsible section per area, each holding one checkbox list per
     * resource. Grouping mirrors how §3.3 and §3.4 describe the roles — by
     * area of responsibility — rather than listing 104 names alphabetically.
     *
     * @return list<Section>
     */
    private static function permissionSections(): array
    {
        $sections = [];

        foreach (PermissionCatalogue::groups() as $group => $resources) {
            $sections[] = Section::make($group)
                ->schema(array_map(self::resourceCheckboxes(...), $resources))
                ->columns(2)
                ->collapsible()
                // Only Catalogue stays open: four expanded sections of
                // checkboxes is a page nobody reads.
                ->collapsed($group !== 'Catalogue');
        }

        $sections[] = Section::make('Reports and settings')
            ->schema([
                CheckboxList::make('standalone_permissions')
                    ->label('')
                    ->relationship(
                        'permissions',
                        'name',
                        fn ($query) => $query->whereIn('name', PermissionCatalogue::STANDALONE_PERMISSIONS),
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Permission $record): string => PermissionCatalogue::label($record->name),
                    )
                    ->columns(3),
            ])
            ->collapsible()
            ->collapsed()
            ->description('Permissions with no model behind them, checked directly on the page that uses them.');

        return $sections;
    }

    /**
     * The checkboxes for one resource — CRUD abilities plus whatever domain
     * verbs it carries, in catalogue order.
     *
     * `relationship()` on each list means Filament reads and writes the
     * role's permissions directly; scoping the query per resource is what
     * lets several lists edit the same relationship without one wiping
     * another's boxes on save.
     */
    private static function resourceCheckboxes(string $resource): CheckboxList
    {
        $names = PermissionCatalogue::namesForResource($resource);

        return CheckboxList::make("permissions_{$resource}")
            ->label(ucfirst(str_replace('_', ' ', $resource)))
            ->relationship(
                'permissions',
                'name',
                fn ($query) => $query->whereIn('name', $names),
            )
            ->getOptionLabelFromRecordUsing(
                // "View any" rather than "viewAny_product" — the resource is
                // already the list's label, so repeating it in every box is
                // noise.
                fn (Permission $record): string => ucfirst(strtolower(
                    preg_replace('/(?<!^)[A-Z]/', ' $0', explode('_', $record->name, 2)[0]) ?? '',
                )),
            )
            ->columns(2)
            ->bulkToggleable();
    }
}
