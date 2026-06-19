Mevcut Phase 1 durumunu, eklenti mimarisini, aldığınız notları ve WordPress çekirdeği (Core) ile olan entegrasyon tartışmalarını derinlemesine analiz ettim.

Şu anki durumunuzda projenin en karmaşık katmanı olan Stateless AI Orchestrator Engine (Çoklu Tool-Call Döngüsü) ve REST API/React Chat entegrasyonunu başarıyla tamamlayarak yolu yarılamış durumdasınız. Ancak WordPress Core AI ekibiyle paralel yürüyen tartışmalar, gözden kaçan bazı kritik UX ve güvenlik açıklarını netleştirerek MVP'nin kaderini belirleyecek nitelikte.

Tüm bu dokümanları, dağınık notları ve yeni kararları bir araya getirerek iş akışınızı netleştirecek, mimariyi toparlayacak ve projenizi nihayete erdirecek Bütünsel Stratejik Yol Haritası ve Nihai Ürün Spesifikasyon Dokümanı aşağıda hazırlanmıştır.

---

# 🚀 AgentMod — Nihai Ürün Spesifikasyon Dokümanı & Yol Haritası (V1.0)

## 1. Ürün Vizyonu ve Stratejik Konumlandırma

AgentMod, WordPress için geliştirilmiş bir **AI Ajan Platformudur (AI Agent Platform)**. Pazardaki sıradan "AI Chatbot" eklentilerinden en büyük farkı, WordPress 7.0 ile native olarak gelen *Connectors*, *WP AI Client* ve *Abilities API* altyapısını tam merkezine alarak bir orkestrasyon katmanı görevi üstlenmesidir.

AgentMod, harici servislerle özel entegrasyon kodları (glue-code) yazmak yerine bir **Ability Ekonomisi** yaratır. WooCommerce (`create_order`), FluentCRM (`create_contact`) veya Amelia (`book_appointment`) gibi eklentilerin dışarıya açtığı yetenekleri (abilities) WordPress Core Registry üzerinden otomatik olarak okur, ajanların kullanımına sunar ve sitenizi otonom bir işletim platformuna dönüştürür.

---

## 2. Mevcut Durum Analizi (Gap Analysis)
Eklentinin FREE ve PRO olacak şekilde 2 versiyon olarak yayınlanma kararı alındı.

### ✅ Tamamlananlar - FREE VERSION
- **AI Engine (Orchestration Layer):** Stateless çalışan, model-agnostic, WordPress native AI Client tabanlı çalışan ajan motoru.
- **Çoklu Yetenek Çağrısı:** `WP_AI_Client_Ability_Function_Resolver` entegrasyonu ile ardışık veya paralel çoklu yetenek çağrısı (multiple tool call) döngüsü stabil çalışıyor.
- **ChatPanel & Admin Chat Widget:** React tabanlı, WordPress Admin Bar üzerinden tetiklenen, `@wordpress/components` Modal mimarisini kullanan çalışan ilk arayüz ürünü.
- **Temel Yetenekler:** `get-site-info` ve `list-recent-posts` gibi salt okunur (read-only) demo yetenekler test edildi.
- **Prompt Tanımlarının Güvenli Hale Getirilmesi:** `PromptBuilder.php` içerisine ajanların yıkıcı eylemler yapmasını kesin olarak yasaklayan ve belirsiz durumlarda netleştirici sorular sormasını (co-pilot clarifying questions) emreden ana sistem direktiflerini eklendi.
- **Sert Limitlerin Kodlanması:** `AbilityRegistrarService.php` ve `AIClientAdapter.php` katmanlarında arama için maks 20 sonuç, tam gövde okuma için maks 5 post sınırlarını uygulayın.
- **"New Topic" Özelliği:** `ChatPanel.jsx` bileşenine oturumu/mesaj geçmişini sıfırlayan bir "New Topic" (Temizle) butonu eklendi.
- **Gelişmiş Arayüz Toggle Bileşeni (Context Scope Selector):** `Composer.jsx` üzerine kullanıcının AI'ın sitenin verilerini okuyup okuyamayacağını seçeceği bir toggle eklendi.

### ❌ Eksik Alanlar & Riskler - FREE VERSION
- **Güvenlik / UX Boşluğu (Yazma/Silme Onay Sistemi):** Ajanın `create_post` veya yıkıcı (destructive) yetenekleri tetiklerken kullanıcıya sormadan direkt arkada çalıştırması ciddi güvenlik riski taşıyor.

