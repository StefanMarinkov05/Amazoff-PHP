<?php

declare(strict_types=1);

namespace App\Livewire\Contact;

use App\Actions\Contact\SubscribeToNewsletter;
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
    public string $email = '';

    public bool $subscribed = false;

    public function subscribe(SubscribeToNewsletter $subscribe): void
    {
        $validated = $this->validate([
            'email' => 'required|email:rfc|max:100',
        ]);

        $subscribe->handle($validated['email'], auth()->user());

        $this->reset('email');
        $this->subscribed = true;
    }

    public function render(): View
    {
        return view('livewire.contact.newsletter-signup');
    }
}
