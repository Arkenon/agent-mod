=== AgentMod ===
Contributors: arkenon
Tags: ai, agent, chatbot, assistant, abilities
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AgentMod is an AI Agent Platform for WordPress that orchestrates native Abilities via the WordPress AI Client.

== Description ==

AgentMod is an **AI Agent Platform** built natively for WordPress. Unlike standard AI chatbot plugins, AgentMod acts as an orchestration layer on top of the *Connectors*, *WP AI Client*, and *Abilities API* infrastructure introduced in WordPress 7.0.

Instead of writing custom integration code (glue-code) for external services, AgentMod creates an **Ability Economy**: it reads abilities exposed by plugins like WooCommerce (`create_order`), FluentCRM (`create_contact`), or Amelia (`book_appointment`) directly from the WordPress Core Registry and makes them available to AI agents — turning your site into an autonomous operating platform.

= Key Features (Free Version) =

**AI Orchestration Engine**
A stateless, model-agnostic agent engine built on the native WordPress AI Client. Supports sequential and parallel multiple ability calls via `WP_AI_Client_Ability_Function_Resolver`.

**Admin Chat Widget**
A React-based chat panel triggered from the WordPress Admin Bar. Built with `@wordpress/components` Modal architecture. Includes a "New Topic" button to reset the session and conversation history at any time.

**Context Scope Selector**
A toggle control inside the chat composer that lets the user decide whether the AI agent can read site data or should operate on general knowledge only.

**Search & Content Tools**
- Search queries return a maximum of 20 post titles/excerpts per call.
- Full content processing is limited to a maximum of 5 post bodies at once. Requests like *"Analyze all 500 posts"* are gracefully declined.
- A maximum of 10 ability calls are allowed per message cycle.

**Write Action Confirmation Modal**
When an agent triggers a database-modifying ability such as `create_post` or `send_email`, an approval modal is shown to the user with a summary of the action. The operation is not executed until the user explicitly confirms.

**Smart Response Rendering**
- Plain text responses are rendered as Markdown with "Copy Text" and "Create Draft" action buttons.
- Code block responses (HTML, CSS, PHP, etc.) are displayed in a syntax-highlighted `<pre>` container with a "Copy Code" button.

**Ability Registry Browser**
A dedicated sub-menu page under AgentMod lists all abilities currently registered on WordPress Core Registry, with detailed information for each.

**Secure System Directives**
The `PromptBuilder` enforces strict system-level directives that prevent agents from performing destructive actions and require them to ask clarifying questions in ambiguous situations.

**Developer Hook System**
A comprehensive set of PHP and JavaScript hooks for extending AgentMod:
- Filters for prompt modification (before/after)
- Actions for ability execution lifecycle (before/after)
- ChatPanel UI customization hooks (add/remove tools, sections)

= Provider Agnostic =

AgentMod does not lock you into any specific AI provider. It works with any provider supported by the WordPress AI Client, including OpenAI, Anthropic, and others configured via provider plugins.

= Requirements =

* WordPress 7.0 or higher (requires native WP AI Client & Abilities API)
* PHP 7.4 or higher
* An active AI provider plugin compatible with WordPress AI Client

== Frequently Asked Questions ==

= Who is this plugin for? =

AgentMod is designed for WordPress site owners, developers, and agencies who want to leverage AI agents to automate tasks on their site using the native WordPress Abilities API — without writing custom integration code for each service.

= Does AgentMod work with any AI provider? =

Yes. AgentMod is fully provider agnostic. It uses the native WordPress AI Client layer, so any AI provider plugin compatible with WordPress 7.0's AI infrastructure will work.

= What is an "Ability" in this context? =

An Ability is a registered, callable action exposed by a WordPress plugin through the Core Abilities Registry. For example, WooCommerce might expose a `create_order` ability, or a booking plugin might expose `book_appointment`. AgentMod reads these automatically and makes them available to the AI agent.

= Can the AI agent delete my content? =

No. Destructive actions (`delete`, `erase`, `trash`) are strictly prohibited by system-level directives in AgentMod's PromptBuilder. The agent will never execute a delete operation directly. Any write or modifying action additionally requires explicit user confirmation via an approval modal before it is executed.

= Is there a limit on how much content the AI can read? =

Yes, hard limits are enforced to manage performance and token costs:
- Search results: maximum 20 titles/excerpts per query
- Full content reading: maximum 5 post bodies per cycle
- Ability calls per message: maximum 10

= Where can I see which Abilities are available? =

Under the AgentMod menu in your WordPress admin, there is a dedicated **Abilities** sub-page that lists all abilities currently registered in the WordPress Core Registry, along with their details and parameters.

= What happens if I want to create a post via the agent? =

If you ask the agent to create a post, it will show you a confirmation modal summarizing the action before anything is written to the database. You must explicitly approve the action for it to proceed.

== Changelog ==

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

* All images located in the Admin/assets/img folder are self created and are licensed under CC0 1.0 Universal (CC0 1.0) Public Domain Dedication.

Composer Packages:

* PHP DI - Copyright (c) Matthieu Napoli

Libraries:

* Native Custom Fields - Copyright (c) Arkenon (https://wordpress.org/plugins/native-custom-fields/)

== Source Code ==

It is available on GitHub:
* GitHub: https://github.com/Arkenon/agent-mod

== Developers ==

If you want to contribute to the plugin:
1) Download the source code and run `npm install` to install the development dependencies.
2) To install composer dependencies, run `composer install`.
3) Run `npm start` to start the development server.
4) To build the plugin, run `npm run build`.