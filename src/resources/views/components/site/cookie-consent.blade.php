{{--
    Cookie-consent banner — ePrivacy Art. 5(3) / GDPR Art. 6(1)(a),
    ADR-0019.

    The shop currently sets only strictly-necessary cookies (session, CSRF),
    which are exempt from consent. This banner exists so the *mechanism* is
    in place: it records the visitor's choice in a first-party
    `cookie_consent` cookie (`accepted` / `rejected`), and
    `App\Support\CookieConsent::granted()` is what any future analytics or
    marketing script must check before it loads. Until such a script exists
    the choice changes nothing that is set — but the record, and the ability
    to withdraw, are there.

    Rendered once, from the app layout. Hidden the moment a choice cookie is
    present; the decision persists for a year.
--}}
@php($consent = request()->cookie('cookie_consent'))

@if ($consent === null)
    <div
        x-data="{
            choose(value) {
                document.cookie = 'cookie_consent=' + value
                    + '; path=/; max-age=' + (60 * 60 * 24 * 365)
                    + '; SameSite=Lax';
                this.$el.remove();
            },
        }"
        role="region"
        aria-label="Cookie notice"
        class="fixed inset-x-0 bottom-0 z-50 border-t border-ink-200 bg-white/95 backdrop-blur-sm"
    >
        <div class="mx-auto flex max-w-5xl flex-col gap-3 px-4 py-4 text-sm text-ink-600 sm:flex-row sm:items-center sm:justify-between">
            <p class="max-w-2xl">
                This site uses only the cookies it needs to work — a session
                and a security token. Nothing tracks you.
                <a href="{{ route('cookies') }}" wire:navigate
                   class="font-medium text-marine-700 underline-offset-4 hover:underline">
                    Cookie policy
                </a>
            </p>
            <div class="flex shrink-0 gap-2">
                <button type="button" x-on:click="choose('rejected')"
                        class="rounded-control border border-ink-300 px-3 py-1.5 text-sm font-medium
                               text-ink-700 hover:bg-ink-50">
                    Decline non-essential
                </button>
                <button type="button" x-on:click="choose('accepted')"
                        class="rounded-control bg-ink-900 px-3 py-1.5 text-sm font-medium text-white
                               hover:bg-marine-700">
                    OK
                </button>
            </div>
        </div>
    </div>
@endif
