Phase 1 — Adım 2: Admin Chat Bot Widget
Context
Adım 1'de stateless AI Orchestrator motoru ve POST /agent-mod/v1/test-chat REST endpoint'i tamamlandı (canlı Gemini'ye karşı doğrulandı). Şimdi motorun gerçek hayatta test edilebileceği Admin Chat Widget'ı kuruyoruz. Bu, AgentMod'un ilk çalışan ürünü olacak ve sonraki adımda gelecek CPT/Data-Model'den önce motoru görsel olarak kullanılabilir kılacak.

Karara bağlanan kapsam (bu adım):

Floating buton YOK. Bunun yerine wp-admin admin bar'a bir ikon eklenir; tıklanınca @wordpress/components Modal açılır.
Modal içinde sadece metin sohbeti (dosya yükleme Phase 2).
İstekler AIChatRestController üzerinden gider (yeni temiz /chat route'u eklenir).
Frontend JavaScript (.jsx) ile, native WordPress paketleriyle yazılır: @wordpress/scripts, @wordpress/element, @wordpress/components, @wordpress/data, @wordpress/api-fetch, @wordpress/i18n.
Admin component'leri için ayrı bir webpack.config.js (wp-scripts default config'i extend ederek) — mevcut src/first-block otomatik build'i korunur.
İkon ve script sadece wp-admin'de yüklenir.
Kapsam dışı (sonraki adımlar): CPT/Data-Model, kalıcı History/Conversation persistence, dosya/görsel yükleme, FloatingButton.

Mimari Genel Bakış
Admin bar ikonu (PHP) ──click──> @wordpress/data action: openChat()
                                          │
                                  ChatApp (React root, admin_footer)
                                          │
                                  Modal (@wordpress/components)
                                   ├─ MessageList / MessageItem
                                   └─ Composer ──sendMessage(thunk)──> apiFetch
                                                                          │
                                              POST /agent-mod/v1/chat (AIChatRestController)
                                                                          │
                                                       AIOrchestratorService->chat()
1. Build Altyapısı
webpack.config.js (eklenti kökü — YENİ)
wp-scripts default config'i extend eder, first-block block build'ini bozmadan admin-chat entry'sini ekler. defaultConfig.entry hem fonksiyon hem obje olabileceğinden ikisini de destekler:

const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

const baseEntry =
  typeof defaultConfig.entry === 'function'
    ? defaultConfig.entry()
    : defaultConfig.entry;

module.exports = {
  ...defaultConfig,
  entry: {
    ...baseEntry, // first-block + diğer block.json'lar korunur
    'admin-chat/index': path.resolve(process.cwd(), 'src/admin-chat', 'index.js'),
  },
};
Çıktı: build/admin-chat/index.js + build/admin-chat/index.asset.php (bağımlılıklar + version) + build/admin-chat/index.css. @wordpress/* importları DependencyExtractionWebpackPlugin tarafından wp.* global'lerine map edilir; bu paketleri ayrıca npm install etmeye gerek yoktur (build externalize eder).

package.json
Mevcut build/start scriptleri değişmez — wp-scripts kökteki webpack.config.js'i otomatik kullanır. (İsteğe bağlı: IDE IntelliSense için @wordpress/components, @wordpress/data, @wordpress/element devDependency olarak eklenebilir; build için gerekli değil.)

2. Frontend — src/admin-chat/ (YENİ)
CLAUDE.md JS konvansiyonu: agentMod camelCase. Text domain agent-mod, i18n için @wordpress/i18n __().

Dosya	Sorumluluk
index.js	Entry. admin_footer'daki #agent-mod-chat-root'a createRoot ile <ChatApp/> mount eder. Admin bar düğümüne (#wp-admin-bar-agent-mod-chat a) click listener bağlar → dispatch(STORE).openChat() (preventDefault). Store'u import ederek register eder.
store/index.js	@wordpress/data createReduxStore + register. State: { isOpen, messages: [], isLoading, error }.
store/actions.js	openChat, closeChat, appendMessage, setLoading, setError, clearError, ve thunk sendMessage(text) — kullanıcı mesajını ekler, setLoading(true), apiFetch ile POST eder, dönen text'i assistant mesajı olarak ekler, hata olursa setError. (Thunk'lar @wordpress/data'da varsayılan etkin.)
store/reducer.js	Yukarıdaki action'lara göre saf reducer.
store/selectors.js	getMessages, isLoading, isChatOpen, getError.
components/ChatApp.jsx	useSelect(isChatOpen); açıkken <ChatModal/> render eder.
components/ChatModal.jsx	@wordpress/components Modal (title: "AgentMod Assistant", onRequestClose → closeChat). İçinde <MessageList/> + <Composer/>.
components/MessageList.jsx	getMessages map → <MessageItem/>. Yeni mesajda en alta scroll (useRef+useEffect). isLoading iken Spinner.
components/MessageItem.jsx	Tek mesaj balonu; role (user/assistant) ile stil.
components/Composer.jsx	TextareaControl + Button (isPrimary). Enter ile gönder (Shift+Enter yeni satır). Boşsa veya isLoading iken disabled. dispatch(sendMessage).
style.scss	Mesaj listesi, balonlar, composer layout. index.js'te import './style.scss'.
apiFetch çağrısı (store/actions.js içinde)
Nonce, wp-api-fetch script'i admin'de otomatik nonce middleware kurduğu için elle eklenmez:

import apiFetch from '@wordpress/api-fetch';
// ...
const data = await apiFetch({
  path: window.agentModChat.restPath, // 'agent-mod/v1/chat'
  method: 'POST',
  data: { message: text, agent: window.agentModChat.defaultAgent, history },
});
// data.success ? data.text : data.error.message
history, store'daki mevcut messages'tan {role, text} olarak türetilir (kalıcı persistence yok; sayfa yenilenince sıfırlanır — Phase 1 için kabul).

3. Backend Değişiklikleri
includes/Presentation/Admin/Controllers/AIChatRestController.php (DÜZENLE)
registerRoutes() içine kalıcı /chat route'u eklenir; mevcut /test-chat korunur. İkisi de aynı handleChat callback'ine ve checkPermission (manage_options) iznine bağlanır. (Mevcut handleChat zaten message/agent/history alıp AgentResponse->toArray() döndürüyor — değişiklik gerekmez.)

includes/Presentation/Admin/Controllers/AIChatWidgetController.php (YENİ)
Namespace AgentMod\Presentation\Admin\Controllers. Constructor'da hook'lar (BlockService/AdminController desenine uygun):

add_action('admin_bar_menu', [$this,'addToolbarNode'], 100) — is_admin() ve current_user_can('manage_options') guard. $wp_admin_bar->add_node(['id'=>'agent-mod-chat', 'title'=> ikon span (dashicons-format-chat) + label, 'href'=>'#']).
add_action('admin_enqueue_scripts', [$this,'enqueueAssets']) — build/admin-chat/index.asset.php'den deps/version okuyup wp_enqueue_script('agent-mod-chat', AGENT_MOD_URL.'build/admin-chat/index.js', $asset['dependencies'], $asset['version'], true); wp_enqueue_style('agent-mod-chat', '...build/admin-chat/index.css', ['wp-components'], $asset['version']); wp_enqueue_style('wp-components'). Build dosyası yoksa sessizce çık (guard).
Aynı metodda wp_localize_script('agent-mod-chat', 'agentModChat', [...]) → restPath (Constants::REST_NAMESPACE.'/chat'), defaultAgent (provider gemini, abilitySource 'all'), strings (i18n: title, placeholder, send, error).
add_action('admin_footer', [$this,'renderRoot']) — echo '<div id="agent-mod-chat-root"></div>'.
CLAUDE.md kuralları: defined('ABSPATH')||exit;, use importları, class PhpDoc (@package/@subpackage/@since), capability check, esc_* çıktı.

includes/Presentation/ControllerInit.php (DÜZENLE)
AIChatWidgetController::class'ı $adminControllers'a ekle (+use). (AIChatRestController zaten clientControllers'ta — REST her istekte yüklenmeli.)

Kritik Dosyalar Özeti
Yeni:

webpack.config.js
src/admin-chat/index.js, src/admin-chat/style.scss
src/admin-chat/store/{index,actions,reducer,selectors}.js
src/admin-chat/components/{ChatApp,ChatModal,MessageList,MessageItem,Composer}.jsx
includes/Presentation/Admin/Controllers/AIChatWidgetController.php
Düzenlenen:

includes/Presentation/Admin/Controllers/AIChatRestController.php (+/chat route)
includes/Presentation/ControllerInit.php (+AIChatWidgetController → adminControllers)
Yeniden kullanılan: AIOrchestratorService + AgentConfig::fromArray() (Adım 1), Constants::REST_NAMESPACE, AGENT_MOD_URL/AGENT_MOD_PATH.

Doğrulama (End-to-End)
npm install (node_modules yoksa) — kökte wp-content/plugins/agent-mod.
npm run build → build/admin-chat/index.js + index.asset.php + index.css oluşmalı; mevcut build/first-block bozulmamalı.
wp-admin'e manage_options yetkili kullanıcıyla gir → admin bar'da sohbet ikonu görünür.
İkona tıkla → Modal açılır ("AgentMod Assistant").
"Bu sitenin WordPress sürümü nedir?" gönder → agent-mod/get-site-info çağrılır, metin yanıt balonda görünür (Network'te POST /wp-json/agent-mod/v1/chat 200).
Tool-calling: "Son 3 yazıyı listele" → agent-mod/list-recent-posts çalışır, yanıt listelenir.
Hata yolu: Composer boşken/isLoading iken Send disabled; backend WP_Error dönerse hata mesajı balonu/notice görünür.
npm start (watch) ile geliştirme sırasında değişikliklerin yeniden build alındığı doğrulanır.