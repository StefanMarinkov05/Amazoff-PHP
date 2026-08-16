<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductReviews\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProductReviewForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'id'),
                Select::make('product_id')
                    ->relationship('product', 'name')
                    ->required(),
                Select::make('order_item_id')
                    ->relationship('orderItem', 'id'),
                TextInput::make('author_name')
                    ->required(),
                TextInput::make('rating')
                    ->required()
                    ->numeric(),
                Textarea::make('body')
                    ->required()
                    ->columnSpanFull(),
                Toggle::make('approved')
                    ->required(),
            ]);
    }
}
