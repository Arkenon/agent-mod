## Architecture Diagram

```text
agent-mod/
│
├── composer.json                           # Composer dependencies and autoload
├── agent-mod.php                           # Main plugin file, bootstrap and hooks
├── uninstall.php                           # Plugin uninstall script
├── readme.txt                              # Documentation
│
├── src/                                    # Frontend / React source files
│   ├── ability-list/                       # Ability list UI components
│   ├── admin-chat/                         # Admin chat widget (React app)
│   │   ├── components/                     # UI components (ChatPanel, Composer, etc.)
│   │   ├── store/                          # Redux data store (actions, reducers, etc.)
│   │   ├── utils/                          # Helper functions
│   │   └── index.js                        # Entry point
│   ├── dashboard/                          # Dashboard UI components
│   └── settings-editor/                    # Settings editor UI components
│
├── build/                                  # Built assets from src/ via webpack
│
└── includes/                               # PSR-4 autoload: AgentMod\
    │
    ├── App.php                             # 🚀 Main Application Bootstrap
    │                                       # - Plugin lifecycle management
    │                                       # - Service initialization (plugins_loaded, init hooks)
    │                                       # - Run services from DI container
    │
    ├── Services/                           # 🔧 SERVICES (Plugin Features & Logic)
    │   ├── ActivationService.php           # Plugin activation logic
    │   ├── DeactivationService.php         # Plugin deactivation logic
    │   ├── SettingsService.php             # Manages plugin settings via NCF (Native Custom Fields)
    │   ├── AbilityRegistrarService.php     # Registers core and block design abilities
    │   ├── LibraryService.php              # Manages library assets/scripts
    │   ├── SiteManagementAbilityService.php# Main service for site management abilities
    │   ├── NavigationAbilityService.php    # Navigation menu (wp_navigation) list/get/update abilities
    │   ├── FindAbilitiesAbilityService.php # agent-mod/find-abilities: the agent's tool search (dynamic tool loading)
    │   │
    │   ├── SiteManagement/                 # ⚙️ Site Management Managers
    │   │   ├── Guard.php                   # Capability and security checks
    │   │   ├── OptionManager.php           # wp_options table management
    │   │   ├── PluginManager.php           # Plugin installation/activation
    │   │   ├── SystemManager.php           # Core system updates and info
    │   │   ├── ThemeManager.php            # Theme installation/activation
    │   │   ├── UpgraderContext.php         # WP Upgrader wrapper context
    │   │   └── UserManager.php             # User CRUD operations
    │   │
    │   └── AI/                             # 🧠 AI Orchestration & Logic
    │       ├── AIOrchestratorService.php   # Main AI engine (handles chat loops, tool calls)
    │       ├── AIClientAdapter.php         # Adapter for WordPress AI Client integration
    │       ├── AbilityResolver.php         # Filters abilities based on config/mode; selects the tools sent up front
    │       ├── AbilityCatalog.php          # Dynamic tool loading: run-scoped ability search, loaded-tool tracking
    │       ├── PromptBuilder.php           # Assembles the final system prompt
    │       ├── ProviderInfoService.php     # Fetches AI provider/model details
    │       ├── ConfirmationStore.php       # Stores pending action states for confirmation
    │       ├── ProgressStore.php           # Tracks live AI progress
    │       ├── KnowledgeResolver.php       # RAG / site context builder
    │       ├── Http/                       # HTTP/API helpers
    │       └── DTO/
    │           └── AgentConfig.php         # Data Transfer Object for Agent configuration
    │
    ├── Common/                             # 🛠️ SHARED UTILITIES
    │   ├── Helper.php                      # Sanitization and utility functions
    │   ├── DI.php                          # Dependency Injection container setup
    │   └── Constants.php                   # Plugin constants (paths, URLs, default configs)
    │
    └── Presentation/                       # 🎨 PRESENTATION LAYER (UI & Controllers)
        ├── ControllerInit.php              # Controller initialization manager
        │
        └── Admin/                          # WordPress Admin Operations
            ├── Controllers/
            │   ├── AdminController.php         # Admin menu, general scripts, styles
            │   ├── SettingsController.php      # NCF settings page registration
            │   ├── AIChatRestController.php    # REST endpoints for AI chat, models, confirmation
            │   ├── AIChatWidgetController.php  # Chat widget renderer/enqueuer
            │   └── AbilitiesController.php     # REST endpoints for capabilities/abilities
            │
            ├── Views/
            │   └── admin-menu-content.php      # Admin page template
            └── Assets/
                ├── css/
                └── js/
```

