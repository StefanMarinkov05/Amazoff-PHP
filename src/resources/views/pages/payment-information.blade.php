<x-site.prose-page
    title="Payment"
    standfirst="How you can pay, what happens to your card details, and when money actually moves."
>
    <h2>How you can pay</h2>
    <ul>
        <li>
            <strong>Card.</strong> Visa and Mastercard, processed by Stripe.
            Your bank may ask you to confirm the payment in its own app or by a
            one-time code — this is 3-D Secure, and it is a requirement of the
            card scheme rather than something we add.
        </li>
        <li>
            <strong>Cash on delivery.</strong> Pay the courier when the parcel
            arrives. The amount is passed to the courier with the shipment, so
            you pay exactly what the order says.
        </li>
    </ul>

    <h2>Your card details never reach us</h2>
    <p>
        The card fields at checkout are served by Stripe, not by this site, and
        the card number is sent straight to Stripe. We never see it, never
        transmit it, and store no card data at all — there is no field in our
        database that could hold one.
    </p>

    <h2>When the money moves</h2>
    <p>
        For a card payment the amount is taken when you confirm at checkout. If
        your bank asks for 3-D Secure confirmation, nothing is taken until you
        complete it.
    </p>
    <p>
        Occasionally the confirmation from your bank reaches us a few seconds
        after you return to the site. If your order page says we are still
        confirming, the order is already reserved and nothing more is needed
        from you.
    </p>
    <p>
        For cash on delivery no money moves until the courier hands over the
        parcel.
    </p>

    <h2>Prices and VAT</h2>
    <p>
        Every price shown includes VAT. The VAT rate applied to each item is
        recorded on your order, so the breakdown you see at checkout is the
        breakdown on your order for good — it does not change afterwards even
        if a rate changes later.
    </p>
    <p>
        Delivery cost is added at checkout and shown before you pay. There are
        no other charges.
    </p>

    <h2>Refunds</h2>
    <p>
        A refund goes back to the card that paid, through Stripe. Your bank
        decides how quickly it appears on your statement, typically within a few
        working days. Partial refunds are possible where only part of an order
        is returned.
    </p>

    <h2>Something looks wrong</h2>
    <p>
        If a charge does not match your order, send us the order number through
        the <a href="/contact" wire:navigate>contact form</a>. Please do not
        include card numbers in a message — we do not need them and cannot use
        them.
    </p>
</x-site.prose-page>
