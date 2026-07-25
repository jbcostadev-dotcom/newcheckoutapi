<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmtpSetting extends Model
{
    protected $fillable = [
        'store_id',
        'name',
        'host',
        'port',
        'username',
        'password',
        'encryption',
        'from_email',
        'from_name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'password' => 'encrypted',
    ];

    protected $hidden = [
        'password',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function logs()
    {
        return $this->hasMany(EmailLog::class);
    }
}
