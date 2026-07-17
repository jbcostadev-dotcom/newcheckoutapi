<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialProof extends Model
{
    protected $fillable = [
        'store_id',
        'name',
        'testimonial',
        'photo_url',
        'stars',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'stars' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
