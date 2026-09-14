<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContactMessages\Pages;

use App\Filament\Resources\ContactMessages\ContactMessageResource;
use App\Models\ContactMessage;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewContactMessage extends ViewRecord
{
    protected static string $resource = ContactMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('markHandled')
                ->label('Mark handled')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->authorize('update')
                ->visible(fn (ContactMessage $record): bool => $record->handled_at === null)
                ->action(function (ContactMessage $record): void {
                    $record->update(['handled_at' => now()]);

                    Notification::make()->title('Marked handled')->success()->send();
                }),
            EditAction::make(),
        ];
    }
}
