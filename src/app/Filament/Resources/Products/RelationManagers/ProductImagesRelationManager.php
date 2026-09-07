<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\RelationManagers;

use App\Actions\Catalogue\AddProductImage;
use App\Actions\Catalogue\RemoveProductImage;
use App\Actions\Catalogue\SetMainProductImage;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Dimensions;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ProductImagesRelationManager extends RelationManager
{
    use ReportsDomainFailures;

    protected static string $relationship = 'productImages';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('path')
                    ->label('Image')
                    ->image()
                    ->imageEditor()
                    ->disk(ProductImage::DISK)
                    ->directory(ProductImage::DIRECTORY)
                    ->acceptedFileTypes(ProductImage::ACCEPTED_MIME_TYPES)
                    ->maxSize(ProductImage::MAX_SIZE_KB)
                    // ->image() sniffs MIME only. Dimensions are a Laravel
                    // validation rule, not a FileUpload method, applied the
                    // same way price fields below apply `decimal:0,2`.
                    //
                    // The default message is "has invalid image dimensions",
                    // which names neither the requirement nor what was
                    // actually uploaded — leaving an admin to guess and
                    // retry. validationMessages() replaces it with the
                    // numbers, and the helper text states them before the
                    // file picker is even opened.
                    ->rules([
                        (new Dimensions)
                            ->minWidth(ProductImage::MIN_WIDTH_PX)
                            ->minHeight(ProductImage::MIN_HEIGHT_PX),
                    ])
                    ->validationMessages([
                        'dimensions' => 'This image is too small. Product images must be at least '
                            .ProductImage::MIN_WIDTH_PX.'×'.ProductImage::MIN_HEIGHT_PX.' pixels.',
                    ])
                    ->helperText(
                        'At least '.ProductImage::MIN_WIDTH_PX.'×'.ProductImage::MIN_HEIGHT_PX
                        .' pixels, up to '.round(ProductImage::MAX_SIZE_KB / 1024, 1).' MB.'
                    )
                    ->required(),
                TextInput::make('alt_text')
                    ->maxLength(255)
                    ->nullable(),
                TextInput::make('sort_order')
                    ->required()
                    ->integer()
                    ->default(0),
                // No is_main toggle. "Exactly one main image" is an invariant
                // across the set, so promotion goes through the Make main
                // action and SetMainProductImage — a toggle per row is a
                // second door onto the same rule.
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('path')
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('path')
                    ->disk(ProductImage::DISK)
                    ->label('Image'),
                TextColumn::make('alt_text')
                    ->searchable(),
                IconColumn::make('is_main')
                    ->label('Main')
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->numeric()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(fn (array $data): ProductImage => $this->reportingDomainFailures(
                        function () use ($data): ProductImage {
                            /** @var Product $product */
                            $product = $this->getOwnerRecord();

                            return app(AddProductImage::class)->handle($product, $data, $this->actor());
                        },
                        'Image could not be added',
                    )),
                // Bulk upload sits beside the single-image create rather
                // than replacing it: this one takes many files at once but
                // sets no alt text, and alt text is an accessibility
                // requirement rather than a nicety — so the per-image form
                // stays for when it matters, and each uploaded row can be
                // edited afterwards to add it.
                //
                // Composes AddProductImage once per file rather than
                // inserting directly, so the first-image-becomes-main rule
                // and the ordering both hold exactly as they do for a
                // single upload.
                Action::make('uploadMany')
                    ->label('Upload images')
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->modalSubmitActionLabel('Upload')
                    ->schema([
                        FileUpload::make('paths')
                            ->label('Images')
                            ->multiple()
                            ->reorderable()
                            ->image()
                            ->disk(ProductImage::DISK)
                            ->directory(ProductImage::DIRECTORY)
                            ->acceptedFileTypes(ProductImage::ACCEPTED_MIME_TYPES)
                            ->maxSize(ProductImage::MAX_SIZE_KB)
                            // Not ->rules([new Dimensions]): on a ->multiple()
                            // field Filament validates every file through one
                            // nested Validator keyed "paths.*" and surfaces
                            // only $validator->errors()->first() — the first
                            // failing file's message, with no filename
                            // attached, because the validation attribute is
                            // the field's own label ("Images"), not any one
                            // file's name. "One of these images is too small"
                            // was the most that rule could ever say, on any
                            // number of files. A closure rule gets each
                            // TemporaryUploadedFile directly and can name it.
                            ->rules([
                                function (): Closure {
                                    return function (string $attribute, mixed $value, Closure $fail): void {
                                        foreach (Arr::wrap($value) as $file) {
                                            if (! $file instanceof TemporaryUploadedFile) {
                                                continue;
                                            }

                                            $size = @getimagesize($file->getRealPath());

                                            if ($size === false) {
                                                continue;
                                            }

                                            [$width, $height] = $size;

                                            if ($width < ProductImage::MIN_WIDTH_PX || $height < ProductImage::MIN_HEIGHT_PX) {
                                                $fail(sprintf(
                                                    '"%s" is %dx%d — product images must be at least %dx%d pixels.',
                                                    $file->getClientOriginalName(),
                                                    $width,
                                                    $height,
                                                    ProductImage::MIN_WIDTH_PX,
                                                    ProductImage::MIN_HEIGHT_PX,
                                                ));
                                            }
                                        }
                                    };
                                },
                            ])
                            ->helperText(
                                'Pick several at once. At least '.ProductImage::MIN_WIDTH_PX.'×'
                                .ProductImage::MIN_HEIGHT_PX.' pixels each, up to '
                                .round(ProductImage::MAX_SIZE_KB / 1024, 1).' MB. Drag to set the order. '
                                .'Add alt text afterwards by editing each row.'
                            )
                            ->required(),
                    ])
                    ->action(fn (array $data) => $this->reportingDomainFailures(
                        function () use ($data): void {
                            /** @var Product $product */
                            $product = $this->getOwnerRecord();

                            // Existing count as the offset so a second bulk
                            // upload appends rather than restarting at 0 and
                            // interleaving with what is already there.
                            $sortOrder = $product->productImages()->count();

                            foreach (array_values($data['paths'] ?? []) as $path) {
                                app(AddProductImage::class)->handle($product, [
                                    'path' => $path,
                                    'alt_text' => null,
                                    'sort_order' => $sortOrder++,
                                ], $this->actor());
                            }
                        },
                        'Images could not be added',
                    )),
            ])
            ->recordActions([
                // Editing touches alt_text and sort_order only — 1 table, no
                // invariant, so ADR-0007 leaves it as default CRUD.
                EditAction::make(),
                Action::make('setMain')
                    ->label('Make main')
                    ->icon(Heroicon::OutlinedStar)
                    ->visible(fn (ProductImage $record): bool => ! $record->is_main)
                    ->requiresConfirmation()
                    ->action(fn (ProductImage $record) => $this->reportingDomainFailures(
                        fn () => app(SetMainProductImage::class)->handle($record, $this->actor()),
                        'Main image could not be changed',
                    )),
                DeleteAction::make()
                    ->using(fn (Model $record): bool => $this->reportingDomainFailures(
                        function () use ($record): bool {
                            /** @var ProductImage $record */
                            app(RemoveProductImage::class)->handle($record, $this->actor());

                            return true;
                        },
                        'Image could not be removed',
                    )),
            ]);
        // No delete bulk action: it writes Eloquent directly, so it would skip
        // both the in-use check and the successor promotion.
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
