<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'order_id',
        'carrier_id',
        'tracking_number',
        'status',
        'label_url',
        'courier_tracking_url',
        'shipping_address_id',
        'recipient_name',
        'recipient_phone',
        'street',
        'city',
        'postcode',
        'country',
        'shipped_at',
        'delivered_at',
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
            'carrier_id' => 'integer',
            'shipping_address_id' => 'integer',
            'shipped_at' => 'timestamp',
            'delivered_at' => 'timestamp',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function shipmentTrackingEvents(): HasMany
    {
        return $this->hasMany(ShipmentTrackingEvent::class);
    }
}
