<?php

namespace MauticPlugin\YandexAuthBundle\Integration;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class YandexAuthIntegration extends AbstractIntegration
{
    public const NAME = 'YandexAuth';

    public const CLIENT_ID_FIELD = 'client_id';

    public const ALLOWED_DOMAIN_FIELD = 'allowed_domain';

    public const SHOW_LOGIN_BUTTON_FIELD = 'show_login_button';

    public function getName()
    {
        return self::NAME;
    }

    public function getDisplayName()
    {
        return 'Yandex Auth';
    }

    public function getDescription()
    {
        return '';
    }

    public function getAuthenticationType()
    {
        return 'none';
    }

    public function getIcon()
    {
        return 'plugins/YandexAuthBundle/Assets/img/yandexauth.svg';
    }

    public function getPriority()
    {
        return 10;
    }

    public function getSupportedFeatures()
    {
        return [
            'sso_service',
        ];
    }

    public function getRequiredKeyFields()
    {
        return [
            self::CLIENT_ID_FIELD => 'mautic.integration.yandexauth.client_id',
        ];
    }

    /**
     * @param FormBuilder|Form $builder
     * @param array            $data
     * @param string           $formArea
     */
    public function appendToForm(&$builder, $data, $formArea): void
    {
        if ('keys' !== $formArea) {
            return;
        }

        $builder
            ->add(
                self::ALLOWED_DOMAIN_FIELD,
                TextType::class,
                [
                    'label'    => 'mautic.integration.yandexauth.allowed_domain',
                    'required' => false,
                    'data'     => $data[self::ALLOWED_DOMAIN_FIELD] ?? '',
                    'attr'     => [
                        'class'   => 'form-control',
                        'tooltip' => 'mautic.integration.yandexauth.allowed_domain.tooltip',
                    ],
                ]
            )
            ->add(
                self::SHOW_LOGIN_BUTTON_FIELD,
                YesNoButtonGroupType::class,
                [
                    'label' => 'mautic.integration.yandexauth.show_login_button',
                    'data'  => array_key_exists(self::SHOW_LOGIN_BUTTON_FIELD, $data)
                        ? (bool) $data[self::SHOW_LOGIN_BUTTON_FIELD]
                        : true,
                    'attr'  => [
                        'tooltip' => 'mautic.integration.yandexauth.show_login_button.tooltip',
                    ],
                ]
            );
    }

    public function getClientId(): string
    {
        return trim((string) ($this->keys[self::CLIENT_ID_FIELD] ?? ''));
    }

    public function getAllowedDomain(): string
    {
        return strtolower(trim((string) ($this->keys[self::ALLOWED_DOMAIN_FIELD] ?? '')));
    }

    public function shouldShowLoginButton(): bool
    {
        if (!array_key_exists(self::SHOW_LOGIN_BUTTON_FIELD, $this->keys)) {
            return true;
        }

        return (bool) $this->keys[self::SHOW_LOGIN_BUTTON_FIELD];
    }

    public function getAuthCheckUrl(): string
    {
        return $this->router->generate(
            'mautic_sso_login_check',
            ['integration' => self::NAME],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    public function getAuthStartUrl(): string
    {
        return $this->router->generate(
            'mautic_sso_login',
            ['integration' => self::NAME],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    public function getDefaultScope(): string
    {
        return 'login:email';
    }

    public function getFormNotes($section)
    {
        if ('custom' === $section) {
            return [
                'custom'     => true,
                'template'   => '@YandexAuth/Integration/form.html.twig',
                'parameters' => [
                    'redirect_uri' => $this->getAuthCheckUrl(),
                    'scope'        => $this->getDefaultScope(),
                    'start_url'    => $this->getAuthStartUrl(),
                ],
            ];
        }

        return parent::getFormNotes($section);
    }
}
