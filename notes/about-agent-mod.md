AgentMod - WordPress Eklentisi Taslak Dokümanı
AgentMod, WordPress içinde AI ajanları oluşturarak içerik üretme, site yönetimi, önyüz üzerinden kullanıcı etkileşim sağlama ve müşteri ilişkilerini yönetme, iletişim formları, chatbot’lar ekleme imkanı sunan bir eklenti olacaktır.

Genel Yapı:

├─ AI Providers - WordPress 7.0 Connectors ile native olarak mevcut
│
├─ Ability Engine - WordPress Ability API üzerine inşa edilecek
│
├─ Knowledge Engine
│
├─ Agent Engine - Ability’leri kullanabilen ve native WP AI Client’ı kullanarak providerlarla iletişimi sağlan ajan altyapısı
│
├─ Agent Memory/History
│
├─ Workflow Engine
│
├─ Scheduling Engine
│
├─ Reporting Engine
│
└─ Widget/Block Engine


Agent Kavramı
Kullanıcı eklenti ile ajanlar oluşturabilir.
Örnekler:
Visitor Agent
Ziyaretçilerle konuşur.
Bugünkü ürün aslında bu.

Content Agent
Yeni blog yazıları üretir.
Taslak oluşturur.
SEO önerileri verir.

Lead Agent
Lead'leri analiz eder.
Skorlar.
Özet çıkarır.
Yöneticiye rapor gönderir.

Support Agent
Müşteri sorularını yanıtlar.
Ticket oluşturur.

Monitoring Agent
Sitedeki değişiklikleri izler.
Haftalık rapor yollar.

SEO Agent
İçerikleri analiz eder.
Eksikleri bulur.
Görev listesi üretir.

Automation Agent
WP Cron ile çalışır.
Belirli zamanlarda görev yapar.

Custom Agents
Kullanıcı kendi ajanını üretir.


Agent Studio
Ajan oluşturmaya imkan tanıyan ekran.
Ajan oluştururken:
Kimlik
Agent Name
Avatar
Description

Role
Örnek:
Sales Agent
Support Agent
SEO Agent
Travel Agent

Goal
Örnek:
Generate Leads
Answer Questions
Book Appointments
Create Content

Personality
Örnek:
Friendly
Professional
Luxury
Corporate
Ama burada daha ileri gidebiliriz.
Örneğin:
Curious
Aggressive
Helpful
Persuasive
Patient
Bir nevi NPC oluşturur gibi.

Memory
Agent'ın neyi hatırlayacağı.
Örneğin:
Conversation History

Lead History

Site Changes

Generated Content

Knowledge
Şunları kullanabilir:
Pages
Posts
Products
CPTs
Media
Custom Sources


Abilites
WP Abilities API WordPress çekirdeğinde yer alan ve WP üzerine AI ajanlarının anlayabileceği yetenekler eklemeyi sağlayan bir arayüzdür. Yine WordPress çekirdeğinde yer alan WordPress AI Client bu yetenekleri tool olarak AI sağlayıcılara iletebilir. Dolayısıyla eklenti hem kendi yeteneklerini hem de başka eklentiler tarafından oluşturulmuş yetenekleri kullanabilir. Bunun için ekstra bir altyapıya gerek yoktur. Sadece üstyapı gerekir.
AgentMod ability’lerine örnekler::
Create Lead

Send Email

Create Post

Submit Form

Generate Content

Open WhatsApp


Agent Hub
MVP’de yer almasa da (ki alabilir) eklenti başka eklentilerinden hook kullanarak veya özel fonksiyonlar vasıtasıyla veya json input’larla Agent oluşturmasına imkan verebilir. Böylece hem AgentMod tarafından hazır eklenmiş agent’lar hem de başkalarının hub’a eklediği agent lar (bunun için ayrı platform gerekebilir, oradan veriler fetch edilir) Hub içinde görünür. Kullanıcı istediğini import eder. Örnekler:
Tourism Agent Pack
Reservation Agent
Travel Advisor Agent

Clinic Pack
Appointment Agent
FAQ Agent

Real Estate Pack
Property Advisor
Lead Qualification Agent

SEO Pack
Content Agent
Optimization Agent

Agency Pack
Client Reporting Agent
Proposal Agent


Agent Memory
Eklenti oluşturulan ajanların geçmişlerini kayıt altına alabilmeli ve ajan çalışırken bu history’den faydalanabilmelidir.
Mesela:
Agent:
Travel Agent

Hatırlıyor:

