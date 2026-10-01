=== Admin Access Profiles – Restrict wp-admin by Role ===
Contributors: mehdibouchami
Donate link: https://ba9chich.com/fr/mehdibouchami
Tags: user role, restrict admin, access control, agency access, admin menu
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.0.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Give agencies, freelancers and staff their own wp-admin access without making them administrators. Lock whole plugins, open extra access on demand.

== Description ==

Your marketing agency needs "admin access" to add a pixel. Your freelancer needs to edit landing pages. Your support person needs sales numbers. None of them should see your orders, customers, courses or snippet code.

Admin Access Profiles lets you create as many access profiles as you need. Each profile is a user role with its own limits:

* **Protection packs** – lock a whole plugin in one click: its admin screens, content, REST API, AJAX actions and settings. Included: LearnDash, WooCommerce, GrassBlade xAPI and code snippet plugins (Code Snippets, WPCode…). Packs match by pattern, so they keep working across plugin versions (for example classic and HPOS WooCommerce orders).
* **Switches with expiry** – open posts & pages, menus & Customizer, themes, plugin settings pages, plugin installation or read-only WooCommerce sales reports for 1 hour, 24 hours, 7 days, 30 days or unlimited. Access switches itself off when the time is up.
* **Menu access** – choose exactly which admin menus a profile sees. Menus added by newly installed plugins stay hidden until you allow them (or let the profile see every menu its role can use).
* **Based on a role** – start a profile from Editor, Shop manager or any role, then take away what it must not touch.
* **Tracking Scripts** – a safe head / body / footer box for Meta Pixel, Google tag or Tag Manager. HTML/JavaScript only, never PHP, with version history and one-click restore.
* **Activity log** – see what each profile user viewed, changed, installed, and every attempt that was blocked.
* **Built-in safety** – profile users can never manage users or roles, edit plugin/theme files, update WordPress core, use privacy export/erase tools, change the admin email or default role, or deactivate this plugin.

Presets: Marketing / ads agency, SEO specialist, Content writer, Shop manager without LMS.

== Installation ==

1. Upload the plugin and activate it.
2. Open **Access Profiles** and create a profile (pick a preset).
3. Add a user and give them the profile's role.
4. Log in as that user in a private window to check what they see.

== Frequently Asked Questions ==

= The agency needs a page I blocked. What do I do? =
Check **Access Profiles → Activity**: every blocked attempt is logged with the page name. Allow the menu on the profile, or add the page under "Extra hidden pages".

= I installed a new plugin but it is not in the menu list =
The list is recorded while an owner browses wp-admin. Load any admin page once and the new menu appears, marked NEW.

= Is "Install & activate plugins" safe? =
Any plugin can run code with full power, so a profile that can install plugins could get around restrictions. Turn it on for a short time and check the Activity log afterwards.

= Does it work on multisite? =
Not tested on multisite. It is designed for single sites.

== Changelog ==

= 1.0.4 =
* Menu access hints no longer list plugin capabilities the user already receives from that plugin.

= 1.0.3 =
* Clearer Menu access hints: plugin permissions tied to "Plugin settings pages", and pages a plugin hides until its own setup or sign-in.

= 1.0.2 =
* Menu access marks menus the profile cannot open yet and names the switch or capability they need.
* Capabilities that other plugins grant on the fly (for example Site Kit) are no longer removed, unless a locked pack forbids them.

= 1.0.1 =
* Switches now have the final say over their area, even for profiles based on a role (Off removes the access).

= 1.0.0 =
* First release.
