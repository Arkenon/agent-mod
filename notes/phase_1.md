
Amaç

Phase 1'in amacı AgentMod'un temelini oluşturacak 3 çekirdek sistemi üretmektir:

AI Engine (Orchestration Layer)
Agent veri modeli ve yönetim altyapısı
Admin Chat Widget

Bu aşamanın sonunda:

Agent oluşturulabilecek
Agent'lar Ability kullanabilecek
Admin panelden agent ile sohbet edilebilecek
History tutulabilecek
Knowledge kaynakları tanımlanabilecek
Gelecekte Visitor Agent, Lead Agent ve Widget sistemlerinin üzerine inşa edileceği altyapı hazır olacak
1. DATA MODEL & NATIVE CUSTOM FIELDS YAPISI

Bu bölüm tamamen Native Custom Fields Pro ile oluşturulacaktır.

Custom Post Types
1. agentmod_agent

Sistemdeki ajanları temsil eder.

Temel Bilgiler
Alan	Tip
title	WP Title
status	draft/publish
description	textarea
avatar	image
icon	text
agent_type	select
system_prompt	wysiwyg
welcome_message	textarea
Agent Type

İlk sürüm:

Generic Agent
Visitor Agent
Lead Agent
Content Agent
Support Agent

İleride:

SEO Agent
Monitoring Agent
Automation Agent
2. agentmod_conversation

Sohbet oturumlarını temsil eder.

Alanlar
Alan	Tip
agent_id	relationship
session_id	text
source	select
started_at	datetime
last_message_at	datetime
status	select
Source
Admin Chat
Frontend Widget
Full Page Assistant
API
3. agentmod_report

AI tarafından oluşturulan raporlar.

Alanlar
Alan	Tip
agent_id	relationship
report_type	select
content	wysiwyg
severity	select
generated_at	datetime
4. agentmod_knowledge

Bilgi kaynakları.

Alanlar
Alan	Tip
source_type	select
title	title
description	textarea
enabled	true/false
Source Type
Posts
Pages
CPT
Media
URL
Manual Content

Phase 1'de sadece tanımlanacak.

Indexleme daha sonra gelecek.

Custom Taxonomies
agent_category

Agent kategorileri.

Örnek:

Sales
Support
Marketing
SEO
Tourism
Real Estate
agent_tag

Etiket sistemi.

Örnek:

multilingual
lead-generation
booking
support
Agent Meta Fields
Identity Tab
Agent Name

WP Title

Description

Textarea

Avatar

Image

Role

Select

Örnek:

Sales Agent
Support Agent
Assistant
Content Creator
Goal

Textarea

Örnek:

Generate Leads
Answer Questions
Create Content
Personality Tab

Çoklu seçim.

Personality Traits
Helpful
Friendly
Professional
Corporate
Persuasive
Curious
Patient
Analytical

Bu alan daha sonra system prompt üretiminde kullanılacak.

Memory Tab
Memory Strategy
Disabled
Session Only
Persistent
Max History Messages

Number

Varsayılan:

20

Summarize History

Boolean

Knowledge Tab

Relationship

Agent hangi knowledge kaynaklarını kullanacak.

Ability Tab

Bu bölüm çok önemli.

Agent'a hangi ability'lerin açık olduğunu belirler.

Ability Source
All Registered Abilities
Selected Abilities
Allowed Abilities

Multi Select

Burada WordPress Ability Registry okunacak.

Örneğin:

create_post
update_post
send_email
create_lead
Plugin Options
General
Default Provider

Provider seçimi

Default Model

Model seçimi

Max Context Window

Sayısal değer

Enable Logging

Boolean

AI Engine
Max Tool Calls

Varsayılan:

10

Tool Call Strategy
Sequential
Parallel
Enable Multi Tool Calls

Boolean

Enable History Compression

Boolean

Knowledge
Auto Include Site Context

Boolean

Auto Include Site Information

Boolean

Debug
Debug Mode

Boolean

Store Requests

Boolean

Store Responses

Boolean

2. AI ENGINE (ORCHESTRATION LAYER)

Bu proje aslında WP AI Client'ın alternatifi değildir.

WP AI Client zaten:

