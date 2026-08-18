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
                TextEntry::make('product.name')
                    ->label('Product'),
                TextEntry::make('author_name'),
                TextEntry::make('user.email')
                    ->label('Account')
                    ->placeholder('Deleted account'),
                TextEntry::make('orderItem.product_sku')
                    ->label('Verified purchase')
                    ->placeholder('No linked order'),
                TextEntry::make('rating')
                    ->formatStateUsing(fn (int $state): string => str_repeat('★', $state).str_repeat('☆', 5 - $state)),
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
