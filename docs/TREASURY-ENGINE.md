# Hazine motoru

Faz 7; kasa ve banka hesaplarını, tahsilat/ödemeyi, hesaplar arası transferleri, çek/senet portföyünü, banka ekstresi mutabakatını ve ödeme planlarını kapsar. Bu belge uygulanan iş kurallarını ve desteklenen sınırları açıklar. Doğrulama durumu `PROGRESS.md` içinde izlenir.

## Modül sınırları

Hazine, satış ve satın alma faturalarının tablolarına doğrudan erişmez. Fatura bilgileri ve kapamalar ilgili modüllerin `Contracts` arayüzlerinden alınır. Fatura bakiyesini satışta `ReceivableSettlement`, satın almada `PayableSettlement` günceller. Hazine muhasebe fişlerini yalnızca `PostingService` aracılığıyla oluşturur ve tersler.

Her para hareketi şirket kapsamındadır. Para tutarları API'de ondalık metin olarak taşınır; hesaplama `BigDecimal` ile yapılır. Ekrandaki ön izleme muhasebe kaydı değildir. Kesin kayıt sırasında tutarlar, izinler, sürüm, kaynak fatura bakiyeleri ve hesap bakiyesi yeniden doğrulanır.

## Kasa ve banka hesapları

Hazine hesabı; kod, ad, tür, para birimi ve muhasebe hesabıyla tanımlanır. Banka bilgileri ve IBAN isteğe bağlıdır. Pasif hesap yeni para hareketi oluşturmak için kullanılamaz. Hareket görmüş bir hesabın para birimi veya muhasebe bağlantısı sonradan değiştirilmez.

Bakiye alanına doğrudan tutar yazılmaz. Hesap bakiyesi, kaydedilmiş giriş ve çıkış hareketlerinden türetilir. Açılış bakiyesi de tarih, tutar, karşı hesap ve açıklama gerektiren ayrı bir kayıt işlemidir; hem hazine hareketini hem dengeli muhasebe fişini oluşturur. İşlem görmüş hesap için yeniden açılış bakiyesi oluşturulmaz.

Negatif bakiyeye izin verilmeyen hesaplarda ödeme ve transfer çıkışları hesap kilidi altında kontrol edilir. Aynı bakiyeyi eşzamanlı iki ödemenin kullanması engellenir. Bir girişin geri alınması da hesabı izin verilmeyen negatif bakiyeye düşürmemelidir.

## Tahsilat ve ödeme

Ödeme belgesi bir hazine hesabını, cariyi, tarihi, yönü, para birimini, kuru ve tutarı taşır. Kaynak fatura kapamaları ayrı satırlardır. Bir faturaya kısmi ödeme yapılabilir; faturanın kalan bakiyesi aşılamaz. Aynı faturanın belge içinde tekrar edilmesi veya başka şirketin faturasıyla eşleştirme yapılması reddedilir.

| Kaynak belge | Para hareketi |
|---|---|
| Satış faturası | Tahsilat |
| Satış iade faturası | Müşteriye ödeme |
| Alış faturası | Tedarikçiye ödeme |
| Alış iade/borç dekontu | Tedarikçiden tahsilat |

Faturalara dağıtılmayan tutar açık hesap/avans olarak izlenir; kapama yapılmış gibi gösterilmez. Fatura kapaması, muhasebe fişi ve hazine hareketi tek veritabanı işlemi içinde kaydedilir. Adımlardan biri başarısızsa diğerleri de geri alınır.

Açık hesap tutarının müşteri mi tedarikçi hesabına mı yazılacağı, tek anlamlı cari/fatura rolünden belirlenir. İki rolün birden kullanıldığı belirsiz durumda `counterparty_module` ile satış veya satın alma tarafı seçilir. İşlemin tahsilat ya da ödeme olması, tek başına cari hesabı belirlemek için yeterli değildir.

Kaydedilmiş belge düzenlenmez veya silinmez. Düzeltme için gerekçe ve tarih içeren ters kayıt kullanılır. Ters kayıt; yeni bir muhasebe fişi ve karşı para hareketi oluşturur, ilgili fatura kapamalarını geri alır. Önceki kayıt denetim için korunur.

Fatura kapaması, faturanın ilk kaydındaki cari muhasebe hesabını kapatır. Hesap belirleme kuralı sonradan değişse de geçmiş faturanın borcu/alacağı yeni hesaba taşınmış gibi işlem yapılmaz. Çek/senetlerin portföy ve tahsil hesabı geçişlerinde de ilk kaydın hesap bağlantıları korunur.

## Döviz ve tarihler

