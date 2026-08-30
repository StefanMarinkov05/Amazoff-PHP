<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductCategories\Schemas;

use App\Models\ProductCategory;
use App\Support\ResolveCategoryFamily;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;

class ProductCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Where this category sits in the tree, root first, itself
                // emphasised — edit-only, because a category being created
                // has no ancestry to show until its parent is chosen and
                // saved. Read-only: the parent select below is what actually
                // moves a category.
                View::make('filament.components.category-ancestry')
                    ->viewData(fn (ProductCategory $record): array => [
                        'ancestry' => ResolveCategoryFamily::ancestryOf($record),
                    ])
                    ->visibleOn(Operation::Edit)
                    ->columnSpanFull(),
                // The same indented tree ProductForm's category picker and
                // the storefront sidebar use, rather than the flat
                // alphabetised list ->relationship() builds — a parent is
                // being chosen out of a hierarchy, so the hierarchy should be
                // visible while choosing.
                //
                // Excludes the category itself and every descendant of it:
                // either choice would make the tree cyclic, and a cycle turns
                // every walk of parent_id — the storefront filter, the
                // ancestry breadcrumb above, the mega-menu — into an
                // unbounded loop. This is the readable half only;
                // UpdateProductCategory refuses the same move with
                // CategoryCycleException regardless of what the dropdown
                // happened to offer.
                Select::make('parent_id')
                    ->label('Parent category')
                    ->options(function (?ProductCategory $record): array {
                        $options = ResolveCategoryFamily::selectOptions();

                        if ($record === null) {
                            return $options;
                        }

                        $illegal = ResolveCategoryFamily::selfAndDescendantIds($record);

                        return collect($options)
                            ->reject(fn (string $label, int $id): bool => in_array($id, $illegal, true))
                            ->all();
                    })
                    ->searchable()
                    ->helperText('Leave empty for a top-level category. A category cannot move under one of its own subcategories.'),
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('description'),
            ]);
    }
}
