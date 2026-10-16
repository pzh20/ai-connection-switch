# AI Connection Switch

A small WordPress plugin that keeps **AI / MCP connector plugins** (such as Novamira) **switched off unless you are working with them**.

Connector plugins let an AI assistant work on your site, and many can read the database, change settings or run code. That makes them the most powerful way into a site. While the connector is switched off, a leaked application password or a flaw in the connector can't be used against you.

## What it does

- **On when you log in.** When one of the administrators you choose logs in, the connector plugins are activated.
- **Off when you log out.**
- **Off when idle.** After a set time with no AI activity (default 2 hours), they're deactivated automatically. This covers staying logged in for days with "Remember Me".
- **One-click switch.** An **AI: ON / OFF** switch in the admin bar, shown only to the administrators you choose.
- **Audit trail.** The settings page lists the last 50 switch events: who, when and why.

Switching off uses WordPress's normal plugin deactivation, so the connector keeps its settings, licence and your application password. Switching on again needs no reconnecting.

## Download and install

1. Download **[ai-connection-switch-1.0.1.zip](dist/ai-connection-switch-1.0.1.zip)** (on that page, click **Download raw file**, the download arrow at the top right).
2. In WordPress: **Plugins ▸ Add New Plugin ▸ Upload Plugin**, choose the zip, then **Install Now** and **Activate**.
3. Go to **Settings ▸ AI Connection Switch**:
   - Tick the connector plugin(s), e.g. **Novamira** *and* **Novamira Pro**.
   - Tick the administrator(s) in control.
   - Choose the automatic options and idle time, then **Save**.

> **Before you switch a connector off, check nothing else depends on it.** Some connectors load your own code from a "sandbox" folder; Novamira loads PHP from `wp-content/novamira-sandbox/` on every request. If your site relies on code there, move it into your theme, a snippets plugin or a plugin of its own first.

## Disclaimer

AI Connection Switch is shared free, as-is, with no warranty of any kind. It was built for my own site and I'm sharing it in case it helps others. Use it at your own risk.

It reduces risk by keeping AI connector plugins switched off when you're not using them. It is **not** a complete security solution and doesn't replace good passwords, two-factor authentication, updates, a firewall or backups.

Before using it, **back up your site**, test it on a staging copy first, and check that nothing on your site depends on the connector plugin being active.

I'm not affiliated with Novamira or any other connector it works with. Support is best-effort only, and I can't promise fixes or updates.

## Requirements

WordPress 6.0+, PHP 7.4+. Designed for single sites; on multisite it switches plugins on the current site only and doesn't handle network-activated connectors.

## Licence

GPL v2 or later. See [LICENSE](LICENSE).
