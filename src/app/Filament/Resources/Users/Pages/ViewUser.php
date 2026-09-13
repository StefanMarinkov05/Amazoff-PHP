<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Gdpr\EraseCustomer;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ViewUser extends ViewRecord
{
    use ReportsDomainFailures;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),

            // GDPR Art. 17 erasure, for a request emailed to the shop
            // (ADR-0019). Not a DeleteAction: this anonymises the customer's
            // orders and hard-deletes the rest through the real Action, with
            // its policy and lock. Hidden for your own account — self-service
            // at /account/delete is that path, and erasing yourself here
            // drops your panel access mid-transaction.
            Action::make('erase')
                ->label('Erase personal data (GDPR)')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->visible(fn (User $record): bool => $record->getKey() !== Auth::id()
                    && Auth::user()?->can('erase', $record) === true)
                ->requiresConfirmation()
                ->modalHeading(fn (User $record): string => "Erase {$record->email}?")
                ->modalDescription(
                    'Anonymises this customer\'s orders (kept as invoices) and '
                    .'deletes their account, addresses, cart, wishlist, newsletter '
                    .'subscription and contact messages. This cannot be undone.'
                )
                ->schema([
                    TextInput::make('confirm_email')
                        ->label('Type the customer\'s email to confirm')
                        ->required()
                        ->rule(fn (User $record) => 'in:'.$record->email),
                ])
                ->action(fn (User $record) => $this->reportingDomainFailures(
                    function () use ($record): void {
                        app(EraseCustomer::class)->handle($record, Auth::user());
                        $this->redirect(UserResource::getUrl('index'));
                    },
                    'Erasure could not be completed',
                )),
        ];
    }
}
