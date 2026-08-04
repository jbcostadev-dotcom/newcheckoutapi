<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KwaiConversionEvent extends Model
{
    protected $fillable = [
        'store_id', 'order_id', 'event_name', 'event_id', 'event_time',
        'status', 'response_code', 'error_message',
    ];

    protected $casts = ['event_time' => 'integer', 'response_code' => 'integer'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
