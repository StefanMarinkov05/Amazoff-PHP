<?php

declare(strict_types=1);

namespace App\Filament\Resources\Articles\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ArticleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('author.id')
                    ->label('Author'),
                TextEntry::make('articleCategory.name')
                    ->label('Article category')
                    ->placeholder('-'),
                TextEntry::make('title'),
                TextEntry::make('slug'),
                TextEntry::make('summary')
                    ->placeholder('-'),
                TextEntry::make('content')
                    ->columnSpanFull(),
                ImageEntry::make('main_image_path')
                    ->placeholder('-'),
                TextEntry::make('status')
                    ->badge(),
                IconEntry::make('featured')
                    ->boolean(),
                TextEntry::make('seo_title')
                    ->placeholder('-'),
                TextEntry::make('seo_description')
                    ->placeholder('-'),
                TextEntry::make('published_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
