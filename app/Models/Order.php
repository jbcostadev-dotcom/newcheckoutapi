<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_WAITING_PAYMENT = 'waiting_payment';

    public const STATUS_IN_ANALYSIS = 'in_analysis';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_IN_PROTEST = 'in_protest';

    public const STATUS_CHARGEDBACK = 'chargedback';

    protected $fillable = [
        'store_id', 'gateway_id', 'customer_id', 'customer_name', 'customer_email',
        'customer_phone', 'customer_document', 'amount', 'payment_method',
        'status', 'gateway_transaction_id', 'shopify_order_id', 'pix_qrcode', 'pix_copia_cola',
        'card_brand', 'card_last4', 'card_token', 'installments',
        'boleto_url', 'boleto_barcode', 'boleto_digitable_line', 'gateway_expires_at',
        'shipping_cep', 'shipping_logradouro', 'shipping_numero',
        'shipping_complemento', 'shipping_bairro', 'shipping_cidade', 'shipping_uf',
        'shipping_method_id', 'shipping_price',
        'upsell_id', 'upsell_amount', 'upsell_status', 'upsell_product_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'upsell_amount' => 'decimal:2',
        'shipping_price' => 'decimal:2',
        'installments' => 'integer',
        'gateway_expires_at' => 'datetime',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function gateway()
    {
        return $this->belongsTo(Gateway::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function shippingMethod()
    {
        return $this->belongsTo(ShippingMethod::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function upsell()
    {
        return $this->belongsTo(Upsell::class);
    }

    public function upsellProduct()
    {
        return $this->belongsTo(Product::class, 'upsell_product_id');
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isRefunded(): bool
    {
        return $this->status === self::STATUS_REFUNDED;
    }

    public function isApproved(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_AUTHORIZED], true);
    }

    public function hasUpsellDecided(): bool
    {
        return $this->upsell_status !== null;
    }

    /**
     * Mapeia um status retornado pela FastSoft (webhook/transaction) para
     * o status interno do pedido.
     */
    public static function mapFastSoftStatus(?string $status): ?string
    {
        if (! $status) {
            return null;
        }

        return match (strtoupper($status)) {
            'PROCESSING' => self::STATUS_PROCESSING,
            'WAITING_PAYMENT' => self::STATUS_WAITING_PAYMENT,
            'IN_ANALYSIS' => self::STATUS_IN_ANALYSIS,
            'AUTHORIZED' => self::STATUS_AUTHORIZED,
            'PAID' => self::STATUS_PAID,
            'REFUSED' => self::STATUS_REFUSED,
            'CANCELED' => self::STATUS_CANCELED,
            'REFUNDED' => self::STATUS_REFUNDED,
            'IN_PROTEST' => self::STATUS_IN_PROTEST,
            'CHARGEDBACK' => self::STATUS_CHARGEDBACK,
            default => null,
        };
    }
}
