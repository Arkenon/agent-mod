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
     * @param GeminiThoughtSignatureRepairer $geminiThoughtSignatureRepairer Gemini repairer.
     *
     * @since 1.0.0
     */
    public function __construct(GeminiThoughtSignatureRepairer $geminiThoughtSignatureRepairer)
    {
        $this->repairers = [
            $geminiThoughtSignatureRepairer,
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
