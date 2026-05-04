<?php

return [
    'name'        => 'Yandex Auth',
    'description' => 'Yandex sign-in for existing Mautic users matched by email.',
    'version'     => '1.0.3',
    'author'      => 'Sales Snap',
    'services'    => [
        'events' => [
            'plugin.yandexauth.auth_subscriber' => [
                'class'     => MauticPlugin\YandexAuthBundle\EventListener\YandexAuthSubscriber::class,
                'arguments' => [
                    'mautic.helper.integration',
                    'router',
                    'translator',
                    'doctrine.orm.entity_manager',
                    'logger',
                ],
            ],
        ],
        'integrations' => [
            'mautic.integration.yandexauth' => [
                'class'     => MauticPlugin\YandexAuthBundle\Integration\YandexAuthIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'request_stack',
                    'router',
                    'translator',
                    'logger',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.lead.model.dnc',
                    'mautic.lead.field.fields_with_unique_identifier',
                ],
            ],
        ],
    ],
];
