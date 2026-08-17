<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza itens de cliente (Customer) com a Shopify via Admin API REST.
 *
 * Requer os escopos `read_customers` e `write_customers` no app Shopify da loja.
 *
 * A Shopify separa Nome e Sobrenome, mas coletamos um único campo "nome completo"
 * no checkout. Conforme decisão de produto, o nome completo vai em `first_name`
 * e `last_name` fica vazio — que é o que recomendam ao não houver separação.
 */
class ShopifyCustomerSync
{
    protected string $apiVersion;

    public function __construct()
    {
        $this->apiVersion = (string) config('services.shopify.api_version', '2026-07');
    }

    /**
     * Cria (ou atualiza) o customer na Shopify e persiste shopify_customer_id.
     * Sincroniza também o endereço quando disponível.
     *
     * Best-effort: falhas são logadas e não bloqueiam o fluxo de checkout.
     */
    public function sync(Store $store, Customer $customer): ?string
    {
        if (!$store->isShopifyConnected()) {
            return null;
        }

        $payload = $this->buildPayload($customer);

        try {
            if ($customer->shopify_customer_id) {
                $endpoint = "https://{$store->shopify_domain}/admin/api/{$this->apiVersion}/customers/{$customer->shopify_customer_id}.json";
                $response = $this->request($store, 'PUT', $endpoint, ['customer' => $payload]);
            } else {
                // Tenta localizar por e-mail antes de criar para evitar duplicidade.
                $existingId = $this->findByEmail($store, $customer->email);

                if ($existingId) {
                    $endpoint = "https://{$store->shopify_domain}/admin/api/{$this->apiVersion}/customers/{$existingId}.json";
                    $response = $this->request($store, 'PUT', $endpoint, ['customer' => $payload]);
                } else {
                    $endpoint = "https://{$store->shopify_domain}/admin/api/{$this->apiVersion}/customers.json";
                    $response = $this->request($store, 'POST', $endpoint, ['customer' => $payload]);
                }
            }

            $id = $response['customer']['id'] ?? null;

            if ($id) {
                $customer->update(['shopify_customer_id' => (string) $id]);
                return (string) $id;
            }
        } catch (\Throwable $e) {
            Log::warning('Shopify customer sync falhou', [
                'store_id' => $store->id,
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Apenas atualiza o endereço do customer na Shopify.
     */
    public function updateAddress(Store $store, Customer $customer): void
    {
        if (!$store->isShopifyConnected()) {
            return;
        }

        if (!$customer->zip || !$customer->street) {
            return;
        }

        // Garante que o customer existe na Shopify primeiro.
        if (!$customer->shopify_customer_id) {
            $this->sync($store, $customer);
        }

        if (!$customer->shopify_customer_id) {
            return;
        }

        $address = $this->buildAddress($customer);

        try {
            $endpoint = "https://{$store->shopify_domain}/admin/api/{$this->apiVersion}/customers/{$customer->shopify_customer_id}/addresses.json";
            $response = $this->request($store, 'POST', $endpoint, ['address' => $address]);

            $addressId = $response['address']['id'] ?? null;

            // Define como endereço padrão quando recém-criado.
            if ($addressId) {
                $setDefaultEndpoint = "https://{$store->shopify_domain}/admin/api/{$this->apiVersion}/customers/{$customer->shopify_customer_id}/addresses/{$addressId}/default.json";
                $this->request($store, 'PUT', $setDefaultEndpoint);
            }
        } catch (\Throwable $e) {
            Log::warning('Shopify customer address sync falhou', [
                'store_id' => $store->id,
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Localiza um customer na Shopify pelo e-mail (busca exata).
     */
    protected function findByEmail(Store $store, string $email): ?string
    {
        $endpoint = "https://{$store->shopify_domain}/admin/api/{$this->apiVersion}/customers/search.json";

        try {
            $response = $this->request($store, 'GET', $endpoint, null, ['query' => 'email:' . $email]);

            $customers = $response['customers'] ?? [];

            return isset($customers[0]['id']) ? (string) $customers[0]['id'] : null;
        } catch (\Throwable $e) {
            Log::warning('Shopify customer search falhou', [
                'store_id' => $store->id,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Estrutura o payload do customer para a Shopify.
     *
     * @return array<string,mixed>
     */
    protected function buildPayload(Customer $customer): array
    {
        $payload = [
            'first_name' => $customer->name,
            'email' => $customer->email,
        ];

        $normalizedPhone = $customer->phone ? $this->normalizePhone($customer->phone) : null;
        if ($normalizedPhone) {
            $payload['phone'] = $normalizedPhone;
        }

        if ($customer->document) {
            $note = ($customer->person_type === 'company' ? 'CNPJ: ' : 'CPF: ') . $customer->document;
            if ($customer->person_type === 'company') {
                $stateRegistration = $customer->state_registration_exempt
                    ? 'Isento'
                    : ($customer->state_registration ?: 'Não informada');
                $note .= "\nInscrição Estadual: {$stateRegistration}";
            }
            $payload['note'] = $note;
        }

        // Endereço default — atualiza quando já houver dados completos.
        if ($customer->zip && $customer->street) {
            $payload['default_address'] = $this->buildAddress($customer);
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    protected function buildAddress(Customer $customer): array
    {
        $address = [
            'address1' => $customer->street,
            'address2' => $customer->complement,
            'number' => $customer->number,
            'city' => $customer->city,
            'province' => $customer->uf,
            'zip' => $customer->zip,
            'first_name' => $customer->name,
            'country' => 'BR',
        ];

        $normalizedPhone = $customer->phone ? $this->normalizePhone($customer->phone) : null;
        if ($normalizedPhone) {
            $address['phone'] = $normalizedPhone;
        }

        return $address;
    }

    /**
     * Normaliza o telefone para o formato E.164 esperado pela Shopify.
     *
     * Regras:
     *  - remove tudo que não for dígito;
     *  - remove o 0 inicial (prefixo de longa distância) quando presente;
     *  - adiciona o código do Brasil (55) quando não houver;
     *  - retorna null se o resultado não parecer um número válido.
     */
    protected function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (empty($digits)) {
            return null;
        }

        // Remove o 0 de longa distância, se existir.
        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        // Adiciona o código do Brasil caso ainda não esteja presente.
        if (!str_starts_with($digits, '55')) {
            $digits = '55' . $digits;
        }

        // E.164 válido para Brasil: +55 + 10 ou 11 dígitos.
        if (!preg_match('/^55\d{10,11}$/', $digits)) {
            return null;
        }

        return '+' . $digits;
    }

    /**
     * Wrapper de request com header de auth. Não traduz erros para evitar
     * acoplar com o injetor de tema — apenas loga e repassa.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE).
     * @param array<string,mixed>|null $body Corpo JSON.
     * @param array<string,mixed>|null $query Query params.
     * @return array<mixed>
     */
    protected function request(Store $store, string $method, string $endpoint, ?array $body = null, ?array $query = null): array
    {
        $client = Http::withHeaders([
            'X-Shopify-Access-Token' => $store->shopify_access_token,
        ]);

        if ($query) {
            $client = $client->withQueryParameters($query);
        }

        $response = $client->{strtolower($method)}($endpoint, $body ?? []);

        if (!$response->successful()) {
            Log::warning('Shopify Customer API erro', [
                'store_id' => $store->id,
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Shopify customer API falhou: ' . $response->status(), $response->status());
        }

        return $response->json() ?? [];
    }
}
