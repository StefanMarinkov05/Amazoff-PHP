<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\NewsletterStatus;
use App\Models\ContactMessage;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * `newsletter_subscribers` and `contact_messages` — flat single-table sets
 * with existing factories and no Action, so factories are the right tool
 * here per CLAUDE.md's lookup-table reasoning.
 *
 * Contact message bodies are hand-written rather than `fake()->text()`,
 * because an inbox screen full of Latin filler proves nothing about how real
 * customer mail reads or how staff triage it. They live in
 * `database/fixtures/reference/contact-messages.json` rather than in a const
 * here — content, not logic, and the same reasoning that put the review
 * bodies in `review-bodies.json`: a wording change should not be a PHP edit,
 * and the pool can grow without this class changing at all.
 */
class DemoEngagementSeeder extends Seeder
{
    private const SUBSCRIBER_COUNT = 80;

    private const SUBSCRIBERS_REUSING_CUSTOMER_EMAIL = 30;

    /**
     * How many contact messages to write, and how many of those are already
     * handled. Fewer than the fixture pool holds, deliberately — the pool is
     * shuffled and sliced, so two seed runs produce a different inbox rather
     * than the same 40 rows in a different order.
     */
    private const CONTACT_MESSAGE_COUNT = 32;

    private const CONTACT_MESSAGES_HANDLED = 20;

    /**
     * Lazily-loaded contact message pool. See `contactMessages()`.
     *
     * @var list<array{subject: string, message: string}>|null
     */
    private ?array $contactMessages = null;

    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $this->seedNewsletterSubscribers();
        $this->seedContactMessages();
    }

    private function seedNewsletterSubscribers(): void
    {
        /** @var Collection<int, User> $customers */
        $customers = User::query()->doesntHave('roles')->inRandomOrder()->take(self::SUBSCRIBERS_REUSING_CUSTOMER_EMAIL)->get();

        $unsubscribedCount = (int) round(self::SUBSCRIBER_COUNT * 0.175);

        $created = 0;

        foreach ($customers as $customer) {
            NewsletterSubscriber::factory()->create([
                'user_id' => $customer->getKey(),
                'email' => $customer->email,
                'status' => $created < $unsubscribedCount ? NewsletterStatus::Unsubscribed : NewsletterStatus::Subscribed,
                'subscribed_at' => Carbon::now()->subDays(random_int(1, 240)),
            ]);
            $created++;
        }

        $remaining = self::SUBSCRIBER_COUNT - $created;

        for ($i = 0; $i < $remaining; $i++) {
            NewsletterSubscriber::factory()->create([
                'user_id' => null,
                'status' => $created < $unsubscribedCount ? NewsletterStatus::Unsubscribed : NewsletterStatus::Subscribed,
                'subscribed_at' => Carbon::now()->subDays(random_int(1, 240)),
            ]);
            $created++;
        }

        $this->command?->info("Created {$created} newsletter subscriber(s).");
    }

    private function seedContactMessages(): void
    {
        /** @var Collection<int, User> $staff */
        $staff = User::query()->has('roles')->get();

        $messages = $this->contactMessages();
        shuffle($messages);

        foreach (array_slice($messages, 0, self::CONTACT_MESSAGE_COUNT) as $index => $entry) {
            $isHandled = $index < self::CONTACT_MESSAGES_HANDLED;

            ContactMessage::factory()->create([
                'user_id' => random_int(0, 1) === 1 ? User::query()->doesntHave('roles')->inRandomOrder()->value('id') : null,
                'subject' => $entry['subject'],
                'message' => $entry['message'],
                'handled_at' => $isHandled ? Carbon::now()->subDays(random_int(1, 30)) : null,
                'internal_note' => $isHandled ? $this->internalNoteFor($entry['subject']) : null,
            ]);
        }

        $this->command?->info('Created '.self::CONTACT_MESSAGE_COUNT.' contact message(s), '.self::CONTACT_MESSAGES_HANDLED.' handled.');
    }

    /**
     * The contact message pool, read once from
     * `database/fixtures/reference/contact-messages.json`.
     *
     * Fails loudly on a missing file, a malformed document, or a pool
     * smaller than `CONTACT_MESSAGE_COUNT` asks for. That last check is the
     * one worth having: `array_slice` on a short pool silently returns fewer
     * rows, so the seeder would report success while writing an inbox
     * smaller than the count it claims.
     *
     * @return list<array{subject: string, message: string}>
     */
    private function contactMessages(): array
    {
        if ($this->contactMessages !== null) {
            return $this->contactMessages;
        }

        $path = database_path('fixtures/reference/contact-messages.json');

        if (! is_file($path)) {
            throw new RuntimeException("Contact messages fixture missing at [{$path}].");
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['messages']) || ! is_array($decoded['messages'])) {
            throw new RuntimeException("Contact messages fixture at [{$path}] has no 'messages' array.");
        }

        $messages = [];

        foreach ($decoded['messages'] as $index => $entry) {
            if (! is_array($entry) || ! isset($entry['subject'], $entry['message'])) {
                throw new RuntimeException("Contact message [{$index}] is missing 'subject' or 'message'.");
            }

            $messages[] = [
                'subject' => (string) $entry['subject'],
                'message' => (string) $entry['message'],
            ];
        }

        if (count($messages) < self::CONTACT_MESSAGE_COUNT) {
            throw new RuntimeException(
                'Contact messages fixture holds '.count($messages).' entries, fewer than the '
                .self::CONTACT_MESSAGE_COUNT.' this seeder writes.'
            );
        }

        return $this->contactMessages = $messages;
    }

    private function internalNoteFor(string $subject): string
    {
        return match (true) {
            str_contains($subject, 'Return') || str_contains($subject, 'damaged') => 'Return label issued, replacement dispatched.',
            str_contains($subject, 'Refund') => 'Confirmed with finance, refund reprocessed manually.',
            str_contains($subject, 'Coupon') => 'Coupon had expired; issued a new one as goodwill.',
            str_contains($subject, 'Wholesale') || str_contains($subject, 'supplier') || str_contains($subject, 'Bulk') => 'Forwarded to sales for a quote.',
            str_contains($subject, 'account') || str_contains($subject, 'log into') => 'Password reset manually, confirmed access restored.',
            default => 'Reviewed and responded via email.',
        };
    }
}
