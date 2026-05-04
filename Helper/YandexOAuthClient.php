<?php

namespace MauticPlugin\YandexAuthBundle\Helper;

class YandexOAuthClient
{
    private const TOKEN_URL = 'https://oauth.yandex.com/token';

    private const USERINFO_URL = 'https://login.yandex.ru/info?format=json';

    /**
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code, string $clientId, string $redirectUri, string $codeVerifier): array
    {
        $payload = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'code_verifier' => $codeVerifier,
        ];

        $data = $this->request(
            'POST',
            self::TOKEN_URL,
            [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            http_build_query($payload, '', '&', PHP_QUERY_RFC3986)
        );

        if (empty($data['access_token'])) {
            throw new \RuntimeException('mautic.integration.yandexauth.invalid_token');
        }

        return $data;
    }

    /**
     * @return array{email: string, raw: array<string, mixed>}
     */
    public function fetchUserInfo(string $accessToken, string $clientId, string $allowedDomain = ''): array
    {
        $data = $this->request(
            'GET',
            self::USERINFO_URL,
            [
                'Accept: application/json',
                'Authorization: OAuth '.$accessToken,
            ]
        );

        $tokenClientId = trim((string) ($data['client_id'] ?? ''));
        if ('' !== $tokenClientId && !hash_equals(trim($clientId), $tokenClientId)) {
            throw new \RuntimeException('mautic.integration.yandexauth.invalid_token');
        }

        $email = strtolower(trim((string) ($data['default_email'] ?? '')));
        if ('' === $email && isset($data['emails']) && is_array($data['emails'])) {
            foreach ($data['emails'] as $candidate) {
                $candidate = strtolower(trim((string) $candidate));
                if ('' !== $candidate) {
                    $email = $candidate;
                    break;
                }
            }
        }

        if ('' === $email || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('mautic.integration.yandexauth.email_missing');
        }

        $domain = strtolower(trim($allowedDomain));
        if ('' !== $domain) {
            $emailDomain = strtolower(substr(strrchr($email, '@') ?: '', 1));
            if (!hash_equals($domain, $emailDomain)) {
                throw new \RuntimeException('mautic.integration.yandexauth.domain_denied');
            }
        }

        return [
            'email' => $email,
            'raw'   => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $headers[] = 'User-Agent: mautic-yandex-auth/1.0';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            if ('POST' === $method) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $body);
            }
            $response = curl_exec($ch);
            $code     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if (is_string($response) && $code >= 200 && $code < 300) {
                return $this->jsonDecode($response);
            }

            throw new \RuntimeException('mautic.integration.yandexauth.invalid_token');
        }

        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers)."\r\n",
                'content'       => 'POST' === $method ? (string) $body : '',
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if (!is_string($response) || '' === $response) {
            throw new \RuntimeException('mautic.integration.yandexauth.invalid_token');
        }

        return $this->jsonDecode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonDecode(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('mautic.integration.yandexauth.invalid_token');
        }

        return $decoded;
    }
}
