# Webhooks do jCheckout

Os webhooks enviam eventos do checkout para uma URL pública cadastrada no painel em **Configurar > Webhooks**.

## Eventos

| Evento | Quando é enviado |
| --- | --- |
| `ORDER_CREATED` | Quando um pedido é criado. |
| `ORDER_PAID` | Quando o pagamento é confirmado. |
| `ORDER_REFUSED` | Quando uma tentativa de cartão é recusada ou falha. |
| `CART_ABANDONED` | Após 15 minutos sem atividade e sem início de pagamento. |
| `PIX_CREATED` | Assim que o Pix é gerado e permanece aguardando pagamento. |
| `BILLET_CREATED` | Após 15 minutos com o boleto ainda aguardando pagamento. |

Um Pix, boleto ou cartão tentado não é classificado como `CART_ABANDONED`. Para cobrir toda a recuperação, habilite também `PIX_CREATED`, `BILLET_CREATED` e `ORDER_REFUSED`.

## Entrega

- Método: `POST`
- Conteúdo: `application/json`
- Autenticação: `Authorization: Bearer <token>`
- Timeout: 5 segundos
- Sucesso: qualquer resposta `2xx`
- Retentativas: até 3 tentativas, com 1 minuto de intervalo
- Redirecionamentos HTTP não são seguidos
- A ordem de chegada não é garantida

O token é criado junto com a loja e é único por loja. Todos os endpoints de webhook da mesma loja usam esse mesmo token; editar, excluir ou recriar um endpoint não altera a credencial.

Cada combinação de endpoint, evento e recurso é registrada uma única vez. O consumidor também deve deduplicar usando `orderId + eventType`.

## Payload

```json
{
  "eventType": "ORDER_PAID",
  "title": "jCheckout | Pedido #438 pago",
  "text": "jCheckout | Pedido #438 pago",
  "image": null,
  "actions": [
    {
      "name": "Pedido #438",
      "url": "https://app.bersenker.shop/dashboard/orders?order=438"
    }
  ],
  "orderId": "438",
  "platform": "jCheckout",
  "currency": "BRL",
  "paymentMethod": "pix",
  "status": "paid",
  "createdAt": "2026-08-17T12:00:00.000000Z",
  "approvedDate": "2026-08-17T12:02:11.000000Z",
  "refundedAt": null,
  "customer": {
    "name": "Cliente Exemplo",
    "email": "cliente@example.com",
    "phone": "11999998888",
    "document": "00000000000",
    "country": "BR",
    "ip": "203.0.113.20"
  },
  "products": [
    {
      "id": 92,
      "name": "Produto Exemplo",
      "planId": 92,
      "planName": "Produto Exemplo",
      "quantity": 1,
      "priceInCents": 12990,
      "image": "https://cdn.example.com/produto.jpg"
    }
  ],
  "coupons": [],
  "trackingParameters": {
    "src": null,
    "sck": null,
    "utm_source": "instagram",
    "utm_campaign": "agosto",
    "utm_medium": "bio",
    "utm_content": null,
    "utm_term": null
  },
  "commission": {
    "totalPriceInCents": 12990,
    "gatewayFeeInCents": 0,
    "userCommissionInCents": 12990
  },
  "address": {
    "street": "Rua Exemplo",
    "number": "100",
    "complement": null,
    "neighborhood": "Centro",
    "zipcode": "01001000",
    "city": "São Paulo",
    "state": "SP",
    "country": "BR"
  },
  "isTest": false,
  "pixQrCode": null,
  "abandonouNa": null,
  "trackingNumber": null,
  "integrationsPartners": {}
}
```

Para carrinhos sem pedido, `orderId` usa o formato `CART-<id>`.
