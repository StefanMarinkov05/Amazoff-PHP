<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\LengthUnit;
use App\Enums\WeightUnit;
use App\Models\Attribute;
use App\Models\ProductCategory;
use App\Models\ProductVariation;
use App\Support\Resolvers\ResolveAllowedAttributes;
use App\Support\Resolvers\ResolveCategoryFamily;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Indented tree rather than a flat 173-row dump; a plain
                // options() array because the indentation lives in the
                // label, which ->relationship() cannot express. ->live()
                // so the two attribute fields below can scope to it.
                Select::make('product_category_id')
                    ->label('Category')
                    ->options(fn (): array => ResolveCategoryFamily::selectOptions())
                    ->searchable()
                    ->live()
                    ->required(),
                Select::make('brand_id')
                    ->relationship('brand', 'name')
                    ->nullable(),
                // Deliberately NOT ->relationship(): that saves after
                // handleRecordCreation() returns, too late for CreateProduct
                // to validate each variation's values against these axes
                // inside its own transaction. CreateProduct/UpdateProduct
                // sync it; EditProduct hydrates it. See actions.md.
                //
                // ->live() so the variation repeater below can scope its own
                // pickers to the current selection on the same render.
                Select::make('attributes')
                    ->label('Variation axes')
                    ->options(fn (Get $get): array => Attribute::query()
                        ->whereIn('id', self::allowedAttributeIds($get))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->helperText('Scoped to this product\'s own category — set the category above first.')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->live(),
                // Facts true of every variation, which the customer does
                // not choose between — see product-variability.md for why
                // this is a separate pivot from the axes above. Options
                // exclude anything already picked as an axis;
                // SetProductAttributeValues refuses that regardless.
                Select::make('descriptive_attribute_value_ids')
                    ->label('Product details')
                    ->helperText('Facts true of every variation — material, notes, certifications. Not something the customer picks between.')
                    ->options(function (Get $get): array {
                        $axisIds = self::intList($get('attributes'));

                        // Minus this product's own axes, and minus every
                        // attribute that is axis-only by nature (Size,
                        // Colour) — the customer chooses between those, so
                        // no product can assert one as a whole.
                        /** @var list<int> $variationOnly */
                        $variationOnly = Attribute::query()
                            ->where('is_variation_only', true)
                            ->pluck('id')
                            ->all();

                        return self::attributeValueOptions(array_values(array_diff(
                            self::allowedAttributeIds($get),
                            $axisIds,
                            $variationOnly,
                        )));
                    })
                    ->multiple()
                    ->searchable()
                    ->preload(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(100),
                TextInput::make('slug')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(100),
                TextInput::make('sku')
                    ->label('SKU')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(64),
                TextInput::make('short_description')
                    ->maxLength(255),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('regular_price')
                    ->required()
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0)
                    ->rules(['decimal:0,2', 'max:99999999.99'])
                    ->prefix('EUR'),
                // Explicitly nullable: a product with no active discount is
                // the normal case, and ->lt('regular_price') alone reads as
                // a constraint on a value that must exist.
                TextInput::make('discount_price')
                    ->numeric()
                    ->step('0.01')
                    ->minValue(0)
                    ->rules(['decimal:0,2', 'max:99999999.99'])
                    ->prefix('EUR')
                    ->lt('regular_price')
                    ->nullable(),
                DateTimePicker::make('discount_starts_at')
                    ->nullable(),
                DateTimePicker::make('discount_ends_at')
                    ->nullable(),
                TextInput::make('vat_rate')
                    ->numeric()
                    ->step('0.01')
                    ->rules(['decimal:0,2', 'between:0,100'])
                    ->default(20.00),
                TextInput::make('min_order_quantity')
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->default(1),
                // Weight and dimensions are entered in whatever unit suits
                // the product and stored canonically — grams and millimetres.
                // The *_display_unit columns remember what was typed so this
                // form shows the same number back; nothing computes from
                // them. reference/schema/open-schema-questions.md #3.
                Select::make('weight_display_unit')
                    ->label('Weight unit')
                    ->options(WeightUnit::class)
                    ->default(WeightUnit::default())
                    ->selectablePlaceholder(false)
                    ->live()
                    ->required(),
                TextInput::make('weight_input')
                    ->label('Weight')
                    ->numeric()
                    ->step('0.001')
                    ->minValue(0)
                    ->nullable()
                    ->dehydrated(),
                Select::make('dimension_display_unit')
                    ->label('Dimension unit')
                    ->options(LengthUnit::class)
                    ->default(LengthUnit::default())
                    ->selectablePlaceholder(false)
                    ->live()
                    ->required(),
                TextInput::make('length_input')
                    ->label('Length')
                    ->numeric()
                    ->step('0.1')
                    ->minValue(0)
                    ->nullable()
                    ->dehydrated(),
                TextInput::make('width_input')
                    ->label('Width')
                    ->numeric()
                    ->step('0.1')
                    ->minValue(0)
                    ->nullable()
                    ->dehydrated(),
                TextInput::make('height_input')
                    ->label('Height')
                    ->numeric()
                    ->step('0.1')
                    ->minValue(0)
                    ->nullable()
                    ->dehydrated(),
                Toggle::make('is_available')
                    ->required()
                    ->default(true),
                Toggle::make('is_featured')
                    ->required()
                    ->default(false),
                TextInput::make('seo_title')
                    ->maxLength(100)
                    ->nullable(),
                TextInput::make('seo_description')
                    ->maxLength(255)
                    ->nullable(),
                // Create-only: §6-7 puts stock on the variation, so a product
                // saved without one has nowhere to hold a quantity and
                // CreateProduct refuses it. On edit the variations relation
                // manager owns them, and two editors for one relationship
                // disagree the moment either is used. A hidden component is
                // not dehydrated, so `variations` is simply absent from the
                // edit payload.
                Repeater::make('variations')
                    ->label('Variations')
                    ->helperText('At least one. A product with nothing to vary still needs one, because stock hangs off the variation.')
                    ->visibleOn(Operation::Create)
                    ->minItems(1)
                    ->defaultItems(1)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('sku')
                            ->label('SKU')
                            ->required()
                            ->maxLength(64)
                            // product_variations.sku is globally unique, so the
                            // table check spans every variation. distinct()
                            // covers two rows of this repeater sharing a SKU,
                            // which are both new and so in no table yet.
                            ->unique(table: ProductVariation::class)
                            ->distinct(),
                        // "What makes this one different?" — scoped to
                        // whatever's currently picked in "Variation axes"
                        // above, via a relative Get path: '../' leaves this
                        // repeater item, a second '../' leaves the repeater
                        // itself, landing back at the root-level 'attributes'
                        // field. Reacts live because that field is ->live().
                        Select::make('attribute_value_ids')
                            ->label('Attribute values')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(fn (Get $get): array => self::attributeValueOptions(self::intList($get('../../attributes'))))
                            ->helperText(fn (Get $get): string => (($get('../../attributes') ?? []) === [])
                                ? 'Pick this product\'s "Variation axes" above first.'
                                : 'At most one value per axis. Leave an axis unpicked if this variation does not use it.'),
                        TextInput::make('price')
                            ->numeric()
                            ->step('0.01')
                            ->minValue(0)
                            ->rules(['decimal:0,2', 'max:99999999.99'])
                            ->prefix('EUR')
                            ->helperText('Leave empty to inherit the product price.')
                            ->nullable(),
                        TextInput::make('discount_price')
                            ->numeric()
                            ->step('0.01')
                            ->minValue(0)
                            ->rules(['decimal:0,2', 'max:99999999.99'])
                            ->prefix('EUR')
                            ->lt('price')
                            ->nullable(),
                        Select::make('weight_display_unit')
                            ->label('Weight unit')
                            ->options(WeightUnit::class)
                            ->default(WeightUnit::default())
                            ->selectablePlaceholder(false)
                            ->required(),
                        TextInput::make('weight_input')
                            ->label('Weight')
                            ->numeric()
                            ->step('0.001')
                            ->minValue(0)
                            ->nullable()
                            ->dehydrated(),
                        // Not a column. AddProductVariation turns this into an
                        // InitialStock movement against the row it creates.
                        TextInput::make('initial_quantity')
                            ->label('Opening stock')
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        Toggle::make('is_available')
                            ->default(true),
                    ]),
            ]);
    }

    /**
     * Attribute ids the currently-picked category allows, or `[]` when no
     * category is chosen yet — both attribute fields above scope to this.
     *
     * @return list<int>
     */
    private static function allowedAttributeIds(Get $get): array
    {
        $categoryId = $get('product_category_id');

        if (! is_numeric($categoryId)) {
            return [];
        }

        $category = ProductCategory::query()->find((int) $categoryId);

        return $category === null
            ? []
            : ResolveAllowedAttributes::forCategory($category);
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static function (mixed $id): int {
            if (! is_scalar($id)) {
                throw new InvalidArgumentException('Product form attribute id must be a scalar value.');
            }

            return (int) $id;
        }, $value));
    }

    /**
     * `$attributeIds`'s own values, grouped by attribute name for the
     * Select's optgroups — the same shape and reasoning
     * `ProductVariationsRelationManager::attributeValueOptions()` uses,
     * duplicated rather than shared because that method reads a saved
     * product's own relation while this one reads the create form's
     * unsaved, still-changing selection; the two have no model in common to
     * hang a shared method off of.
     *
     * @param  list<int>  $attributeIds
     * @return array<string, array<int, string>>
     */
    private static function attributeValueOptions(array $attributeIds): array
    {
        if ($attributeIds === []) {
            return [];
        }

        /** @var Collection<int, Attribute> $attributes */
        $attributes = Attribute::query()
            ->whereIn('id', $attributeIds)
            ->with('attributeValues')
            ->orderBy('name')
            ->get();

        return $attributes
            ->mapWithKeys(function (Attribute $attribute): array {
                /** @var array<int, string> $values */
                $values = $attribute->attributeValues
                    ->sortBy('sort_order')
                    ->pluck('value', 'id')
                    ->all();

                return [$attribute->name => $values];
            })
            ->all();
    }
}