## 🧠 Configuration Hierarchy & Data Flow

AgentMod follows a strict top-down data flow for configuring the AI agent's behavior and constraints. The hierarchy is designed to allow global defaults, user settings overrides, and finally per-request (or per-agent) configurations.

### 1. `Constants.php` (The Foundation)
This file defines the hardcoded, absolute default values for the plugin. If nothing else is configured by the user, the system falls back to these constants.
- Contains defaults like `AI_AGENT_DEFAULT_ROLE`, `AI_MAX_TOOL_CALLS`, `AI_CONTEXT_ENABLED`, and the fallback base system directives (`aiDefaultSystemPrompt()`).

### 2. `SettingsService.php` (User Configuration)
This service acts as the bridge between the database (options saved via the `SettingsController` using Native Custom Fields) and the application.
- It reads user preferences (e.g., global system prompt, personality traits, allowed abilities, token limits).
- If a setting is empty or unconfigured, it automatically falls back to `Constants.php`.
- Setting data are serialized and stored in database as `agent_mod_settings`:

Sample serialized data in database:

```php
Array
(
    [agent_mod_chat_behaviour] => Array
        (
            [personality_traits] => Array
                (
                    [0] => helpful
                    [1] => friendly
                )

            [site_context_enabled] => 
            [role] => You are a WordPress expert AI assistant. You can create content, design, and manage the website using abilities.
            [goal] => Your goal is completing requests with high accuracy and efficiency and making no mistakes.
            [global_system_prompt] => Never directly execute destructive operations (delete, trash, erase, or remove content or users). Instead, list the affected items and ask the user for explicit confirmation before any such action is taken. If no relevant tool exists to list them, describe what would be affected and request confirmation. When a user request is ambiguous or missing required details, ask one short clarifying question before calling any tools. Do not assume intent. When a tool can answer the user accurately, call it before responding. Base your answers on tool results rather than guessing.
        )

    [agent_mod_abilities] => Array
        (
            [tool_loading] => dynamic
        )

    [agent_mod_ai_limits] => Array
        (
            [max_tool_calls] => 10
            [max_search_results] => 20
            [max_full_content_posts] => 5
            [max_answer_length] => 20000
        )

    [agent_mod_attachments] => Array
        (
            [attachment_max_count] => 5
            [attachment_max_bytes] => 5242880
            [attachment_mime_types] => image/png image/jpeg image/gif image/webp application/pdf text/plain text/markdown text/csv
        )

)
```

### 3. `AgentConfig.php` (The DTO)
This Data Transfer Object (DTO) represents a single, stateless agent configuration for a specific chat request. It is the central configuration object passed around the `AI` services.
- When an AI request is made (via `AIChatRestController`), an `AgentConfig` instance is created (e.g., via `AgentConfig::fromArray()`).
- The DTO constructor takes the incoming request data. If certain configuration parameters (like personality, ability source, or allowed abilities) are missing from the request, `AgentConfig` falls back to `SettingsService` to fill in the gaps.
- It guarantees that the rest of the system has a complete, valid configuration object.

### 4. `PromptBuilder.php` (The Output)
This service is responsible for assembling the final system instruction string sent to the AI provider.
- It takes the fully resolved `AgentConfig` as its primary input.
- It builds the prompt by appending the agent's identity, role, goal, `personality` traits, base system prompt, context, and mode directives (Ask, Plan, Execute).
- Finally, it applies WP filters (`agent_mod_system_prompt`) before returning the completed prompt string.

**The Flow Summary:**
`Constants` (Hardcoded Defaults) ➔ `SettingsService` (DB Overrides) ➔ `AgentConfig` (Request Overrides + Settings Fallback) ➔ `PromptBuilder` (Final AI Instruction)

## 📚 Detailed Explanation:

### 🚀 **App.php (Bootstrap)**
- Main entry point of the plugin
- Service lifecycle management (`plugins_loaded`, `init` hooks)
- Initialization of services through DI container