### ❌ Eksik Alanlar & Riskler - PRO VERSION
- **Veri Modelleri:** `agentmod_agent` ve `agentmod_conversation` veri yapıları henüz veritabanına basılmıyor, kalıcı hafıza (history persistence) bulunmuyor. (AgentCPTService.php oluşturuldu ancak aktif değil. CPT ve custom fields özellikleri, Native Custom Fields eklentisi kullanılarak ayrıca projeye dahil edilecek. Bunun için ayrı çalışma yapılacak.)
- **Yapısal Veri Gösterimi Eksikliği (DataViews):** Ajan "Son 3 yazıyı bul" dediğinde sonuçlar ham markdown metin olarak dökülüyor. WordPress'in yeni nesil interaktif tablo bileşeni olan *DataViews* entegrasyonu bulunmuyor. Bunun için ayrı çalışma yapılacak.)
- **Gutenberg Bağlantısı Kopukluğu:** Blok editörün içinden AI Workspace'e bağlamsal geçiş (handoff) senaryosu tasarlanmamış. Bunun için ayrı çalışma yapılacak.
- **Ajan ve Model Yönetim UI Eksikliği:** Kullanıcı arayüzden ajan seçemiyor, statik bir bot çalışıyor. Bunun için ayrı çalışma yapılacak.

---

## 3. Güncellenmiş Mimari ve İş Akış Süreçleri (Workflow)

Ajan etkileşim döngüsü, güvenlik ve performans sınırlarını korumak adına katı kurallara bağlı bir middleware katmanından geçmek zorundadır.

### 🔄 Genişletilmiş AI Engine Akış Şeması

``` plaintext
[Kullanıcı Mesajı / Dosya] - FREE
       │
       ▼
[ChatPanel / Composer] ──► Context Scope Kontrolü (Site Context / General Knowledge) - FREE
       │
       ▼
[ConversationManager] ──► Oturum ve Token Limit Kontrolü (Hard Limits) - FREE
       │
       ▼
[HistoryManager] ──► Geçmiş Mesajların Sıkıştırılması / Özetlenmesi - PRO
       │
       ▼
[PromptBuilder] ──► System Prompt + Kişilik + RAG / Site Bağlamı Birleştirme - FREE
       │
       ▼
[AbilityResolver] ──► WP Ability Registry Filtreleme + Yetki/Capability Kontrolü - FREE
       │
       ▼
[AIClientAdapter] ──► (DÖNGÜ) WP AI Client ◄──► Canlı LLM Sağlayıcısı - FREE
       │
       ├─► [Tool Call Algılandı mı?]
       │         │
       │         ├──► [Yazma/Silme İşlemi mi?] ──► EVET ──► [Onay Modalı Göster] (UI)
       │         │                                               │ (Kullanıcı Onayı)
       │         │                                               ▼
       │         └──► [Salt Okunur / Onaylandı] ──► WP_Ability::execute_abilities()
       │
       ▼
[Artifact Sınıflandırma] ──► Markdown Metin / DataViews Tablo / Code Snippet - PRO
       │
       ▼
[ChatPanel / MessageItem] ──► Eylem Butonları ile Render (Copy, Create Draft, Edit Link) - PRO
```

### 🔒 Güvenlik & Hard Limits Protokolleri

