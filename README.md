# YandexAuthBundle

Yandex sign-in plugin for Mautic 5, 6, and 7.

The plugin authenticates only existing Mautic users. A Yandex login succeeds when:

- the plugin is published and configured,
- Yandex returns a valid OAuth authorization code,
- the authorization code is exchanged with PKCE for a Yandex access token,
- Yandex ID returns an email for the authenticated account,
- the optional allowed email domain check passes,
- the Yandex email exactly matches an active Mautic user email.

No Mautic user is auto-created.

## Mautic setup

1. Install the bundle into `plugins/YandexAuthBundle`.
2. Run Mautic plugin discovery/install and clear cache.
3. Open **Settings -> Plugins -> Yandex Auth**.
4. Set **Yandex Client ID**.
5. Optionally set **Allowed email domain**, for example `example.com`.
6. Keep **Show Yandex button on login page** enabled if the login page should show the extra button.
7. Publish the plugin and save.

The plugin tile shows the exact callback URL and required Yandex OAuth scope.

## Yandex OAuth setup

This plugin uses the Yandex OAuth authorization code flow with PKCE. It stores only the public Client ID in Mautic and does not require a Client Secret in the plugin settings.

### Create or open a Yandex OAuth app

1. Open the Yandex OAuth application list:
   [oauth.yandex.ru/client/my](https://oauth.yandex.ru/client/my).
2. Create a new application, or open the existing application you want to use.
3. In the platform/application settings, enable the web application flow that allows redirects back to your Mautic domain.
4. In the redirect/callback URI field, add the callback URL shown in the Yandex Auth plugin tile. It will look like:

   ```text
   https://mautic.example.com/s/sso_login_check/YandexAuth
   ```

   Use the exact URL from the plugin tile. Do not replace it with `/s/login` and do not add query strings or fragments.

5. In permissions/scopes, allow access to the account email. The required scope shown by the plugin is:

   ```text
   login:email
   ```

6. Save the application.

### Copy the Client ID into Mautic

1. In the Yandex OAuth application settings, copy the application **Client ID**.
2. In Mautic, open **Settings -> Plugins -> Yandex Auth**.
3. Paste the value into **Yandex Client ID**.
4. Save and publish the plugin.
5. Clear Mautic cache if the login page still shows old settings.

### Optional domain restriction

Set **Allowed email domain** only when Yandex login should be limited to one mailbox domain, for example:

```text
example.com
```

Leave it empty to allow any Yandex account whose email exactly matches an existing Mautic user.

## What happens during login

- The Mautic login page shows a **Sign in with Yandex** button.
- The button starts Mautic SSO for `YandexAuth`.
- Mautic redirects the user to Yandex OAuth with a PKCE challenge.
- Yandex redirects back to the callback URL with an authorization code.
- Mautic exchanges the code for a Yandex access token.
- Mautic requests Yandex ID profile data and reads `default_email` or the first email from `emails`.
- If that email exactly matches an existing active Mautic user email, the user is signed in.

## Troubleshooting

- Redirect URI mismatch: add the exact callback URL from the plugin tile to the Yandex OAuth application.
- Email is missing: make sure the application has the `login:email` scope and the Yandex account has an email available to Yandex ID.
- Login returns to the Mautic login page: check that the Yandex account email exactly matches an active Mautic user email.
- Domain denied: clear **Allowed email domain** or set it to the mailbox domain used by the Yandex account.
- Token verification failed: confirm that the Client ID in Mautic belongs to the same Yandex OAuth application used for the login.

## References

- [Yandex ID authorization code flow with PKCE](https://yandex.ru/dev/id/doc/en/codes/code-url)
- [Yandex ID user information request](https://yandex.ru/dev/id/doc/en/user-information)
