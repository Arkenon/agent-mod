AgentMod — Phase 1 Adım 1: AI Orchestrator Engine
Context (Neden / Amaç)
AgentMod, WordPress 7.0'ın native WP AI Client + Abilities API + Connectors altyapısı üzerine kurulu bir "WordPress Agent Platform" çekirdeği olacak. phase_1.md üç çekirdek sistem tanımlıyor (AI Engine, Agent veri modeli, Admin Chat Widget). Bu ilk adımda yalnızca AI Engine / Orchestration Layer kodlanacak.

WordPress 7.0 kuruludur, Gemini provider'ı Connectors ile yapılandırılmış ve API key girilmiştir (->using_provider('gemini') çalışmaya hazır). WP AI Client native gelir, ayrı kurulum gerekmez.

Hedef çıktı: Bir agent tanımı (DTO olarak dışarıdan gelen) + kullanıcı mesajı alan, system prompt + site bağlamı + izinli ability'leri birleştirip WP AI Client üzerinden provider'a giden, çok adımlı (agentic) tool-calling döngüsünü yürüten ve sonucu döndüren stateless bir orchestration motoru. Persistence (CPT/DB, History, Conversation) ve gerçek chat widget bir sonraki adıma bırakılır.

Kullanıcı ile netleşen kararlar
Yapı: Orchestrator + ayrı collaborator sınıfları (phase_1.md'deki gibi modüler).
Agent kaynağı: CPT henüz yok → agent ayarları AgentConfig DTO ile dışarıdan geçilir (stateless).
Test: Geçici, korumalı bir REST endpoint ile canlı Gemini'ye karşı doğrulama.
Doğrulanmış WP AI Client / Abilities API API'si
Keşif sırasında dosyalardan teyit edilen imzalar (planın temelidir):

Giriş: wp_ai_client_prompt( $prompt = null ): WP_AI_Client_Prompt_Builder (wp-includes/ai-client.php). Builder, snake_case metodları __call ile alttaki WordPress\AiClient\Builders\PromptBuilder camelCase metodlarına proxy'ler.
Fluent (chainable) metodlar: ->with_history(...$messages), ->using_system_instruction($str), ->using_provider('gemini'), ->using_model_preference([$provider,$model]), ->using_abilities(...$names_or_objects), ->with_text(), ->with_file($file,$mime).
Terminal: ->generate_result(): GenerativeAiResult|WP_Error, ->generate_text(): string|WP_Error.
using_abilities() SADECE tool declaration ekler — otomatik execute ETMEZ. Tool döngüsü manuel.
Tool döngüsü için: WP_AI_Client_Ability_Function_Resolver (wp-includes/ai-client/class-wp-ai-client-ability-function-resolver.php):
__construct( ...$abilities ) (isim string veya WP_Ability)
has_ability_calls( Message $m ): bool
execute_abilities( Message $m ): Message (FunctionResponse'ları içeren mesaj döner)
statik: ability_name_to_function_name() / function_name_to_ability_name()
Sonuç: GenerativeAiResult::getCandidates(): array, Candidate::getMessage(): Message, GenerativeAiResult::toText(): string, ->getTokenUsage().
Mesaj kurma: new Message(MessageRoleEnum::user(), [ new MessagePart($text) ])
WordPress\AiClient\Messages\DTO\Message, ...\DTO\MessagePart, ...\Enums\MessageRoleEnum
Abilities registry: wp_register_ability($name,$args), wp_get_ability($name), wp_get_abilities(): array (wp-includes/abilities-api.php). Kayıt wp_abilities_api_init, kategori wp_abilities_api_categories_init hook'unda.
Connector kontrolü (opsiyonel guard): wp_is_connector_registered('gemini'), wp_supports_ai().
Boilerplate uyumu (mevcut desen)
PSR-4: AgentMod\ → includes/ (composer.json). PHP-DI autowiring (Common/DI.php, DI::container()->get(...) — her çağrıda yeni container/instance, stateless servisler için sorun değil).
Servisler App.php::$services dizisinde, plugins_loaded'da; controller'lar ControllerInit üzerinden init'te yüklenir. Servis deseni: constructor içinde add_action(...) ile hook bağlama (bkz. BlockService).
Kurallar (CLAUDE.md): defined('ABSPATH')||exit;, use import'ları, class PhpDoc (@package/@subpackage/@since), __()/_e() ile agent-mod text domain, sanitize/escape, nonce + current_user_can(). İsimlendirme: AgentMod namespace, agent-mod slug, agent_mod değişken, AGENT_MOD sabit.
Oluşturulacak Dosyalar
0. Planlama dokümanı (kullanıcı isteği — eklenti kökü)
wp-content/plugins/agent-mod/PHASE_1_AI_ORCHESTRATOR.md Bu plan dosyasının özeti: mimari, sınıf sorumlulukları, akış diyagramı, API referansı, sonraki adıma bırakılanlar (HistoryManager, ConversationManager, CPT). Ekip referansı için.
1. DTO'lar — includes/Services/AI/DTO/
AgentConfig.php (AgentMod\Services\AI\DTO\AgentConfig) Alanlar: name, role, goal, personality (string[]), systemPrompt (özel override), provider (default 'gemini'), model (nullable), abilitySource ('all'|'selected'), allowedAbilities (string[]), maxToolCalls (default 10), autoIncludeSiteContext (bool). fromArray(array): self factory'si (REST'ten gelen sanitize edilmiş dizi → DTO).
AgentResponse.php (AgentMod\Services\AI\DTO\AgentResponse) Alanlar: text, toolCalls (çalıştırılan ability adları/argümanları), messages (güncellenmiş geçmiş — sonraki adımda persist için), tokenUsage, error (?WP_Error). toArray() (REST yanıtı için).
2. Collaborator sınıfları — includes/Services/AI/
KnowledgeResolver.php — getSiteContext(): array Şimdilik: site adı, açıklaması, admin e-posta, WP sürümü, dil. (RAG sonra.)
PromptBuilder.php — buildSystemInstruction(AgentConfig $a, array $siteContext): string System prompt + role + goal + personality + (opsiyonel) site bağlamını tek metinde birleştirir.
AbilityResolver.php — resolve(AgentConfig $a): array (WP_Ability listesi) abilitySource==='all' → wp_get_abilities(); 'selected' → allowedAbilities içinden wp_get_ability() ile var olanları filtreler. (phase_1 "AbilityResolver" karşılığı.)
AIClientAdapter.php — WP AI Client'ı saran tek katman (API değişimini izole eder). generate( string $systemInstruction, array $messages, array $abilities, string $provider, ?string $model, int $maxToolCalls ): AgentResponse İçinde agentic tool-call döngüsü:
$resolver = new WP_AI_Client_Ability_Function_Resolver(...$abilities);
$history  = $messages;                       // Message[]
for ($i=0; $i<$maxToolCalls; $i++) {
    $builder = wp_ai_client_prompt()
        ->with_history(...$history)
        ->using_system_instruction($systemInstruction)
        ->using_provider($provider);
    if ($model)     $builder->using_model_preference([$provider,$model]);
    if ($abilities) $builder->using_abilities(...$abilities);
    $result = $builder->generate_result();
    if (is_wp_error($result)) return AgentResponse error;
    $msg = $result->getCandidates()[0]->getMessage();
    $history[] = $msg;
    if (!$resolver->has_ability_calls($msg)) return AgentResponse( toText, history, usage );
    $history[] = $resolver->execute_abilities($msg);   // FunctionResponse mesajı
}
// limit aşıldı → son metin + uyarı
3. Ana servis — includes/Services/AI/AIOrchestratorService.php
Namespace AgentMod\Services\AI. Constructor injection (PHP-DI) ile PromptBuilder, AbilityResolver, KnowledgeResolver, AIClientAdapter alır.
Ana metod: chat( AgentConfig $agent, string $message, array $history = [] ): AgentResponse Akış (phase_1 "AI ENGINE FLOW"):
$siteContext = autoIncludeSiteContext ? KnowledgeResolver->getSiteContext() : []
$systemInstruction = PromptBuilder->buildSystemInstruction($agent, $siteContext)
$abilities = AbilityResolver->resolve($agent)
$messages = mapHistoryToMessages($history) + yeni user Message
return AIClientAdapter->generate(...)
private mapHistoryToMessages(array): Message[] — {role,text} → Message/MessagePart.
Guard: başta wp_supports_ai() / wp_is_connector_registered($provider) kontrolü; değilse WP_Error.
Not: Bu servis hook bağlamaz (motor on-demand çağrılır), App.php::$services'e EKLENMEZ; DI ile REST controller'a inject edilir.
4. Demo ability'ler (test için tool gerekiyor) — includes/Services/AbilityRegistrarService.php
Namespace AgentMod\Services. Constructor'da add_action('wp_abilities_api_categories_init', ...) + add_action('wp_abilities_api_init', ...).
Kategori: agent-mod. Kayıtlı ability'ler (tool-calling'i kanıtlamak için minimal):
agent-mod/get-site-info (readonly) → site adı/açıklama/WP sürümü döner.
agent-mod/list-recent-posts (readonly, input: count) → son yazıları döner. Her biri permission_callback (current_user_can('read'/edit_posts')), input_schema, output_schema, execute_callback ile. (Yazma örneği create-post sonraki adımda.)
App.php::$services dizisine eklenir.
5. Test endpoint — includes/Presentation/Admin/Controllers/AIChatRestController.php
Namespace AgentMod\Presentation\Admin\Controllers. Constructor inject: AIOrchestratorService. Constructor'da add_action('rest_api_init', [$this,'registerRoutes']).
Route: POST /wp-json/agent-mod/v1/test-chat
permission_callback: current_user_can('manage_options') (REST nonce wp_rest ile).
Body (sanitize): message (text), agent (AgentConfig dizisi, opsiyonel — yoksa makul default: generic agent, provider gemini, abilitySource='all'), history (opsiyonel).
AgentConfig::fromArray() → AIOrchestratorService->chat() → AgentResponse->toArray() rest_ensure_response ile döner; WP_Error ise uygun status.
ControllerInit::$clientControllers'a eklenir (REST is_admin() false olduğundan her istekte yüklenmeli; route yine de manage_options ile korumalı).
Değişecek mevcut dosyalar
includes/App.php → $services dizisine AbilityRegistrarService::class ekle (+use).
includes/Presentation/ControllerInit.php → $clientControllers'a AIChatRestController::class ekle (+use).
(Gerekirse) includes/Common/Constants.php → AI_PROVIDER_DEFAULT='gemini', REST_NAMESPACE='agent-mod/v1', MAX_TOOL_CALLS=10 sabitleri.
composer.json autoload değişmez (AgentMod\ zaten includes/).
Doğrulama (End-to-End Test)
composer dump-autoload (yeni sınıflar için) — eklenti kökünde.
WP admin'de giriş yap; tarayıcı konsolundan REST nonce ile çağrı (manage_options gerekli):
fetch('/wp-json/agent-mod/v1/test-chat', {
  method:'POST',
  headers:{'Content-Type':'application/json','X-WP-Nonce': wpApiSettings.nonce},
  body: JSON.stringify({ message: 'Merhaba, bu sitenin WordPress sürümü nedir?' })
}).then(r=>r.json()).then(console.log);
Beklenen: model agent-mod/get-site-info ability'sini çağırır, sonucu yorumlar, metin döner.
Tool-calling testi: message: 'Son 3 yazıyı listele' → agent-mod/list-recent-posts çağrılır, AgentResponse.toolCalls dolu döner.
Hata yolu: Connector kapalıyken / wp_supports_ai() false iken anlamlı WP_Error dönmeli.
(Opsiyonel) WP_DEBUG_LOG açıkken adapter'da istek/yanıt log'u doğrula.
Bu adımda KAPSAM DIŞI (sonraki adımlara)
CPT'ler (agentmod_agent, agentmod_conversation, agentmod_report, agentmod_knowledge), taksonomiler ve meta alanları → Native Custom Fields ile data-model adımı.
HistoryManager / ConversationManager (DB tabanlı persistence, özetleme).
React Admin Chat Widget (FloatingButton, ChatWindow, Composer...).
Görsel/doküman input (with_file) — altyapı adapter'da hazır, UI sonra.
RAG / vektör knowledge.