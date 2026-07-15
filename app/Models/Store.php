<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $fillable = [
        'name',
        'type',
        'subdomain',
        'custom_domain',
        'shopify_domain',
        'shopify_access_token',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function checkoutSettings()
    {
        return $this->hasOne(CheckoutSetting::class);
    }

    public function domains()
    {
        return $this->hasMany(Domain::class);
    }

    public function gateways()
    {
        return $this->hasMany(Gateway::class);
    }

    public function smtpSettings()
    {
        return $this->hasOne(SmtpSetting::class);
    }

    public function whatsappInstances()
    {
        return $this->hasMany(WhatsappInstance::class);
    }

    /**
     * Indica se a loja possui Shopify conectada.
     */
    public function isShopifyConnected(): bool
    {
        return !empty($this->shopify_domain) && !empty($this->shopify_access_token);
    }

    /**
     * Resolve uma loja pelo identificador de domínio.
     * Aceita: subdomínio, custom_domain, ou entrada na tabela domains.
     * Retorna null se não encontrar ou se a loja estiver inativa.
     */
    public static function resolveByDomain(string $domain): ?Store
    {
        $query = static::where('status', true);

        return $query->where(function ($q) use ($domain) {
            $q->where('subdomain', $domain)
              ->orWhere('custom_domain', $domain);
        })
        ->orWhereHas('domains', function ($q) use ($domain) {
            $q->where('domain', $domain)
              ->where('ssl_active', true);
        })
        ->with(['checkoutSettings', 'gateways' => function ($query) {
            $query->where('is_active', true);
        }])
        ->first();
    }

    /**
     * Gera um subdomínio slug a partir do nome, garantindo uniqueness.
     */
    public static function generateUniqueSubdomain(string $name): string
    {
        $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $base = trim($base, '-');

        if (empty($base)) {
            $base = 'store';
        }

        // Garante que começa com letra
        if (!preg_match('/^[a-z]/', $base)) {
            $base = 'store-' . $base;
        }

        // Limita a 40 caracteres
        $base = substr($base, 0, 40);

        // Verifica se é único, caso contrário adiciona sufixo
        if (!static::where('subdomain', $base)->exists()) {
            return $base;
        }

        $suffix = 2;
        while (static::where('subdomain', "{$base}-{$suffix}")->exists()) {
            $suffix++;
        }

        return "{$base}-{$suffix}";
    }
}
