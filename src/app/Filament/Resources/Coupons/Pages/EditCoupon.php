<?php

declare(strict_types=1);

namespace App\Filament\Resources\Coupons\Pages;

use App\Actions\Coupon\DeleteCoupon as DeleteCouponAction;
use App\Filament\Concerns\ReportsDomainFailures;
use App\Filament\Resources\Coupons\CouponResource;
use App\Models\Coupon;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCoupon extends EditRecord
{
    use ReportsDomainFailures;

    protected static string $resource = CouponResource::class;

    /**
     * Routed through DeleteCoupon for the same reason as ProductCategory's
     * own delete: the default action's raw $record->delete() surfaces the
     * coupon_id foreign key as an uncaught QueryException instead of a
     * message naming which dependency blocked it.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(fn (Model $record): bool => $this->reportingDomainFailures(
                    function () use ($record): bool {
                        /** @var Coupon $record */
                        app(DeleteCouponAction::class)->handle($record, $this->actor());

                        return true;
                    },
                    'Coupon could not be deleted',
                )),
        ];
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
