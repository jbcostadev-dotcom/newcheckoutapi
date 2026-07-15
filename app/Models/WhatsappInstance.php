<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappInstance extends Model
{
    protected $fillable = [
        'store_id',
        'instance_name',
        'instance_key',
        'status',
        'qr_code_url',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
