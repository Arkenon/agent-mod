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
     * TEMPORARY (test toggle): set to false to run with NO connector
     * workarounds at all — Gemini thought-signature, Gemini tool-schema
     * (empty-properties + union-type), and Anthropic thinking-signature — so the
     * providers' raw behaviour can be observed and it can be confirmed which
     * quirks are still present upstream. No code is removed; flip back to true to
     * restore every repairer. When true, behaviour is exactly as before.
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
     * @param GeminiThoughtSignatureRepairer      $geminiThoughtSignatureRepairer      Gemini thought-signature repairer.
     * @param GeminiToolSchemaRepairer            $geminiToolSchemaRepairer            Gemini tool-schema repairer.
     * @param AnthropicThinkingSignatureRepairer  $anthropicThinkingSignatureRepairer  Anthropic thinking-signature repairer.
     *
     * @since 1.0.0
     */
    public function __construct(
        GeminiThoughtSignatureRepairer $geminiThoughtSignatureRepairer,
        GeminiToolSchemaRepairer $geminiToolSchemaRepairer,
        AnthropicThinkingSignatureRepairer $anthropicThinkingSignatureRepairer
    ) {
        $this->repairers = [
            $geminiThoughtSignatureRepairer,
            $geminiToolSchemaRepairer,
            $anthropicThinkingSignatureRepairer,
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
        // Temporarily disabled via the master switch: no repairer is registered,
        // so every request goes to the provider unmodified.
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
