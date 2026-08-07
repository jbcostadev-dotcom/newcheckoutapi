<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Achievement;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => 'password',
                'remember_token' => Str::random(10),
            ]
        );

        $catalog = [
            ['plate', 'revenue_total', 10000000, 'jCheckout Open Sky Award', 'R$ 100.000 em vendas', 'Primeiro grande marco da sua jornada.', 10],
            ['plate', 'revenue_total', 50000000, 'jCheckout Silver Turbine', 'R$ 500.000 em vendas', 'Sua operação ganhou força.', 20],
            ['plate', 'revenue_total', 100000000, 'jCheckout One Million Club', 'R$ 1.000.000 em vendas', 'Você chegou ao clube do milhão.', 30],
            ['plate', 'revenue_total', 500000000, 'jCheckout Five Million', 'R$ 5.000.000 em vendas', 'Uma marca extraordinária de escala.', 40],
            ['badge', 'orders_paid', 1, 'Primeira Venda', '1 pagamento aprovado', 'Sua jornada começa aqui.', 10],
            ['badge', 'orders_paid', 10, 'Decolagem', '10 vendas aprovadas', 'Motor ligado.', 20],
            ['badge', 'revenue_total', 1000000, 'Primeira Nuvem', 'R$ 10.000 em vendas', 'Você saiu do chão.', 30],
            ['badge', 'revenue_total', 5000000, 'Corrente de Vento', 'R$ 50.000 em vendas', 'Seu negócio ganhou velocidade.', 40],
            ['badge', 'revenue_total', 7500000, 'Voo Estável', 'R$ 75.000 em vendas', 'Negócio estruturado.', 50],
            ['badge', 'revenue_total', 10000000, 'Céu Aberto', 'R$ 100.000 em vendas', 'Agora você está voando.', 60],
            ['badge', 'revenue_total', 50000000, 'Turbina de Escala', 'R$ 500.000 em vendas', 'Escala ativada.', 70],
            ['badge', 'revenue_total', 100000000, 'Capitão da Nuvem', 'R$ 1.000.000 em vendas', 'Comando total.', 80],
            ['badge', 'orders_paid_24h', 100, 'Raio Supremo', '100 vendas em 24h', 'Velocidade máxima.', 90],
            ['badge', 'orders_paid_24h', 200, 'Tempestade de Vendas', '200 vendas em 24h', 'Tempestade ativa.', 100],
            ['badge', 'orders_paid_24h', 500, 'Furacão jCheckout', '500 vendas em 24h', 'Força total.', 110],
            ['badge', 'revenue_24h', 5000000, 'Estratosfera', 'R$ 50.000 em um dia', 'Altitude máxima.', 120],
        ];

        foreach ($catalog as [$type, $metric, $target, $title, $subtitle, $description, $sortOrder]) {
            Achievement::firstOrCreate(
                ['title' => $title],
                [
                    'type' => $type,
                    'metric' => $metric,
                    'target_value' => $target,
                    'subtitle' => $subtitle,
                    'description' => $description,
                    'sort_order' => $sortOrder,
                    'active' => true,
                ]
            );
        }
    }
}