*   **Yıkıcı Eylem Yasağı:** Ajanlar hiçbir silme (`delete`, `erase`, `trash`) yeteneğini asla direkt execute edemez. Sadece *"Aşağıdaki içerikleri silmek isteyebilirsiniz"* diyerek ilgili içerikleri interaktif olarak listeler, silme kararı ve eylemi son kullanıcıya bırakılır.
*   **Yazma Onay Modalı (Artifact Confirmation):** `create_post` veya `send_email` gibi veritabanını değiştiren bir tool tetiklendiğinde, gerçek PHP fonksiyon çağrısından önce arayüzde bir özet onay modalı açılır. Kullanıcı onay vermeden işlem yürütülmez.
*   **Yetenek Yetki Kontrolü (Middleware Layer):** AI motoru veritabanına doğrudan erişemez. Her yetenek çağrısından önce PHP katmanında `current_user_can()` kontrolü yapılır. Örneğin; `edit_private_posts` yetkisi olmayan bir kullanıcının seansı için ajan gizli postları listeleyemez ve *"Yetkiniz yetersiz"* hatası fırlatır. (Ability'lerin yetki kontrolü zaten mevcut, bu kontrol zaten yapılıyor olabilir. Test edilecek.)

#### 📦 İçerik Boyutu Sınırları (RAG-Lite Limits)
*   **Search Tool:** Arama sorgularında ajan tek seferde maksimum 20 başlık/özet görebilir.
*   **Full Content Processing:** Latency ve token maliyetlerini yönetmek için ajan aynı anda en fazla 5 post gövdesinin tam içeriğini okuyabilir. *"Sitedeki tüm 500 postu analiz et"* talepleri zarif bir hata mesajı ile reddedilir.
*   **Max Tool Calls:** Bir mesaj döngüsünde ajan en fazla 10 kez tool-call yapabilir.

---

## 4. Nihai Ürün Veri Modeli Yapısı (CPT & Meta)

Veri modeli altyapısı, sistemin kalıcı hale getirilmesi için **Native Custom Fields Pro** (veya benzeri bir altyapı) ile aşağıdaki şemaya göre kurulacaktır:

### 1. `agentmod_agent` (Ajan Tanımları CPT)
*   **Başlık & Durum:** `post_title` (Ajan Adı), `post_status` (draft/publish).
*   **Meta Alanları:**
    *   `description` (Textarea): Ajanın kısa tanımı.
    *   `avatar` (Image) & `icon` (Text).
    *   `system_prompt` (WYSIWYG / Editor Override).
    *   `role` (Select) & `goal` (Textarea).
    *   `personality_traits` (Multi-select): `helpful`, `friendly`, `professional`, `corporate` (Eğer agent_type seçilmemişse, ayrıca girilebilir.), `persuasive`, `curious`, `patient`, `analytical` *(PromptBuilder'da kullanılacak)*.
    *   `allowed_abilities` (Multi-select): Core Registry'den okunan ve bu ajana izin verilen yetenekler (`all`, `selected`). (Eğer agent type seçilmemişse ayrıca girilebilir.)

### 2. `agentmod_conversation` (Sohbet Oturumları CPT)
*   **Meta Alanları:**
    *   `agent_id` (Relationship): Bağlı olduğu ajan. (Select field, agent_type listesinden seçim)
    *   `session_id` (Text): Benzersiz oturum kodu.
    *   `source` (Select): `admin_chat`, `frontend_widget`, `full_page_assistant`.
    *   `started_at` & `last_message_at` (Datetime).
    *   `messages_history` (JSON / LongText): `{role: 'user'|'assistant'|'tool', content: '...', tool_calls: []}` dizisi. (Acaba comment yapısı kullanılabilir mi? Araştırılacak.) 

### 2. `agentmod_agent_type` (Ajan türü Custom Taxonomy)
*   **Tanımlama:** `agentmod_agent` CPT'sine bağlı olarak çalışacak.
*   **Örnek Tipler:** `generic`, `visitor`, `lead`, `content`, `support`.
*   **Meta Alanları:**
    *   `agent_type_description` (Textarea): Ajan türünün kısa tanımı.
    *   `agent_type_icon` (Image).
    *   `agent_type_system_prompt` (WYSIWYG / Editor Override).
    *   `agent_type_personality_traits` (Multi-select): `helpful`, `friendly`, `professional`, `corporate`, `persuasive`, `curious`, `patient`, `analytical` *(PromptBuilder'da kullanılacak)*.
    *   `agent_type_allowed_abilities` (Multi-select): Core Registry'den okunan ve bu ajana izin verilen yetenekler (`all`, `selected`).


---

## 5. İleri Düzey Arayüz (UX) ve Çıktı Bileşenleri - PRO

Ajanın verdiği ham yanıtlar, içerik tipine göre otomatik ayrıştırılarak **Artifact Sistemi** ile render edilecektir:

*   **Metin Çıktısı (Standart):** Yanıt düz metin ise standart markdown render edilir; altına "Metni Kopyala" veya "Taslak Yazı Oluştur" eylem butonları yerleştirilir.
*   **Kod Çıktısı:** Yanıt bir kod bloğu (HTML, CSS, PHP vb.) içeriyorsa Syntax Highlighting uygulanmış bir `<pre>` konteynerinde gösterilir ve üzerinde "Kodu Kopyala" butonu yer alır.
*   **Yapısal Veri Çıktısı (DataViews Entegrasyonu):** Ajan veri listelediğinde (örn: *"Görseli eksik olan son 5 yazı"*), bu veriler düz metin yerine WordPress native **DataViews** bileşeniyle tablo veya kart düzeninde render edilir. Tablo satırlarında ilgili postu düzenlemek için doğrudan "Edit" linkleri yer alır.

---

## 6. Stratejik Yol Haritası (Milestones)

Projenin mevcut durumunu bozmadan, en hızlı katma değer üreten işlerden en karmaşık entegrasyonlara doğru sıralanmış 3 aşamalı yeni yol haritası:

### 🛠️ FREEE VERSION — Kısa Vade (Hızlı Kazanımlar: Güvenlik, Prompt & UI Cilalama)
- **Prompt Tanımlarının Güvenli Hale Getirilmesi:** `PromptBuilder.php` içerisine ajanların yıkıcı eylemler yapmasını kesin olarak yasaklayan ve belirsiz durumlarda netleştirici sorular sormasını (co-pilot clarifying questions) emreden ana sistem direktiflerini ekleyin.
- **Sert Limitlerin Kodlanması:** `AbilityRegistrarService.php` ve `AIClientAdapter.php` katmanlarında arama için maks 20 sonuç, tam gövde okuma için maks 5 post sınırlarını uygulayın.
- **"New Topic" Özelliği:** `ChatPanel.jsx` bileşenine oturumu/mesaj geçmişini sıfırlayan bir "New Topic" (Temizle) butonu ekleyin.
- **Gelişmiş Arayüz Toggle Bileşeni (Context Scope Selector):** `Composer.jsx` üzerine kullanıcının AI'ın sitenin verilerini okuyup okuyamayacağını seçeceği bir toggle ekleyin. UI'da "Site Context (RAG)" ve "General Knowledge" seçenekleri açıkça belirtilmelidir.

### ⚙️ PRO VERSION — Orta Vade (Veri Modeli, Hafıza ve Güvenlik Arayüzleri)
- **Hafıza ve CPT Katmanı:** `agentmod_agent` ve `agentmod_conversation` veri modellerini Native Custom Fields kullanarak oluşturun. `HistoryManager` servisini geçici session bazlı yapıdan çıkarıp bu CPT veritabanı şemasına bağlayarak kalıcı hale getirin.
- **Ajan Seçim ve Konfigürasyon Ekranı (Agent Studio / Hub):** Chat arayüzünün üst kısmına, veritabanındaki ajanları listeleyen ve sohbet anında ajan değiştirmeyi sağlayan bir dropdown/seçici yerleştirin.
- **Onay Modalı (Write-Confirmation Modal):** Ajan bir yazma/düzenleme toolu tetiklediğinde React arayüzünde patlayacak olan `@wordpress/components` tabanlı onay modalını kodlayın.
- **Dosya/Görsel Yükleme Altyapısı:** `AttachmentUploader` bileşenini tamamlayarak Phase 1'deki `with_file` motor desteğini arayüze bağlayın (Görsel analiz yeteneği).

### 🚀 PRO VERSION — Uzun Vade (DataViews, Gutenberg Handoff ve Tam Ekran Deneyimi)
- **DataViews Entegrasyonu:** Ajanın döndürdüğü yapısal JSON nesnelerini yakalayıp ekranda WordPress DataViews tablosu olarak render eden dinamik `MessageItem.jsx` geliştirmesini yapın.
- **Block Editor Handoff:** Gutenberg editör toolbarına bir "Open in AI Workspace" butonu ekleyin. Bu buton tıklandığında mevcut postun ID, content ve meta bilgisini session context'ine alarak tam ekran çalışma alanına paslasın.
- **Full-Screen AI Workspace Modu:** WordPress Site Editor deneyimine benzer şekilde, WP admin sol menüsünü gizleyen, odaklanmış, geniş ekran bir AI Workspace tam sayfa arayüzü sunun. (mevcut durumda admin-menu-content.php ekranı daha genişletilmiş bir görünüm sunuyor ama site editor kadar değil)

---

## 7. Hook Sistemi
FREE versiyon ve PRO versiyon arasında bağlantıyı sağlamak için PHP ve JS hook'lar oluşturulacaktır.
Bunun için ayrı bir çalışma yapılacak. (Prompt filtreleme, promt öncesi ve sonrası action'lar, execution öncesi ve sonrası için action ve filtreler, ChatPanel tool section customizations, tool ekleme çıkarma etc.) (Mevcut Ability Resolving altyapısı ve WP Core bize ne gibi kancalar sunuyor, bunlara da bakılmalı. WP AI Client içinde neler var, incelenecek. JS tarafı için de benzer bir durum sözkonusu mu araştırılacak.) 


## 8. MVP Kapsam Dışı Bırakılanlar (De-Scoped)

Ürünün pazara çıkış süresini (Time-to-Market) geciktirmemek adına MVP aşamasında kesinlikle girilmeyecek başlıklar:
- Model Context Protocol (MCP) entegrasyonu.
- Multi-agent collaboration (Ajanların kendi arasında yazışması).
- Sesli etkileşim (Voice) katmanı.
- Gelişmiş harici CRM entegrasyonları (Yerel yeteneklerle çözülecek).
- Sürükle-bırak görsel Workflow Builder.

---

Bu doküman, şu an elinizde yarıda kalmış olan yazılım parçalarını birbirine bağlayan ve projenizi endüstri standartlarında bir mimariye ulaştıracak olan ana rehberinizdir. Doğrudan Phase 1.5 adımlarını uygulayarak kodlamaya devam edebilirsiniz.
