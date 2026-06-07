## Architecture Diagram

```
light-wp-plugin-boilerplate/
│
├── composer.json                           # Composer dependencies and autoload
├── agent-mod.php                              # Main plugin file, bootstrap ve hooks
├── uninstall.php                           # Plugin uninstall script
├── readme.txt                              # Documentation
│
└── src/first-block/                        # Sample Gutenberg Block (optional)
│
└── build/first-block/                      # Built assets for the sample block
│
└── includes/                               # PSR-4 autoload: AgentMod\
    │
    ├── App.php                             # 🚀 Main Application Bootstrap
    │                                       # - Plugin lifecycle management
    │                                       # - Service initialization (plugins_loaded, init hooks)
    │                                       # - Run services from DI container
    │
    ├── Services/                       # 🔧 SERVICES (Plugin Features & Logic)
    │   ├── ActivationService.php       # Plugin activation logic
    │   ├── BlockService.php            # Gutenberg block registration
    │   ├── DeactivationService.php     # Plugin deactivation logic
    │
    ├── Common/                          # 🛠️ SHARED UTILITIES
    │  	├── Helper.php              	 # Sanitization ve utility functions
    │  	├── DI.php                  	# Dependency Injection container setup
    │  	├── Constants.php           	# Plugin constants (paths, URLs, configs)
    │
    └── Presentation/                   # 🎨 PRESENTATION LAYER (UI & Controllers)
        ├── ControllerInit.php          # Controller initialization manager
        │                               # - Admin/Client controller routing
        │
        ├── Admin/                              # WordPress Admin Operations
        │   ├── Controllers/
        │   │   └── AdminController.php         # Admin menu, scripts, styles
        │   ├── Views/
        │   │   └── admin-menu-content.php      # Admin page template
        │   └── Assets/
        │       ├── css/agent-mod-admin.css
        │       └── js/agent-mod-admin.js
        │
        └── Client/                             # Frontend Interface
            ├── Controllers/
            │   ├── ClientController.php        # Frontend scripts ve styles
            │   └── BookController.php          # AJAX endpoints for Book operations
            └── Assets/
                ├── css/agent-mod-client.css
                └── js/agent-mod-client.js
```
AgentMod must be PROVIDER AGNOSTIC

Do not change 3rd party plugins code. (such as Wp AI Client, AI Provider plugins etc.) All solutions should be located at AgentMod plugin.

## 📚 Detailed Explanation:

### 🚀 **App.php (Bootstrap)**
- Main entry point of the plugin
- Service lifecycle management (`plugins_loaded`, `init` hooks)
- Initialization of services through DI container

### 🔧 **Services**
- WordPress-specific implementations (Custom Fields, Post Types, Taxonomies)
- External service integrations (Mail, Blocks)
- Framework-specific logic

### 🛠️ **Common/Shared**
- **Utilities**: Sanitization, helpers
- **DI Container**: Dependency management with PHP-DI
- **Constants**: Configuration management

### 🎨 **Presentation Layer**
- **Admin Controllers**: WordPress admin area management
- **Client Controllers**: Frontend ve AJAX endpoints
- **Assets**: CSS/JS for admin and client sides

## ✨ Features:
1. **Dependency Injection**: Clean dependency management with PHP-DI
2. **Clean Separation**: Each layer with its own responsibility, loose coupling
3. **WordPress Integration**: Clean interface with native WP APIs

## Generic Prompt for AI Assistants

You must use this plugin structure for developing this WordPress plugin.
This boilerplate is based on WordPress Create Block and moder PHP practices,
and it enforces a layered, maintainable structure.

⚙️ Key rules:
- Always respect the boilerplate’s folder structure and layer responsibilities:
	- **Services Layer** → Application Services (Business Logic)
	- **Common Layer** → Shared utilities, DI, helpers, constants
    - **Presentation Layer** → Admin & Client controllers, Views, Assets (CSS/JS)

- Use **PHP-DI** for dependency injection. All services and repositories must be bound in `Common/DI.php`.
- Business logic belongs in the **Services**, never in controllers or repositories.
- Register Post Types, Taxonomies, and Custom Fields only via the **Services**
- For Gutenberg blocks:
	- Place React/JSX code in `/src`
	- Build with `npm run build`
	- Register via `includes/Infrastructure/Services/BlockService.php`
	-
- Always follow the naming convention:
  `agent_mod` for variables
  `agent-mod` for file names, folder names, slugs and text domains
  `AgentMod` for class names and namespaces
  `AGENT_MOD`for constants and defines
  `agentMod` for JS variables, method and function names

- Always add defined( 'ABSPATH' ) || exit; after namespace section of PHP files.
- Always import used classes with `use` statements after namespace section of PHP files.
- Always document classes and methods/functions at the top of the class (class PhpDoc must include @package, @subpackage, @since tags).
- Always use translation functions `__()` and `_e()` with the text domain `agent-mod`.
- Always sanitize inputs with appropriate `sanitize_*` functions and escape outputs with `esc_*` functions. (Sanitize first, escape later. Always validate.)
- Always use translation functions `__()` and `_e()` with the text domain `agent-mod`.
- Always sanitize inputs with appropriate `sanitize_*` functions and escape outputs with `esc_*` functions.
- Use nonces and capability checks for security in admin actions.
- Check user permissions with `current_user_can()` before sensitive operations.
- Optimize performance by minimizing database queries, using transients for caching, and loading assets conditionally
- Follow WordPress coding standards and PSR-4 autoloading.

🛠 Development workflow:
1. Install composer dependencies: `composer update`
2. Install npm dependencies: `npm i`
3. Update packages: `npm run packages-update`
4. Use `npm start` for development (watch mode)
5. Use `npm run build` for production
6. Follow **GPL v2 or later** license compatibility

Your task: When I describe a feature, requirement, or entity, implement it strictly within this boilerplate’s architecture,
ensuring clean code, separation of concerns, and WordPress best practices.
