<?php

declare(strict_types=1);

namespace App\Livewire\Account;

use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A signed-in customer's saved wishlist (§38, and the toggle
 * `ProductDetails`/`ProductList` write).
 *
 * Every query starts from `auth()->user()->wishlistItems()`, never
 * `WishlistItem::query()` — same scoping discipline `OrderHistory`'s own
 * docblock states, for the same reason: an unscoped lookup would let one
 * customer's wishlist be read by guessing another user's row.
 */
#[Layout('components.layouts.app')]
class Wishlist extends Component
{
    use WithPagination;

    /**
     * @return LengthAwarePaginator<int, WishlistItem>
     */
    private function items(): LengthAwarePaginator
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            // Unreachable behind `auth`, and an empty paginator rather than
            // a crash if that ever changes — same defensive shape
            // OrderHistory uses for its own instanceof check.
            return WishlistItem::query()->whereRaw('1 = 0')->paginate(12);
        }

        return $user->wishlistItems()
            ->with(['product.productImages', 'product.brand'])
            ->latest('id')
            ->paginate(12);
    }

    /**
     * Scoped through the owner, same reasoning as `items()` — a bare
     * `WishlistItem::findOrFail()` would let one customer remove (or at
     * least probe the existence of) a row on someone else's wishlist.
     */
    public function remove(int $wishlistItemId): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $user->wishlistItems()->findOrFail($wishlistItemId)->delete();
    }

    public function render(): View
    {
        return view('livewire.account.wishlist', [
            'items' => $this->items(),
        ]);
    }
}
