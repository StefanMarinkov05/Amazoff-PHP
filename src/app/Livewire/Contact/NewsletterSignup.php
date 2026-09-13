<?php

declare(strict_types=1);

namespace App\Livewire\Contact;

use App\Actions\Contact\SubscribeToNewsletter;
use App\Livewire\Concerns\ThrottlesSubmissions;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Footer newsletter signup — double opt-in (ePrivacy Art. 13, ADR-0019).
 *
 * Submitting does not subscribe: `SubscribeToNewsletter` creates a
 * `Pending` row and emails a confirmation link. This component only ever
 * shows "check your inbox". It goes through the Action because the consent
 * state machine and the panel's `NewsletterSubscriberForm` share one
 * writer.
 */
class NewsletterSignup extends Component
{
    use ThrottlesSubmissions;

    public string $email = '';

    public bool $submitted = false;

    public function subscribe(SubscribeToNewsletter $subscribe): void
    {
        $validated = $this->validate([
            'email' => 'required|email:rfc|max:100',
        ]);

        // Keyed on IP, not on the submitted address: keying on the value
        // being submitted would give an attacker the full allowance per
        // address, which is not a limit on volume at all. SEC-010.
        $this->throttleSubmission('newsletter|'.$this->requestIp(), 'email');

        $subscribe->handle($validated['email'], auth()->user());

        // Same message whether the address is new, pending, or already
        // subscribed — telling a stranger which would leak who is on the
        // list.
        $this->reset('email');
        $this->submitted = true;
    }

    public function render(): View
    {
        return view('livewire.contact.newsletter-signup');
    }
}
