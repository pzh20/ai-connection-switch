=== AI Connection Switch ===
Contributors: hhhconsulting
Tags: security, ai, mcp, application passwords, hardening
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps AI / MCP connector plugins switched off unless you are working with them.

== Description ==

AI assistants can now work on a WordPress site through connector plugins (MCP servers such as Novamira and others). Many of these can read the database, change settings or even run code. That makes the connector the most powerful way into your site.

AI Connection Switch makes **"off unless I'm working"** the default:

* **On when you log in.** When one of the administrators you choose logs in and reaches the dashboard (so after any two-factor step), or opens the dashboard while already logged in ("Remember Me"), the connector plugins are activated.
* **Off when you log out.** Logging out deactivates them again.
* **Off when idle.** If there has been no AI or dashboard activity for a set time (default 2 hours), they are deactivated automatically. This covers the usual case where you stay logged in for days with "Remember Me".
* **One-click switch.** An "AI: ON / OFF" switch in the admin bar, shown only to the administrators you choose.
* **Audit trail.** The settings page lists the last 50 switch events: who, when and why.

While the connector is off, its endpoints do not exist, so a leaked application password or a flaw in the connector cannot be used against the site.

Switching off uses WordPress's normal plugin deactivation, so the connector keeps its settings, licence and your application password. Switching on again needs no reconnecting.

= What counts as AI activity =

* Any successful **application password** login by one of the chosen administrators. Most MCP connectors authenticate this way.
* Any REST request whose route starts with one of the prefixes you list (default: `novamira, mcp`), **made while logged in as one of the chosen administrators**. Requests from anyone else (including visitors who aren't logged in) don't count, so they can't keep the connector on.
* Any dashboard page opened by one of the chosen administrators.

Your AI connector must sign in as one of the chosen administrators for its activity to count. If it uses a different WordPress user, tick that user in the settings, or the connector will switch off after the idle time while the AI is still working.

== Disclaimer ==

AI Connection Switch is shared free, as-is, with no warranty of any kind. It was built for my own site and I'm sharing it in case it helps others. Use it at your own risk.

It reduces risk by keeping AI connector plugins switched off when you're not using them. It is **not** a complete security solution and doesn't replace good passwords, two-factor authentication, updates, a firewall or backups.

Before using it, **back up your site**, test it on a staging copy first, and check that nothing on your site depends on the connector plugin being active.

I'm not affiliated with Novamira or any other connector it works with. Support is best-effort only, and I can't promise fixes or updates.

Licensed under the GPL v2 or later.

== Installation ==

1. Upload the plugin and activate it.
2. Go to **Settings ▸ AI Connection Switch**.
3. Tick the connector plugin(s) to control, e.g. Novamira **and** Novamira Pro.
4. Tick the administrator(s) in control.
5. Check the automatic options and the idle time, then **Save**.

== Before you switch a connector off: check nothing else depends on it ==

Some connectors can load your own code from a "sandbox" folder (Novamira loads PHP files from `wp-content/novamira-sandbox/` on every request). If your site relies on code there, it will stop working when the connector is off. Move that code into your theme, a snippets plugin or a small plugin of its own first.

== Frequently Asked Questions ==

= My AI assistant says it can't connect =

The connector is switched off. Click **AI: OFF** in the admin bar, or just open the dashboard, to switch it on.

If the AI app connects by OAuth (for example a claude.ai connector) and tried while the connector was off, it may say it "couldn't register with the sign-in service", because the sign-in endpoints only exist while the connector is on. Switch it on, then reconnect in the AI app. This plugin can open the site's side of the connection, but it cannot reconnect the AI app for you.

= Does it work on multisite? =

It is designed for single sites. On multisite it switches plugins on the current site only and does not handle network-activated connectors.

= Will it switch off while the AI is in the middle of a task? =

Only if nothing has happened for the whole idle period. Every request from the AI resets the timer.

= Is the idle check reliable on quiet sites? =

It runs every five minutes through WP-Cron, which needs some traffic, and also whenever an administrator loads a dashboard page. For exact timing, use a real server cron to call `wp-cron.php`.

== Changelog ==

= 1.0.3 =
* SECURITY: logging in no longer switches the connector on by itself. WordPress reports a login before two-factor plugins (such as WP 2FA) ask for the code, so a correct password alone used to switch the connector on. Now the first dashboard page after logging in switches it on. If your login takes you straight to the dashboard you won't notice a difference; if it takes you to the front of your site, open any dashboard page (or use the admin-bar switch).
* SECURITY: only requests made while logged in as one of the chosen administrators count as AI activity. Before, any request to a matching route (even from a visitor who wasn't logged in) reset the idle timer, so anyone could keep the connector switched on indefinitely. If your AI connector signs in as a different user, tick that user in the settings.

= 1.0.2 =
* FIX: with "Remember Me" you could come back without logging in again, so "switch on when one of them logs in" never fired after an idle switch-off. Opening any dashboard page now switches the connector on, and working in the dashboard counts as activity. After switching off by hand with the admin-bar switch it stays off until you switch it on or log in again.

= 1.0.1 =
* FIX: switching off from the admin bar while on the connector's own admin page showed "Sorry, you are not allowed to access this page", because that page no longer exists once the connector is off. The switch now goes to its settings page in that case. The switch itself always worked.

= 1.0.0 =
* First release: switch on at log-in, off at log-out, off after idle time, admin-bar switch, audit log.
