<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Product;
use App\Models\ProductImage;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Everything ProductsTable no longer shows by default — SEO copy, package
 * dimensions, and the discount window — lands here in full, not truncated.
 * The variation ↔ image pairing itself (the pivot ProductVariationsRelationManager
 * summarised as a bare count before) stays on that relation manager tab,
 * which ViewProduct inherits from the resource the same way EditProduct
 * does; the images shown here are the product's own gallery, not per-variation.
 */
class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // No ->disk(): this renders N images from a relation, so a
                // per-record disk closure cannot discriminate per image.
                // Resolving each to an absolute URL up front sidesteps that —
                // Filament renders an absolute URL as-is — and reuses
                // servableUrl()'s own seed/upload routing. ADR-0025.
                ImageEntry::make('productImages.path')
                    ->label('Images')
                    ->getStateUsing(fn (Product $record): array => $record->productImages
                        ->map(fn (ProductImage $image): string => $image->servableUrl())
                        ->all())
                    ->stacked()
                    ->circular()
                    ->limit(5)
                    ->limitedRemainingText()
                    ->columnSpanFull(),
                Section::make('Product')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('productCategory.name')
                            ->label('Category'),
                        TextEntry::make('brand.name')
                            ->label('Brand')
                            ->placeholder('—'),
                        TextEntry::make('name'),
                        TextEntry::make('slug'),
                        TextEntry::make('sku')
                            ->label('SKU'),
                        TextEntry::make('short_description')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        IconEntry::make('is_available')
                            ->boolean(),
                        IconEntry::make('is_featured')
                            ->boolean(),
                    ]),
                Section::make('Pricing')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('regular_price')
                            ->money(),
                        TextEntry::make('discount_price')
                            ->money()
                            ->placeholder('No discount'),
                        TextEntry::make('discount_starts_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('discount_ends_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('vat_rate')
                            ->suffix('%'),
                        TextEntry::make('min_order_quantity'),
                    ]),
                Section::make('Shipping')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('weight_g')
                            ->label('Weight')
                            ->suffix(' g'),
                        TextEntry::make('length_mm')
                            ->label('Length')
                            ->suffix(' mm'),
                        TextEntry::make('width_mm')
                            ->label('Width')
                            ->suffix(' mm'),
                        TextEntry::make('height_mm')
                            ->label('Height')
                            ->suffix(' mm'),
                    ]),
                Section::make('SEO')
                    ->columns(1)
                    ->schema([
                        TextEntry::make('seo_title')
                            ->placeholder('—'),
                        TextEntry::make('seo_description')
                            ->placeholder('—'),
                    ]),
                Section::make('Record')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('created_at')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->dateTime(),
                    ]),
            ]);
    }
}
