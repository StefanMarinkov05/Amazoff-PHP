<?php

declare(strict_types=1);

namespace App\Filament\Resources\Articles\Schemas;

use App\Models\Article;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Dimensions;

class ArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('article_category_id')
                    ->relationship('articleCategory', 'name'),
                TextInput::make('title')
                    ->required()
                    ->maxLength(100)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (string $state, Set $set) => $set('slug', Str::slug($state))),
                TextInput::make('slug')
                    ->maxLength(100)
                    ->unique(ignoreRecord: true)
                    ->required(),
                TextInput::make('summary')
                    ->maxLength(255),
                RichEditor::make('content')
                    ->required()
                    ->columnSpanFull(),
                FileUpload::make('main_image_path')
                    ->image()
                    ->imageEditor()
                    // Named, not defaulted: Filament falls back to
                    // `filament.default_filesystem_disk` (`local` here), whose
                    // root is storage/app/private — never web-servable, so the
                    // storefront could not render an uploaded cover at all.
                    ->disk(Article::imageUploadDisk())
                    ->directory(Article::IMAGE_DIRECTORY)
                    ->acceptedFileTypes(Article::IMAGE_ACCEPTED_MIME_TYPES)
                    ->maxSize(Article::IMAGE_MAX_SIZE_KB)
                    ->rules([
                        (new Dimensions)
                            ->minWidth(Article::IMAGE_MIN_WIDTH_PX)
                            ->minHeight(Article::IMAGE_MIN_HEIGHT_PX),
                    ]),
                Toggle::make('featured')
                    ->required(),
                TextInput::make('seo_title')
                    ->maxLength(100),
                TextInput::make('seo_description')
                    ->maxLength(255),
                Select::make('tags')
                    ->relationship('tags', 'name')
                    ->multiple()
                    ->preload(),
                Select::make('products')
                    ->relationship('products', 'name')
                    ->multiple()
                    ->preload()
                    ->label('Related products'),
            ]);
    }
}
