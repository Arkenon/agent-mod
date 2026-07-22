=== Native Custom Fields - Custom Content Types and Meta Fields ===
Contributors: arkenon
Tags: custom fields, custom post type, meta box, gutenberg, block editor
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Custom Content Types and Meta Fields built with WordPress native components. Modern, clean, and performance-focused.

== Description ==

Native Custom Fields is a modern WordPress plugin for creating custom content types, meta fields, and options pages using WordPress’ own native component system.

[youtube https://www.youtube.com/watch?v=M_HO8bI1eZA]

Instead of shipping a proprietary UI framework or custom database structure, Native Custom Fields leverages WordPress core technologies such as:

- @wordpress/scripts
- @wordpress/components
- @wordpress/elements
- @wordpress/icons
- @wordpress/data

This ensures a seamless, future-proof experience that evolves together with WordPress core.

= Why Native Custom Fields? =

Most custom field plugins introduce their own UI systems, internal data storage layers, or hidden configuration post types.

Native Custom Fields follows a different philosophy:

• Uses WordPress native UI components
• Stores configuration in wp_options
• Stores data in postmeta, termmeta, and usermeta
• Does not create unnecessary database tables
• Does not register hidden configuration post types
• Follows WordPress coding standards

The result is a clean, lightweight, and maintainable solution.

= Key Features =

* Register Custom Post Types
* Register Custom Taxonomies
* Import / Export via JSON or PHP
* Options Page & Fields Builder
* AI Integration with Abilities API & WordPress AI Client

= Meta Fields =
Create field groups and attach them to:
* Post Types
* Taxonomies
* User Profiles
* Options Pages

= Supported Components =
* Input Control
* Text Control
* Number Control
* Select Control
* Checkbox Control
* Radio Control
* Textarea Control
* Range Control
* Toggle Control
* Color Picker
* Color Palette
* Date Picker
* DateTime Picker
* Time Picker
* Unit Control
* Angle Picker Control
* Alignment Matrix Control
* Border Box Control
* Border Control
* Box Control
* Toggle Group Control
* Combobox Field
* Font Size Picker
* File Upload
* Media Library
* Form Token
* ExternalLink
* Heading
* Notice
* Text Highlight

Custom Components:
* Repeater
* Group

= Developer-Friendly =
* Built with PSR-4 autoloading
* Strict Types compatible
* Modern React-based admin UI
* Clean and extendable architecture
* Import / Export via JSON or PHP

= Performance-Focused =
* Minimum admin UI bloat
* Native WordPress components
* No redundant database tables
* Optimized for long-term maintainability

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/native-custom-fields`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Start creating Custom Content Types and Field Groups from the admin panel

== Frequently Asked Questions ==

= Who is this plugin for? =

Native Custom Fields is built primarily for WordPress developers, agencies, and users who want full control over structured data while staying aligned with WordPress core standards.

= How is this different from other custom field plugins? =

Native Custom Fields uses WordPress’ official component system instead of a custom-built admin UI framework.
It follows WordPress data architecture and avoids unnecessary database layers.

= Does it create custom database tables? =

No. Configuration is stored in wp_options, and data is stored in standard WordPress meta tables.

= Is it compatible with the Block Editor? =

Yes. The plugin is built around the Block Editor architecture and uses native WordPress components.

= Do the free version have Repeater and Group fields? =

Yes. The free version has Repeater and Group fields. These are custom components built using WordPress native components recursively or grouped.


== Changelog ==

= 1.3.1 =
Fixed: Sanitization issue for multine line text field.

= 1.2.8 =
* Fix: Boot method for external usage in another plugins.

= 1.2.7 =
* Add: Singleton pattern intp DI Container
* Fix: Boot method for external usage in another plugins.
* Move: Hooks in the services into controllers

= 1.2.6 =
* Add: Static boot method for external usage in another plugins.
* Test: Added Composer package capability.
* Test: Added GitHub Actions workflow for testing.

= 1.2.3 =
* Add: Into Packagist repository

= 1.2.0 =
* Add: Parent Slug parameter into options page

= 1.0.9 =
* Add: Annotations parameter into abilities

= 1.0.6 =
* Fixed: Auto generate empty labels for post types and taxonomies in Abilities.

= 1.0.5 =
* Moved: Pro features into free plugin: Import&Export Module & Options Page Builder & AI Integration.

= 1.0.4 =
* Added: Auto generate empty labels for post types.
* Added: Auto generate empty labels for taxonomies.

= 1.0.3 =
* Tested with WordPress 7.0

= 1.0.2 =
* Updated: Readme.txt
* Updated: Dashboard screen

= 1.0.1 =
* Updated: Add PHP-DI Version to 7.1.1
* Updated: Rest enpoint permissions in PostMetaController and OptionsController

= 1.0.0 =
* Initial public release

== Credits ==

Built using official WordPress packages:

* @wordpress/scripts
* @wordpress/components
* @wordpress/elements
* @wordpress/icons
* @wordpress/data

Assets:

* All images located in the Admin/assets/images folder are self created and are licensed under CC0 1.0 Universal (CC0 1.0) Public Domain Dedication.

Composer Packages:

* PHP DI - Copyright (c) Matthieu Napoli


== Source Code ==

It is available on GitHub:
* GitHub: https://github.com/Arkenon/native-custom-fields

== Developers ==

If you want to contribute to the plugin:
1) Download the source code and run `npm install` to install the development dependencies.
2) To install composer dependencies, run `composer install`.
3) Run `npm start` to start the development server.
4) To build the plugin, run `npm run build`.