Provider iletişimi
Tool Calling
Message yapısı
Ability entegrasyonu

gibi işleri yapmaktadır.

AgentMod'un görevi bunları orkestre etmektir.

Temel Sınıflar
AgentEngine

Ana giriş noktası.

$agentEngine->chat(
    $agent,
    $message
);

Görevleri:

Agent yüklemek
Prompt oluşturmak
Knowledge eklemek
History eklemek
Ability eklemek
WP AI Client çalıştırmak
PromptBuilder

Şunları birleştirir:

System Prompt
Role
Goal
Personality
Site Context

çıktı:

You are a professional support agent...
AbilityResolver

WordPress Ability Registry'den yetenekleri toplar.

Agent'ın izin verdiği ability'leri filtreler.

çıktı:

[
   CreatePostAbility,
   SendEmailAbility
]
HistoryManager

Sohbet geçmişini yönetir.

İlk sürüm:

Database tabanlı.

Fonksiyonlar:

saveMessage()
loadHistory()
summarize()
KnowledgeResolver

Agent'ın erişebileceği kaynakları hazırlar.

İlk sürüm:

Site Name
Site Description
Admin Email
WordPress Version

Daha sonra RAG katmanı gelecek.

ConversationManager

Conversation oluşturur.

Mesajları saklar.

Session yönetir.

AIClientAdapter

WP AI Client ile iletişim kuran katman.

Amaç:

WP AI Client API değişirse tüm sistemi etkilememesi.

AI ENGINE FLOW
User Message
      ↓
Conversation Manager
      ↓
History Manager
      ↓
Prompt Builder
      ↓
Knowledge Resolver
      ↓
Ability Resolver
      ↓
WP AI Client
      ↓
Tool Calls
      ↓
Response
      ↓
Save History
Phase 1 Yetenekleri

Desteklenecek:

✅ Tool Calling

✅ Multiple Tool Calls

✅ Conversation History

✅ Agent Memory

✅ Image Input

✅ Document Input

✅ Ability Execution

❌ Multi Agent

❌ Workflow Engine

❌ MCP

❌ Vector Database

3. ADMIN CHAT BOT WIDGET

Bu aslında AgentMod'un ilk çalışan ürünü olacak.

Amaç:

AI Engine'in gerçek hayatta test edilmesi.

Teknik Yapı

Frontend:

React
TypeScript
wp-components
@wordpress/data
@wordpress/api-fetch
Görünüm

Admin panel sağ alt köşe:

 ┌─────────┐
 │   AI    │
 └─────────┘

Tıklayınca:

┌─────────────────────────┐
│ AgentMod Assistant      │
├─────────────────────────┤
│                         │
│ Conversation            │
│                         │
├─────────────────────────┤
│ Type message...         │
└─────────────────────────┘
İlk Kullanım Senaryoları
Ability Testleri

"Yeni bir yazı oluştur"

Agent:

create_post ability çağırır
yazıyı oluşturur
sonucu döndürür
Site Bilgisi

"Kullandığım WordPress sürümü nedir?"

Agent:

Knowledge Resolver kullanır.

Yönetim Yardımcısı

"Son yayınlanan yazıları listele"

Agent:

Ability çağırır.

React Bileşenleri
FloatingButton

Widget aç/kapat.

ChatWindow

Ana pencere.

MessageList

Mesajlar.

MessageItem

Tek mesaj.

Composer

Mesaj giriş alanı.

AttachmentUploader

Görsel ve doküman yükleme.

ConversationStore

State yönetimi.

Phase 1 Çıkış Kriterleri

Bu faz tamamlandığında:

Agent oluşturulabiliyor
Ability seçilebiliyor
Agent ile sohbet edilebiliyor
History saklanıyor
Multiple tool call çalışıyor
Görsel yüklenebiliyor
Doküman yüklenebiliyor
Admin Chat Widget kullanılabiliyor
WP AI Client tam entegre çalışıyor

Bu noktada AgentMod artık sadece bir "AI chat eklentisi" değil, gelecekte Visitor Agent, Lead Agent, Scheduling Engine ve Widget Engine'in üzerine kurulacağı gerçek bir WordPress Agent Platform çekirdeği haline gelmiş olacaktır.