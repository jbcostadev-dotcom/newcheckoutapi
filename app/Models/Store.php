<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Store extends Model
{
    protected $fillable = [
        'name',
        'type',
        'subdomain',
        'custom_domain',
        'shopify_domain',
        'shopify_pending_domain',
        'shopify_access_token',
        'shopify_client_id',
        'shopify_client_secret',
        'shopify_injected_theme_id',
        'shopify_injected_at',
        'status',
    ];

    protected $casts = [
        'status' => 'boolean',
        'shopify_injected_theme_id' => 'integer',
        'shopify_injected_at' => 'datetime',
    ];

    protected $hidden = [
        'shopify_access_token',
        'shopify_client_secret',
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

    public function shippingMethods()
    {
        return $this->hasMany(ShippingMethod::class);
    }

    public function smtpSettings()
    {
        return $this->hasOne(SmtpSetting::class);
    }

    public function emailTemplates()
    {
        return $this->hasMany(EmailTemplate::class);
    }

    public function emailLogs()
    {
        return $this->hasMany(EmailLog::class);
    }

    public function whatsappInstances()
    {
        return $this->hasMany(WhatsappInstance::class);
    }

    public function whatsappTemplates()
    {
        return $this->hasMany(WhatsappTemplate::class);
    }

    public function whatsappLogs()
    {
        return $this->hasMany(WhatsappLog::class);
    }

    public function socialProofs()
    {
        return $this->hasMany(SocialProof::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function orderBumps()
    {
        return $this->hasMany(OrderBump::class);
    }

    public function upsells()
    {
        return $this->hasMany(Upsell::class);
    }

    public function coupons()
    {
        return $this->hasMany(Coupon::class);
    }

    public function utmifySetting()
    {
        return $this->hasOne(UtmifySetting::class);
    }

    public function melhorEnvioSetting()
    {
        return $this->hasOne(MelhorEnvioSetting::class);
    }

    /**
     * Indica se a integração Utmify está ativa (token + habilitada).
     */
    public function isUtmifyActive(): bool
    {
        $setting = $this->utmifySetting;
        return $setting ? $setting->isActive() : false;
    }

    /**
     * Indica se a loja possui Shopify conectada.
     */
    public function isShopifyConnected(): bool
    {
        return !empty($this->shopify_domain) && !empty($this->shopify_access_token);
    }

    /**
     * Regenera o checkout_url de todos os produtos da loja.
     * Chamado quando subdomain/custom_domain mudam.
     */
    public function regenerateProductUrls(): void
    {
        $generator = app(\App\Services\CheckoutUrlGenerator::class);

        foreach ($this->products as $product) {
            $product->update([
                'checkout_url' => $generator->generate($this, (int) $product->id),
            ]);
        }
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
     * Resolve uma loja por identificador imutável (ID numérico) ou legado
     * (subdomínio/custom_domain/domínio). ID numérico tem prioridade e é
     * muito mais rápido (PK única). Slugs/domínios continuam funcionando
     * para backward compatibility.
     */
    public static function resolveByIdentifier(string $identifier): ?Store
    {
        if (is_numeric($identifier)) {
            return static::where('id', (int) $identifier)
                ->where('status', true)
                ->with(['checkoutSettings', 'gateways' => function ($query) {
                    $query->where('is_active', true);
                }])
                ->first();
        }

        return static::resolveByDomain($identifier);
    }

    /**
     * Gera um subdomínio slug a partir do nome, garantindo uniqueness.
     * A lógica roda dentro de uma transação para minimizar race conditions
     * entre criações simultâneas com mesmo nome.
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

        return DB::transaction(function () use ($base) {
            // Verifica se é único, caso contrário adiciona sufixo.
            // Dentro da transação lemos um snapshot consistente; o controller
            // ainda deve tratar violação UNIQUE como última barreira.
            if (!static::where('subdomain', $base)->lockForUpdate()->exists()) {
                return $base;
            }

            $suffix = 2;
            while (static::where('subdomain', "{$base}-{$suffix}")->lockForUpdate()->exists()) {
                $suffix++;
            }

            return "{$base}-{$suffix}";
        });
    }
}
