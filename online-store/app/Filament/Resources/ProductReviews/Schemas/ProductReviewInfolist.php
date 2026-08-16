<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductReviews\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProductReviewInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('user.id')
                    ->label('User')
                    ->placeholder('-'),
                TextEntry::make('product.name')
                    ->label('Product'),
                TextEntry::make('orderItem.id')
                    ->label('Order item')
                    ->placeholder('-'),
                TextEntry::make('author_name'),
                TextEntry::make('rating')
                    ->numeric(),
                TextEntry::make('body')
                    ->columnSpanFull(),
                IconEntry::make('approved')
                    ->boolean(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
