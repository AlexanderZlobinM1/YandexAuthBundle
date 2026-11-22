# Changelog

## Unreleased

- Publish the repository under the GNU General Public License v3 or later with Alexander Zlobin as the copyright holder.

## 1.1.4 — 2026-09-06

- Support Mautic 7.2 while retaining the declared older Mautic versions.
- Use a plugin-scoped EncryptionHelper service alias; keep legacy argument parsing and the global core container unchanged.
- Add a fresh-kernel regression check that instantiates integration services and resolves form types.
- Preserve the Mautic 5 session constructor argument and correctly recognize two-digit patch versions such as 5.2.10.


## 1.1.3 - 2026-08-30

- Made the Sales Snap footer locale-aware: Russian Mautic locales use
  `sales-snap.ru`, while every other locale uses `sales-snap.com`.
- Localized the login-page separator instead of always rendering English
  `or`.
- Added an executable regression test for the footer domain selection.

