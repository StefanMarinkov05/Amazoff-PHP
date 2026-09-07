<?php

declare(strict_types=1);

namespace App\Livewire\Contact;

use App\Actions\Contact\SubscribeToNewsletter;
use App\Livewire\Concerns\ThrottlesSubmissions;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Footer newsletter signup.
 *
 * Unlike `ContactForm`, this one does go through an Action: the panel's
 * `NewsletterSubscriberForm` writes `status` too, and re-subscribing has to
 * reverse an unsubscribe rather than fail.
 */
class NewsletterSignup extends Component
{
    use ThrottlesSubmissions;

    public string $email = '';

    public bool $subscribed = false;

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

        $this->reset('email');
        $this->subscribed = true;
    }

    public function render(): View
    {
        return view('livewire.contact.newsletter-signup');
    }
}
