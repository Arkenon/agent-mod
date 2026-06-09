While many MVP features (title, excerpt, alt text) live within the post editor, we also need to consider how site owners may want to use AI in more conversational or exploratory ways. This could take the form of:

A global “AI Workspace” screen in wp-admin, styled after the modern Site Editor, where users can engage with a chatbot-style interface.
The ability to run multi-step AI interactions that go beyond a single field or content object (e.g., “help me configure my site navigation,” or “generate a campaign outline across multiple posts”).
Using upcoming WordPress admin design directions such as DataViews or DataForms to make this workspace feel like a natural part of core rather than a standalone tool.
Allow for this experience to be triggered both outside and inside the site/block editors.
This approach provides a bridge between single-field helpers and broader conversational AI workflows, giving users more freedom in how they experiment with AI. It also opens a design question: how to ensure consistency between editor-embedded features and this global workspace so users don’t feel like they are moving between two different products.

Expected usage here could range from the simple “Please generate sample titles for my latest post” all the way to more complex requests like “Build a custom 404 page that leverages an URL parameters or invalid slugs to find the 3 most likely posts being searched for, render those in nice post card layouts, after that show an option to display our site search experience in case the rendered results above are not what they were searching for, and then finally provide a listing of my 5 most popular posts in the last 24hrs”.

Product Requirement: Global AI Workspace (Chat)
Feature Overview & Value
Summary: This feature introduces a dedicated, conversational interface within the WordPress admin: the "AI Workspace”, where users can engage in multi-step, context-aware interactions with AI.
Unlike the task-specific tools (like Title Generation), this workspace allows for open-ended exploration, content planning, and site-wide operations.
It has context awareness, meaning AI has read-access to the site’s content, structure, and taxonomy, allowing it to provide answers grounded in the actual reality of the user's WordPress installation.
Use Cases
Users often need to "zoom out" to plan content calendars, analyze gaps in their existing library, or generate complex code snippets (like HTML patterns) that don't fit into a single block. This workspace bridges the gap between discrete editorial tasks and broader site management.

