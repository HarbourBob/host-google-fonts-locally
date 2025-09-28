# WordPress Plugin
# host-google-fonts-locally

Host Google Fonts locally for Divi and other page builders.

== Installation ==
1. Upload the plugin and activate.
2. Purge any minify/CDN caches.
3. Load pages using fonts to populate the cache.

== Frequently Asked Questions ==
= Icons missing? =
This plugin intentionally skips icon CSS. If icons were previously rewritten by another tool, purge its cache and CDN.

= Where is the cache? =
/wp-content/uploads/sgfc-cache/` (css + fonts).

= Debugging =
Add `define('SGFC_DEBUG', true);` in wp-config.php to write to `debug.log`.

== Changelog ==
= 1.8.0 =
* Release Version
