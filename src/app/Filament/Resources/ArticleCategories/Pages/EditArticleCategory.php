<?php

declare(strict_types=1);

namespace App\Filament\Resources\ArticleCategories\Pages;

use App\Actions\Content\DeleteArticleCategory as DeleteArticleCategoryAction;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Filament\Resources\ArticleCategories\ArticleCategoryResource;
use App\Models\ArticleCategory;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditArticleCategory extends EditRecord
{
    use ReportsDomainFailures;

    protected static string $resource = ArticleCategoryResource::class;

    /**
     * Routed through DeleteArticleCategory for the same reason as
     * ProductCategory's own delete: the default action's raw
     * $record->delete() surfaces the article_category_id foreign key as an
     * uncaught QueryException instead of a message naming which dependency
     * blocked it.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(fn (Model $record): bool => $this->reportingDomainFailures(
                    function () use ($record): bool {
                        /** @var ArticleCategory $record */
                        app(DeleteArticleCategoryAction::class)->handle($record, $this->actor());

                        return true;
                    },
                    'Article category could not be deleted',
                )),
        ];
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
