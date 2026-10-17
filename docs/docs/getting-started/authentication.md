---
title: Authentication
description: Sign in with email and password, Passkey, or OpenID Connect.
---

# Authentication

Email and password authentication is enabled by default, while passkeys and OpenID Connect are optional.

## Passkeys

Passkeys stay off until `PASSKEYS_ENABLED=true`. See [Environment variables](/getting-started/environment-variables).

Browsers offer passkeys only in a secure context: HTTPS, or localhost. On an insecure origin the sign-in control stays hidden.

When passkeys are enabled and the browser supports them, the login page shows **Sign in with a passkey** above the email form, then **Or continue with email**.

### Register a passkey

Sign in, then open **Settings → Password & 2FA → Passkeys**. Name the passkey and choose **Register passkey**. The list shows the name, authenticator, when it was added, and when it was last used. Remove a passkey from that list.

A browser that does not support passkeys shows **Passkeys are not supported in this browser.**

Opening **Password & 2FA** asks you to confirm. With passkeys enabled, that page offers **Confirm with passkey**.

## OpenID Connect

OIDC uses the authorization-code flow. LaraPaper loads the provider’s `/.well-known/openid-configuration` document and follows the authorization, token, and userinfo endpoints from that document. Any provider that implements [OpenID Connect Core 1.0](https://openid.net/specs/openid-connect-core-1_0.html) can be used.

### Configure the provider

Register LaraPaper as a confidential client and set the redirect URI to `{APP_URL}/auth/oidc/callback`.

`APP_URL` must be the public URL of this instance. The callback is built from it and is not a separate environment variable. Example: if `APP_URL` is `https://larapaper.example.com`, the redirect URI is `https://larapaper.example.com/auth/oidc/callback`.

Request the `openid`, `profile`, and `email` scopes, or set a different list with `OIDC_SCOPES`.

### Environment

```bash
OIDC_ENABLED=true
OIDC_ENDPOINT=https://your-provider.example.com
OIDC_CLIENT_ID=your-client-id
OIDC_CLIENT_SECRET=your-client-secret
# Optional. Defaults to openid,profile,email
OIDC_SCOPES=openid,profile,email
```

`OIDC_ENDPOINT` accepts either the provider base URL or the full well-known URL (`https://your-provider.example.com/.well-known/openid-configuration`). A base URL has `/.well-known/openid-configuration` appended.

See [Environment variables](/getting-started/environment-variables#openid-connect) for defaults.

### Sign in

With `OIDC_ENABLED=true` and a client ID, secret, and endpoint set, the login page shows **Continue with OIDC**. That button starts the flow at `/auth/oidc/redirect`. The provider sends the browser back to `/auth/oidc/callback`.

### Accounts

On each successful login LaraPaper matches an account in this order:

1. An existing user with the same OIDC subject (`oidc_sub`). Name and email are updated from the provider when those claims are present.
2. Otherwise, an existing user with the same email. That account is linked by storing `oidc_sub`.
3. Otherwise, a new user is created. The email is treated as verified. The account gets a random password because sign-in goes through the provider. If the provider omits an email, the address `{subject}@oidc.local` is used.

Users linked to OIDC do not see **Password & 2FA** in Settings, so they cannot add passkeys there.

### Check the configuration

```bash
php artisan oidc:test
```

The command reports whether OIDC is enabled, whether the endpoint, client ID, and client secret are set, and the redirect URL it will use.
