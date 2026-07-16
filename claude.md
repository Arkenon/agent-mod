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
│   │   └── index.js                        # Entry point
│   └── dashboard/                          # Dashboard UI components
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
    │   │
    │   └── AI/                             # 🧠 AI Orchestration & Logic
    │       ├── AIOrchestratorService.php   # Main AI engine (handles chat loops, tool calls)
    │       ├── AIClientAdapter.php         # Adapter for WordPress AI Client integration
    │       ├── AbilityResolver.php         # Filters abilities based on config/mode
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
- **Abilities (`AbilityRegistrarService`)**: Registers the tools that the AI can use (e.g., creating posts, querying templates).
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

🛠 Development workflow:
1. Install composer dependencies: `composer update`
2. Install npm dependencies: `npm i`
3. Use `npm start` for development (watch mode)
4. Use `npm run build` for production
5. Follow **GPL v2 or later** license compatibility

Your task: When I describe a feature, requirement, or entity, implement it strictly within this boilerplate’s architecture, ensuring clean code, separation of concerns, and WordPress best practices.