Hazine hesabının para birimi, ödeme tutarının para birimidir. Fatura kapama satırı ise faturanın kendi para birimini taşır. Ön izleme; yerel para karşılığını, faturadan kapanan tarihi kayıt değerini, açık hesap tutarını ve gerçekleşmiş kur farkını ayrı gösterir. Fark, kazanç veya zarar hesabıyla dengelenir.

Fatura kapamasının muhasebe tarihi ile sisteme işlendiği an farklı kavramlardır. Geri tarihli ödeme ve sonraki tarihli ters kayıtlarda, dönem raporları ilgili muhasebe tarihine göre hesaplanmalıdır. Denetim kaydı gerçek işlem anını korur. Eski bir tarih seçildiğinde bugünkü kalan bakiyeyi geçmiş bakiye olarak göstermek doğru değildir.

Kaynak faturadaki son kapama/geri alma olayından daha eski tarihli yeni bir kapama veya geri alma reddedilir. Bu sıra kontrolü, gelecekte geçerli bir geri alma varken aradaki dönemde faturanın iki kez kapanmasını önler. Kısmi kapamaların yerel muhasebe tutarları ayrı saklanır; önceki bir kısmi ödeme geri alınsa da son ödeme gerçek kalan yerel değeri tam kapatır.

Kur farkı bu aşamada muhasebe kazanç/zarar kaydıdır; kendiliğinden yeni bir satış veya alış faturası oluşturmaz.

## Transfer ve ödeme planı

Transfer iki farklı hazine hesabı arasında yapılır. Kaynak ve hedef hesaplar aynı işlem içinde, sabit kilit sırasıyla kontrol edilir. Dövizli transferde her iki para biriminin tutarı ve yerel karşılığı açıkça gösterilir. Masraf varsa ayrı bir masraf hesabına kaydedilir. Ters kayıt her iki hesabın hareketini ve varsa masrafı birlikte ele alır.

Ödeme planı gelecekte beklenen tahsilat veya ödemeyi tutar. Plan oluşturmak para hareketi veya muhasebe fişi yaratmaz. Gerçekleşme, uygun kaydedilmiş ödeme belgesine bağlanır; plandan otomatik banka transferi çalıştırılmaz.

## Çek ve senet

Portföy, alınan ve verilen çek/senetleri ayrı yönlerle izler. Belge; cari, tutar, para birimi, düzenleme/vade tarihi ve tanımlayıcı bilgileri taşır. Kabul edilen her durum değişikliği açık bir işleme, denetim kaydına ve gerektiğinde muhasebe fişine karşılık gelir.

Çek veya senet alınması/keşide edilmesi ile bankadan tahsil edilmesi/ödenmesi farklı olaylardır. Portföye giriş banka bakiyesini artırmaz; bankada kesinleşen tahsilat ilgili portföy veya tahsil hesabını kapatır. Ciro, teminat, tahsile verme, karşılıksız ve iade işlemleri izin ve geçiş kurallarıyla yönetilir. Bir belge iki kez tahsil edilemez. Bağımlı ciro/kapama işlemleri varken kaynak belgeyi geri alma, bağlantılı fatura bakiyelerini tutarsız bırakamaz.

Fatura dağıtımında alınan çek/senet normal satış faturalarına, verilen çek/senet ve ciro normal alış faturalarına bağlanır. Bu akıştaki kaynak faturalar ve tahsil/ödeme hesabı belgeyle aynı para biriminde olmalıdır. İade faturası/dekont kapamaları tahsilat/ödeme belgelerinde ele alınır. Senet portföyü ve borç senedi hesaplarının zorunlu cari boyutu muhasebe satırlarında korunur.

## Banka ekstresi ve mutabakat

CSV ve desteklenen MT940 alt kümesi, önce ön izleme ve doğrulama aşamasından geçer. İçe aktarma gerçek bankaya bağlanmaz. Hatalı para birimi, eksik veya kesilmiş kayıt, belirsiz tutar biçimi ve desteklenmeyen biçim özellikleri sessizce düzeltilmez; hata olarak gösterilir. İçe aktarma sınırları ve desteklenen alanlar ekran/API açıklamalarında belirtilir.

İçe aktarma UTF-8 metin için 2 MB ve 5.000 hareket satırıyla sınırlıdır. CSV'de virgül, noktalı virgül veya sekme ayırıcı ile nokta/virgül ondalık biçimi seçilir. MT940 desteği tek hesaplı, kesin açılış/kapanış bakiyeli ham ekstre içindir; ara bakiye blokları, çoklu mesaj ve SWIFT taşıma zarfları desteklenmez.

