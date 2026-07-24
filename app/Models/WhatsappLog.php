<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappLog extends Model
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'store_id',
        'whatsapp_instance_id',
        'whatsapp_template_id',
        'event',
        'context_key',
        'phone',
        'message',
        'status',
        'error',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function instance()
    {
        return $this->belongsTo(WhatsappInstance::class, 'whatsapp_instance_id');
    }

    public function template()
    {
        return $this->belongsTo(WhatsappTemplate::class);
    }
}