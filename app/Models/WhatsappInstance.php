<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappInstance extends Model
{
    public const STATUS_DISCONNECTED = 'disconnected';
    public const STATUS_STARTING = 'starting';
    public const STATUS_QR_READY = 'qr_ready';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'store_id',
        'instance_name',
        'instance_key',
        'session_name',
        'status',
        'phone_number',
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

    public function logs()
    {
        return $this->hasMany(WhatsappLog::class);
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED && $this->is_active;
    }
}