Bir ekstre satırı yalnızca aynı şirketin, aynı hesabın, aynı para biriminin ve aynı yön/tutarın kaydedilmiş, geri alınmamış hareketiyle eşleştirilebilir. Eşleştirme önerisi otomatik onay değildir. Aynı hareket birden fazla ekstre satırını kapatamaz. Mükerrer dosya içe aktarma ayrıca önlenir.

Mutabakat, bütün satırlar eşleştirilmeden ve açılış bakiyesi + hareketler = kapanış bakiyesi doğrulanmadan tamamlanmaz. Eşleştirilmiş bir para hareketinin terslenmesi, mutabakatı sessizce geçerli bırakmamalıdır; önce bağlantı kontrollü biçimde çözülmelidir. Yeniden açma gerekçesi denetim kaydında tutulur.

## Erişim ve tekrar güvenliği

Okuma, oluşturma, düzenleme, kayıt, ters kayıt, ciro ve mutabakat izinleri ayrıdır. Bir ekrana girebilmek bütün para işlemlerini yapma yetkisi vermez. Kaynak fatura bilgileri gösterilirken kaynak modülün okuma izni de korunur.

Oluşturma ve kesinleştirme istekleri `Idempotency-Key` kullanır. Yanıt kaybolduğunda aynı işlem yeniden denenirse ikinci para hareketi oluşmamalıdır. Form veya diyalog kapanıp yeniden açılsa da aynı yük için bekleyen anahtar korunur. Güncel olmayan sürümler HTTP 409 ile reddedilir. Taslak olmayan ödeme belgesinin düzenlenmesi veya silinmesi HTTP 409 `DOCUMENT_LOCKED` alır; yalnız dağıtımları değiştiren bir kayıt da belgenin sürümünü artırır.

## Çevrimdışı taslak

Masaüstü uygulaması ödeme belgesini çevrimdışıyken yalnız taslak olarak oluşturur, düzenler ve siler (`treasury-payments`, [Senkron protokolü](SYNC-PROTOCOL.md#çevrimdışı-yazma-kapsamı)). Senkron, REST ucunun kendisini aynı izinlerle çalıştırır; numara, onaya gönderme, kaydetme, ters kayıt ve iptal çevrimiçi kalır. Senkron itmesi para hareketi, muhasebe fişi veya fatura kapaması oluşturmaz; kaydedilmiş, onaylanmış veya iptal edilmiş belge çevrimdışı değiştirilemez.

## Raporlar ve doğrulama

Nakit akışı, hesap bakiyeleri, alacak/borç yaşlandırması, ödeme öngörüsü, çek/senet portföyü ve mutabakat durumu ayrı raporlardır. Farklı dövizler doğrudan toplanmaz; toplamın döviz veya yerel para cinsinden olduğu açıkça belirtilir. Dışa aktarmalar satır sınırı ve elektronik tablo formül koruması kullanır.

Alacak/borç yaşlandırması tarihsel kapama ve geri alma tarihlerini kullanır. Kaynak fatura taraması 50.000 kayıtla sınırlıdır; sınır aşılırsa kısmi toplam gösterilmez, istek hata verir. Ödeme öngörüsü ve mutabakat durum raporu güncel plan/durum bilgisini gösterir; bunlarda geçmiş `as_of` tarihi kabul edilmez. Ekstre eşleştirmesinde muhasebe tarihiyle banka tarihi aynı olmak zorunda değildir; adaylar tutar, hesap ve döviz uyumuyla sayfalanır.

Doğrulama; muhasebe fişlerinin dengesi, fatura kapama ve ters kayıt simetrisi, şirket/izin izolasyonu, sürüm/idempotency, eşzamanlı bakiye kontrolleri, kur ve yuvarlama, ekstre doğrulama, çek/senet durumları ve tarayıcıdan gerçek oturumla temel akışları kapsar. Teknik kontrollerin tamamlanma durumu `PROGRESS.md` içinde izlenir.

## Dosya biçimi kaynakları

MT940 uygulaması için banka tarafından yayımlanan biçim belgeleri esas alınır: [ING yapılandırılmış MT940/MT942 açıklaması](https://www.ingwb.com/binaries/content/assets/support-content/payments-and-reporting/insidebusiness-payments/ing-format-description-structured-mt940-mt942-version-4-strategic-v6.0-01aug2023.pdf) ve [Danske Bank MT940 açıklaması](https://danskeci.com/-/media/pdf/danskeci-com/swift-mt/reconciliation/mt940_structured.pdf?hash=C4A798C2B633C25AE37B5CBCA950605B&rev=eaffaec517704cea9e0689cde0fd626c). Bunlar bütün banka varyantlarının desteklendiği anlamına gelmez; içe aktarıcının kabul ettiği alt küme doğrulama kurallarıyla sınırlıdır.
