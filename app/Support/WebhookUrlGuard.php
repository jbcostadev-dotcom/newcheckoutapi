<?php

namespace App\Support;

use RuntimeException;

class WebhookUrlGuard
{
    public static function isAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if ($host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host);
        }

        return str_contains($host, '.');
    }

    /**
     * Resolve o hostname antes da entrega e rejeita qualquer resposta DNS
     * privada ou reservada. O IP retornado pode ser fixado no cliente HTTP.
     */
    public static function resolvePublicAddress(string $url): string
    {
        if (! self::isAllowed($url)) {
            throw new RuntimeException('A URL do webhook não é pública.');
        }

        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        $addresses = gethostbynamel($host) ?: [];

        if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
            foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (! empty($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        $addresses = array_values(array_unique($addresses));
        if ($addresses === []) {
            throw new RuntimeException('Não foi possível resolver o domínio do webhook.');
        }

        foreach ($addresses as $address) {
            if (! self::isPublicIp($address)) {
                throw new RuntimeException('O domínio do webhook resolve para uma rede privada ou reservada.');
            }
        }

        return $addresses[0];
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
