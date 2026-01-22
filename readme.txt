=== Synced Patterns for Themes ===
Contributors:      twentybellows, pbking
Tags:              block
Tested up to:      6.8
Stable tag:        1.2.1
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

A WordPress plugin that empowers themes to provide synced patterns.

== Description ==

This plugin enables theme developers to ship patterns that behave as synced patterns (reusable blocks) while maintaining the benefits of theme-bundled patterns. When a theme pattern is marked as synced, it automatically becomes available as a reusable block that updates across all instances when modified.

- **Theme-Provided Synced Patterns**: Convert any theme pattern into a synced pattern by adding a simple metadata flag
- **Automatic Synchronization**: Updates to synced patterns propagate across all instances site-wide
- **Block Bindings Support**: Full compatibility with WordPress block bindings
- **Template Integration**: Use synced patterns in templates and template parts
- **Seamless User Experience**: Synced patterns appear naturally in the pattern inserter

== Installation ==

1. Download the plugin from the [WordPress Plugin Directory](https://wordpress.org/plugins/synced-patterns-for-themes/) or [GitHub](https://github.com/twenty-bellows/synced-patterns-for-themes)
2. Upload to your `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress

== Usage ==

= For Theme Developers =

To make a theme pattern synced, add `Synced: true` to the pattern file's metadata header:

    <?php
    /**
     * Title: My Synced Pattern
     * Slug: mytheme/my-pattern
     * Categories: featured
     * Synced: true
     */
    ?>
    <!-- Your pattern blocks here -->

= Using Synced Patterns in Templates =

Reference synced patterns in templates or other patterns using the pattern block:

    <!-- wp:pattern {"slug":"mytheme/my-pattern"} /-->

== Development ==

The plugin source is available on [GitHub](https://github.com/twenty-bellows/synced-patterns-for-themes).

Node & NPM are needed to install and run the development tools:

* `npm run start` - Start the development environment
* `npm run stop` - Stop the development environment
* `npm run test` - Run unit tests (requires running environment)
* `npm run build` - Build production assets

See the source for more details.

== Frequently Asked Questions ==

== Screenshots ==

== Changelog ==

= 1.0.0 =
* Initial release

= 1.2.0 =
* Enhanced pattern synchronization using logic from Pattern Builder 

= 1.2.1 =
* Improved Documentation