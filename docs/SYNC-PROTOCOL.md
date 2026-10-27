# Senkron Protokolü

Masaüstü istemcinin sunucuyla veri alışverişini tanımlar: kapsam manifesti, artımlı okuma (pull), outbox gönderimi (push), çakışma kuralları, cihaz kimliği ve saklama süreleri. Sunucu tarafı web reposunda `Modules/Sync` olarak yaşar; masaüstü reposu bu sözleşmeleri upstream'den aynen alır.

Kararların gerekçeleri:

- [ADR 0004](adr/0004-sync-xid-watermark.md): xid watermark ile pull
- [ADR 0005](adr/0005-sync-push-and-conflicts.md): push sonuçları ve çakışma kuralları
- [ADR 0006](adr/0006-offline-manifest.md): offline-manifest ve ONLINE_ONLY
- [ADR 0007](adr/0007-device-tokens.md): cihaz token'ları
- [ADR 0008](adr/0008-sync-module-adapters.md): senkron modülü ve modül adaptörleri

## Sözleşme dosyaları

Tüm şemalar JSON Schema draft 2020-12'dir. Tel tipleri bu şemalardan üretilir: Rust için `typify`, TypeScript için `json-schema-to-typescript`. PHP testleri sunucu yanıtlarını aynı şemalara karşı doğrular.

| Dosya | İçerik |
|---|---|
| `contracts/offline-manifest.json` | Hangi kaynağın nasıl okunup yazılabildiği, ONLINE_ONLY işlemler. Sunucu ve Rust aynı dosyayı okur. |
| `contracts/offline-manifest.schema.json` | Manifestin şeması. |
| `contracts/sync/common.schema.json` | Ortak tanımlar: `uuid`, `uuidv7`, `xid8`, `cursor`, `visibilityGeneration`, `scope`, `resourceName`, `timestamp`, `requires`, `apiError`, `resourceRow`. |
| `contracts/sync/manifest.response.schema.json` | `GET /api/v1/sync/manifest` yanıtı. |
| `contracts/sync/pull.request.schema.json` | `POST /api/v1/sync/pull` isteği. |
| `contracts/sync/pull.response.schema.json` | `POST /api/v1/sync/pull` yanıtı. |
| `contracts/sync/push.request.schema.json` | `POST /api/v1/sync/push` isteği. |
| `contracts/sync/push.response.schema.json` | `POST /api/v1/sync/push` yanıtı. |
| `contracts/sync/tombstone.schema.json` | Silinen satır bildirimi. |
| `contracts/sync/error-codes.schema.json` | `errors.code` değerleri (mevcut `ErrorCode` + önerilen senkron kodları). |
| `contracts/sync/error.response.schema.json` | İstek düzeyindeki hata zarfı. |
| `contracts/sync/device-login.request.schema.json`, `device-refresh.request.schema.json`, `device-token.response.schema.json` | Cihaz girişi ve refresh token yenilemesi (Identity; D1'de OpenAPI'ye taşınır). |
| `contracts/sync/fixtures/` | Her şema için geçerli örnekler; `fixtures/invalid/` şemanın reddetmesi gereken örnekler. Dosya adı `<şema>.<durum>.json`. |

Şema `$id` değerleri `https://keystone-erp.invalid/contracts/...` biçimindedir. Bu adres çözülmez; yalnızca dosyalar arası `$ref` için taban URI'dir.

Şemanın ifade edemediği kurallar (alan kümesi eşitliği, sürüm karşılaştırması, saklanan sonuçlar) bu belgede **sunucu kuralı** olarak tanımlıdır ve Pest testleriyle zorlanır.

## Genel kurallar

- Uçlar `/api/v1` altındadır. Başarılı yanıt `{data, meta}`, hata `{errors:{message, code, fields?}}` biçimindedir (`docs/ENGINEERING-RULES.md`).
- Kimlik doğrulama `Authorization: Bearer <cihaz token'ı>` ile yapılır; token'ın `desktop` yeteneği olmalıdır. Şirket `X-Company-Id` başlığıyla seçilir (`company` middleware'i). Senkron uçları `auth:sanctum`, `password.changed` ve `company` middleware'lerinin arkasındadır.
- Her senkron durumu (cursor, outbox, yerel depo) **kullanıcı + şirket** çiftine bağlıdır. Şirket değişince başka bir yerel veri kümesine geçilir.
- `xid8` değerleri 64 bittir ve **ondalık string** olarak taşınır. JavaScript sayısına çevrilmez.
- Zaman damgaları API Resource'lardaki gibi ISO 8601 ve ofsetlidir.
- **Meşgul satır kilidi.** Başka bir transaction'ın tuttuğu satır kilidi yüzünden reddedilen deyim (SQLSTATE `55P03`: `NOWAIT` reddi veya `lock_timeout`) REST'te HTTP 409 `VERSION_CONFLICT` ve `Retry-After: 1` döner; istemci kaydı yeniden yükleyip tekrar dener (`Core/Http/ExceptionRenderer.php:55`, `:63`). Diğer veritabanı hataları 500 `SERVER_ERROR` kalır. Push bu durumu farklı yanıtlar (İşleme bölümünün sonundaki geçici hatalar).

### Hız ve eşzamanlılık sınırları

Üç senkron ucu da `throttle:sync` arkasındadır (`Modules/Sync/routes.php:11`, `SyncServiceProvider.php:35`). Sınırlar `config/sync.php` `throttle` bölümündedir (`config/sync.php:22-29`); manifest ve tel sözleşmesine girmez.

| Sınır | Varsayılan | Anahtar |
|---|---|---|
| Cihaz başına istek | Dakikada 120 (`device_per_minute`) | Doğrulanmış cihaz: `user_devices.id`. Token yenilemesi kotayı sıfırlamaz. Cihaz satırı olmayan `desktop` token'ı `token:<id>` anahtarına düşer (`EnsureDesktopToken.php:31`, `:65-67`). |
| Kullanıcı başına istek | Dakikada 300 (`user_per_minute`) | Kullanıcı kimliği. |
| Cihaz başına eşzamanlı pull | 1 (`concurrent_pulls_per_device`) | `user_devices.id`; yenilenen access token aynı slotu kullanır. |
| Kullanıcı başına eşzamanlı pull | 3 (`concurrent_pulls_per_user`) | Kullanıcı kimliği. |
| Pull slot süresi | 120 sn (`pull_slot_seconds`) | Ölen işçinin slotu bu süre sonunda düşer. |

- Dakika sınırı aşılınca istek 429 `TOO_MANY_ATTEMPTS` ve `Retry-After` başlığıyla, mevcut hata zarfında döner (`Core/Http/ErrorCode.php:106`).
- Eşzamanlı pull sınırı yalnız `/sync/pull`'dadır (`LimitConcurrentPulls`). Slot alınamazsa istek işe başlamadan 429 `TOO_MANY_ATTEMPTS` ve `Retry-After: 1` alır (`LimitConcurrentPulls.php:68-70`). Slotlar cache kilididir; pull bittiğinde, hatayla bitse bile, bırakılır (`:81-87`). Sınır ve aşağıdaki pull süresi yalnız uygulamanın cache store'u kilit destekliyorsa (`LockProvider`) ve istekte kullanıcı ile cihaz anahtarı çözülmüşse kurulur; aksi halde pull sınırsız ve süresiz devam eder (`:54-57`). Pull süresi ayrıca veritabanı bağlantısının Laravel `Connection` olmasını ister (`:75-77`).
- **Pull süresi (lease).** İsteğin tek bir bitiş zamanı vardır; sayaç istek slot beklemeye başladığında, slot alınmadan önce başlar (`LimitConcurrentPulls.php:59`, `:76`). Süre slot süresinden 5 sn kısadır; slot süresi 10 sn veya daha kısaysa yarısıdır (120 sn → 115 000 ms; `LimitConcurrentPulls::deadlineMs`, `:94-99`). `PullLease` bağlantının `transaction_timeout` ve `statement_timeout` ayarlarını kalan süreyle kurar ve her deyimden önce kalan süreyi denetler: süre bitmişse deyim çalıştırılmadan 500 `SERVER_ERROR` ve `Retry-After: 1` döner. Kalan süre, geçerli `statement_timeout`'un 100 ms'den fazla altına indiğinde `statement_timeout` kalan süreye indirilir; ayarlama adımlı olduğundan ve ayar sorgusundan sonra süre yeniden ölçülmediğinden (`:134-136`), bir deyimin zaman aşımı kalan süreyi küçük ama garanti edilmeyen bir miktar aşabilir (`PullLease.php:33`, `:71-84`, `:112-139`). Pull bitince lease devreden çıkar ve ayarlar önceki değerlerine döndürülür; bu geri yükleme sorguları süre denetimine tabi değildir. Geri yüklenemezse bağlantı kapatılır (`:96-110`). Kesin toplam süre garantisi yoktur; bkz. Bilinen sınırlamalar.

### Kaynak adları

Kaynak adı, `/api/v1` sonrasındaki gerçek route yolunun parametresiz bölümlerinin `.` ile birleşimidir:

| Route | Kaynak adı |
|---|---|
| `/api/v1/parties` | `parties` |
| `/api/v1/items/{item}/variants` | `items.variants` |
| `/api/v1/price-lists/{priceList}/lines` | `price-lists.lines` |
| `/api/v1/warehouses/{warehouse}/locations` | `warehouses.locations` |
| `/api/v1/treasury-reports/receivables-ageing` | `treasury-reports.receivables-ageing` |

Manifestte yalnızca backend'de gerçekten tanımlı route'lar bulunur. Yeni bir kaynak, önce REST ucu ve API Resource'u ile web'e girer, sonra manifeste eklenir.

### Kapsam (`scope`)

Her manifest kaynağı bir şirket kapsamı bildirir. Kapsam, modelin backend'deki gerçek davranışını izler:

| `scope` | Kaynaklar | Satır kümesi |
|---|---|---|
| `company` | Varsayılan; Parties, Catalog, Inventory, Sales, Purchasing ve Reference'ın şirkete ait tabloları | `company_id = :company` |
| `company_or_global` | `exchange-rates`, `tax-codes` | `company_id IS NULL OR company_id = :company`. `NULL` satırlar sistem satırıdır (TCMB kurları, resmî vergi kodları); `ExchangeRate` ve `TaxCode` global scope'larıyla aynıdır. |
| `global_with_company_activation` | `currencies` | Tüm `currencies` satırları; şirkete özel durum `company_currencies` (`activation.key = currency_code`). |

