---
title: Multi-user mode
description: Run LaraPaper with multiple users, admin approval, and per-user device and plugin ownership.
---

# Multi-user mode

Multi-user mode lets multiple people share a single LaraPaper instance. Each user owns their own devices and plugins, admins oversee the whole installation.

## Enabling

Set `MULTI_USER_MODE=true` in your environment (or set `REGISTRATION_ENABLED=1`, which implies multi-user mode).

```env
MULTI_USER_MODE=true
```

When disabled (default for single-user installs), all access controls and confirmation flows are bypassed.

## Roles

| Role | How assigned |
|---|---|
| **Primary admin** | The first registered user (ID = 1). Cannot be deleted or demoted. |
| **Admin** | Any user promoted via the User Management page. |
| **User** | Everyone else. Must be confirmed by an admin before they can log in. |

## Account confirmation

New registrations are **pending** until an admin confirms them. A pending user who tries to log in is logged out with the message _"Your account is awaiting admin approval."_

Admins manage accounts at **Settings → User Management**:

- **Confirm** — grants access
- **Revoke** — removes access without deleting the account
- **Promote to admin** — grants admin privileges
- **Remove admin** — demotes to regular user (primary admin is protected)
- **Delete** — permanently removes the account

## Device ownership

- **Unowned devices** are visible to all users and can be claimed by anyone.
- Each device can be assigned to one user. Owners (and admins) can configure and delete their devices.
- Admins see a **Show all devices** toggle on the Devices page to view every device across all users.
- Admins can **reassign** a device to a different user from the device detail page.

## Plugin (recipe) ownership

- Users see only their own plugins by default.
- Admins see a **Show all plugins** toggle to view plugins across all users.
- Admins can **reassign** a plugin to a different user from the plugin detail page.

### Shared plugins

Any user can share their own recipe plugins. Shared plugins appear in the **Shared** tab for all users, who can then **copy** them to their own account.

## Template language restrictions

Blade templates execute arbitrary PHP and are unsafe for untrusted users. In multi-user mode, **only admins can use Blade**; regular users are limited to **Liquid** (sandboxed).

This applies to:
- The template language selector in the recipe editor
- The **Plugins → Markup** page
- Recipe rendering at screen generation time

To allow non-admins to use Blade (e.g. on a trusted private instance), set:

```env
MULTI_USER_DANGEROUSLY_ALLOW_BLADE_TEMPLATES_FOR_NON_ADMINS=true
```

::: warning
Enabling this gives non-admin users the ability to execute arbitrary PHP on the server. Only use it when all users are trusted.
:::

## Environment variables

| Variable | Description | Default |
|---|---|---|
| `MULTI_USER_MODE` | Enable multi-user mode | `REGISTRATION_ENABLED` |
| `MULTI_USER_DANGEROUSLY_ALLOW_BLADE_TEMPLATES_FOR_NON_ADMINS` | Allow non-admins to use Blade templates | `false` |
