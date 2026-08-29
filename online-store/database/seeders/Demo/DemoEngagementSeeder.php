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

/**
 * `newsletter_subscribers` and `contact_messages` — flat single-table sets
 * with existing factories and no Action, so factories are the right tool
 * here per CLAUDE.md's lookup-table reasoning.
 *
 * Contact message bodies are hand-written rather than `fake()->text()`,
 * because an inbox screen full of Latin filler proves nothing about how real
 * customer mail reads or how staff triage it.
 */
class DemoEngagementSeeder extends Seeder
{
    private const SUBSCRIBER_COUNT = 80;

    private const SUBSCRIBERS_REUSING_CUSTOMER_EMAIL = 30;

    private const CONTACT_MESSAGE_COUNT = 25;

    private const CONTACT_MESSAGES_HANDLED = 16;

    /**
     * @var list<array{subject: string, message: string}>
     */
    private const CONTACT_MESSAGES = [
        ['subject' => 'Delivery to Plovdiv taking longer than expected', 'message' => "I placed an order six days ago and it still shows as preparing. The confirmation email said 2-4 business days for delivery. Can someone check what's going on with ORD-000042? I need the item before the weekend if at all possible."],
        ['subject' => 'Can I change my delivery address after ordering?', 'message' => 'I just realised I entered my old address by mistake on my last order. Is there any way to update it before it ships, or do I need to cancel and reorder? Happy to do whatever is easiest on your end.'],
        ['subject' => 'Wholesale pricing for power tools', 'message' => 'We run a small contracting business in Varna and are looking to buy drills and grinders in bulk on a recurring basis - probably 15-20 units a month across a few SKUs. Do you offer trade or wholesale pricing, and if so who do I talk to about setting up an account?'],
        ['subject' => 'Return request - wrong size received', 'message' => 'I ordered a medium t-shirt and received a small instead. The packing slip does say medium so I think it was a picking error on your end. Could you send a return label and either a replacement or a refund? Order number is on the invoice attached to the package.'],
        ['subject' => 'Item arrived damaged', 'message' => 'The ceramic kitchen set I ordered arrived with one bowl cracked straight through. Everything else looks fine. I have photos if you need them for the claim. Would rather get a replacement bowl than return the whole set if that is possible.'],
        ['subject' => 'Question about the cordless drill battery', 'message' => 'Does the 18V cordless drill listed on your site come with one battery or two? The product photos show two batteries in the case but the description only mentions "battery" singular. Want to make sure before I order.'],
        ['subject' => 'Coupon code not applying at checkout', 'message' => "I have a discount code from your newsletter but it keeps saying it's not valid when I try to use it at checkout. I copied it directly from the email so I don't think it's a typo. Can you take a look?"],
        ['subject' => 'Invoice needed for my order', 'message' => 'My company needs a proper VAT invoice for accounting purposes, not just the order confirmation email. Could you send one through, or let me know how to generate it from my account?'],
        ['subject' => 'Product page shows out of stock but I want to order', 'message' => "The angle grinder I've been eyeing shows as out of stock. Any idea when it'll be back, or is there a similar model you'd recommend in the meantime? Don't want to wait too long if there's a comparable option."],
        ['subject' => 'Complaint about courier handling', 'message' => "The courier left my package outside the building entrance instead of at my door as instructed, and it wasn't marked as attempted delivery in the tracking. Luckily a neighbour brought it in, but this isn't the first time. Please pass this along to whoever manages the courier relationship."],
        ['subject' => 'Interested in becoming a supplier', 'message' => 'We manufacture kitchenware locally and would like to explore listing our products on Amazoff. Who handles vendor onboarding? Happy to send a catalogue and pricing sheet.'],
        ['subject' => 'Cannot log into my account', 'message' => "I've tried resetting my password twice and the reset email never arrives, not even in spam. My email is registered on the account already. Could someone manually reset it or check if there's an issue with my account?"],
        ['subject' => 'Refund still not received after two weeks', 'message' => 'I returned an item and got confirmation it was received back at your warehouse on the 3rd, but the refund still has not shown up on my card statement. My bank says they see nothing pending from your side. Could you check the status?'],
        ['subject' => 'Gift wrapping option', 'message' => 'Is there a way to request gift wrapping at checkout, or is that not something you offer? Ordering a birthday present and would love for it to arrive ready to give.'],
        ['subject' => 'Product dimensions seem wrong', 'message' => "The listing for the garden storage box gives dimensions that don't match what arrived - the box I received is noticeably smaller than what's stated. Could you double check the listing? Might be affecting other customers too."],
        ['subject' => 'Thank you for the quick resolution', 'message' => 'Just wanted to say thanks - I emailed last week about a missing item in my order and your team sorted it out within a day, no back and forth needed. Really appreciated, will definitely order again.'],
        ['subject' => 'Bulk order for office supplies', 'message' => 'Looking to order desk organisers and storage bins for a new office setup, roughly 40 units total across a few different products. Is there a way to get a combined shipping quote before I place separate orders?'],
        ['subject' => 'Newsletter unsubscribe not working', 'message' => "I've clicked unsubscribe on your newsletter emails three times now and I'm still receiving them. Could you remove me manually? My email should already be in your system."],
        ['subject' => 'Sizing chart request for workwear', 'message' => "I don't see a sizing chart on the workwear trousers listing, only S/M/L/XL labels with no measurements. Could you add one, or tell me the waist measurements for a large in the meantime?"],
        ['subject' => 'Order split into two shipments unexpectedly', 'message' => 'My order confirmation said one shipment but I just got two separate tracking numbers. Is this normal, or did something go wrong with my order? Would like to know if I should expect two deliveries or if this is a mistake.'],
        ['subject' => 'Warranty question on power tools', 'message' => 'Does the cordless drill come with a manufacturer warranty, and if so how long is it and where would I register it? Could not find this information on the product page.'],
        ['subject' => 'Price dropped after I ordered', 'message' => 'I ordered a kitchen appliance three days ago and noticed the price is now about 15% lower. Is there any kind of price match policy for orders placed shortly before a discount starts?'],
        ['subject' => 'Missing item from multi-item order', 'message' => 'My order had four items listed but only three arrived in the box. The packing slip inside also only lists three, so it looks like something was left off at the warehouse rather than lost in transit. Could you send the missing item?'],
        ['subject' => 'Question about cash on delivery availability', 'message' => 'Is cash on delivery available for orders being shipped to smaller towns outside Sofia and Plovdiv, or is it limited to major cities? Could not find a clear list of eligible areas.'],
        ['subject' => 'Feedback on the new site design', 'message' => 'Just browsing and wanted to say the new category filters make it much easier to find things compared to before. One small suggestion: it would help to see stock availability directly in the search results instead of only on the product page.'],
    ];

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

        $messages = self::CONTACT_MESSAGES;
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