`global_with_company_activation` kaynaklarında her iki tablo da syncable'dır. Bir satırın akıştaki konumu `(greatest(currencies.sync_txid, company_currencies.sync_txid), currencies.id)` olarak hesaplanır; böylece yalnızca aktivasyon değiştiğinde de satır yeniden yayılır ve bir şirketin aktivasyonu başka şirketin akışına sızmaz. Satır, `ReferenceResource`'un şirket alanlarıyla (`company_is_active`, `company_version`) döner.

Global satırlar (`company_id IS NULL` ya da `currencies`) çevrimdışı yazılamaz; `write` tanımlı kaynakların kapsamı `company` olmak zorundadır (şema denetler).

### Satır şekli

Pull ve push yanıtlarındaki satırlar, kaynağın REST ucunun döndürdüğü **API Resource JSON'u ile birebir aynıdır** (manifestteki `api_resource`). Ayrı bir senkron DTO'su yoktur. Sözleşme yalnızca `id` alanını şart koşar; geri kalan şekil OpenAPI'deki ilgili Resource şemasıdır. `embeds` ile listelenen ilişkiler (`lines`, `addresses`, `units` gibi) hem pull satırlarında hem push `data`'sında yüklenmiş olarak gelir. İstemci yerel satırı bu JSON ile tümüyle değiştirir; bu yüzden ilişkisi yüklenmemiş bir Resource çıktısı döndürmek sözleşme ihlalidir.

## GET /api/v1/sync/manifest

Etkin şirket ve kullanıcı için senkron kapsamını döner. Şema: `manifest.response.schema.json`.

- `resources`: `offline-manifest.json` kaynaklarının ve alt akışlarının (`children`) kullanıcının izinleriyle süzülmüş listesi. İzin, `requires.any_of` (biri yeterli) ve `requires.all_of` (hepsi gerekli) ile değerlendirilir. `writable`, kullanıcının yetkili olduğu yazma işlemleridir (`create`, `update`, `delete`, `action:<ad>`). `visibility_generation`, kaynağın güncel görünürlük neslidir.
- `permissions`: etkin şirketteki etkin izinler. İstemci butonları bu listeye göre gösterir.
- `online_only`: kullanıcının yetkili olduğu ama çevrimdışıyken devre dışı kalacak işlemlerin anahtarları.
- `schema_version` / `min_schema_version`: istemcinin `schema_version` değeri bu aralıkta değilse pull ve push `SYNC_SCHEMA_MISMATCH` döner.
- `limits`: sayfa ve parti sınırları, saklama süreleri.

İstemci manifesti oturum açılışında, şirket değişiminde ve her senkron turunun başında alır. Önceki manifestte olup yenisinde olmayan bir kaynak yerelden silinir (izin kaybı).

## POST /api/v1/sync/pull

Kaynak başına cursor ile artımlı okuma. Şemalar: `pull.request.schema.json`, `pull.response.schema.json`.

### Değişiklik izleme

Senkronlanan her tabloda `sync_txid xid8` kolonu vardır. `BEFORE INSERT OR UPDATE` trigger'ı bu kolona `pg_current_xact_id()` yazar. Silme ve soft delete (`deleted_at` dolması), `sync_tombstones` tablosuna `(resource, id)` başına **tek satır** olarak upsert edilir; tombstone'un `sync_txid`'i son silmenin transaction'ıdır. Soft delete'in geri alınması (restore) satırın `sync_txid`'ini yeniler ve aynı trigger tombstone'u siler. Bunlar A6 `syncable()` migration makrosunun işidir (bkz. Açık konular).

### Olay akışı ve tekilleştirme

Kaynak başına olaylar, sayfalamadan **önce** tekilleştirilmiş tek bir akıştır:

- **Canlı satır olayı:** `deleted_at IS NULL` olan satır, konumu `(sync_txid, id)`.
- **Tombstone olayı:** `sync_tombstones` satırı, konumu `(sync_txid, id)`; yalnızca aynı `id` için canlı satır yoksa. Canlı satır varsa tombstone hiç okunmaz.

Tombstone `(resource, id)` için tekil olduğundan ve canlı satır her zaman tombstone'a üstün geldiğinden, akışta her `id` en fazla bir kez bulunur. Aynı transaction'da silinip geri alınan kayıt canlı satır olarak görünür. Bu yüzden keyset ve `LIMIT` belirsizlik olmadan uygulanır ve bir sayfada aynı `id` iki kez geçmez.

### Sorgu

Her istek için sunucu önce horizon alır:

```sql
SELECT pg_snapshot_xmin(pg_current_snapshot()) AS horizon;
```

Horizon'un altındaki tüm transaction'lar tamamlanmıştır. Bu yüzden `sync_txid < horizon` olan bir olay daha sonra daha küçük bir `sync_txid` ile ortaya çıkmaz ve olay atlanmaz. Global kilit veya sayaç satırı yoktur; web yazmaları yavaşlamaz.

```sql
-- olaylar = canlı satırlar ∪ (canlı satırı olmayan tombstone'lar), kapsam koşuluyla (Kapsam bölümü)
-- cursor.after_id null ise:
WHERE pos_txid >= :txid AND pos_txid < :horizon
-- cursor.after_id doluysa:
WHERE (pos_txid, id) > (:txid, :after_id) AND pos_txid < :horizon
ORDER BY pos_txid, id
LIMIT :limit + 1
```

- İlk indirmede yalnızca `cursor: null` gönderilir.
- `has_more = true` ise `next_cursor`, sayfadaki son olayın konumudur. `has_more = false` ise `next_cursor = {txid: horizon, after_id: null, session: <akış etiketi>}`.
- Cursor kullanıcı, şirket ve kaynak akışına bağlıdır. Artımlı cursor'ın opak `session` alanı, `i_` önekli, padding içermeyen base64url HMAC-SHA256 akış etiketidir. Girdi `sync-cursor|1|user_id|company_id|resource|txid|after_id|gen` biçimindedir (null `after_id` boş yazılır); alt anahtar `hash_hmac('sha256', 'sync-cursor-tag', base64_decode(APP_KEY))` ile türetilir (APP_KEY'in `base64:` öneki çıkarılır). Sunucuda kapsam kaydı tutulmaz. APP_KEY döndürülürse tüm artımlı cursor'lar bir kez `cursor_expired` reseti alır. Dolu cursor ile `session: null` gönderen eski istemci de (`txid: "0"` dahil) bir kez reset alır; dönen etiketli cursor ile devam eder.
- Cursor her zaman dört alan taşır: `txid`, `after_id`, `gen`, `session`. İstemci aldığı `next_cursor`'ı bütün olarak, değiştirmeden geri gönderir; `session` alanı eksik olan cursor `SYNC_CURSOR_INVALID` alır.
- `next_cursor.gen` her zaman kaynağın güncel görünürlük neslidir.
- İstemci sayfaları sırayla uygular ve `next_cursor`'ı sayfayı yerel depoya yazdığı transaction içinde saklar.
- Görünürlük kaynak düzeyindedir: pull, kaynağın manifest `requires` iznini denetler ve satırları adaptör sorgusunun şirket kapsamıyla okur; satır başına Policy çalışmaz. `requires` sağlanmıyorsa istek `FORBIDDEN` alır.
- `window_days` tanımlı kaynaklarda yalnızca `window_field` değeri pencere içinde olan satırlar `rows` içinde döner. Pencere **filtre değil dönüşümdür**: konumu cursor aralığında olan (yani değişmiş) ama `window_field` değeri pencerenin dışında kalan canlı satır, `reason: out_of_scope` tombstone'u olarak döner. Böylece belge tarihi pencere dışına değiştirildiğinde istemci, eski tarihi bilse bile kaydı kaldırır. Değişmeden yaşlanarak pencereden çıkan belgeler için olay üretilmez; istemci bunları yerelde bildiği `window_field` değerine göre kendisi budar. Pencereye geri giren satır, değiştiği için normal satır olayı olarak gelir.

### Görünürlük nesli

Görünürlük, sunucuda `(user_id, company_id, resource)` başına tutulan bir **görünürlük nesli** sayacıyla izlenir. Nesil gözlemle artar: kaynağın `requires` koşulunun sağlanıp sağlanmadığı hesaplanır ve `(user_id, company_id, resource)` için saklanan durumla (`visible`/`hidden`) karşılaştırılır; durum değiştiyse nesil bir artar, aynıysa değişmez (`Modules/Sync/Services/Visibility.php:31-60`). Gözlem üç yerde yapılır: her manifest isteği, her pull isteği ve her yetki değişikliği.

**Yetki değişikliği.** Identity, kullanıcının yapabileceklerini değiştiren her işlemde `AccessChanged(userIds, companyId)` olayını yayar (`Modules/Identity/Events/AccessChanged.php:17`). Olay senkrondur; değişikliğin transaction'ı içinde, izin önbelleği temizlendikten sonra dağıtılır:

| Değişiklik | Kimler, hangi şirket | Kod |
|---|---|---|
| Rol izinleri (rol oluşturma/güncelleme), yalnız izin kümesi gerçekten değiştiyse | Rolün tüm sahipleri, rolün şirketi | `Identity/Services/RoleAdministration.php:97-108` |
| Rol silme | Silmeden önceki sahipleri, rolün şirketi | `RoleAdministration.php:69-79` |
| Kullanıcının rolleri (`syncRoles`), yalnız rol kimlik kümesi değiştiyse; tüm rollerin kaldırılması dahil | O kullanıcı, o şirket | `Identity/Services/UserAdministration.php:198-199` |
| `is_super_admin` bayrağı | O kullanıcı, **tüm şirketler** (`companyId = null`) | `UserAdministration.php:79-86` |
| Şirket üyeliğinin aktifliği (`CompanyDirectory::setMembership`), yalnız aktiflik gerçekten değiştiyse | O kullanıcı, o şirket | `Organization/Services/DatabaseCompanyDirectory.php:72-88` |

Pasifleştirme ve kullanıcı silme olay yaymaz: tüm token'lar iptal edilir, kullanıcının senkron isteği zaten geçmez (`UserAdministration.php:25-27`).