### 🔧 **Services**
- **AI Engine (`Services/AI`)**: Contains the core logic for chatting, tool execution (`AIOrchestratorService`), and prompt generation.
- **Abilities (`AbilityRegistrarService`)**: Registers the tools that the AI can use (e.g., creating posts, querying templates). If an ability does not require an input schema do not pass empty object. No input_schema on purpose: WP_Ability rejects a null input when a schema is present, and providers routinely call zero-argument tools with no arguments at all.
- **System abilities**: Abilities that are part of how the agent works (ask-user, present-plan, request-approval, find-abilities) use the `agent-mod-system` category (`Constants::SYSTEM_ABILITY_CATEGORY`). They are never selectable: `AbilityResolver::resolve()` drops them from every list and adds them back only where they apply, and selection lists (e.g. PRO's `FormOptionsService::abilityOptions()`) skip them via `AbilityResolver::isSelectable()`, which also excludes the MCP Adapter's `mcp-adapter/*` proxy abilities. A new internal tool of this kind goes into that category.
- **Dynamic tool loading**: By default (`tool_loading` = `dynamic`) only a few tools are sent up front (find-abilities, the interactive chat tools, `agent_mod_core_abilities`, @-mentioned and recently used abilities). The agent loads the rest by calling `agent-mod/find-abilities`; the matches are appended to the tool list for the rest of the run (`AbilityCatalog::loadedNames()` derives them from the history, so resume needs no extra state). The executable set is still the full resolved set, so the write gate, auto-approval and Ask/Plan read-only rules keep working on real ability names. Write ability descriptions so their name, label and description contain the words a user would search with. Sets of up to `AI_DYNAMIC_TOOLS_THRESHOLD` abilities and the `all` setting send every ability.
- **Settings (`SettingsService`)**: Provides a clean API for reading plugin settings.

### 🛠️ **Common/Shared**
- **Utilities**: Sanitization, helpers
- **DI Container**: Dependency management with PHP-DI
- **Constants**: Configuration management

### 🎨 **Presentation Layer**
- **Admin Controllers**: WordPress admin area management (Settings, Chat Widget injection).
- **REST Controllers**: Endpoints for React applications to communicate with the backend.
- **React Frontend (`src/`)**: Modern JavaScript UI applications using `@wordpress/element` and `@wordpress/data`. The main app is the admin chat widget (`src/admin-chat`).

## ✨ Features:
1. **Dependency Injection**: Clean dependency management with PHP-DI
2. **Clean Separation**: Each layer with its own responsibility, loose coupling
3. **Stateless AI Orchestration**: Configuration is passed via DTOs per request.
4. **React & REST Driven UI**: The frontend uses a scalable Redux-like store and communicates solely via REST APIs.

## Generic Prompt for AI Assistants

You must use this plugin structure for developing this WordPress plugin.
This boilerplate is based on WordPress Create Block and modern PHP practices,
and it enforces a layered, maintainable structure.

⚙️ Key rules:
- Always respect the boilerplate’s folder structure and layer responsibilities:
	- **Services Layer** → Application Services (Business Logic)
	- **Common Layer** → Shared utilities, DI, helpers, constants
    - **Presentation Layer** → Admin controllers, REST endpoints, Views
- Use **PHP-DI** for dependency injection. All services and repositories must be bound in `Common/DI.php`.
- Business logic belongs in the **Services**, never in controllers.
- Register Post Types, Taxonomies, and Custom Fields only via the **Services**
- For Gutenberg blocks and React components:
	- Place React/JSX code in `/src`
	- Build with `npm run build`
- Always follow the naming convention:
  `agent_mod` for variables
  `agent-mod` for file names, folder names, slugs and text domains
  `AgentMod` for class names and namespaces
  `AGENT_MOD` for constants and defines
  `agentMod` for JS variables, method and function names
- Always add `defined('ABSPATH') || exit;` after the namespace section of PHP files.
- Always import used classes with `use` statements after the namespace section.
- Always document classes and methods/functions at the top of the class (class PhpDoc must include @package, @subpackage, @since tags).
- Always use translation functions `__()` and `_e()` with the text domain `agent-mod`.
- Always sanitize inputs with appropriate `sanitize_*` functions and escape outputs with `esc_*` functions. (Sanitize first, escape later. Always validate.)
- Use nonces and capability checks for security in admin actions and REST endpoints.
- Check user permissions with `current_user_can()` before sensitive operations.
- Optimize performance by minimizing database queries, using transients for caching, and loading assets conditionally.
- Follow WordPress coding standards and PSR-4 autoloading.
- If a new function, method or class generated, update documentation (class PhpDoc must include @package, @subpackage, @since tags). always set @since tag to "x.x.x"
- `@since x.x.x` is only a placeholder for the version that is not decided yet. It must NOT stay in a release: when the release version is set, replace every `@since x.x.x` in the plugin sources (`includes/`, `src/`, root PHP files) with that version (e.g. `@since 1.3.3`) BEFORE the release commit, in the same commit as the version bump. After a release, `git grep "x\.x\.x" -- includes src agent-mod.php uninstall.php` must return nothing. Never conclude from older commits that still contain `x.x.x` that the placeholder is meant to stay.

🛠 Development workflow:
1. Install composer dependencies: `composer update`
2. Install npm dependencies: `npm i`
3. Use `npm start` for development (watch mode)
4. Use `npm run build` for production
5. Follow **GPL v2 or later** license compatibility

Your task: When I describe a feature, requirement, or entity, implement it strictly within this boilerplate’s architecture, ensuring clean code, separation of concerns, and WordPress best practices.

## Git release

1. Bump the version in all three places: `Version:` header in `agent-mod.php`, `version` in `package.json`, and `Stable tag:` in `readme.txt`.
2. Add a `= x.y.z =` entry at the top of the `== Changelog ==` section of `readme.txt`.
3. Replace every `@since x.x.x` in `includes/`, `src/` and the root PHP files with the new version (see the rule above), and check with `git grep "x\.x\.x" -- includes src agent-mod.php uninstall.php`. If JS sources changed, rebuild `build/`.
4. Commit (one commit: version bump + changelog + `@since` versions) and push to `main`.

## WordPress.org SVN release

After the Git release is pushed, the same version is published to WordPress.org through SVN. Do this only when asked.

- Working copy: `%USERPROFILE%\Desktop\svn\agent-mod` (`trunk/`, `tags/<version>/`, `assets/`). Tag folders have no `v` prefix (`tags/1.2.6`).
- Only TortoiseSVN is installed (`C:\Program Files\TortoiseSVN\bin\TortoiseProc.exe`); there is no `svn` command line client. The user commits from the TortoiseSVN dialog.
- `trunk/` holds only the runtime files: `build/`, `includes/`, `languages/`, `vendor/`, `composer.json`, `agent-mod.php`, `readme.txt`, `uninstall.php`, `LICENSE`. Never copy `src/`, `docs/`, `node_modules/`, `package*.json`, `webpack.config.js`, `composer.lock`, `.editorconfig`, `.gitignore`, `claude.md` or other dev files.
- Steps:
  1. Check that `trunk/` equals the previous `tags/<previous version>/` (`diff -rq`), so the starting point is known.
  2. Compare `trunk/` with the plugin folder (`diff -rq` on `includes`, `build`, `languages` and the root files listed above) and copy every changed file into `trunk/`. If JS changed, run `npm run build` first so `build/` is current (npm is broken on this machine, run wp-scripts with node, see memory). Update `vendor/` only when Composer dependencies changed (`composer install --no-dev`).
  3. Files deleted in Git must also be deleted in `trunk/`. A plain file delete shows up as "missing" in the commit dialog; the user ticks it to commit the deletion.
  4. Create `tags/<version>/` as a copy of `trunk/` (`cp -r trunk tags/<version>`) and confirm with `diff -rq trunk tags/<version>`. Never change an existing tag folder.
  5. Check that `Version:` in `agent-mod.php` (`AGENT_MOD_VERSION` is read from this header) and `Stable tag:` in `readme.txt` match the new version in both `trunk/` and the tag.
  6. Open the commit dialog for the user (it commits nothing on its own):
     `TortoiseProc.exe /command:commit /path:"<working copy>" /logmsg:"Release <version>: <short summary>"`
     Tell the user to tick the unversioned `tags/<version>` folder (and any missing/deleted files) and press OK.
- `assets/` (banners, icons, screenshots) is changed only when asked.
