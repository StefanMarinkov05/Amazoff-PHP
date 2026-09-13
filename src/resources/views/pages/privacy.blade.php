<x-site.prose-page
    title="Privacy notice"
    standfirst="What personal data we hold, the legal basis for each use, how long we keep it, and the rights you have over it."
    updated="September 2026"
    :draft="true"
>
    <p>
        This notice explains how <strong>[Legal entity name]</strong> (&ldquo;we&rdquo;,
        the shop trading as <em>Amazoff</em>) handles personal data as a
        <strong>data controller</strong> under the General Data Protection
        Regulation (Regulation (EU) 2016/679) and the Bulgarian Personal Data
        Protection Act. It is written to be read; if something you need is not
        here, ask through the <a href="/contact" wire:navigate>contact form</a>.
    </p>

    <h2>Who we are</h2>
    <ul>
        <li><strong>Controller:</strong> [Legal entity name], [registered address, Bulgaria]</li>
        <li><strong>Company number (ЕИК):</strong> [ЕИК]</li>
        <li><strong>Contact for privacy questions:</strong> [privacy@example.com]</li>
        <li><strong>Data protection officer:</strong> [not appointed / name and contact]</li>
    </ul>

    <h2>What we collect, why, and on what legal basis</h2>
    <p>
        Each row is a distinct purpose with its own legal basis under GDPR
        Art. 6. We do not re-use data collected for one purpose for another
        without telling you.
    </p>
    <table>
        <thead>
            <tr>
                <th>Data</th>
                <th>Purpose</th>
                <th>Legal basis</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Name, email, phone, delivery and billing address, the items ordered</td>
                <td>Fulfilling your order and handing the parcel to a courier</td>
                <td>Art. 6(1)(b) — performance of the contract you entered at checkout</td>
            </tr>
            <tr>
                <td>Order number, amounts, currency, VAT breakdown, payment method, payment status, the reference Stripe returns. <strong>No card number is ever stored or seen by us.</strong></td>
                <td>Taking payment, and keeping proof of the sale</td>
                <td>Art. 6(1)(b) for the payment; Art. 6(1)(c) — legal obligation — for retaining the invoice under Bulgarian accounting and tax law</td>
            </tr>
            <tr>
                <td>The order-confirmation email (line items, totals, addresses, withdrawal information)</td>
                <td>Confirming the concluded contract on a durable medium</td>
                <td>Art. 6(1)(b) and Consumer Rights Directive Art. 8(7)</td>
            </tr>
            <tr>
                <td>Account: name, email, phone, saved addresses, a hashed password</td>
                <td>Running the account you asked us to create</td>
                <td>Art. 6(1)(b)</td>
            </tr>
            <tr>
                <td>Email address for the newsletter</td>
                <td>Sending occasional shop news you subscribed to</td>
                <td>Art. 6(1)(a) — consent, given by confirming the double opt-in link and withdrawable at any time</td>
            </tr>
            <tr>
                <td>Your cookie choice</td>
                <td>Remembering whether you accepted non-essential cookies</td>
                <td>Art. 6(1)(c) / (f) — to honour the choice ePrivacy requires us to offer</td>
            </tr>
            <tr>
                <td>Product reviews (rating, text, the name shown)</td>
                <td>Showing other customers genuine reviews from verified buyers</td>
                <td>Art. 6(1)(b) — reviewing a product you bought — and Art. 6(1)(f) for publishing it</td>
            </tr>
            <tr>
                <td>Contact-form messages (name, email, subject, message)</td>
                <td>Answering your query</td>
                <td>Art. 6(1)(f) — our legitimate interest in responding to you</td>
            </tr>
            <tr>
                <td>A one-way hash of the email used to redeem a one-per-customer coupon; the IP address on an order</td>
                <td>Preventing abuse of promotions and fraudulent orders</td>
                <td>Art. 6(1)(f) — legitimate interest in fraud prevention</td>
            </tr>
            <tr>
                <td>A record of which staff member changed an order's status, and when</td>
                <td>Accountability for changes to your order</td>
                <td>Art. 6(1)(f) and Art. 6(1)(c)</td>
            </tr>
        </tbody>
    </table>

    <h2>How long we keep it</h2>
    <ul>
        <li>
            <strong>Orders and their invoices:</strong> retained for
            <strong>[11] years</strong> from the end of the year of the sale,
            as Bulgarian accounting and tax law requires. This obligation
            outlives any request to delete your data — see &ldquo;Erasure&rdquo;
            below.
        </li>
        <li>
            <strong>Account:</strong> until you close it. Closing the account
            anonymises the linked orders (it does not delete them) and removes
            everything not tied to the retention obligation.
        </li>
        <li>
            <strong>Newsletter:</strong> until you unsubscribe. An address that
            never confirms the opt-in is deleted after 30 days.
        </li>
        <li>
            <strong>Contact messages:</strong> kept only as long as needed to
            deal with the query and any follow-up.
        </li>
        <li>
            <strong>Anonymised orders</strong> are deleted outright once the
            retention period expires — nothing about them is personal data any
            more.
        </li>
    </ul>

    <h2>Who else processes it</h2>
    <p>
        We do not sell your data and never share it for advertising. We use a
        small number of processors, each under a data-processing agreement and
        each receiving only what it needs:
    </p>
    <ul>
        <li>
            <strong>Stripe</strong> (Stripe Payments Europe, Ltd., Ireland, and
            Stripe, Inc., United States) — card payment processing and fraud
            checks. Card details go from your browser to Stripe directly.
        </li>
        <li>
            <strong>Econt Express OOD</strong> and <strong>Speedy AD</strong>
            (Bulgaria) — receive the delivery address, name and phone number for
            the parcel they carry, and the amount to collect on a
            cash-on-delivery order.
        </li>
        <li>
            <strong>[Email provider]</strong> — sends transactional and
            newsletter email on our behalf.
        </li>
        <li>
            <strong>[Hosting provider]</strong> — hosts the website and its
            database within the [EU/EEA].
        </li>
    </ul>

    <h3>Transfers outside the EEA</h3>
    <p>
        Stripe, Inc. is in the United States. Payment data transferred to it is
        protected by the European Commission's
        <strong>Standard Contractual Clauses</strong> and Stripe's own
        data-processing terms. No other processor transfers your data outside
        the EEA. [Confirm before go-live.]
    </p>

    <h2>Your rights</h2>
    <p>
        Under the GDPR you can, in respect of the data we hold about you:
    </p>
    <ul>
        <li><strong>Access</strong> — ask what we hold and get a copy. You can
            download a machine-readable copy yourself at
            <a href="/account/data" wire:navigate>Account &rarr; Download my data</a>.</li>
        <li><strong>Rectification</strong> — correct anything inaccurate, from
            <a href="/account/profile" wire:navigate>your profile</a> or by asking us.</li>
        <li><strong>Erasure</strong> — ask us to delete what we are not obliged
            to keep. Self-service at
            <a href="/account/delete" wire:navigate>Account &rarr; Delete my account</a>.
            <strong>This does not erase past orders</strong> — the law requires
            us to retain the invoice — but it removes the account, anonymises
            the orders, and deletes everything else.</li>
        <li><strong>Restriction and objection</strong> — ask us to pause a
            particular use, or object to a use based on legitimate interest.</li>
        <li><strong>Portability</strong> — receive the data you gave us in a
            structured, machine-readable format (the same JSON download above).</li>
        <li><strong>Withdraw consent</strong> — unsubscribe from the newsletter
            at any time using the link in every email, or the account settings.
            Withdrawing consent does not affect anything done before.</li>
    </ul>
    <p>
        To exercise a right, use the <a href="/contact" wire:navigate>contact
        form</a> from the email address on the account, so we can be reasonably
        sure the request is yours. We respond within one month.
    </p>

    <h2>Complaints</h2>
    <p>
        If you think we have handled your data wrongly, you can lodge a
        complaint with the Bulgarian supervisory authority:
    </p>
    <ul>
        <li>
            <strong>Комисия за защита на личните данни</strong> (Commission for
            Personal Data Protection), 2 Prof. Tsvetan Lazarov Blvd., 1592
            Sofia — <a href="https://www.cpdp.bg" rel="noopener">cpdp.bg</a>.
        </li>
    </ul>

    <h2>Security</h2>
    <p>
        Traffic to this site is encrypted in transit. Passwords are hashed, not
        encrypted — we cannot read them. Staff access to customer data is
        limited by role, and every change to an order is recorded with who made
        it. Card data never touches our servers.
    </p>

    <h2>Cookies</h2>
    <p>
        The site sets only strictly-necessary cookies. What each one does is in
        the <a href="/cookies" wire:navigate>cookie policy</a>.
    </p>

    <h2>Changes to this notice</h2>
    <p>
        If we change how we handle data we will update this page and, where the
        change is significant, tell account holders by email.
    </p>
</x-site.prose-page>