Sync modülünün dinleyicisi `VisibilityRefresh` (`SyncServiceProvider.php:47`) etkilenen `(kullanıcı, şirket)` çiftlerinden **en az bir nesil satırı olanları**, yani o şirkette en az bir manifest veya pull görmüş olanları seçer ve her pull kaynağını yeni izinlerle yeniden gözler (`Modules/Sync/Services/VisibilityRefresh.php:37-66`). Görünürlüğü gerçekten değişen kaynağın nesli bir artar, değişmeyeninki aynı kalır. Hiç senkron yapmamış kullanıcı için satır oluşturulmaz; ilk manifest veya pull durumu kaydeder.

- **Transaction içinde.** Nesil yazıları yetki değişikliğiyle birlikte commit edilir veya geri alınır. İki senkron arasında yetki kaldırılıp geri verilirse, arada hiç manifest veya pull olmasa da nesil iki kez artar; eski cursor `reset_reason: visibility_changed` alır.
- **Serileşme.** Dinleyici her çift için, çiftleri `(user_id, company_id)` sırasıyla, transaction sonuna kadar süren bir advisory kilit (`pg_advisory_xact_lock(hashtextextended('sync.visibility:<user>:<company>', 0))`) alır, ardından izin önbelleğini temizleyip izinleri yeniden okur (`VisibilityRefresh.php:50-61`). Aynı çifti değiştiren ikinci transaction ilkinin commit'ini bekler ve onun sonucunu görerek gözler. Böylece aynı izni veren iki rolün eşzamanlı kaldırılması da nesli artırır; sabit sıra çapraz kilidi önler.
- **Üyelik kaybında çift artış.** Üyelik kaybı ayrıca Identity'nin `MembershipRevoked` olayını yayar (`UserAdministration.php:179-181`). Bu olay commit'ten sonra dağıtılır (`Identity/Events/MembershipRevoked.php:17`, `ShouldDispatchAfterCommit`) ve kullanıcının o şirketteki **tüm** nesillerini koşulsuz bir artırır (`SyncServiceProvider.php:52`, `Visibility.php:67-73`). Aynı kayıpta `setMembership` üzerinden gelen `AccessChanged` de görünürlüğü değişen kaynakları artırmış olabilir; nesil bu yüzden bir kayıpta birden fazla artabilir. Sözleşme nesil değerinin kendisine değil yalnız **eşitliğine** dayanır: değer yalnız büyür, istemci onu yorumlamaz, sunucu `cursor.gen` güncel nesle eşit değilse reset verir. Fazladan artış en çok bir fazladan tam indirmeye yol açar.
- **Kapsam dışı.** `DefaultRoles` kurulum/seeder yolu izinleri doğrudan `givePermissionTo` ile verir ve olay yaymaz (`Identity/Services/DefaultRoles.php:36`, `:42`). Yeni şirket kurulumunda henüz nesil satırı olan kullanıcı bulunmadığından etkisi yoktur.

- Nesil tüketilen bir bayrak değildir. Her cihaz kendi cursor'ındaki `gen` değerini sunucunun güncel nesliyle karşılaştırır; bir cihazın reset alması diğer cihazların durumunu değiştirmez.
- `cursor.gen` güncel nesilden farklıysa sunucu o kaynak için `reset: true, reset_reason: visibility_changed` döner. `rows`, yeni tam indirmenin ilk sayfasıdır ve `next_cursor.gen` güncel nesildir.
- Tam indirme sayfalanırken nesil yeniden değişirse, eski `gen` taşıyan sonraki sayfa tekrar reset alır. Böylece hiçbir cihaz eski görünürlükle başlamış bir indirmeyi yeni görünürlükle tamamlamaz.

### Tam indirme oturumu ve akış etiketi

İlk indirme (`cursor: null`) ve her reset bir **tam indirme** başlatır. Tam indirmenin sayfaları, konumlarına göre değil indirmenin başladığı horizon'a göre tombstone tabanıyla karşılaştırılır: tabanın altındaki satırlar indirmenin kendisidir; ancak tabandan eski bir indirme silme kaçırmış olabilir.

- Sunucu her tam indirme için opak bir **oturum kimliği** üretir ve indirmenin `has_more = true` sayfalarında `next_cursor.session` alanına yazar. Değer opak bir stringdir (`^[A-Za-z0-9_-]{1,64}$`; oturum kimliği UUIDv7, akış etiketi i_ önekli). İstemci onu yorumlamaz, karşılaştırmaz, üretmez; yalnızca cursor ile birlikte aynen geri gönderir.
- İndirmenin son sayfasında (`has_more = false`) `next_cursor.session` akış etiketi olur: bundan sonraki cursor **artımlıdır**. Artımlı akışın ara sayfalarında da etiket taşınır; artımlı cursor kendi konumuyla (`cursor.txid`) tabana göre denetlenir. Oturum kimliği ve akış etiketi aynı opak alanı paylaşır; istemci bunları ayırmaz.
- UUIDv7 oturum kimliği taşıyan cursor yalnızca o oturumun devamıdır. Oturum bitmişse, aynı cihazın yeni bir tam indirmesiyle değişmişse ya da bu konumu o oturum vermemişse, `txid/after_id/gen` yeni oturumun bir cursor'ıyla aynı olsa bile devam ettirilmez; kaynak `reset: true, reset_reason: cursor_expired` alır ve yeni bir oturum başlar. Böylece kaybolan bir reset yanıtından sonra eski cursor ile yapılan tekrar deneme yeniden reset alır.
- Oturumlar cihaz (`user_devices`), kullanıcı, şirket ve kaynak başına tektir; access token yenilemesinden etkilenmez, cihaz iptal edilince silinir. Oturuma yapılan her yazma okunan duruma göre karşılaştır-ve-değiştir ile yapılır: geciken bir istek yeni bir oturumu değiştirmez veya silmez. Bir sayfa isteğinin tekrarı (aynı cursor, aynı `limit`) aynı oturumun devamı olarak kabul edilir; farklı `limit` ile tekrarlanan eski bir sayfanın yanıtındaki cursor bir sonraki istekte reset alabilir.

### Reset

`reset: true` dönen kaynakta istemci yerel kopyayı siler ve aynı yanıttaki `rows`'u yeni tam indirmenin ilk sayfası olarak uygular. Bu kaynağa ait bekleyen outbox kayıtları silinmez; push sırasında normal kurallarla değerlendirilir.

| `reset_reason` | Ne zaman |
|---|---|
| `cursor_expired` | `cursor.txid`, şirketin **tombstone tabanından** küçük ya da cursor'ın session/etiketi bu akış için geçerli değil. |
| `visibility_changed` | `cursor.gen`, güncel görünürlük nesline eşit değil. |
| `schema_changed` | Kaynağın API Resource şekli uyumsuz değişti. |

**Tombstone tabanı.** `xid8` değerinden yaş hesaplanamaz. Bu yüzden tombstone temizliği (90 günden eski `deleted_at`) `(company_id, resource)` başına bir `tombstone_floor_txid` saklar. Taban **dışlayıcı üst sınırdır**: temizlenen tombstone'ların en büyük `sync_txid` değerinin bir fazlası (`max(purged.sync_txid) + 1`); yalnızca büyür. Kural tek ve kesindir: `cursor.txid < tombstone_floor_txid` ise cursor `cursor_expired` alır. Böylece temizlenen bir silmeyle aynı `sync_txid`'e sahip cursor (`{txid: T, after_id: null}` veya `{txid: T, after_id: a}`, T = temizlenen en büyük değer) da reset alır; `cursor.txid >= tombstone_floor_txid` olan her cursor için aralıktaki tüm tombstone'lar hâlâ tablodadır. İlk indirme (`cursor: null`) tabandan etkilenmez.

### Anlık görüntü kaynakları

`source: snapshot` olan kaynaklar (`stock-balances`, `treasury-reports.receivables-ageing`, `treasury-reports.payables-ageing`) pull ile değil, kendi REST uçlarından tümüyle alınır. Bunlar syncable tablo değildir; hesaplanmış raporlardır. İstemci bunları `snapshot_refresh_minutes` aralığında yeniler, alındığı zamanla saklar ve yalnızca çevrimdışıyken "şu tarih itibarıyla" etiketiyle gösterir (`read: offline-fallback`).

## POST /api/v1/sync/push

Outbox'tan partilenmiş mutasyonlar. Şemalar: `push.request.schema.json`, `push.response.schema.json`.

### Mutasyon

```json
{
  "idempotency_key": "UUIDv7",
  "op": "create | update | delete | action",
  "action": "counts",
  "resource": "sales-quotes",
  "id": "UUIDv7",
  "parent_id": "UUID (alt kaynaklarda)",
  "base_version": 4,
  "changed_fields": ["notes"],
  "occurred_at": "2026-10-25T18:05:40+03:00",
  "payload": { "notes": "..." },
  "depends_on": ["UUIDv7"],
  "resolution": { "resolves": "UUIDv7", "code": "DUPLICATE_RECORD", "choice": "keep_mine" }
}
```

- `id` istemcinin ürettiği UUIDv7'dir ve sunucuda doğrudan birincil anahtar olur. `client_id`/`server_id` ikiliği yoktur.
- Create'te `id` Core kuralı `ClientKey` ile denetlenir: küçük harfli kanonik UUIDv7 olmalı ve tabloda, tüm şirketler ve silinmiş satırlar dahil, kullanılmamış olmalıdır (`Core/Sync/Rules/ClientKey.php:31-37`). Her başarısızlık (biçimsiz, v7 değil, bu şirkette alınmış, başka şirkette alınmış) ve ekleme yarışında birincil anahtar ihlali aynı mesajı verir: `VALIDATION_ERROR`, `fields.id` = `core::sync.client_key_unusable` ("Bu id yeni bir kayıt için kullanılamaz."; `Core/lang/*/sync.php:7`). Aynı kural REST create uçlarında (`PartyRequest.php:62`, Sales `DocumentRequest.php:79`, Purchasing `DocumentRequest.php:118`, `StockDocumentRequest.php:34`) ve push'ta alınmış `id` cevabında (`PushService.php:679-682`) kullanılır. Push'ta biçimsiz veya v7 olmayan `id` bu kurala ulaşmaz: `PushRequest` onu istek düzeyinde regex ile reddeder (422 `VALIDATION_ERROR`, alan `mutations.<n>.id`; `Sync/Http/Requests/PushRequest.php:22`, `:30`). Tek tip mesaj push'ta yalnız geçerli biçimdeki ama kullanılmış `id` için geçerlidir. Mesaj hangi durumun oluştuğunu söylemez; kullanılan bir `id`'nin reddedildiği yine görülebilir (Bilinen sınırlamalar).
- `payload`, manifestteki `write.ops[].route` ucunun FormRequest girdisidir, **`version` hariç** (şema `version` anahtarını reddeder). Örnek: sayım girişi `PATCH /stock-documents/{id}/counts` için `{"counts": [{"line_id": "…", "counted_quantity": "38"}]}` (`CountsRequest`).
- `base_version` create için `null`, diğerleri için değişikliğin dayandığı sunucu sürümüdür.

