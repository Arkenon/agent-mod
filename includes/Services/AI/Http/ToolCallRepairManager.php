<?php

/**
 * Tool-call repair manager.
 *
 * Aggregates the provider-specific tool-call repairers and exposes a single
 * register/unregister pair to the (provider-agnostic) AIClientAdapter. The adapter
 * depends only on this manager, so the core tool-calling loop never references a
 * concrete provider. Adding support for another provider's quirk means writing a
 * new ProviderToolCallRepairerInterface implementation and adding it here.
 *
 * @package AgentMod
 * @subpackage Services\AI\Http
 * @since 1.0.0
 */

namespace AgentMod\Services\AI\Http;

defined('ABSPATH') || exit;

class ToolCallRepairManager
{
    /**
     * Master switch for every provider tool-call repairer.
     *
     * Set to false to run with NO connector workarounds at all — the Google
     * connector repairer (schema normalisation, thought-signature round-trip,
     * web search + function calling) and the Anthropic connector repairer
     * (thinking-signature round-trip, pause_turn continuation + text merge) —
     * so the providers' raw behaviour can be observed. Individual repairers
     * also carry their own switch, so a single provider's repairs can be
     * retired independently once its fixed connector ships.
     *
     * @var bool
     * @since 1.1.0
     */
    private const REPAIRS_ENABLED = true;

    /**
     * Registered repairers.
     *
     * @var ProviderToolCallRepairerInterface[]
     * @since 1.0.0
     */
    private array $repairers;

    /**
     * Constructor (PHP-DI autowired).
     *
     * Each provider repairer is injected by type and added to the set. New
     * providers are wired up by adding a constructor parameter here.
     *
     * @param GoogleConnectorRepairer    $googleConnectorRepairer    Google (Gemini) connector repairer.
     * @param AnthropicConnectorRepairer $anthropicConnectorRepairer Anthropic (Claude) connector repairer.
     *
     * @since 1.0.0
     */
    public function __construct(
        GoogleConnectorRepairer $googleConnectorRepairer,
        AnthropicConnectorRepairer $anthropicConnectorRepairer
    ) {
        $this->repairers = [
            $googleConnectorRepairer,
            $anthropicConnectorRepairer,
        ];
    }

    /**
     * Registers every repairer.
     *
     * @return void
     * @since 1.0.0
     */
    public function register(): void
    {
        // With the master switch off no repairer is registered, so every
        // request goes to the provider unmodified.
        if (! self::REPAIRS_ENABLED) {
            return;
        }

        foreach ($this->repairers as $repairer) {
            $repairer->register();
        }
    }

    /**
     * Unregisters every repairer.
     *
     * @return void
     * @since 1.0.0
     */
    public function unregister(): void
    {
        foreach ($this->repairers as $repairer) {
            $repairer->unregister();
        }
    }
}
