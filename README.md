# Admin Access Profiles

Give agencies, freelancers and staff their own wp-admin access without making them administrators.

Create unlimited **access profiles**: each one is a WordPress role with its own limits.

- **Protection packs:** lock a whole plugin (screens, content, REST API, AJAX, settings). Packs are included for LearnDash, WooCommerce, GrassBlade xAPI and code snippet plugins. They match by pattern, so they survive plugin updates.
- **Switches with expiry:** open posts & pages, Customizer, themes, plugin settings pages, plugin installation or read-only sales reports, for 1 hour to 30 days or unlimited.
- **Menu access:** deny-by-default menu allowlist per profile. New plugin menus stay hidden until you allow them.
- **Based on a role:** start from Editor, Shop manager or any role, then restrict it.
- **Tracking Scripts:** head/body/footer box for pixels and tag managers. HTML/JS only, never PHP, with history and restore.
- **Activity log:** views, changes, installs and blocked attempts, per profile and per user.
- **Built-in safety:** no user/role management, file editors, core updates, privacy tools, admin email or default role changes, and no way to deactivate this plugin.

Requires WordPress 6.0+ and PHP 7.2+.

## Install

Download the zip from Releases → Plugins → Add New → Upload Plugin → activate → **Access Profiles**.

## Contributing

Issues and pull requests are welcome, especially new protection packs for popular plugins. See `includes/class-aap-packs.php`.

## Support the project

If this plugin saves you time, you can support its development on [Ba9chich](https://ba9chich.com/fr/mehdibouchami).

## Author

[Mehdi Bouchami](https://github.com/mehdibouchami) · License: GPL-2.0-or-later