### Alan kümesi (`changed_fields`)

- `update` ve `action`: `changed_fields` **payload'ın üst düzey anahtar kümesine birebir eşit** olmalıdır. Sunucu bunu doğrular; eşit değilse `rejected` / `SYNC_CHANGED_FIELDS_MISMATCH`. Çakışma kontrolü ve uygulanan alanlar her zaman bu doğrulanmış kümedir; payload'da bildirilmemiş alan uygulanamaz.
- Dizi alanları (`lines`, `counts`, `billing_address`) tek alan sayılır ve bütün olarak yazılır.
- **Etkin yazma kümesi.** Servisler bazı alanları yazma sırasında yeniden hesaplar (ör. `document_date` değişince `due_date`). Çakışma kontrolü `changed_fields` ile değil, **etkin yazma kümesiyle** yapılır: `changed_fields` ∪ manifestteki `write.derived_fields` kurallarının, tetikleyicisi kümede olan ve payload'da açıkça verilmemiş `derives` alanları; kurallar sabit noktaya kadar zincirlenir, `when: ["*"]` her update'te uygulanır. `field-merge` update tanımlı her kaynak bu listeyi taşımak zorundadır (şema denetler).
- **Beyan edilmemiş türetme çakışmadır.** `base_version` eskiyken birleştirme uygulanırsa sunucu, servis yazdıktan sonra ve commit'ten önce gerçekten değişen kolonları (ön görüntü ile karşılaştırarak; `version`, `updated_at`, `sync_txid` hariç) etkin yazma kümesiyle karşılaştırır. Küme dışında bir kolon değişmişse transaction geri alınır ve sonuç `conflict` / `VERSION_CONFLICT` olur (`conflicting_fields` = beyan dışı kolonlar). Yani türetme hesaplanamıyorsa birleştirme reddedilir; `base_version` güncelse bu kontrol gerekmez.

Bilinen türetmeler (backend kaynağı manifestte `source` olarak):

| Kaynak | Tetikleyen | Türetilen |
|---|---|---|
| `sales-quotes`, `sales-orders` | `party_id` | `payment_term_id`, `price_list_id`, `billing_address`, `due_date`, `party_name`, `party_tax_office`, `party_tax_number` (`Sales/Services/DocumentService.php:93`) |
| | `currency_code` | `fx_rate` (`DocumentService.php:97`) |
| | `document_date`, `payment_term_id` | `due_date` (`DocumentService.php:101`, `Composer/DocumentComposer.php:107`) |
| | `document_date` | `lines`: satırların vergi oranı belge tarihinde yeniden aranır (`DocumentComposer.php:326`, `:340`), fiyatı verilmemiş satırın fiyatı da o tarihle çözülür (`:247`). Toplamlar aşağıdaki `lines` kuralıyla zincirlenir. |
| | `lines`, `fx_rate` | `subtotal`, `discount_total`, `tax_total`, `total` ve `*_local` karşılıkları (`DocumentComposer`) |
| | her update | `party_name`, `party_tax_office`, `party_tax_number` (cari anlık görüntüsü her yazmada yenilenir, `DocumentComposer.php:147`) |
| `purchase-requests` | `lines` | `estimated_total` (`Purchasing/Services/RequestService.php:379`) |
| | her update | `currency_code`: her yazmada şirketin ana para birimi (`RequestService.php:345`) |
| `stock-documents` | — | Yok; sayım satırları yalnızca `counts` işlemiyle (`exact`) yazılır, `doc_type` ve sayım deposu değiştirilemez (`Inventory/Services/StockDocuments.php:70-77`). |

Not: `RequestService::update` satırları her update'te silip yeniden yazar; satır `id`'leri bu yüzden değişir. İstemci satırları kimlikle değil, dönen `data.lines` ile bütün olarak değiştirir.
- `create` ve `delete`: `changed_fields` boştur; `delete` payload'ı boş nesnedir (şema denetler).

### Sürüm kontrolü

Manifestteki her işlem `version_check` bildirir:

| `version_check` | İşlem | Kural |
|---|---|---|
| `none` | `create` | Sürüm yok. Aynı `id` zaten varsa `rejected` / `VALIDATION_ERROR` (`fields.id`). |
| `field-merge` | `update` | Aşağıdaki çakışma kuralları 2-4. |
| `exact` | `delete`, `action` | `base_version` sunucu sürümüne **eşit** olmalıdır; değilse `conflict` / `VERSION_CONFLICT` (alan kesişimi aranmaz). |

Her işlem için: `base_version` sunucu sürümünden **büyükse** `rejected` / `SYNC_BASE_VERSION_AHEAD`; istemci kaydı yeniden indirir.

**REST sürüm parametresine çeviri.** Sunucu hedef satırı `SELECT … FOR UPDATE` ile kilitler ve sürüm kontrolünü bu kilit altında yapar. REST ucuna giden `version` değeri:

- `exact`: `base_version` (kilit altında sunucu sürümüne eşit olduğu doğrulanmıştır).
- `field-merge`, `base_version` güncel: `base_version`.
- `field-merge`, `base_version` eski ama kesişim yok: kilit altında okunan **güncel sunucu sürümü**. Kilit, kontrol ile yazma arasında başka bir yazmayı engeller; `HasVersion` karşılaştır-ve-değiştir yine çalışır ve beklenmedik bir `VERSION_CONFLICT` olursa sonuç `conflict` olarak döner.

### İşleme

Adımlar bu sırayla uygulanır. **Yetkilendirme, hiçbir saklanan sonuç, `conflict` veya `server_data` dönmeden önce yapılır**; bir adım başarısızsa sonraki adımlar çalışmaz.