Strategic Content Planning (Context-Aware): "Look at my last 10 published posts. What are three related topics I haven't covered yet that would fit my site's specific tone?"
Multi-Post Generation: "I need to launch a series on 'Remote Work.' Please generate titles and short excerpts for a 5-part series, and create draft posts for each."
Technical/Design Assistance: "Write the HTML for a custom 404 page that lists my most popular categories, and style it using Tailwind CSS classes".
Site Querying: "Find all posts tagged 'Updates' published in 2023 that have no featured image."
User Interaction Flow
Overview: The experience lives in a top-level admin screen designed to feel like a modern tool (similar to the Site Editor or DataViews), rather than a classic settings page. It persists conversation history within the session but clearly delineates between "chatting" and "doing."
Entry Point (Full Screen App perhaps?)
Primary: A submenu item labeled AI Workspace appears under AI Experiments. Clicking this launches a full-screen React application (similar to the Site Editor interface), removing the standard WP Admin sidebar to maximize space for DataViews.
Secondary: An "Open in Workspace" action is available in the Block Editor toolbar, allowing users to seamlessly hand off complex tasks to the full workspace.
Triggering this passes the current post's context (ID, content, and meta) to the Workspace, initializing the session so the user can immediately query or manipulate that specific post.
The Interface
Main Chat Area: A central feed displaying the conversation history.
Context Scope: A subtle indicator or selector showing what the AI uses as its source of truth.
Default: "Site Context" (RAG-enabled). The AI prioritizes the user's WordPress content, tags, and structure.
Option: "General Knowledge" (Base Model). The AI ignores site-specific data and relies solely on its pre-trained knowledge (standard LLM behavior). Note: This does not include live web browsing in Phase 1.
Input Field: A prominent text area supporting multi-line prompts.
Output & Actions
The AI responds with text, code blocks, or structured data lists.
Actionable Artifacts: When AI generates useful content, the UI provides specific actions:
For Text: "Copy" or "Create Draft Post" (if the output looks like a post).
For Code: "Copy Code."
For Data: If the AI lists posts (e.g., "Here are 5 posts needing updates"), the list is rendered using the DataViews component, allowing the user to click through to edit those posts directly.
Session Management
Users can clear the chat to start a "New Topic."
MVP: History is local/session-based.
Future: Saved threads (e.g., "SEO Strategy Thread").
Technical Requirements (High-Level)
AI Input Requirements (Context Window):
User prompt.
System Prompt / Tools: The AI must be equipped with "Tools" (Function Calling) to query the WordPress database safely.
The AI does not ingest the entire database at once; it queries relevant data based on the user's prompt (RAG-lite approach).
AI Output Requirements:
Markdown support for formatting.
Structured JSON when requested (to facilitate the "Create Draft" features).
Architecture & Security (Middleware Layer)
Sandboxing: The AI model never accesses the database directly. All requests must pass through a PHP middleware layer.
Permission Enforcement: The middleware explicitly checks current_user_can() capabilities before executing any tool.
Example: If a user asks "List private posts," the middleware checks edit_private_posts. If false, the system returns a "permission denied" error to the AI, which then informs the user, preventing unauthorized data access.
Context & Token Management (RAG-lite)
Retrieval Strategy: The AI uses a two-step process to manage costs and latency. First, it uses a Search Tool to identify relevant content headers. Second, it retrieves the full content of only the most relevant items.
Hard Limits:
Max Search Results: The Search Tool returns a maximum of 20 titles/excerpts per query.
Max Context Processing: The AI can only read the full body content of 5 posts simultaneously. Requests to "Audit all 500 posts" will be rejected with a user-friendly message to prevent timeout errors.
Model Selection:
Requires a model capable of strong reasoning and function calling to handle context querying effectively.
Editorial Handling
Tone: The workspace should feel like a "Co-pilot". It should ask clarifying questions if a request (like "Delete bad posts") is ambiguous or dangerous.
Safety: The AI should never execute destructive actions (Delete/Erase) directly. It should only list items for the user to delete manually.
Open Questions / Design Notes
Action Confirmation: If the AI suggests creating 5 drafts, do we show a modal confirmation summary before the actual wp_insert_post calls happen? (Recommendation: Yes).
Context Token Limits: How much site data is too much? If a user asks "Summarize all 5,000 posts," we need a graceful failure state or a "process the last 50 only" fallback.
Mobile Experience: How does this complex workspace degrade on mobile screens? This is likely not an MVP functionality.
User Flows for Design
1. Access and Entry Points
Admin Sidebar Entry: The user navigates to the workspace via a top level menu item in the WP Admin sidebar similar to the Site Editor.
Editor Handoff: The user is working in the Block Editor and clicks an "Open in AI Workspace" action to move their current task into the broader conversational interface.
2. Core Chat Interface and Context Selection
Standard Chat Loop: The user enters a prompt and receives a text response. This includes states for empty state, typing, loading, and streaming the response.
Context Scoping: The user toggles or confirms the AI context scope to determine if AI is looking at the entire site content or just general internet knowledge.
3. Site Querying and DataViews
Querying Content: The user asks a question about existing content such as "Find my most popular posts."
Data Presentation: The system renders the results not just as text but as a structured list or table using the WordPress DataViews component.
Direct Manipulation: The user clicks an item in the generated list to edit the post or perform a bulk action directly from the chat interface.
4. Multi Step Content Creation
Campaign Generation: The user asks to generate a content plan or multiple post outlines.
Artifact Creation: The AI proposes multiple drafts or pages. The user reviews the proposal and clicks a "Create Drafts" button.
Success Feedback: The system confirms the creation of the new objects and provides links to edit them.
5. Technical and Code Generation
Code Request: The user asks for a specific template or code snippet such as a custom 404 page layout.
Code Block Interaction: The system displays the code in a formatted block with a "Copy" or "Insert" action.
6. Mobile Experience
Responsive Chat: The user accesses the workspace from a mobile device. The layout adapts to show the chat feed and input field clearly while managing screen real estate for complex data views or lists. If this is reasonably achievable, ok fine, but otherwise fine to punt this as non-MVP.


Özet: Genel Değerlendirme
WP AI Plugin tartışması, AgentMod'un mimari temelini zaten doğru kurduğunu doğruluyor. Temel yaklaşım (provider-agnostic, tool-calling loop, REST API backend, React frontend) iki taraf arasında büyük ölçüde örtüşüyor. Kritik boşluklar UX katmanında ve güvenlik protokollerinde.

Örtüşen Alanlar (İyi Haber)
WP AI Plugin'in istediği şeylerin büyük kısmı AgentMod'da zaten var:

WP AI Plugin İstediği	AgentMod Durumu
WP Admin sohbet arayüzü	✅ Phase 1 tamamlandı
Context-aware AI (site içeriği)	✅ KnowledgeResolver + autoIncludeSiteContext
Function calling / Tool use	✅ Multi-step tool-call loop
Permission kontrolü	✅ manage_options + current_user_can
Provider-agnostic mimari	✅ WP AI Client wrapper
Session-based mesaj geçmişi	✅ @wordpress/data store
RAG-lite yaklaşımı	✅ list-recent-posts, get-site-info abilities
Max tool call limiti	✅ AGENT_MOD_MAX_TOOL_CALLS = 10
Bizim Gözden Kaçırdığımız / Henüz Planlamadığımız Şeyler
1. DataViews Entegrasyonu — En Kritik Eksik
WP AI Plugin, AI "son 5 post'unu bul" dediğinde sonuçları düz metin yerine WordPress'in native DataViews component'iyle interaktif tablo olarak render etmek istiyor. Kullanıcı listeden direkt post'a tıklayıp edit edebilecek.

AgentMod şu an tool call sonuçlarını markdown metin olarak döküyor. Bu yapısal veri gösterimi hiç planlanmamıştı.

