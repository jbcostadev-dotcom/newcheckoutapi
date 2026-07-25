<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'store_id',
        'smtp_setting_id',
        'email_template_id',
        'event',
        'context_key',
        'email',
        'subject',
        'message',
        'status',
        'error',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function smtpSetting()
    {
        return $this->belongsTo(SmtpSetting::class);
    }

    public function template()
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }
}