1. İstek düzeyi kontroller: token, şirket üyeliği, `schema_version`, parti boyutu (`limits.push_batch_max`). Hata varsa tüm istek `error.response.schema.json` biçiminde döner.
2. Mutasyonlar **sırayla** ve her biri **kendi transaction'ında** işlenir. Parti atomik değildir.
3. **Manifest ve izin.** Kaynak, `op`/`action` manifestteki `write` tanımında olmalı ve kullanıcının **şu anki** izinleri işlemin `requires` koşulunu sağlamalı. Tanım dışıysa `rejected` / `SYNC_OFFLINE_WRITE_NOT_ALLOWED`; izin yoksa `rejected` / `FORBIDDEN`. Varsayılan kural reddir; manifestte tanımlı olmayan her yazma ONLINE_ONLY'dir.
4. **Hedef yükleme ve Policy.** Önce **sınıf düzeyinde** yetki: create için `Gate::authorize('create', Model::class)`, diğer işlemler için `viewAny`; reddedilirse `rejected` / `FORBIDDEN`. Ardından hedef satır `id` ile **şirket kapsamlı** sorguyla (`company` middleware'i + `BelongsToCompany`) ve `SELECT … FOR UPDATE` ile yüklenir:
   - **update, delete, action:** satır yoksa veya başka şirketinse `rejected` / `NOT_FOUND`; varlığı sızdırılmaz. Delete tekrarı istisnadır (5. adım). Satır varsa Policy işlemin yeteneğiyle (`update`, `delete`) **ve** `view` ile satır üzerinde çalışır; biri reddederse `rejected` / `FORBIDDEN`.
   - **create:** satırın **olmaması** normal durumdur ve `NOT_FOUND` üretmez; mutasyon sınıf düzeyi `create` yetkisiyle devam eder. Aynı `id` ile satır varsa: aynı kullanıcı-şirket için saklanan bir sonucu varsa 5. adım (tekrar) uygulanır ve satır üzerinde `view` Policy'si çalışır; yoksa `rejected` / `VALIDATION_ERROR` (`fields.id`), başka şirketin satırı da aynı cevabı alır (varlık sızdırılmaz).
   `server_data` ve `data` yalnızca bu adımı geçmiş satırlar için üretilir.
5. **Idempotency.** `(kullanıcı, şirket, 'sync.push', idempotency_key)` için saklanan bir sonuç varsa mutasyon yeniden çalıştırılmaz. 3. ve 4. adımlar replay için de çalışmıştır; yetkisini yitiren kullanıcı saklanan veriyi geri alamaz (sonuç `rejected` / `FORBIDDEN` veya `NOT_FOUND`, `replayed: false`, saklanmaz).
   - İstek özeti farklıysa `rejected` / `IDEMPOTENCY_KEY_REUSED`.
   - Saklanan sonuç `applied` ise `duplicate` döner; ilk sonucun `version` ve `data`'sı aynen, `replayed: true`. Delete'in tekrarında hedef satır artık yoktur; 4. adım bu durumda `NOT_FOUND` üretmez, saklanan `data: null` sonucu döner.
   - Saklanan sonuç `conflict` veya `rejected` ise **aynı statü ve aynı gövdeyle** döner, `replayed: true`. İstisna: conflict'te `conflicting_fields`, `server_version` ve `server_data` birlikte, 4. adımda kilitlenen **aynı güncel satır görüntüsünden** yeniden üretilir (`server_version = server_data.version`); `code`, `base_version` ve `choices` saklanan değerdir; alan listesi güncel satırın alan çakışmalarından hesaplanır (exact işlemlerde boştur).
   Sonucun tamamı 7 gün saklanır. Geçici hatalar ve yetkilendirme retleri saklanmaz.
6. **Bağımlılık.** Aynı partide aynı `id`'ye ait daha önceki mutasyonlar ve `depends_on` ile bildirilenler `applied` veya `duplicate` olmalıdır; değilse `rejected` / `SYNC_DEPENDENCY_FAILED` ve mutasyon denenmez. `depends_on` yalnızca aynı partide **daha önce** gelen ya da önceki bir partide gönderilmiş (sonucu saklı) anahtarlara işaret edebilir; ileri veya bilinmeyen referans `rejected` / `VALIDATION_ERROR` (`fields.depends_on`) olur. Referanslar yalnızca geriye baktığı için döngü oluşamaz. `resolution` taşıyan mutasyon için istisna aşağıda (Çakışma çözümü).
7. **Payload kısıtları.** `payload_constraints` (create ve update payload'ı) sağlanmıyorsa `rejected` / `SYNC_OFFLINE_WRITE_NOT_ALLOWED`.
8. **Hedef kısıtları** (update, delete, action; 4. adımda kilitlenen satır üzerinde):
   - `target_constraints` sağlanmıyorsa `rejected` / `SYNC_OFFLINE_WRITE_NOT_ALLOWED`. Örnek: `stock-documents` için hedefin `doc_type` değeri `count` olmalıdır; başka türdeki taslak çevrimdışı güncellenemez, silinemez ve sayılamaz.
   - `draft_only` sağlanmıyorsa `conflict` / `DOCUMENT_LOCKED`.
   - Sürüm kontrolü (yukarıda).
9. Mutasyon ilgili REST ucunun **FormRequest → Service** zincirinden geçirilir (Policy 4. adımda çalıştı; servis içindeki ek yetki kontrolleri aynen geçerlidir). Senkron için ayrı iş kuralı yazılmaz.

### Sonuçlar

| `status` | Anlamı | Zorunlu alanlar | İstemci |
|---|---|---|---|
| `applied` | Bu istekte uygulandı. | `data`; `data` doluysa `version`; kesişimsiz birleştirmede `merged_fields`. `replayed: false`. | Outbox kaydını siler; yerel satırı aşağıdaki **sürüm kuralıyla** `data` ile değiştirir. |
| `duplicate` | Daha önce `applied` olmuş anahtarın tekrarı. | `applied` ile aynı; `replayed: true`. | `applied` gibi; `data` eski olabileceğinden sürüm kuralı burada özellikle önemlidir. |
| `conflict` | Kullanıcı kararı gerekir. | `conflict`. | Kaydı Conflict Inbox'a taşır. |
| `rejected` | Kalıcı hata. | `error`. | Outbox kaydını hata koduyla işaretler, kullanıcıya gösterir. |

Geçici hatalarda (ağ, 5xx, `SERVER_ERROR`, `TOO_MANY_ATTEMPTS`, `IDEMPOTENCY_IN_PROGRESS`) sonuç yoktur; istemci aynı anahtarlarla geri çekilerek yeniden gönderir. Çok mutasyonlu istekte geçici hatadan sonra gönderim tekli yapılır (aşağıdaki son madde).

- Bir mutasyon sırasında geçici hata oluşursa o mutasyonun transaction'ı (idempotency kaydı dahil) geri alınır ve **tüm push isteği** bu hatayla, istek düzeyindeki hata zarfında döner. Sonuç listesi dönmez.
- Partide daha önce işlenen mutasyonlar kendi transaction'larında commit edilmiş kalır ve sonuçları saklanmıştır. Aynı anahtarlarla yeniden gönderimde bunlar saklanan sonuçlarıyla (`applied` için `duplicate`) döner; işleme hatanın oluştuğu mutasyondan devam eder.
- **Meşgul satır kilidi.** Mutasyon transaction'ı içinde, hedef satırın `FOR UPDATE` kilidinde (`lock_timeout`) veya yazarın içinde (`NOWAIT`) oluşan SQLSTATE `55P03` mutasyon sonucu değildir: push isteği düzeyinde HTTP 409 `IDEMPOTENCY_IN_PROGRESS` ve `Retry-After: 1` döner (`Sync/Services/PushService.php:139-152`). REST'teki `VERSION_CONFLICT` eşlemesi burada kullanılmaz; çünkü conflict sonucu 7 gün saklanır ve aynı anahtarın her tekrarında yeniden döner (geçici bir kilit için istemciye gereksiz çözüm ekranı açardı). Yalnız kilide takılan mutasyonun transaction'ı (idempotency kaydı dahil) geri alınır; partide ondan önceki mutasyonlar commit'li ve sonuçları saklı kalır. İstemci partiyi aynı anahtarlarla yeniden gönderdiğinde önceki sonuçlar saklanan sonuçlarıyla, `replayed: true` olarak döner (5. adım: `applied` → `duplicate`; `conflict` ve `rejected` statüsünü korur, `VERSION_CONFLICT`/`DOCUMENT_LOCKED` conflict'inde `server_version` ve `server_data` güncel satırdan yeniden üretilir; `Core/Sync/WriteOutcome.php:74-80`, `PushService.php:436-458`). Kilide takılan mutasyon yeniden denenir.
- Aynı mutasyon sürekli geçici hata alıyorsa sunucu tarafında bir karantina yoktur. İstemci kaydı kendi tarafında karantinaya alır: sonraki partilere koymaz ve kullanıcıya gösterir.
- Çok mutasyonlu bir push isteğinde geçici hata deneme sayılmaz; istemci sonraki gönderimleri tek mutasyonlu yapar (gönderilebilir kuyruk boşalana kadar) ve deneme yalnız tekli istekte sayılır.

**Sürüm kuralı (istemci).** Push sonucu yerel satırı asla geriletmez:

- **Yerel satır yoksa push `data`'sı uygulanmaz** (`applied` ve `duplicate` için aynı kural; tek istisna, aşağıdaki yerel create işaretidir). Satır arada tombstone, `out_of_scope` veya reset ile kaldırılmış olabilir ve `data` o kaldırmadan eski olabilir. İstemci outbox kaydını kapatır ve kaynağın `detail_path` ucundan güncel durumu ister: 200 dönerse yanıt, kaynağın pencere ve kapsam kuralından geçiyorsa uygulanır; 404/403 dönerse veya pencere dışındaysa satır yerelde yok kalır. Çevrimdışıysa doğrulama bir sonraki bağlantıya ertelenir; o zamana kadar satır eklenmez.
- Yerel satır varsa: yerel `version < data.version` ise `data` uygulanır; yerel `version >= data.version` ise (arada daha yeni bir pull uygulanmış) yerel satır korunur, yalnızca outbox kaydı kapanır.
- Çevrimdışı oluşturulan taslağın ayna satırı yoktur; taslak, outbox ile birlikte tutulan ayrı bir yerel create işaretiyle izlenir. İlk `create` sonucu bu işaret varsa uygulanır; tombstone, pull satırı, reset veya silme işareti düşürür.
- `data: null` (delete): yerel satır yoksa bir şey yapılmaz. Yerel `version` delete mutasyonunun `base_version` değerine eşit ya da küçükse satır silinir. Büyükse satır silinmez; kaynak bir sonraki pull'da yeniden doğrulanır (tombstone gelirse silinir, canlı satır gelirse kalır).
- Cursor bu kuraldan etkilenmez. Pull olayları için kural: istemci bir pull satırını yalnız yerel sürüm kesinlikle daha yeniyse atlar; eşit sürüm uygulanır. Tam indirmeyi başlatan sayfa (`reset: true` veya `cursor: null`) yerel kopyayı siler ve satırları atlamadan yazar; aynı indirmenin sonraki sayfaları bu sürüm kuralına tabidir.
- Aynı sürüm kuralı push/replay (`duplicate`) sonucuna ve `take_server` çözümüne de uygulanır: daha yeni yerel sürüm geriletilmez, silinmiş satır diriltilmez (`take_server` ile sunucu silmesi yerel satırı yalnız yerel sürüm sunucu sürümünden yeni değilse siler). `data: null` satırı yalnız yerel `version <= base_version` ise siler.

### Çakışma kuralları

Sessiz üzerine yazma yasaktır.

1. **Belge kilitli.** `write.draft_only` alanı (`status`) artık `draft` değilse (`purchase-requests` için `draft` veya `rejected` değilse; bkz. Çevrimdışı yazma kapsamı) sonuç `conflict` / `DOCUMENT_LOCKED` olur. Aynı kural REST'te de geçerlidir: taslak olmayan belgeyi `PATCH`/`DELETE` ile düzenleme girişimi HTTP 409 `DOCUMENT_LOCKED` döner (D1 öncesinde 422 `INVALID_STATE_TRANSITION` idi); push bu REST hatasını conflict sonucuna çevirir. Seçenekler yalnızca `save_as_new_draft` (istemci yeni UUIDv7 ve yeni anahtarla `create` üretir) veya `discard` (şema denetler).
2. **Eski sürüm, kesişim yok** (`field-merge`). `base_version` güncel değilse, **etkin yazma kümesi** (`changed_fields` + türetilen alanlar) ile `base_version`'dan beri sunucuda değişen alanlar (audit diff) karşılaştırılır. Kesişim yoksa değişiklik uygulanır: `applied` ve `merged_fields`.
3. **Eski sürüm, kesişim var.** Sonuç `conflict` / `VERSION_CONFLICT`; `conflicting_fields` ve `server_data` döner. Seçenekler `keep_mine`, `take_server`, `merge` içinden. `delete`/`action` için `exact` kuralı gereği kesişim aranmaz; seçenekler `keep_mine` ve `take_server`.
4. **Belirsizlik çakışmadır.** Aradaki değişikliği audit kaydından kesin çıkarılamayan alan (ör. activitylog'un izlemediği `lines`) kesişiyor sayılır.
5. **Mükerrer cari.** `parties` create'inde sunucu önce REST zincirinin yetki ve doğrulama adımlarını (`PartyRequest`) çalıştırır, sonra `GET /api/v1/parties/duplicates` ile aynı aramayı (`DuplicateFinder`) yapar. Aday varsa `conflict` / `DUPLICATE_RECORD` ve `candidates` döner (en az bir kimlik; şema `minItems: 1`). Seçenekler `use_existing` (istemci yerel taslağı aday kayda bağlar; sunucuya yazma yok), `keep_mine`, `discard`.
   - Aramayı yalnızca doğrulanmış bir `resolution` `{code: DUPLICATE_RECORD, choice: keep_mine}` atlar (Çakışma çözümü). Payload'da gelen `allow_duplicate` anahtarı sunucuda silinir; aramayı atlatmaz.
   - Kullanıcının `parties` için `viewAny` yetkisi yoksa aday araması yapılmaz: adayları göremez ve şema en az bir aday ister. Create, REST `POST /api/v1/parties` gibi devam eder.
   - Normalleştirilmiş vergi numarası aktif bir cariyle birebir aynıysa sonuç `rejected` / `VALIDATION_ERROR` (`fields.tax_number`) olur, `DUPLICATE_RECORD` değil. Doğrulama aramadan önce çalışır; burada `keep_mine` sunmak anlamsızdır, çünkü aynı kural yazmayı yine reddeder.

### Çakışma çözümü (`resolution`)

Conflict Inbox kararı, **yeni bir `idempotency_key`** ile gönderilen yeni bir mutasyondur; `resolution.resolves` çözülen conflict sonucunun anahtarını taşır. Sunucu saklanan conflict sonucunu aynı kullanıcı, şirket, kaynak ve `id` için arar; bulamazsa veya seçim o çakışma türünde geçersizse `rejected` / `SYNC_RESOLUTION_INVALID`.

| Çakışma | Seçim | Yeni mutasyon |
|---|---|---|
| `VERSION_CONFLICT` | `keep_mine` | Aynı `op`, `base_version = server_version`, kullanıcının alanları; `resolution` `{code: VERSION_CONFLICT, choice: keep_mine}`. |
| `VERSION_CONFLICT` | `merge` | `update`, `base_version = server_version`, birleştirilmiş alanlar; `resolution.choice = merge`. |
| `VERSION_CONFLICT` | `take_server` | Mutasyon yok; istemci `server_data`'yı uygular. |
| `DOCUMENT_LOCKED` | `save_as_new_draft` | Yeni `id` ile `create`; `resolution` gerekmez. |
| `DUPLICATE_RECORD` | `keep_mine` | Aynı `id` ile `create`; `resolution` `{code: DUPLICATE_RECORD, choice: keep_mine}`. Sunucu bu durumda mükerrer kontrolünü atlar; diğer doğrulamalar ve `parties.create` izni aynen uygulanır. Atlama yalnızca payload, çakışmayı alan mutasyonun payload'ıyla aynıysa (kanonik özet) geçerlidir; farklı payload'da arama yeniden çalışır. |
| `DUPLICATE_RECORD` | `use_existing` | Mutasyon yok; bağımlı outbox kayıtları istemcide aday `id`'ye yeniden yazılır. |
| Herhangi | `discard` | Mutasyon yok; outbox kaydı silinir. |

**Yerine geçme.** `resolution` taşıyan mutasyon, çözdüğü mutasyonun (`resolves`) **yerine geçer**:

- Sunucu, `resolves` sonucunun saklanan statüsü `conflict` olduğu ve kodunun `resolution.code` ile eşleştiği sürece bu mutasyonu işler. Çözülen mutasyon örtük aynı-`id` bağımlılığından ve `depends_on` kontrolünden çıkarılır; yani çözülen conflict, çözen mutasyonu `SYNC_DEPENDENCY_FAILED` ile engellemez.
- Çözen mutasyon `applied` olunca sunucu çözülen kaydı `superseded_by = <yeni anahtar>` olarak işaretler. Aynı `resolves` için ikinci bir çözüm `rejected` / `SYNC_RESOLUTION_INVALID` olur. Çözülen anahtarın tekrarı saklanan conflict'i döndürmeye devam eder; istemci onu outbox'tan zaten çıkarmıştır.
- Aynı `id` için çözülen mutasyondan sonra gelmiş ve onun yüzünden `SYNC_DEPENDENCY_FAILED` almış mutasyonları istemci, çözen mutasyondan sonra yeni anahtarlarla ve güncel `base_version` ile yeniden üretir.

Numara verme sunucudadır. Çevrimdışı oluşturulan taslak numarasızdır; numara push uygulandığında `data.number` ile gelir.

## Okuma modları

| `read` | Davranış |
|---|---|
| `local-first` | Ekran her zaman yerel depodan okur; pull arka planda tazeler. Ana veriler ve çevrimdışı yazılabilen belgeler. |
| `online-first` | Önce ağ (kısa zaman aşımı); başarısızsa yerel kopya "eski olabilir" etiketiyle gösterilir. Son 30 günün salt okunur belgeleri. |
| `offline-fallback` | Çevrimiçiyken yalnızca ağ. Yerel anlık görüntü yalnızca çevrimdışıyken, alındığı zamanla etiketli gösterilir. Bakiyeler. |

`write` tanımlı her kaynak `local-first` olmak zorundadır (şema bunu denetler).

## Çevrimdışı yazma kapsamı

| Kaynak | İşlemler (`version_check`) | Kısıt |
|---|---|---|
| `parties` | create (`none`) | Mükerrer kontrolü (`DUPLICATE_RECORD`). |
| `sales-quotes` | create (`none`), update (`field-merge`), delete (`exact`) | Hedef `status = draft`. |
| `sales-orders` | create, update, delete | Hedef `status = draft`. |
| `purchase-requests` | create, update, delete | Hedef `status` `draft` veya `rejected`. |
| `stock-documents` | create, update, delete, action `counts` (`exact`) | Payload ve hedef `doc_type = count`; hedef `status = draft`. |

`purchase-requests` için reddedilmiş talep de taslak gibi düzenlenir ve silinir; bu, web'deki "revize et" akışıyla aynı kuraldır (`RequestService::update`/`delete`, `PurchaseRequestSyncWriter::isDraft`). `rejected` bir talebin update'i talebi yeniden `draft` durumuna alır. Diğer durumlarda sonuç `conflict` / `DOCUMENT_LOCKED`'dır. Manifest bunu `draft_only: {field: status, value: draft, also: [rejected]}` ile bildirir (`contracts/offline-manifest.json:383`); `also`, `value` dışında düzenlenebilir sayılan değerlerin listesidir (şemada isteğe bağlı, en az bir öğe; `offline-manifest.schema.json:154`). Diğer kaynaklar `also` taşımaz. Bu alan istemci sözleşmesidir: istemci `also` değerlerindeki belgeyi kilitli göstermemelidir. Sunucu `also`'yu okumaz; kararı yazarın `isDraft` sonucuyla verir (`Sync/Services/PushService.php:272`, `PurchaseRequestSyncWriter.php:59-64`). Alan, `document_date` → `lines` ve `currency_code` türetmeleriyle birlikte `manifest_version` 2'de eklendi (`offline-manifest.json:3`).

## ONLINE_ONLY

`offline-manifest.json` içindeki `online_only` listesi, çevrimdışıyken devre dışı görünen işlemleri ve karşılık gelen gerçek route'ları tanımlar. Butonlar `desktop.onlineOnly.<action>` tooltip'ini gösterir. Kategoriler: kesinleştirme (`finalize`), onay (`approve`), ödeme/tahsilat (`payment`), stok düşümü/girişi (`stock`), e-Belge (`edocument`), dönem kapanışı (`period-close`), sunucu hesabı (`server-calculation`: toplam önizleme, fiyat çözümleme, risk) ve canlı raporlar (`live-report`). Gerekçe: boşluksuz numara, risk limiti, stok rezervasyonu ve defter bütünlüğü sunucuda kalır.

Liste UI etiketi içindir; güvenlik sınırı değildir. Sunucu, manifestte `write` tanımı olmayan her push mutasyonunu zaten reddeder.

## Hata kodları

İlk 22 kod `backend/app/Core/Http/ErrorCode.php` ile aynıdır. Aşağıdakiler senkron için eklenen 11 koddur; `ErrorCode` enum'unda, `status()` eşlemesinde ve `lang/*/errors.php` çevirilerinde bulunurlar (`ErrorCode.php:43-46`, `:59-62`; `backend/lang/en/errors.php:30-38`). HTTP sütunu `status()` eşlemesidir.

| Kod | HTTP | Nerede |
|---|---|---|
| `DOCUMENT_LOCKED` | 409 | REST düzenleme/silme uçları ve push `conflict`. Hedef belge taslak değil. |
| `DUPLICATE_RECORD` | 409 | Push `conflict`. Mükerrer cari adayı. |
| `SYNC_SCHEMA_MISMATCH` | 409 | İstek. İstemci şema sürümü desteklenmiyor. |
| `SYNC_CURSOR_INVALID` | 422 | İstek. Cursor biçimsiz, horizon'dan ileride ya da `gen` güncel nesilden büyük. |
| `SYNC_RESOURCE_UNSUPPORTED` | 422 | İstek veya push `rejected`. Kaynak manifestte yok. |
| `SYNC_OFFLINE_WRITE_NOT_ALLOWED` | 422 | Push `rejected`. Mutasyon `write` tanımının ya da hedef kısıtlarının dışında. |
| `SYNC_BATCH_TOO_LARGE` | 413 | İstek. Kaynak veya mutasyon sayısı limitin üstünde. |
| `SYNC_DEPENDENCY_FAILED` | 422 | Push `rejected`. Bağımlı olunan mutasyon başarısız. |
| `SYNC_BASE_VERSION_AHEAD` | 422 | Push `rejected`. `base_version` sunucu sürümünden büyük. |
| `SYNC_CHANGED_FIELDS_MISMATCH` | 422 | Push `rejected`. `changed_fields` payload anahtarlarına eşit değil. |
| `SYNC_RESOLUTION_INVALID` | 422 | Push `rejected`. `resolution` saklanan bir çakışmaya karşılık gelmiyor. |

Son dört kodun `status()` eşlemesi 422'dir, ancak bugün yalnızca push sonucu içinde `error.code` olarak döner; push yanıtının HTTP durumu 200'dür. İstek düzeyindeki hata zarfında gelmezler. Push sonuçlarında mevcut kodlar da kullanılır: `VALIDATION_ERROR`, `FORBIDDEN`, `NOT_FOUND`, `INVALID_STATE_TRANSITION`, `APPROVAL_REQUIRED`, `IDEMPOTENCY_KEY_REUSED`. İzin kaybı `FORBIDDEN`, iptal edilmiş cihaz `UNAUTHENTICATED` döner. İstek düzeyinde hız ve eşzamanlı pull sınırı 429 `TOO_MANY_ATTEMPTS`, push'ta meşgul satır kilidi 409 `IDEMPOTENCY_IN_PROGRESS` döner (ikisi de `Retry-After` ile).

## Cihaz kimliği

- `POST /api/v1/auth/device`: e-posta, parola, TOTP (2FA açıksa), `device_name`, `fingerprint`. `desktop` yetenekli Sanctum token'ı verir; token cihaza (`user_devices`) bağlıdır.
- `GET /api/v1/me/devices` ve `DELETE /api/v1/me/devices/{id}`: cihazları listeler ve iptal eder.
- Pasifleştirme, parola değişimi ve yönetici sıfırlaması cihaz token'larını iptal eder (`AccessRevoker`).
- `POST /api/v1/auth/device/refresh`: refresh token'ı döndürerek erişim token'ını yeniler (ADR 0007). Şemalar: `device-login.request`, `device-token.response`, `device-refresh.request` (`contracts/sync/`; D1'de OpenAPI'ye taşınır).

### Token yaşam döngüsü

| Değer | Kural |
|---|---|
| Erişim token'ı | 60 dakika. `desktop` yetenekli Sanctum token'ı. |
| Refresh token | 30 gün, kayan: her başarılı yenileme yeni bir refresh token ve yeni 30 gün verir. Tek kullanımlıktır. |
| Saklama | `device_refresh_tokens(id, device_id, token_hash, family_id, expires_at, used_at)`. Ham token saklanmaz. |

- **İlk giriş.** `POST /auth/device` erişim token'ını ve ailenin ilk refresh token'ını birlikte döner: `{data: {token, expires_at, refresh_token, refresh_expires_at}}`. Aynı kullanıcı ve fingerprint ile yeni giriş cihaz kaydını korur; önceki token çiftini ve ailesini iptal eder, cihazın tam indirme oturumlarını sonlandırır. Kullanıcı başına en fazla 10 cihaz tutulur (`identity.device_tokens.max_devices_per_user`); sınır aşılırsa last_used_at, ardından created_at sırasıyla en eski cihaz iptal edilir.
- **Yenileme.** `POST /auth/device/refresh` gövdesi `{refresh_token}`; `Authorization` başlığı gerekmez. Sunucu token'ı `used_at` ile işaretler, aynı `family_id` içinde yeni refresh token ve yeni erişim token'ı verir, eski erişim token'ını siler. Yanıt ilk girişle aynı şekildedir.
- **Yeniden kullanım.** `used_at` dolu bir refresh token gelirse sunucu ailenin tamamını, cihazın erişim token'larını ve cihaz kaydını iptal eder ve 401 `UNAUTHENTICATED` döner. İstemci yerel oturumu kapatır; outbox korunur ve aynı kimlikle (`kullanıcı:şirket`) yeniden girişten sonra gönderilir (farklı kimlikte silinir).
- **Diğer hatalar.** Bilinmeyen, süresi dolmuş veya iptal edilmiş cihaza ait refresh token: 401 `UNAUTHENTICATED`. Pasif kullanıcı: 403 `USER_DEACTIVATED`. Hız sınırı: 429 `TOO_MANY_ATTEMPTS`. Biçimsiz gövde: 422 `VALIDATION_ERROR`.
- **Eşzamanlılık.** Eşzamanlı yenileme desteklenmez; ikinci istek yeniden kullanım sayılır. İstemci yenilemeyi tek uçuşla sıralar ve erişim token'ı `expires_at`'ten önce (ör. 5 dakika kala) yeniler.
- **İptal.** `DELETE /me/devices/{id}`, kullanıcı pasifleştirme, parola değişimi ve yönetici sıfırlaması (`AccessRevoker`) aileyi de iptal eder.
- **İstemci kimlik kuralları.** Aşağıdaki kurallar yalnız hatayı alan isteğin nesli (oturum üretimi) hâlâ güncelse uygulanır; eski nesilden gelen 401/403 hiçbir şey silmez.
  - Nihai 401 (yenileme de başarısız olduysa ya da yenilenen token'la istek yine 401 aldıysa): yerel okumalar kapanır, ayna diskte kalır; aynı kimlikle (`kullanıcı:şirket`) yeniden girince açılır. Farklı kullanıcı ya da şirketle girişte mühürlü iş dahil her şey silinir.
  - 403 `COMPANY_FORBIDDEN`: yalnız isteğin şirketi yerel kimliğin (`kullanıcı:şirket`) şirketiyle eşleşiyorsa o şirketin aynası silinir ve bekleyen iş mühürlenir. Mühür aynı kimlikle doğrulanınca kalkar; farklı kullanıcı ya da şirketle girişte mühürlü iş dahil her şey silinir.
  - Güncel nesil 403 `USER_DEACTIVATED`: ayna silinir; outbox ve çatışmalar silinmez, mühürlenir (okunamaz ve gönderilemez). O nesle ait token'lar da temizlenir. Aynı kimlikle (`kullanıcı:şirket`) yeniden girip kimlik doğrulanınca mühür kalkar; farklı kullanıcı ya da şirketle girişte mühürlü iş dahil her şey silinir.
## Saklama ve limitler

| Değer | Süre / sınır |
|---|---|
| Tombstone | 90 gün (`deleted_at`'e göre). Temizlik `tombstone_floor_txid`'i (dışlayıcı üst sınır) yükseltir; `cursor.txid < tombstone_floor_txid` olan cursor `reset: cursor_expired` alır. |
| Erişim token'ı / refresh token | 60 dakika / 30 gün kayan. |
| Push idempotency kaydı | 7 gün (`sync.push` kapsamı); istek özeti ve sonucun tamamı. |
| Görünürlük nesli | Süresiz; yalnızca artar. |
| Pull sayfası | Varsayılan 200, en fazla 1000 satır. |
| Pull isteğindeki kaynak sayısı | `limits.pull_resources_max` |
| Push partisi | `limits.push_batch_max`; şema üst sınırı 500. |
| Belge penceresi | 30 gün (`defaults.recent_window_days`). |
| İstek hızı | Cihaz başına dakikada 120, kullanıcı başına dakikada 300 (`config/sync.php` `throttle`; Hız ve eşzamanlılık sınırları). |
| Eşzamanlı pull | Cihaz başına 1, kullanıcı başına 3; slot 120 sn, pull süresi slot süresinden 5 sn kısa. |

Uzun süren transaction'lar horizon'u geride tutar ve yalnızca senkronu geciktirir. `now - en eski açık transaction` metriği izlenir.

## Sürümleme

- `schema_version`: tel biçimi. Uyumsuz değişiklikte artar; sunucu `min_schema_version`'a kadar eski istemciyi destekler.
- `manifest_version`: `offline-manifest.json` içeriği. Her değişiklikte artar.
- API Resource şekline alan eklemek uyumludur. Alan silmek veya anlamını değiştirmek `schema_changed` reset'i gerektirir.

## Doğrulama

- Her şema 2020-12 meta-şemasına karşı derlenir; `offline-manifest.json` kendi şemasına karşı doğrulanır.
- `fixtures/*.json` şemasına karşı geçmeli, `fixtures/invalid/*.json` kalmalıdır; her geçersiz örnek belirli bir kuralı (konum + anahtar kelime) hedefler.
- Fixture'lardaki istek gövdeleri gerçek FormRequest'lerle, `data` örnekleri gerçek API Resource çıktısıyla aynı alanları taşır (ör. sayım `counts[].line_id`; `sales-quotes` ve `stock-documents` `data`'sı `lines` dahil).
- Sunucu testleri (Pest) pull/push yanıtlarını bu şemalara karşı ve bu belgedeki sunucu kurallarını (alan kümesi eşitliği, `exact` sürüm, saklanan sonuç tekrarı, hedef kısıtları, görünürlük nesli) doğrular; Rust testleri aynı fixture'ları deserialize eder.
- Manifestteki her route, backend route tanımlarında bulunmalıdır. Bu kontrol CI'da `php artisan route:list --json` çıktısına karşı yapılmalıdır.

## Açık konular

D1 sonrası durum. Kontrol `backend/` kaynak kodu (main) üzerinde yapıldı. Numaralar D0 listesiyle aynıdır; her madde **Kapandı** (nerede uygulandığıyla), **Kısmen** veya **Açık** olarak işaretlidir. Uygulamanın bilerek taşıdığı sınırlar ayrıca "Bilinen sınırlamalar" altındadır.

**Senkron altyapısı (A6)**

1. **Kapandı.** `syncable()` Blueprint makrosu `backend/app/Core/Sync/Schema/Syncable.php`'dedir: `sync_txid xid8` kolonu, `(company_id, sync_txid, id)` index'i (şirket kolonu yoksa `(sync_txid, id)`) ve `zz_sync_touch` trigger'ı. Trigger fonksiyonları `backend/database/migrations/2027_08_01_100000_create_sync_functions.php`'dedir. Modül tabloları `Modules/<Ad>/Database/migrations/2027_08_01_200N00_add_sync_to_<modül>.php` migration'larıyla bağlanır: Parties `200100`, Catalog `200200`, Reference `200300`, Sales `200400`, Purchasing `200500`, Inventory `200600`.
2. **Kapandı.** `sync_tombstones` tablosu `2027_08_01_100100` migration'ındadır. `sync_tombstone()` trigger'ı silmede `deleted`, `deleted_at` dolduğunda `soft_deleted` nedeniyle `(resource_table, row_id)` başına upsert yapar; restore'da tombstone'u siler.
3. **Kapandı.** Sözleşme `backend/app/Core/Sync/`, sunucu `backend/app/Modules/Sync/`'tedir. `GET /sync/manifest`, `POST /sync/pull`, `POST /sync/push` uçları `Modules/Sync/routes.php`'de `auth:sanctum`, `EnsureDesktopToken`, `password.changed` ve `company` middleware'lerinin arkasındadır.
4. **Kapandı.** Alt tablolar `syncable(parent: ..., parentKey: [...])` ile bağlanır. Deyim düzeyindeki `sync_touch_parent()` trigger'ları alt satır yazmasında üst satırın `sync_txid`'ini yeniler (ör. `party_addresses`/`party_contacts`/`party_bank_accounts` → `parties`, belge satırları → belge başlığı). Kesinleşmiş üst satırı koruyan guard'lar bu dokunuşu yalnız `Syncable::GUARD_EXEMPTION` ile, yani `sync_txid` dışında hiçbir kolon değişmediğinde geçirir (`Core/Sync/Schema/Syncable.php:53`, `sync_touch_only()`: `database/migrations/2027_08_01_100300_create_sync_touch_only_function.php`); ayrıntı [Mimari](ARCHITECTURE.md#satır-guardlarında-senkron-istisnası). Satıra gömülü ama tablo yazması olmayan türetilmiş değerler için bkz. Bilinen sınırlamalar.
5. **Kapandı.** `sync_visibility_generations` tablosu ve `Modules/Sync/Services/Visibility.php` vardır. Nesil manifest, pull ve Identity'nin `AccessChanged` olayıyla (rol izinleri, rol ataması, rol silme, süper admin bayrağı, üyelik aktifliği; değişikliğin transaction'ı içinde) gözlenir; `MembershipRevoked` ayrıca tüm nesilleri artırır. Kurallar Görünürlük nesli bölümündedir. Kalan kapsam dışı yol: `DefaultRoles` kurulum/seeder yolu.
6. **Kapandı.** Taban `sync_watermarks(resource_table, company_id, floor_txid)` tablosunda tutulur (`2027_08_01_100200`). Bu belgedeki `tombstone_floor_txid` bu kolondur. Günlük `sync:prune` (`Modules/Sync/Console/PruneCommand.php`) 90 günden eski tombstone'ları siler ve tabanı `max(sync_txid) + 1`'e yükseltir. Aynı iş süresi dolan push sonuçlarını, çözüm kayıtlarını ve eski tam indirme oturumlarını da temizler. Pull; tabanı, canlı satırları ve tombstone'ları tek bir `REPEATABLE READ, READ ONLY` anlık görüntüsünden okur.
7. **Kapandı.** `CurrencySyncResource::positionColumn()` `GREATEST(currencies.sync_txid, company_currency_sync.sync_txid)` döner. `ExchangeRateSyncResource` ve `TaxCodeSyncResource` `SyncScope::CompanyOrGlobal` kullanır.

**Push zinciri**

8. **Kapandı.** Store FormRequest'leri istemci `id`'sini `App\Core\Sync\Rules\ClientKey` ile kabul eder (UUIDv7 + tabloda kullanılmamış, tek tip hata mesajı): `PartyRequest`, Sales ve Purchasing `DocumentRequest`, `StockDocumentRequest`. Kural Mutasyon bölümündedir.
9. **Kapandı.** Push sonuçları `sync.push` kapsamında (`SyncConfig::PUSH_IDEMPOTENCY_SCOPE`) istek özeti ve sonucun tamamıyla 7 gün saklanır (`config/sync.php` `idempotency_retention_days`). REST `Idempotency-Key` katmanı değişmedi (24 saat, `config/idempotency.php`).
10. **Kapandı.** Sales ve Purchasing `DocumentService::assertEditable` ile Inventory `Support/Messages.php`, taslak olmayan belgede 409 `DOCUMENT_LOCKED` atar. Önerilen 11 kod `ErrorCode`'dadır. Açık: web arayüzünün 422 yerine 409'u işlemesi (frontend MSW handler'ları ve hata işleme).
11. **Kapandı (push).** `PartySyncWriter` create öncesinde `DuplicateFinder`'ı çalıştırır (Çakışma kuralları 5). REST `POST /api/v1/parties` mükerrer kontrolü yapmaz; web arayüzü `GET /api/v1/parties/duplicates`'i kullanır.
12. **Kısmen.** `Modules/Sync/Services/AuditDiff.php`, `base_version`'dan sonraki her sürüm adımının activitylog kaydını arar. Herhangi bir adımın kaydı eksikse sonuç "bilinmiyor" olur ve kural 4 uygulanır. İzlenen alanları yazarın `auditFields()` listesi belirler; Purchasing talep satırlarını `lines` olarak kaydeder (`PurchaseRequest::recordLineChange`). Daha ince birleştirme için sürüm bazlı alan değişikliği kaydı yine gerekir.
13. **Açık.** Sunucu toplamları hesaplıyor (`preview-totals`). Çevrimdışı taslakta tutarlar geçicidir; push sonrası `data` ile kesinleşir.

**Cihaz kimliği**

14. **Kapandı.** `device_refresh_tokens` tablosu (`Modules/Identity/Database/migrations/2027_08_01_300100_create_device_refresh_tokens_table.php`) ve `POST /api/v1/auth/device/refresh` (`DeviceController::refresh`) uygulandı; kurallar Token yaşam döngüsü bölümündedir.

**Pull**

- **Kapandı.** Artımlı cursor kullanıcı, şirket, kaynak ve konuma HMAC akış etiketiyle bağlıdır (`Modules/Sync/Services/CursorTags.php`); eşleşmeyen veya eksik etiket `cursor_expired` reseti üretir. Etiket durumsuz ve deterministiktir; aynı cursor farklı `limit` ile de tekrar kullanılabilir. Tel şekli ve desenler değişmediği için `schema_version` 1 kalır.

- **Kapandı.** Tam indirme sayfasının tekrarı: tekrarlanan isteğin kendi cursor'ı (`session` dahil) aynı `limit` ile aynen yeniden gönderilir; alınan `next_cursor` tekrar değil, sonraki sayfanın isteğidir. Sunucu oturumun son verdiği cursor'ı ve bir öncekini tanır (`Modules/Sync/Services/FullDownloads.php`); önceki cursor'la yapılan tekrar okur ama oturumu ilerletmez. Çok sayfalı indirmenin son sayfası (`has_more = false`) oturumu siler; bu sayfayı isteyen devam isteği bitmiş oturumun kimliğini taşıdığından tekrarı `reset: true, reset_reason: cursor_expired` alır ve yeni bir tam indirme başlar. Tek sayfada biten ilk indirmede (`cursor: null`) oturum açılmaz; isteğin tekrarı yine `cursor: null` ile yeni bir tam indirmedir ve `reset: false` döner. Kural Tam indirme oturumu bölümündedir.

**Kapsam**

15. **Açık.** B3'teki çevrimdışı yazma türlerinden masraf beyanı, izin talebi, puantaj/zaman çizelgesi (İK, Faz 11) ile not ve aktivite için backend'de modül veya uç yok; manifestte yer almıyorlar.
16. **Açık.** ONLINE_ONLY listesindeki bordro ve MRP çalıştırma uçları yok (Faz 10 ve 11); eklenince manifeste girecek.
17. **Açık.** Cari bakiyesi için ayrı liste ucu yok. Anlık görüntü `treasury-reports/receivables-ageing` ve `payables-ageing` raporlarından alınıyor; `customers/{id}/exposure` cari başına ve çevrimiçi.
18. **Açık.** Depolar iki uçtan okunuyor: `warehouses` (Reference, ana veri) ve `stock-warehouses` (Inventory ayarları). Manifest ana veri olarak `warehouses`'ı kullanıyor.

**Frontend zemini (A6)**

19. **Açık.** `frontend/src/platform/web/` altında `http`, `connectivity`, `notify`, `realtime` var; `files` (kaydet/yazdır/aç) arayüzü yok.
20. **Açık.** Query key'ler `[resource, …]` standardında değil, modül köklü: `['parties', company]`, `['catalog', company, 'items', …]`, `['inventory', company, 'docs', …]`, `['reference', name, …]`. Senkron sonrası jenerik invalidation için ya key'ler standartlaştırılmalı ya da manifest kaynağı → key öneki eşlemesi tutulmalı.
21. **Kapandı.** `.github/workflows/contracts.yml` şemaları derler ve fixture'ları doğrular (kökte `npm run contracts:validate`). `backend.yml`, manifest route'larını `backend/scripts/check-offline-manifest.php` ile route listesine karşı denetler.

**Bilinen sınırlamalar**

- **Create payload büyüklüğü.** Push şeması ve sunucu, create payload'ının üst düzey anahtar sayısını sınırlamaz (`contracts/sync/push.request.schema.json:60-64`; `minProperties`/`maxProperties` yalnız update/action ve delete için). Parti boyutu (`limits.push_batch_max`) ve istek hızı (Hız ve eşzamanlılık sınırları) sınırlıdır.
- **Başka şirkette UUID varlığı.** İstemci anahtarı global birincil anahtardır. `ClientKey` her başarısızlıkta aynı mesajı verse de, geçerli bir UUIDv7'nin başka bir şirkette kullanıldığı ayırt edilebilir: zorunlu alanları eksik bir istekte bile yalnız kullanılmış UUID için `errors.fields.id` oluşur (`Core/Sync/Rules/ClientKey.php:16-20`). UUIDv7 tahmin edilemez, istek `create` izni ister ve senkron uçları hız sınırlıdır. Şirket kapsamlı anahtar tasarımı kapsam dışıdır.
- **`parties.create` olup `parties.view` olmayan kullanıcı.** Aktif carilerde `(company_id, tax_number)` tekildir (`Parties/Http/Requests/PartyRequest.php:168-175`); `PartyPolicy::create` yalnız `parties.create` ister (`Parties/Policies/PartyPolicy.php:29-32`). Böyle bir kullanıcı aynı vergi numarasıyla oluşturmayı deneyerek kendi şirketinde o numaralı aktif cari olup olmadığını öğrenebilir; ad veya `id` açıklanmaz. Mesajı genelleştirmek bunu kapatmaz; kapatmak create için `parties.view` şartı koşmayı, yani "`viewAny` yoksa aday araması atlanır" akışını (Çakışma kuralları 5) kaldırmayı gerektirir. Varsayılan rollerde create izni olan her rolde view de vardır.
- **Sayım satırı `avg_cost`.** `stock-documents` sayım satırlarındaki `avg_cost`, Resource çıktısında güncel değerlemeden (`stock_valuations`) hesaplanır (`Inventory/Http/Resources/StockDocumentResource.php:55-73`). Başka bir stok kaydı değerlemeyi değiştirince belgenin `sync_txid`'i ilerlemez. Yerel kopyadaki `avg_cost` bu yüzden belge yeniden değişene veya reset olana kadar bayat kalabilir. Değer yalnız görüntüdür: kesinleştirme değerlemeyi yeniden yapar ve deftere bayat tutar yazılmaz; push payload'ı `avg_cost` taşımaz. Değerleme değişince taslak sayım belgelerine dokunmak yeni bir kilit döngüsü (iki sayımın aynı depoda birbirini beklemesi) açacağı için yapılmaz. İstemci güncel ortalama maliyeti çevrimiçiyken `stock-balances` anlık görüntüsünden okuyabilir.
- **Pull toplam süresi.** Pull süresi (Hız ve eşzamanlılık sınırları) yalnız veritabanı deyimlerinde uygulanır: deyimden önce kalan süre denetlenir, deyim içinde `statement_timeout` keser. İki deyim arasındaki saf PHP işi kesilemez ve ancak sonraki deyimde yakalanır (`LimitConcurrentPulls.php:25-31`). Bundan başka aşım kaynakları da vardır: `statement_timeout` kalan süreyi 100 ms'lik adımlarla izler (`PullLease.php:33`, `:124-136`) ve pull sonundaki ayar geri yükleme sorguları süre denetimi dışındadır (`:96-110`). Aşım olağan durumda küçüktür ama sayısal bir üst sınırı garanti edilmez; slot süresi, bu kaynakların toplamı 5 sn'lik güvenlik payını geçmedikçe aşılmaz. Cache store kilit desteklemiyorsa sınır ve süre hiç kurulmaz.