- Ahmet geçen ay rezervasyon sormuştu.
- Telefon bırakmıştı.
- Henüz dönüş yapılmamış.
Bu ciddi değer üretir.

Reporting/Notification Engine
Ajanlar yaptıkları işlemleri veritabanı üzerinden hem de mail aracılığı ile raporlayabilmelidir. Ne zaman rapor vereceğine AI karar verir. Veritabanı kayıtları admin panelden görüntülenebilir. Site yönetici süreç içinde gerçekleşen aksiyonlara da e-postalar alabilir.
Bunu biraz kontrollü yapmak lazım.
Tamamen AI'ya bırakmak yerine:
Immediate

Daily Digest

Weekly Summary

AI Recommended
modları olabilir.
Örneğin:
"Bugün 12 yeni lead topladım."
"Bu hafta 3 yüksek potansiyelli müşteri tespit ettim."

Knowledge Engine
Mevcut ability’ler kullanılarak sitenin bilgisi (içerikler, ürünler, site bilgileri, eklentiler, sayfalar, menüler vs) çekilerek bir kaynak veri oluşturulur. Ajan her zaman bu temel bilgiyi kullanarak işlem yapar, kullanıcı sorularını cevaplar, müşteri ilişkilerini kurar.

Workflow Engine - Scheduling Engine
Ajanlar iş akış süreçlerini yönetebilir. Bu noktada scheduling engine (cron jobs) ile birlikte bu süreçler gerçekleştirilir.

Widgets, Blocks, Shortcodes
Kullanıcıların kullanımı için hem admin tarafında hem ön tarafta sohbet pencereleri, butonlar vb. araçlar olmalıdır. Bunlar özel bloklar, kısa kodlar, admin widget’lar olabilir (hoş artık widget sistemi pek kullanılmıyor. Buna gerek olmayabilir)
Floating Chat - Klasik sağ alt köşe

Full Page Assistant - Özel sayfa.

Inline Assistant
İçeriğin içinde.
Örneğin:
Bungalovları incelemek için asistanla konuşun.

Navigation Assistant
Menüde: Planlayıcı butonu.

Form Assistant
Tamamen form gibi görünür.
Ama arkada AI vardır.

MVP V1
Agent Engine
Temel.

Knowledge Engine
Siteyi anlamak.

Ability Engine
WP Abilities.

Visitor Agent
İlk hazır ajan.

Lead Agent
İkinci hazır ajan.

Scheduling Engine
WP Cron tabanlı.

Agent Studio
Temel oluşturucu.

Reporting
Mail + Dashboard.

Widget System
Chat
Inline
Full Page
Form

Özellikle Yapmayacağım Şeyler
MVP'de şunlara girmem:
MCP
Multi-agent collaboration
Agent-to-agent messaging
Voice
CRM entegrasyonları
Marketplace
Workflow builder
Çünkü bunlar ürünü 6 ay geciktirir.

Ürün Genel Tanımı:
AgentMod is an AI Agent Platform for WordPress.
Using WordPress AI Client, Abilities API, and native AI Providers, AgentMod  enables website owners to create intelligent AI agents that understand their website, interact with visitors, execute actions, automate workflows, and generate business outcomes.
Agents can capture leads, answer questions, create content, send notifications, execute WordPress abilities, and perform scheduled tasks.
From visitor-facing assistants to autonomous business agents, AgentMod transforms WordPress from a content management system into an AI-powered operating platform.
Bu vizyon, mevcut "AI chatbot" pazarından çıkıp, WordPress çekirdeğine gelen AI altyapısının üzerine kurulan ilk gerçek "WordPress Agent Platform" ürünlerinden biri olma şansı veriyor. Özellikle Abilities API ve WP AI Client'ın merkezde olması, ürünü sıradan chat çözümlerinden belirgin şekilde ayırır.

Bir başka fırsat da WordPress ekosisteminde çok az kişinin düşündüğü şu konu:
Ability Economy
Başka eklentiler ability yayınlar.
Örneğin:
WooCommerce → create_order
FluentCRM → create_contact
The Events Calendar → create_booking
Amelia → book_appointment
AgentMod bunların hiçbirini özel entegrasyon yazmadan kullanabilir.
Bu noktada AgentMod 
"WordPress'teki tüm AI özelliklerini bir araya getiren orkestrasyon katmanı"
haline gelir.
Bence uzun vadeli en büyük değer burada.

AgentMod  is the AI Agent Platform for WordPress. Create intelligent agents that understand your website, interact with visitors, execute abilities, automate workflows, and generate business outcomes.
