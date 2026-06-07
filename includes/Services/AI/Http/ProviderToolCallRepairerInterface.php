<?php

/**
 * Provider tool-call repairer contract.
 *
 * AgentMod is provider-agnostic: its tool-calling loop works purely with the AI
 * Client Message DTOs and never inspects the wire format. Some provider connectors,
 * however, fail to round-trip provider-specific metadata that the API requires on
 * subsequent turns (e.g. Gemini's `thoughtSignature` on function-call parts). Until
 * those connectors are fixed upstream, a repairer compensates at the WordPress HTTP
 * layer — scoped to its own provider's endpoint so it is a no-op for every other
 * provider.
 *
 * Repairers are registered for the duration of an agent run and removed afterwards
 * (see ToolCallRepairManager / AIClientAdapter). Each implementation is responsible
 * for guarding by URL so multiple repairers can coexist without interfering.
 *
 * @package AgentMod
 * @subpackage Services\AI\Http
 * @since 1.0.0
 */

namespace AgentMod\Services\AI\Http;

defined('ABSPATH') || exit;

interface ProviderToolCallRepairerInterface
{
    /**
     * Registers the HTTP filters and resets any captured state.
     *
     * Implementations must be safe to call repeatedly.
     *
     * @return void
     * @since 1.0.0
     */
    public function register(): void;

    /**
     * Removes the HTTP filters and clears any captured state.
     *
     * @return void
     * @since 1.0.0
     */
    public function unregister(): void;
}
