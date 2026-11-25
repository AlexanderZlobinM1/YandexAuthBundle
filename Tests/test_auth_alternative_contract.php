<?php

declare(strict_types=1);

$subscriber = file_get_contents(dirname(__DIR__).'/EventListener/YandexAuthSubscriber.php');
$script     = file_get_contents(dirname(__DIR__).'/Resources/js/auth-alternative-coordinator.js');

if (false === $subscriber || false === $script) {
    fwrite(STDERR, "Auth alternative contract sources are missing\n");
    exit(1);
}

foreach ([
    'mautic-auth-alternative-button',
    'data-mautic-auth-alternative',
    'data-mautic-auth-alternative-block',
    'data-mautic-auth-separator-label',
    'auth-alternative-coordinator.js',
] as $required) {
    if (!str_contains($subscriber, $required)) {
        fwrite(STDERR, sprintf("PHP login output is missing %s\n", $required));
        exit(1);
    }
}

foreach ([
    '[data-mautic-auth-alternative-block="1"]',
    '[data-mautic-auth-alternative-separator="1"]',
    'MauticAuthAlternativeCoordinator',
    'MutationObserver',
] as $required) {
    if (!str_contains($script, $required)) {
        fwrite(STDERR, sprintf("Coordinator is missing %s\n", $required));
        exit(1);
    }
}

$translations = [
    'en_US' => ['or', 'Sign in with Yandex'],
    'ru'    => ['или', 'Войти через Яндекс'],
    'sr_RS' => ['ili', 'Prijava putem Yandex-a'],
];

foreach ($translations as $locale => [$separator, $button]) {
    $path = dirname(__DIR__).'/Translations/'.$locale.'/messages.ini';
    $data = is_file($path) ? parse_ini_file($path) : false;
    if (!is_array($data)
        || ($data['mautic.integration.yandexauth.login_separator'] ?? null) !== $separator
        || ($data['mautic.integration.yandexauth.login_button'] ?? null) !== $button) {
        fwrite(STDERR, sprintf("Auth alternative translation test failed for %s\n", $locale));
        exit(1);
    }
}

echo "Auth alternative contract tests passed\n";
