<?php

declare(strict_types=1);

namespace App\Livewire\Contact;

use App\Livewire\Concerns\ThrottlesSubmissions;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The public contact form — §37's contact requirement, `ContactMessageResource`
 * is where staff read what it writes.
 *
 * No Action: this is one INSERT into one table with no invariant the schema
 * cannot express, which is the same test that keeps the lookup tables on
 * Filament's default CRUD. ADR-0007.
 */
class ContactForm extends Component
{
    use ThrottlesSubmissions;

    public string $name = '';

    public string $email = '';

    public string $subject = '';

    public string $message = '';

    public string $website = '';

    public bool $sent = false;

    /** @return array<string, string> */
    protected function rules(): array
    {
        return [
            'name' => 'required|string|min:2|max:50',
            'email' => 'required|email:rfc|max:100',
            'subject' => 'nullable|string|max:100',
            'message' => 'required|string|min:10|max:2000',
        ];
    }

    public function mount(): void
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $this->name = mb_substr($user->getFilamentName(), 0, 50);
            $this->email = $user->email;
        }
    }

    public function updated(string $property): void
    {
        $this->validateOnly($property);
    }

    public function submit(): void
    {
        $validated = $this->validate();

        // After the honeypot, not before: a bot that fills `website` is
        // turned away without consuming a real visitor's allowance, and the
        // limit is spent only on submissions that would otherwise write a
        // row. Keyed on IP — there is no account behind this form. SEC-010.
        if ($this->website !== '') {
            $this->sent = true;

            return;
        }

        $this->throttleSubmission('contact|'.$this->requestIp(), 'message');

        ContactMessage::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'subject' => $validated['subject'] ?? null,
            'message' => $validated['message'],
            'user_id' => auth()->id(),
        ]);

        $this->reset(['subject', 'message']);
        $this->sent = true;
    }

    public function render(): View
    {
        return view('livewire.contact.contact-form')->title('Contact');
    }
}
