<?php

declare(strict_types=1);

namespace App\Livewire\Account;

use App\Actions\Gdpr\ExportCustomerData;
use App\Livewire\Concerns\ThrottlesSubmissions;
use App\Models\User;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Self-service GDPR Art. 15 / Art. 20 data export (ADR-0019).
 *
 * `ExportCustomerData` builds the document; this streams it back as a JSON
 * download. No permission — a customer accessing their own data is a right.
 * Throttled per user: the export touches every table the customer has rows
 * in, so it should not be a free amplification endpoint.
 */
#[Layout('components.layouts.app')]
class DownloadData extends Component
{
    use ThrottlesSubmissions;

    public function download(ExportCustomerData $export): StreamedResponse
    {
        $this->throttleSubmission('data-export|'.auth()->id(), 'download', maxAttempts: 3, decaySeconds: 300);

        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $document = $export->handle($user);
        $filename = 'amazoff-data-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(
            fn () => print (json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            $filename,
            ['Content-Type' => 'application/json'],
        );
    }

    public function render(): View
    {
        return view('livewire.account.download-data');
    }
}
