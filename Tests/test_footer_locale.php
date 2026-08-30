<?php

declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require dirname(__DIR__).'/vendor/autoload.php';

$loader = new FilesystemLoader(dirname(__DIR__).'/Resources/views/Integration');
$twig   = new Environment($loader);

$formTemplate = file_get_contents(dirname(__DIR__).'/Resources/views/Integration/form.html.twig');
if (false === $formTemplate || !str_contains($formTemplate, "@YandexAuth/Integration/footer.html.twig")) {
    fwrite(STDERR, "Yandex Auth form does not include the locale-aware footer\n");
    exit(1);
}

$cases = [
    'ru'    => 'sales-snap.ru',
    'ru_RU' => 'sales-snap.ru',
    'en_US' => 'sales-snap.com',
    'sr_RS' => 'sales-snap.com',
];

foreach ($cases as $locale => $expectedDomain) {
    $output = $twig->render('footer.html.twig', [
        'app' => ['request' => ['locale' => $locale]],
    ]);

    if (!str_contains($output, 'https://'.$expectedDomain)
        || !str_contains($output, '>'.$expectedDomain.'</a>')) {
        fwrite(STDERR, sprintf("Footer locale test failed for %s\n", $locale));
        exit(1);
    }
}

$separatorCases = [
    'en_US' => 'or',
    'ru'    => 'или',
];

foreach ($separatorCases as $locale => $expectedSeparator) {
    $translations = parse_ini_file(dirname(__DIR__).'/Translations/'.$locale.'/messages.ini');
    if (!is_array($translations)
        || ($translations['mautic.integration.yandexauth.login_separator'] ?? null) !== $expectedSeparator) {
        fwrite(STDERR, sprintf("Login separator translation test failed for %s\n", $locale));
        exit(1);
    }
}

echo "Footer locale tests passed\n";
