# ONET Fiskal 0.7.1 — pregledniji administratorski interfejs

Ovo je nadogradnja prikaza postojećeg sandbox modula. Ne dodaje nove fiskalne tipove, bankarski API, mejlove ili produkciju. WHMCS zaglavlje i bočna navigacija ostaju izvan stilova dodatka.

## Nadogradnja sa 0.6.0

1. Napravi rezervnu kopiju postojećeg direktorijuma dodatka i baze.
2. Iz ZIP-a prekopiraj ceo `modules/addons/onetfiskal/` u isti WHMCS direktorijum. Uključi nove `assets/admin.css` i `lib/Ui.php`, kao i sve postojeće datoteke.
3. Nemoj deaktivirati dodatak, brisati evidenciju ili menjati privatni config/PFX. Ova verzija ne dodaje novu šemu baze.
4. Ponovo otvori ONET Fiskal i osveži stranicu. CSS je lokalno ugrađen u odgovor; nema CDN-a, fontova ili skripti treće strane.
5. Otvori ranije izdatu prodaju, refundaciju i avans. Proveri žurnal, QR, HTML/TXT i štampu. Sam pregled ne izdaje dokument.

## Broj WHMCS računa uz interni ID

Primer `#48` uz oznaku `UTG-2026-048` znači: interni WHMCS ID je 48, a dodeljeni broj računa pročitan je iz `tblinvoices.invoicenum`. Dodatak ne generiše niti prepravlja broj. Prazan broj ili nedostajući WHMCS zapis ne stvaraju lažnu oznaku. Ovo je prikaz trenutne WHMCS veze; originalni PFR broj i sačuvani fiskalni odgovor ostaju neizmenjeni.

Oznake se prikazuju u poslednjim fakturama, detalju računa, žurnalu za izvor WHMCS i uz original fakture na refundaciji. Sintetički testovi i zasebni test avansi ne dobijaju izmišljeni WHMCS broj. Pretraga i dalje koristi polja naznačena na formi.

## Kako čitati ekran

- Gornje kartice prikazuju iznos, status i sledeću radnju. Novčani prikaz je `1.190,00 RSD`, sa poravnatim kolonama i ciframa jednake širine.
- Numerisani koraci razlikuju sačuvane podatke, pregled sa potvrdom i izdat dokument. Boje su dopuna jasnim tekstualnim oznakama.
- U polja za novac unosi se tačka: `1190.00`; za polovinu stavke `0.500`. Izračunavanje u parama ostaje na serveru.
- **Pregledaj / Nastavi** čuva ili otvara nacrt; ne šalje novi dokument V-PFR-u.
- **Potvrdi i izdaj** šalje konkretni test dokument tek nakon obaveznog polja potvrde. Sačuvani hash, CSRF i zaštita od dupliranja ostaju aktivni.
- **Proveri postojeći zahtev** proverava njegov ishod. Kod nepoznatog ishoda novi pokušaj izdavanja nije zamena za proveru.
- **Sačuvaj test evidenciju isplate** beleži samo izmišljenu referencu. Ne pokreće bankarski povraćaj.
- JSON i RequestId nalaze se u sklopljenim tehničkim detaljima. Originalni fiskalni žurnal i QR se ne preoblikuju.

## Avans: izaberi odgovarajući nastavak

**Usluga je izvršena** vodi kroz dva zasebno potvrđena dokumenta: interno AR zatvaranje, zatim konačni PP. Posle internog zatvaranja prikazuje se dugme za konačni račun. Interno zatvaranje ne vraća novac kupcu.

**Vrati avans kupcu** vodi na pregled Avans–Refundacije kupcu. Povraćaj može biti delimičan ili pun. Bankarska isplata i lokalna test evidencija su odvojene.

Postojeći nacrt se prvo nastavlja. Zahtev sa nepoznatim ishodom prvo se proverava. Brojke refundirano/rezervisano uključuju i aktivne rezervacije, pa nisu potvrda da je banka isplatila novac.

## Povraćaj prometa

1. U žurnalu otvori originalnu izdatu prodaju i sekciju povraćaja.
2. Proveri preostali iznos, izaberi količine, test identifikaciju kupca, način i razlog.
3. Pregledaj tačan zbir i originalnu referencu; tek zatim potvrdi izdavanje.
4. Po potvrđenom ishodu dokument je završen. Opciona odvojena test evidencija isplate nije uslov da dokument bude prikazan kao izdat.

## Kopije i preuzimanje

Za ponovno otvaranje/štampanje koristi sačuvani original. Opciona sekcija „Kopije dokumenta” priprema poseban fiskalni tip Kopija; nije zamena za obično preuzimanje. Originalni HTML/TXT/štampa dostupni su i za refundacije, kopije i avansne dokumente, uz iste provere administratorskog pristupa.

## Provera u instaliranom WHMCS-u

- Na normalnom zumu proveri širinu, kontrast i duge brojeve računa. Na manjem ekranu kartice treba da pređu jedna ispod druge, a široke tabele imaju horizontalno pomeranje.
- Tabulatorom dođi do polja, potvrde i dugmeta; fokus mora biti vidljiv. Nazivi radnji ne smeju zavisiti samo od boje.
- Otvori već izdat interni AR: mora biti jasno da sledi konačni račun i da nema bankarske isplate.
- Otvori nepoznati ishod: vidi proveru postojećeg zahteva, bez dugmeta koje ponavlja izdavanje.
- Proveri da druge WHMCS stranice i originalni izvoz računa nisu dobili stilove dodatka.

Lokalne provere koriste izmišljene podatke i simulirani V-PFR. Prikaz treba potvrditi i u aktivnoj WHMCS temi; provera pregleda nije potvrda poreske usklađenosti ili produkcionog odobrenja.

## Vizuelni pravac

v0.7.1 koristi neutralniju paletu, jednostavnije podvučene tabove, kompaktnu tabelu bez ugnježdenih okvira i jasnu hijerarhiju tipografije. Boja ističe status i važne radnje, uz obavezne tekstualne nazive.

Reference: [BoardUI Typography](https://www.boardui.com/components/typography), [Color](https://www.boardui.com/components/color), [Table](https://www.boardui.com/components/table), [Button](https://www.boardui.com/components/button). Prilagođene su smernice prikaza, bez preuzimanja React komponenti ili Pro šablona.