2. Artifact Sistemi — Çıktı Tipine Göre Eylem Butonları
Tartışmada çok net bir ayrım var:

Metin çıktısı → "Copy" veya "Create Draft Post" butonu
Kod çıktısı → Syntax highlighting + "Copy Code"
Veri listesi → DataViews + satır üzerinde edit aksiyonu
MessageItem.jsx'te output type algılaması ve bunlara özel action butonları hiç yok. Kullanıcı markdown görüyor ama üzerinde eylem yapamıyor.

3. "Create Drafts" Onay Modal'ı — Güvenlik UX'i
WP AI Plugin şunu öneriyor: AI "5 taslak oluşturayım" dediğinde, gerçek wp_insert_post çağrısından önce bir özet modal göster, kullanıcı onaylasın.

AgentMod'da tool call direkt çalışıyor. Kullanıcıya sormadan post oluşturulabilir. Bu hem UX hem güvenlik açısından önemli bir boşluk.

4. Block Editor → Workspace Handoff
"Open in AI Workspace" butonu Gutenberg toolbar'ına ekleniyor ve mevcut post'un ID, content, meta bilgisini workspace'e taşıyor. Session otomatik o post bağlamında açılıyor.

AgentMod'da admin bar widget'ı var ama Block Editor'dan bağlamsal geçiş hiç planlanmamış.

5. Context Scope Selector UI
autoIncludeSiteContext: true/false flag'i zaten var ama kullanıcının bunu görebileceği ve kontrol edebileceği bir UI yok. WP AI Plugin bunu:

"Site Context (RAG)" → Site içeriğini kullan
"General Knowledge" → Sadece model bilgisi
şeklinde görsel olarak ayırıyor ve kullanıcıya toggle olarak sunuyor.

6. Destructive Eylem Güvenlik Protokolü — Önemli Atlama
WP AI Plugin şunu açıkça kurala bağlıyor: AI, Delete/Erase eylemlerini asla direkt çalıştırmaz. Sadece "Bu post'ları silmek isteyebilirsiniz, işte liste" der, silme işlemini kullanıcıya bırakır.

PromptBuilder.php'deki system instruction'da bu kural açıkça yok. Write/delete ability'leri eklendiğinde bu ciddi bir risk.

7. Co-pilot Netleştirme Davranışı
"Kötü post'ları sil" gibi belirsiz isteklerde AI açıklayıcı sorular sormalı. Bu da PromptBuilder.php'ye eklenmesi gereken ama şu an eksik olan bir davranış.

8. Hard Limits — İçerik Boyutu Güvenlik Sınırları
WP AI Plugin şunları açıkça tanımlıyor:

Search Tool: max 20 başlık/özet döner
Full content: aynı anda max 5 post gövdesi
"Tüm 500 post'u özetle" isteği → kullanıcı dostu hata mesajı
maxToolCalls: 10 var ama ability response'larında sonuç sayısı ve içerik boyutu limitleri AbilityRegistrarService.php'de tanımlanmamış.

9. Full-Screen Workspace Modu
WP AI Plugin sidebar'ı tamamen kaldıran Site Editor benzeri tam ekran mod istiyor. AgentMod şu an normal admin sayfası içinde iki panelli düzen kullanıyor.

10. "New Topic" / Sohbet Temizleme Butonu
Açık bir session yönetimi UI'ı. Store'da muhtemelen implement edilebilir ama görünür buton yok.

AgentMod'un Daha İleride Olduğu Yerler
WP AI Plugin tartışmasının hiç değinmediği ama AgentMod'un planladığı veya yaptığı şeyler:

Dosya eklenti desteği (resim, PDF, CSV, markdown) — tartışmada hiç yok
Agent konfigürasyonu (isim, rol, hedef, kişilik traits) — tek tip asistan varsayımı var
Frontend/ziyaretçi chat widget planı — tamamen admin odaklı tartışma
Multi-agent collaboration planı
Workflow engine + cron planı
Conversation/Agent CPT veri modeli
Önerilen Öncelik Sırası
Kısa vadede (düşük efor, yüksek etki):

PromptBuilder.php'ye destructive eylem yasağı + co-pilot clarifying questions kuralı ekle
AbilityRegistrarService.php'de hard limits tanımla (max 20 sonuç)
ChatPanel.jsx'e "New Topic" butonu ekle
Orta vadede:
4. MessageItem.jsx'te kod bloğu tespiti + "Copy Code" butonu
5. Context Scope Selector toggle (Composer.jsx)
6. Tool call'larda create_post gibi write aksiyonları için onay modal'ı

Uzun vadede:
7. DataViews entegrasyonu ile yapısal veri gösterimi
8. Full-screen Workspace modu
9. Block Editor → Workspace handoff

Özetle: WP AI Plugin tartışması AgentMod'un yolunu doğruluyor ama özellikle güvenlik protokolleri (destructive action rule, confirmation modal) ve structured data output (DataViews) konularında somut fikirler sunuyor. Bu ikisi kısa sürede sisteme dahil edilebilir.