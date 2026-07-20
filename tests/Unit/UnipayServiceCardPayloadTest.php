<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\UnipayService;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class UnipayServiceCardPayloadTest extends TestCase
{
    public function test_build_card_payload_matches_fastsoft_schema(): void
    {
        $order = new Order([
            'id' => 123,
            'customer_name' => 'João da Silva',
            'customer_email' => 'joao@example.com',
            'customer_phone' => '11999999999',
            'customer_document' => '12345678901',
            'amount' => 99.90,
            'shipping_price' => 10.00,
            'shipping_logradouro' => 'Rua Teste',
            'shipping_numero' => '123',
            'shipping_complemento' => 'Apt 1',
            'shipping_cep' => '01001000',
            'shipping_bairro' => 'Centro',
            'shipping_cidade' => 'São Paulo',
            'shipping_uf' => 'SP',
        ]);

        $order->setRelation('items', new Collection([
            new Order([
                'name' => 'Produto Teste',
                'unit_price' => 89.90,
                'qty' => 1,
                'product_id' => 1,
            ]),
        ]));

        $cardData = [
            'number' => '4111111111111111',
            'holderName' => 'JOAO DA SILVA',
            'expirationMonth' => 12,
            'expirationYear' => 2026,
            'cvv' => '123',
        ];

        $payload = UnipayService::buildCardPayload(
            $order,
            $cardData,
            3,
            'https://example.com/webhook',
            '127.0.0.1'
        );

        $this->assertSame(9990, $payload['amount']);
        $this->assertSame('CREDIT_CARD', $payload['paymentMethod']);
        $this->assertSame(3, $payload['installments']);

        $this->assertArrayNotHasKey('token', $payload['card']);
        $this->assertArrayNotHasKey('installments', $payload['card']);

        $this->assertSame('4111111111111111', $payload['card']['number']);
        $this->assertSame('JOAO DA SILVA', $payload['card']['holderName']);
        $this->assertSame(12, $payload['card']['expirationMonth']);
        $this->assertSame(2026, $payload['card']['expirationYear']);
        $this->assertSame('123', $payload['card']['cvv']);
    }
}
