<?php

declare(strict_types=1);

namespace App\Livewire\Cart;

use App\Support\ResolveCurrentCart;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The header's cart count.
 *
 * Livewire rather than part of `x-site.header`, which is a plain Blade
 * component and so cannot react to anything: `ProductDetails` and `CartPage`
 * both dispatch `cart-updated`, and this is what listens. Without it the
 * badge would only be right on a full page load.
 *
 * @property-read int $count
 */
class CartBadge extends Component
{
    private const DISPLAY_CAP = 9;

    #[Computed]
    public function count(): int
    {
        $cart = ResolveCurrentCart::existing();

        if ($cart === null) {
            return 0;
        }

        return (int) $cart->cartItems()->sum('quantity');
    }

    #[On('cart-updated')]
    public function refresh(): void
    {
        unset($this->count);
    }

    public function render(): View
    {
        return view('livewire.cart.cart-badge', [
            'label' => $this->count > self::DISPLAY_CAP
                ? self::DISPLAY_CAP.'+'
                : (string) $this->count,
        ]);
    }
}
