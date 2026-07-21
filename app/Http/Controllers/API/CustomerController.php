<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Services\ShopifyCustomerSync;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    /**
     * Registro público de cliente durante o checkout.
     * Chamado quando o cliente preenche nome/email/telefone e avança de etapa.
     *
     * Payload: domain, name, email, phone, document?, address?
     */
    public function register(Request $request, ShopifyCustomerSync $sync)
    {
        $validated = $request->validate([
            'domain' => 'required|string',
            'name' => 'required|string|min:3|max:150',
            'email' => 'required|email|max:150',
            'phone' => 'required|string|min:10|max:20',
            'document' => 'nullable|string|max:20',
            'address' => 'nullable|array',
            'address.cep' => 'nullable|string|max:9',
            'address.logradouro' => 'nullable|string|max:255',
            'address.numero' => 'nullable|string|max:30',
            'address.complemento' => 'nullable|string|max:120',
            'address.bairro' => 'nullable|string|max:120',
            'address.cidade' => 'nullable|string|max:120',
            'address.uf' => 'nullable|string|max:2',
        ]);

        $store = Store::resolveByDomain($validated['domain']);

        if (!$store) {
            return response()->json(['error' => 'Store not found or inactive'], 404);
        }

        $customer = $this->upsertCustomer($store, $validated);

        // Sincroniza com a Shopify (best-effort) — cria/atualiza o Customer e,
        // se houver endereço, atualiza também o endereço default.
        try {
            $sync->sync($store, $customer);

            if (!empty($validated['address']) && !empty($validated['address']['cep'])) {
                $sync->updateAddress($store, $customer);
            }
        } catch (\Throwable $e) {
            // Silencioso no fluxo público — o registro local é o prioritário.
        }

        return response()->json([
            'customer_id' => $customer->id,
            'shopify_customer_id' => $customer->shopify_customer_id,
        ]);
    }

    /**
     * Atualização pública do endereço do cliente durante o checkout.
     * Chamado quando o cliente avança da etapa de entrega.
     *
     * Payload: domain, email, address
     */
    public function updateAddress(Request $request, ShopifyCustomerSync $sync)
    {
        $validated = $request->validate([
            'domain' => 'required|string',
            'email' => 'required|email|max:150',
            'address' => 'required|array',
            'address.cep' => 'required|string|max:9',
            'address.logradouro' => 'required|string|max:255',
            'address.numero' => 'required|string|max:30',
            'address.complemento' => 'nullable|string|max:120',
            'address.bairro' => 'required|string|max:120',
            'address.cidade' => 'nullable|string|max:120',
            'address.uf' => 'nullable|string|max:2',
        ]);

        $store = Store::resolveByDomain($validated['domain']);

        if (!$store) {
            return response()->json(['error' => 'Store not found or inactive'], 404);
        }

        $customer = $store->customers()->where('email', $validated['email'])->first();

        if (!$customer) {
            return response()->json(['error' => 'Customer not found'], 404);
        }

        $addr = $validated['address'];

        $customer->update([
            'zip' => $addr['cep'] ?? null,
            'street' => $addr['logradouro'] ?? null,
            'number' => $addr['numero'] ?? null,
            'complement' => $addr['complemento'] ?? null,
            'district' => $addr['bairro'] ?? null,
            'city' => $addr['cidade'] ?? null,
            'uf' => $addr['uf'] ?? null,
        ]);

        try {
            $sync->updateAddress($store, $customer);
        } catch (\Throwable $e) {
            // best-effort
        }

        return response()->json([
            'customer_id' => $customer->id,
            'shopify_customer_id' => $customer->shopify_customer_id,
        ]);
    }

    /**
     * Lista clientes da loja (com métricas de pedidos pagos/não pagos).
     * Filtros: search, status (paid|unpaid).
     */
    public function index(Request $request, string $storeId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);

        $query = $store->customers()->with(['orders' => function ($q) {
            $q->select('id', 'customer_id', 'amount', 'status', 'payment_method', 'created_at')
                ->latest();
        }]);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Filtro por status de pagamento
        if ($request->filled('status')) {
            $status = $request->status;

            if ($status === 'paid') {
                $query->whereHas('orders', function ($q) {
                    $q->where('status', Order::STATUS_PAID);
                });
            } elseif ($status === 'unpaid') {
                // Clientes sem nenhum pedido pago (mas podem ter pedidos outros status)
                $query->whereDoesntHave('orders', function ($q) {
                    $q->where('status', Order::STATUS_PAID);
                });
            }
        }

        $customers = $query->latest()->paginate($request->get('per_page', 15));

        // Adiciona campos computados.
        $customers->getCollection()->transform(function (Customer $customer) {
            $orders = $customer->orders ?? collect();
            $paidOrders = $orders->where('status', Order::STATUS_PAID);
            $customer->orders_count = $orders->count();
            $customer->paid_orders_count = $paidOrders->count();
            $customer->paid_total = (float) $paidOrders->sum('amount');
            $customer->paid = $paidOrders->isNotEmpty();
            return $customer;
        });

        return response()->json($customers);
    }

    /**
     * Detalhe de um cliente com todos os pedidos.
     */
    public function show(Request $request, string $storeId, string $customerId)
    {
        $store = $request->user()->stores()->findOrFail($storeId);
        $customer = $store->customers()->with(['orders.items.product:id,name,image_url,price'])->findOrFail($customerId);

        $paidOrders = $customer->orders->where('status', Order::STATUS_PAID);
        $customer->orders_count = $customer->orders->count();
        $customer->paid_orders_count = $paidOrders->count();
        $customer->paid_total = (float) $paidOrders->sum('amount');
        $customer->paid = $paidOrders->isNotEmpty();

        return response()->json($customer);
    }

    /**
     * Cria ou atualiza o customer local.
     *
     * @param array<string,mixed> $validated
     */
    private function upsertCustomer(Store $store, array $validated): Customer
    {
        $data = [
            'store_id' => $store->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'document' => $validated['document'] ?? null,
        ];

        if (!empty($validated['address']) && !empty($validated['address']['cep'])) {
            $addr = $validated['address'];
            $data['zip'] = $addr['cep'] ?? null;
            $data['street'] = $addr['logradouro'] ?? null;
            $data['number'] = $addr['numero'] ?? null;
            $data['complement'] = $addr['complemento'] ?? null;
            $data['district'] = $addr['bairro'] ?? null;
            $data['city'] = $addr['cidade'] ?? null;
            $data['uf'] = $addr['uf'] ?? null;
        }

        return $store->customers()->updateOrCreate(
            ['email' => $validated['email']],
            $data
        );
    }
}