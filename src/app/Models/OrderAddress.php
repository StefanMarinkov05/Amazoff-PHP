<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AddressType;
use App\Enums\DeliveryType;
use Database\Factories\OrderAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property AddressType $type
 * @property DeliveryType $delivery_type
 */
class OrderAddress extends Model
{
    /** @use HasFactory<OrderAddressFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'source_address_id',
        'type',
        'delivery_type',
        'first_name',
        'last_name',
        'phone',
        'country',
        'city',
        'postcode',
        'street',
        'courier_office_code',
        'courier_office_name',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'order_id' => 'integer',
            'source_address_id' => 'integer',
            'type' => AddressType::class,
            'delivery_type' => DeliveryType::class,
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Address, $this> */
    public function sourceAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }
}
