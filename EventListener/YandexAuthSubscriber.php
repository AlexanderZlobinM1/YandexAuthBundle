<?php

namespace MauticPlugin\YandexAuthBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Event\AuthenticationEvent;
use Mautic\UserBundle\UserEvents;
use MauticPlugin\YandexAuthBundle\Helper\YandexOAuthClient;
use MauticPlugin\YandexAuthBundle\Integration\YandexAuthIntegration;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class YandexAuthSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private IntegrationHelper $integrationHelper,
        private RouterInterface $router,
        private TranslatorInterface $translator,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private YandexOAuthClient $yandexClient,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserEvents::USER_PRE_AUTHENTICATION => ['onUserPreAuthentication', 0],
            KernelEvents::RESPONSE              => ['onKernelResponse', 0],
        ];
    }

    public function onUserPreAuthentication(AuthenticationEvent $event): void
    {
        if (YandexAuthIntegration::NAME !== (string) $event->getAuthenticatingService()) {
            return;
        }

        $integration = $this->getReadyIntegration();
        if (!$integration instanceof YandexAuthIntegration) {
            $this->fail($event, 'mautic.integration.yandexauth.misconfigured');

            return;
        }

        if (!$event->isLoginCheck()) {
            $event->setResponse(new RedirectResponse($this->generateAuthorizationUrl($event, $integration)));

            return;
        }

        $request = $event->getRequest();
        if ($request->query->has('error')) {
            $this->logger->info('Yandex Auth returned error: '.(string) $request->query->get('error'));
            $this->fail($event, 'mautic.integration.yandexauth.access_denied');

            return;
        }

        $code          = trim((string) $request->query->get('code', ''));
        $state         = trim((string) $request->query->get('state', ''));
        $expectedState = trim((string) $request->getSession()->get('yandexauth_state', ''));
        $codeVerifier  = trim((string) $request->getSession()->get('yandexauth_code_verifier', ''));
        $request->getSession()->remove('yandexauth_state');
        $request->getSession()->remove('yandexauth_code_verifier');

        if ('' === $code || '' === $state || '' === $expectedState || '' === $codeVerifier || !hash_equals($expectedState, $state)) {
            $this->fail($event, 'mautic.integration.yandexauth.invalid_state');

            return;
        }

        try {
            $token = $this->yandexClient->exchangeCode(
                $code,
                $integration->getClientId(),
                $integration->getAuthCheckUrl(),
                $codeVerifier
            );

            $profile = $this->yandexClient->fetchUserInfo(
                (string) $token['access_token'],
                $integration->getClientId(),
                $integration->getAllowedDomain()
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Yandex Auth token exchange or profile lookup failed: '.$e->getMessage());
            $this->fail($event, $e->getMessage());

            return;
        }

        $email = strtolower(trim($profile['email']));
        if ('' === $email) {
            $this->fail($event, 'mautic.integration.yandexauth.email_missing');

            return;
        }

        try {
            $user = $this->loadUserByEmail($event, $email);
        } catch (\Throwable $e) {
            $this->logger->info('Yandex Auth user lookup failed for '.$email.': '.$e->getMessage());
            $this->fail($event, 'mautic.integration.yandexauth.not_authorized');

            return;
        }

        $mauticEmail = strtolower(trim((string) $user->getEmail()));
        if (!hash_equals($email, $mauticEmail)) {
            $this->fail($event, 'mautic.integration.yandexauth.email_mismatch');

            return;
        }

        $event->setIsAuthenticated(YandexAuthIntegration::NAME, $user, false);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (method_exists($event, 'isMainRequest') && !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ('login' !== (string) $request->attributes->get('_route') || $request->isXmlHttpRequest()) {
            return;
        }

        $integration = $this->getReadyIntegration();
        if (!$integration instanceof YandexAuthIntegration || !$integration->shouldShowLoginButton()) {
            return;
        }

        $response = $event->getResponse();
        if (200 !== $response->getStatusCode()) {
            return;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');
        if ('' !== $contentType && false === stripos($contentType, 'html')) {
            return;
        }

        $content = (string) $response->getContent();
        if ('' === $content || false !== strpos($content, 'yandexauth-login-block')) {
            return;
        }

        $content = $this->removeStandardSsoLink($content);
        $block   = $this->renderButtonBlock();
        $pos     = stripos($content, '</form>');
        if (false === $pos) {
            $content = str_ireplace('</body>', $block.'</body>', $content);
        } else {
            $content = substr_replace($content, '</form>'.$block, $pos, 7);
        }

        $response->setContent($content);
    }

    private function getReadyIntegration(): ?YandexAuthIntegration
    {
        $integration = $this->integrationHelper->getIntegrationObject(YandexAuthIntegration::NAME);
        if (!$integration instanceof YandexAuthIntegration) {
            return null;
        }

        $settings  = $integration->getIntegrationSettings();
        $published = false;
        if (is_object($settings)) {
            if (method_exists($settings, 'isPublished')) {
                $published = (bool) $settings->isPublished();
            } elseif (method_exists($settings, 'getIsPublished')) {
                $published = (bool) $settings->getIsPublished();
            }
        }

        if (!$published || !$integration->isConfigured()) {
            return null;
        }

        $features = method_exists($settings, 'getSupportedFeatures') ? (array) $settings->getSupportedFeatures() : [];
        if (!in_array('sso_service', $features, true)) {
            return null;
        }

        return $integration;
    }

    private function generateAuthorizationUrl(AuthenticationEvent $event, YandexAuthIntegration $integration): string
    {
        $state        = bin2hex(random_bytes(16));
        $codeVerifier = $this->base64UrlEncode(random_bytes(48));
        $event->getRequest()->getSession()->set('yandexauth_state', $state);
        $event->getRequest()->getSession()->set('yandexauth_code_verifier', $codeVerifier);

        $params = [
            'response_type'         => 'code',
            'client_id'             => $integration->getClientId(),
            'redirect_uri'          => $integration->getAuthCheckUrl(),
            'scope'                 => $integration->getDefaultScope(),
            'state'                 => $state,
            'code_challenge'        => $this->base64UrlEncode(hash('sha256', $codeVerifier, true)),
            'code_challenge_method' => 'S256',
        ];

        return 'https://oauth.yandex.com/authorize?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function loadUserByEmail(AuthenticationEvent $event, string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            throw new \RuntimeException('Mautic user not found');
        }

        $identifier = $this->getUserIdentifier($user, $email);
        $provider   = $event->getUserProvider();
        if (method_exists($provider, 'loadUserByIdentifier')) {
            $providerUser = $provider->loadUserByIdentifier($identifier);
        } else {
            $providerUser = $provider->loadUserByUsername($identifier);
        }

        if (!$providerUser instanceof User || !$this->isSameUser($user, $providerUser)) {
            throw new \RuntimeException('Mautic user not found');
        }

        return $providerUser;
    }

    private function getUserIdentifier(User $user, string $fallback): string
    {
        foreach (['getUserIdentifier', 'getUsername'] as $method) {
            if (method_exists($user, $method)) {
                $identifier = trim((string) $user->{$method}());
                if ('' !== $identifier) {
                    return $identifier;
                }
            }
        }

        return $fallback;
    }

    private function isSameUser(User $expected, User $actual): bool
    {
        if (method_exists($expected, 'getId') && method_exists($actual, 'getId')) {
            return (string) $expected->getId() === (string) $actual->getId();
        }

        return hash_equals(
            strtolower(trim((string) $expected->getEmail())),
            strtolower(trim((string) $actual->getEmail()))
        );
    }

    private function fail(AuthenticationEvent $event, string $message): void
    {
        $translated = $this->translator->trans($message);
        $event->setFailedAuthenticationMessage($translated);

        $request = $event->getRequest();
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add('error', $translated);
        }

        $event->setResponse(new RedirectResponse($this->router->generate('login')));
    }

    private function renderButtonBlock(): string
    {
        $startUrl = $this->router->generate('mautic_sso_login', ['integration' => YandexAuthIntegration::NAME]);
        $label    = $this->translator->trans('mautic.integration.yandexauth.login_button');

        return '<style>.yandexauth-login-block{clear:both;margin:14px 0 0}.yandexauth-sep{font-size:11px;color:#6b7280;text-align:center;margin:10px 0}.yandexauth-button{box-sizing:border-box;display:flex;align-items:center;justify-content:center;gap:10px;width:100%;min-height:42px;padding:10px 14px;border:1px solid #111827;border-radius:6px;background:#111827;color:#fff;font-size:14px;font-weight:600;text-decoration:none}.yandexauth-button:hover,.yandexauth-button:focus{background:#000;color:#fff;text-decoration:none}.yandexauth-mark{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#fc3f1d;color:#fff;font-weight:700;font-family:Arial,sans-serif}</style>'.
            '<div class="yandexauth-login-block">'.
            '<div class="yandexauth-sep">or</div>'.
            '<a class="yandexauth-button" href="'.$this->e($startUrl).'"><span class="yandexauth-mark">Я</span><span>'.$this->e($label).'</span></a>'.
            '</div>';
    }

    private function removeStandardSsoLink(string $content): string
    {
        $path    = $this->router->generate('mautic_sso_login', ['integration' => YandexAuthIntegration::NAME]);
        $pattern = '#\s*<a\s+href="'.preg_quote($path, '#').'"[^>]*>.*?</a>#is';
        $updated = preg_replace($pattern, '', $content, 1);

        return is_string($updated) ? $updated : $content;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
