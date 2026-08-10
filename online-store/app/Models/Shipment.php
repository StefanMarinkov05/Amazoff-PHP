<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ShipmentStatus;
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
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'carrier_id',
        'shipment_number',
        'tracking_number',
        'status',
        'raw_status',
        'label_path',
        'courier_tracking_url',
        'cod_amount',
        'weight',
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
            'status' => ShipmentStatus::class,
            'cod_amount' => 'decimal:2',
            'weight' => 'decimal:2',
            'shipped_at' => 'timestamp',
            'delivered_at' => 'timestamp',
        ];
    }

    public function shipmentTrackingEvents(): HasMany
    {
        return $this->hasMany(ShipmentTrackingEvent::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }
}
