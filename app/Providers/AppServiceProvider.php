<?php

namespace App\Providers;

use App\Models\CheckoutSetting;
use App\Models\Domain;
use App\Models\Gateway;
use App\Models\Gift;
use App\Models\GoogleAdsSetting;
use App\Models\KwaiPixelSetting;
use App\Models\MetaPixelSetting;
use App\Models\Order;
use App\Models\OrderBump;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\SocialProof;
use App\Models\Store;
use App\Models\TaboolaPixelSetting;
use App\Models\TikTokPixelSetting;
use App\Observers\CheckoutCacheObserver;
use App\Observers\OrderObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Order::observe(OrderObserver::class);

        foreach ([
            Store::class,
            Domain::class,
            Product::class,
            CheckoutSetting::class,
            Gateway::class,
            ShippingMethod::class,
            OrderBump::class,
            Gift::class,
            SocialProof::class,
            GoogleAdsSetting::class,
            MetaPixelSetting::class,
            TikTokPixelSetting::class,
            KwaiPixelSetting::class,
            TaboolaPixelSetting::class,
        ] as $model) {
            $model::observe(CheckoutCacheObserver::class);
        }

        // Anti-bruteforce para o endpoint de login.
        // Combina IP + e-mail alvo (lowercase) para isolar tentativas por conta,
        // evitando que ataques a um usuário esgotem a cota de outro.
        // - 100 tentativas/dia por IP (limite amplo contra abuso de origem)
        // - 5 tentativas a cada 60 segundos por IP+e-mail (limite agressivo por conta)
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower((string) $request->input('email', ''));
            $ip = $request->ip() ?? 'unknown';

            return [
                Limit::perDay(100)->by($ip)->response(fn () => response()->json([
                    'message' => 'Muitas tentativas de login a partir deste endereço. Tente novamente mais tarde.',
                ], 429)),

                Limit::perMinutes(1, 5)
                    ->by("login:{$ip}|{$email}")
                    ->response(fn () => response()->json([
                        'message' => 'Muitas tentativas de login para esta conta. Aguarde 60 segundos e tente novamente.',
                    ], 429)),
            ];
        });

        // Limite mais leve para cadastro (evita enumeração/abuso de criação de contas).
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinutes(5, 5)
                ->by('register:'.($request->ip() ?? 'unknown'))
                ->response(fn () => response()->json([
                    'message' => 'Muitas contas criadas em pouco tempo. Aguarde alguns minutos.',
                ], 429));
        });
    }
}
