# ONET Fiskal 0.9.0 — WHMCS sandbox modul

Prva instalaciona razvojna verzija sopstvenog ESIR-a za UTG/ONET. Nema tuđeg fiskalnog servisa: modul šalje zahtev V-PFR sandboxu Poreske uprave Srbije.

**Samo za razvoj i testiranje. Ovo nije odobren ESIR i ne izdaje račune za stvarni promet. Produkcioni endpoint je programski blokiran.**

## Novo u 0.9.0 — administratorski pregled dostave

Uz već izdati dokument dostupan je pregled primaoca, sandbox poruke i priloga. Primalac WHMCS prodaje/refundacije/kopije čita se preko originalne fakture i klijenta. Zasebni testovi koriste demonstracionu adresu. Interno zatvaranje avansa i njegova kopija ne ulaze u dostavu kupcu.

Ovo još ne šalje mejlove niti upisuje zadatak za slanje. Prilozi su postojeći HTML sa sačuvanim QR-om i originalni TXT; PDF se i dalje čuva kroz browser štampu. Nema nove šeme baze ni promene privatnog config-a. Prekopiraj ceo addon, uključujući novi `lib/Delivery.php`. [Koraci provere i dalji delovi faze 6](docs/PHASE6-DELIVERY.md).

## Novo u 0.8.0 — radni pregled i vidljiv sledeći korak

Početni ekran objedinjuje svih pet izvora: WHMCS fakture, zasebne testove, povraćaje, kopije i avansne dokumente. Prvo prikazuje nepotvrđene/odbijene zahteve, zatim nacrte. Svaki red ima naziv dokumenta, WHMCS vezu, iznos, status i konkretnu radnju za nastavak. Brojači su brojevi dokumenata, ne zbir prometa.

Posebno se ističu potvrđena interna zatvaranja avansa kojima nedostaje nacrt konačnog računa. U pregledu dokumenta veći iznos je u zasebnom sažetku desno; na manjim ekranima ide iznad stavki. Potvrda izdavanja ima izdvojen red sa glavnim dugmetom. Tehnički detalji ostaju sklopljeni, a obračuni, zaštite i sačuvani fiskalni žurnali ne menjaju se.

[Pregled dva ekrana sa izmišljenim podacima](docs/UI-PREVIEW.html). U ovoj datoteci sve finansijske radnje su isključene. Za instalaciju prekopiraj ceo addon iz ZIP-a bez deaktivacije i bez promene privatnog config-a.

## Novo u 0.7.1 — WHMCS broj računa i mirniji prikaz

Uz interni ID prikazuje se oznaka sa stvarnim `tblinvoices.invoicenum`, npr. `#48` i `UTG-2026-048`. Prefiks i broj se ne generišu u dodatku. Kada broj nije dodeljen ili nedostaje WHMCS zapis, ostaje samo ID. Oznake su dostupne u poslednjim fakturama, detalju, žurnalu i vezanoj refundaciji; jedna lista koristi jedan grupni upit.

Dorađeni su tipografija, neutralne površine, kompaktne tabele, oznake statusa i navigacija prema BoardUI smernicama. Ovo je lokalni PHP/CSS prikaz prilagođen WHMCS-u; nema React/Tailwind zavisnosti ili spoljnog učitavanja fontova. Pravila obračuna i izdavanja ostaju ista.

Nadogradnja: prekopiraj ceo `modules/addons/onetfiskal/` iz novog ZIP-a. Privatni config i postojeća evidencija ostaju isti; modul se ne deaktivira.

## Novo u 0.7.0 — čitljiviji interfejs i jasni koraci

Horizontalna navigacija, kartice sa iznosima, statusi na srpskom, numerisani koraci i odvojena dugmad za pregled, izdavanje, proveru ishoda i evidenciju isplate. Povraćaj kupcu i interno zatvaranje avansa imaju zasebne kartice i objašnjenja; posle internog AR-a jasno sledi konačni račun. Tehnički JSON je sklopljen. Originalni fiskalni žurnal ostaje neizmenjen.

Prekopiraj ceo addon, uključujući novi `assets/admin.css` i `lib/Ui.php`. Ne deaktiviraj modul i ne menjaj privatni config ili bazu. Nema nove šeme baze niti automatskog izdavanja, banke ili mejla. [Nadogradnja i vodič kroz interfejs](docs/UI-GUIDE.md).

## Novo u 0.6.0 — zasebni ručni sandbox avansi

Ekran „Avansi” dodaje jednu izmišljenu uplatu po test porudžbini, povraćaj avansa kupcu i odvojeni postupak interno zatvaranje AR → konačni PP. Konačni zahtev sadrži samo eventualnu novu doplatu, uz celu vrednost izvršene test usluge i zasebne napomene o iskorišćenom avansu. Posle prekida nastavlja se postojeći postupak bez ponavljanja internog zatvaranja.

Prekopiraj ceo addon bez deaktivacije; dve tabele se dodaju automatski, privatni config ostaje isti. [Detaljni testovi sa/bez doplate i povraćajem](docs/PHASE5-ADVANCES.md). Nema automatskog povezivanja sa stvarnim WHMCS narudžbinama, novih bankarskih transakcija ili slanja kupcu. Ovo je sledeći deo faze 5, ne završen produkcioni ESIR.

## Novo u 0.5.0 — sandbox kopije

Dodati su nacrt i potvrda za Kopija–Prodaja i Kopija–Refundacija, povezane sa originalom. Izdavanje kopije je zaseban V-PFR zahtev; preuzimanje originala i dalje ne šalje zahtev. Kopije ne menjaju promet, saldo refundacije ni bankarsku evidenciju. Prikazuju se u žurnalu uz QR i uvećanu poruku „ОВО НИЈЕ ФИСКАЛНИ РАЧУН”.

Prekopiraj ceo addon bez deaktivacije i promene privatne konfiguracije. [Koraci provere i ograničenja](docs/PHASE5-COPIES.md). Ovo je prvi deo faze 5; avansi, poslovna pravila i ostale ESIR funkcije ostaju za dalji razvoj.

## Novo u 0.4.0 — ručne sandbox refundacije

Pune i delimične refundacije potvrđenih originalnih test prodaja; originalna cena i poreska oznaka, količine do tri decimale, obavezna test identifikacija i reference. Samo povraćaj na karticu/prenos na račun. Zajedničko zaključavanje i provera zbira sprečavaju prekoračenje originala i duplo izdavanje. Posebno se čuva izmišljena evidencija isplate — bez poziva banci ili izmene WHMCS transakcija.

Prekopiraj **ceo** addon direktorijum bez deaktivacije. Nova tabela refundacija se dodaje automatski, privatna konfiguracija ostaje ista. [Tačni koraci prvog testa i ograničenja](docs/PHASE3-REFUNDS.md).

## Novo u 0.3.0 — žurnal, QR i preuzimanje

Nova faza 2 dodaje objedinjeni žurnal sa pretragom, filterima i stranicama, prikaz sa originalnim QR kodom V-PFR-a, samostalni HTML, originalni TXT i prikaz za štampu / čuvanje u PDF iz browsera. Nema automatskog izdavanja ni slanja mejla.

**Nadogradnja:** prekopiraj ceo `modules/addons/onetfiskal`, uključujući novi `receipt.php` i biblioteke. Ne deaktiviraj modul, ne briši bazu i ne menjaj privatni config. Otvori „Elektronski žurnal” i proveri već izdat račun. Detalji i lista provere: [docs/PHASE2-JOURNAL.md](docs/PHASE2-JOURNAL.md).

## Novo u 0.2.0 — zaseban sandbox test

Preporučeni prvi test sada je **Zaseban sandbox test**, umesto pravljenja i plaćanja test WHMCS faktura. U modulu izaberi scenario hosting/domen, osnovicu (npr. 1000.00) i jedan od gateway-a iz privatne konfiguracije. Klikni **Napravi nacrt i pregledaj zahtev**, proveri iznos/način plaćanja, zatim potvrdi i izdaj izmišljeni sandbox račun. Za test VAT 19% očekuj ukupno 1190.00 RSD. Za običan QR nalog `onetipsqr => 4`, za karticu `corvuspay => 2`.

Ovaj ekran koristi fiksni opis SANDBOX TEST i izmišljeno fizičko lice. Ne kreira WHMCS fakture, uplate, klijente ili hosting/domen, ne menja globalne poreske postavke i ne šalje mejlove. Čuva poseban nacrt i račun u `mod_onetfiskal_tests`. Ponovljen isti POST za pregled vraća isti nacrt; nakon izdavanja nema novog POST-a za isti test.

**Nadogradnja:** sačuvaj rezervnu kopiju modula i njegovih tabela, prekopiraj samo `modules/addons/onetfiskal` i otvori modul. Ne deaktiviraj i ne briši evidenciju. Nadogradnja dodaje novu tabelu i polje `source` u zajedničku tabelu zaključavanja. Postojeće fakture, računi i zaključavanja se čuvaju. Privatni config, PFX i postojeći kombinovani CA bundle ostaju isti. Postojeći nepoznat ishod blokira i zasebne testove dok ga ne proveriš.

Detaljan plan provere: [docs/TESTING.md](docs/TESTING.md). Automatsko izdavanje, avansi, refundacije i slanje kupcima ostaju za kasnije faze.

## Izmena 0.1.2 — potvrda već izdatog računa

V-PFR identifikatori u broju računa mogu sadržati mala i velika slova. Provera sada prihvata oba i čuva originalni broj bez promene. Starija verzija mogla je ispravan HTTP 200 odgovor ostaviti u stanju `unknown`.

Nadogradi samo `modules/addons/onetfiskal`, bez deaktivacije i bez brisanja tabela ili privatne konfiguracije. Otvori postojeću fakturu sa stanjem `unknown` i klikni dugme za proveru ishoda. Modul prvo proverava već sačuvan odgovor prema originalnom zahtevu (JID, iznos, broj, journal, potpis i zvanični sandbox link). Ako odgovara, prebacuje ga u `issued` i oslobađa zaključavanje bez novog POST-a i bez mrežnog poziva. Ako sačuvani odgovor nije odgovarajući, ostaje provera GET-om. Ne praviti zamensku fakturu za isti već izdat račun.

## Izmena 0.1.1 i nadogradnja postojećeg modula

- Na osnovu stvarnog odgovora korisnikovog sandboxa dodata je test VAT stopa 19%, oznaka Ж; postojeći testovi za 20% ostaju.
- Tačan VAT par (kategorija, stopa i oznaka) mora biti prisutan u statusu. Modul ne prihvata druge poreze kao VAT i ne preračunava neusklađene WHMCS fakture.
- Prepiši samo `modules/addons/onetfiskal` novom verzijom; nema izmene šeme baze ni potrebe za deaktivacijom. Sačuvaj privatni config i dodaj `'vat_rate' => 19,` i `'tax_label' => 'Ж',`.
- Privatna putanja CA bundle-a ostaje tvoja. Ako je prethodno TLS zahtevao sistemske CA, zadrži kombinovani `vsdc-ca-bundle.pem`; ne vraćaj je na bundle koji ima samo CA sertifikate Poreske.
- Test račun sa 19% napravi isključivo u razvojnoj WHMCS instanci. Ne menjaj produkcione poreske postavke, postojeće fakture ili stvarne uplate radi sandboxa.

## Šta radi

- Addon stranica u WHMCS adminu; pristup samo dozvoljenim admin ulogama.
- Provera sertifikata, veze, JID-a i aktivne poreske oznake za podešene sandbox VAT stope (19% ili 20%).
- Ručni pregled fakture i izdavanje sandbox računa Promet–Prodaja (Normal/Sale).
- InvoicePaid hook automatski evidentira fakturu za pregled; ne izdaje račun.
- Čuva originalni zahtev, RequestId, odgovor, identitet admina i stanje u svojim tabelama.
- Prikazuje originalni tekst računa i link za proveru na zvaničnom sandbox portalu.
- Blokira dupli POST za istu WHMCS fakturu. Neizvestan ishod blokira i ostale račune dok se ne proveri.
- Provera neizvesnog zahteva koristi GET sa istim RequestId. Nema automatskog ponovnog POST-a.
- Deaktivacija zadržava evidenciju; ne briše i ne menja WHMCS fakture i transakcije.

## Granice ove verzije

Podržana je test faktura domaćeg fizičkog lica, samo RSD, jedna puna uplata bez korišćenja kredita, sve stavke pozitivne i oporezive podešenom sandbox VAT stopom (19% ili 20%), bez drugog poreza. WHMCS Tax Type mora biti **Exclusive**. Prikazani iznosi prema V-PFR-u uključuju PDV. WHMCS stavka se šalje kao jedna obračunata usluga, količina 1 i ukupan opis perioda/usluge iz fakture.

Za originalnu prodaju namerno su zaustavljeni avansi, konačni računi koji zatvaraju avanse, refundirane WHMCS uplate, kupci firme/preduzetnici, ino kupci, kredit sa naloga, delimične/više uplata, negativne stavke/popusti i valute osim RSD. Nema javnog prikaza u klijentskoj zoni, automatskog mejla, PDF izmene ili produkcione automatizacije u v0.1.1. Time se izbegava da se test račun dostavi kao stvarni račun kupcu.

Ako hosting naplaćuješ unapred, to se ne sme u produkciji samo pretvoriti u Promet–Prodaja. Sa knjigovođom prvo odredi trenutak prometa i model avansnog/periodičnog obračuna za hosting i domene. Pre produkcije implementirati i proveriti ceo potreban tok; potrebno je i odobrenje Poreske uprave za sopstvenu upotrebu.

## Zahtevi

- Kod koristi sintaksu podržanu u PHP 8.3; potrebne ekstenzije curl, openssl i mbstring; libcurl >= 7.71.
- WHMCS sa standardnim addon/hook API-jem i Capsule bazom (cilj: aktuelni WHMCS 9.x).
- Izlazni HTTPS sa WHMCS servera prema `vsdc.sandbox.suf.purs.gov.rs:443` i ispravno vreme servera.
- Sandbox PFX, njegova lozinka, PAK i JID. Windows instalacija sertifikata ne daje pristup PHP-u na serveru: PFX mora posebno biti postavljen privatno na server.
- CA bundle koji verifikuje serverski TLS sertifikat: sistemski javni CA sertifikati uz originalne Sandbox RCA i Issuing CA sertifikate Poreske uprave kada su potrebni.

Najpre instaliraj na razvojnoj WHMCS instanci sa izmišljenim test podacima. Sandbox dobija opis usluga koji pošalješ. Nisu potrebni stvarni klijenti za prvi test.

## Instalacija

1. Raspakuj ZIP na svom računaru.
2. Samo folder `modules/addons/onetfiskal` kopiraj u isti folder WHMCS instalacije. Primer: `/home/CPANEL_USER/public_html/modules/addons/onetfiskal/onetfiskal.php`. Ako je WHMCS u podfolderu, prilagodi osnovnu putanju. Ne kopiraj config, tests i README u javni web direktorijum.
3. Napravi privatni direktorijum, npr. `/home/CPANEL_USER/onet-private`, izvan svih javnih document root direktorijuma. Dozvole direktorijuma 700; fajlova 600. PHP treba da radi kao taj cPanel korisnik. Kod zasebne PHP grupe administrator može koristiti 750/640. Ne koristi 777.
4. U privatni direktorijum stavi PFX. Kopiraj `config/onetfiskal.example.php` u taj direktorijum kao `onetfiskal.php` i popuni ga lokalno na serveru. Lozinku i PAK ne šalji u chat i ne upisuj u javne fajlove.
5. Pripremi `sandbox-ca-bundle.pem` prema narednom odeljku.
6. U WHMCS otvori **System Settings → Addon Modules → ONET Fiskal — Sandbox → Activate**.
7. Pod **Configure** unesi putanju privatnog config fajla: `/home/CPANEL_USER/onet-private/onetfiskal.php`. U **Access Control** dozvoli svoju administratorsku ulogu. Sačuvaj.
8. Otvori **Addons → ONET Fiskal — Sandbox**.

PFX, PAK i CA bundle se ne snimaju u WHMCS podešavanja. Kod proverava da su fajlovi izvan WHMCS i trenutnog document root direktorijuma i bez dozvola za ostale korisnike. Ti ili administrator morate proveriti i da taj direktorijum nije objavljen kroz neki drugi domen, alias ili web server.

## CA bundle

Koristi originalne RCA i ICA fajlove iz mejla ili zvaničnog portala. Ekstenzija `.cer` može sadržati PEM ili DER: nije dovoljno preimenovati fajl u `.pem`.

Ako imaš Terminal u cPanelu/SSH, kopiraj i pomoćni `config/build-ca-bundle.php` u privatni direktorijum. Primer, iz tog direktorijuma:

```bash
php build-ca-bundle.php 'Sandbox SUF RCA.cer' 'Sandbox SUF Issuing CA 1.cer' sandbox-ca-bundle.pem
```

Skripta prihvata PEM i DER, proverava da su X.509 sertifikati vremenski važeći i pravi novi PEM fajl. Na standardnim Linux serverima dodaje i dostupne sistemske javne CA sertifikate, kako bi provera serverskog TLS sertifikata radila uz sandbox CA. Ako sistemski CA nisu pronađeni, ispisaće to; administrator treba da obezbedi odgovarajući kompletan CA bundle. Ne utvrđuje poreklo CA sertifikata; koristi samo fajlove Poreske uprave.

Bez SSH-a: u Windows Certificate Viewer za svaki CA izaberi **Details → Copy to File → Base-64 encoded X.509 (.CER)**. Sadržaj oba tako izvezena fajla spoji u jedan tekstualni fajl `sandbox-ca-bundle.pem`, sa oba bloka `BEGIN CERTIFICATE`/`END CERTIFICATE`. Postavi privatno na server. Ne isključuj TLS proveru.

## Konfiguracija

U privatnom config fajlu promeni sve placeholder vrednosti.

| Polje | Vrednost |
|---|---|
| environment | Ostaje `sandbox` |
| vsdc_url | Ostaje zvanični sandbox URL iz primera |
| pfx_path | Apsolutna putanja testnog PFX-a na serveru |
| pfx_password | Lozinka tog PFX fajla, nije PAK |
| pac | PAK za isti sertifikat |
| expected_uid | Tačan osmocifreni/osmoznakovni JID istog sertifikata |
| ca_bundle | Apsolutna putanja PEM bundle-a |
| tax_label | Tačan znak aktivne sandbox VAT stope, kopiran iz sandbox podataka |
| payment_map | Stvarni WHMCS gateway identifikator → PFR kod načina plaćanja |

Za prikazani odgovor sandboxa (grupa 8) koristi `vat_rate => 19` i `tax_label => 'Ж'`. To nisu poreska pravila za produkciju: ovaj sandbox vraća testne VAT stope 10%, 0% i 19%, pa 20% u tom odgovoru nije dostupno. Modul proverava i stopu i oznaku; ne preimenuje 20% u 19%. Faktura sa 20% se odbija kada je test konfigurisan na 19%. Ako se sandbox podaci promene, poruka navodi sve dostupne VAT parove oznaka/stopa. Ćirilično `Ж` mora se kopirati tačno.

Primer `banktransfer => 4` važi za prenos na račun. Ako imaš svoj modul naplate, koristi gateway iz stvarne transakcije: vidi se u pregledu fakture. Ne mapiraj sve načine plaćanja na isti kod. Za instant plaćanje proveri aktuelni srpski šifarnik; generički protokol ne objašnjava sve lokalne nazive. Nije uveden podrazumevani način plaćanja za nepoznate gateway-e.

Glavni DeveloperAuth sertifikat je prvenstveno za pristup portalu. Ako V-PFR ne prihvati taj sertifikat ili nedostaje odgovarajući testni poslovni prostor, kroz razvojni portal traži dodatni sandbox PFX za testno izdavanje računa. Koristi njegov JID, lozinku i PAK zajedno. Sertifikat za modul treba da bude namenski: tokom neizvesnog ishoda ne izdavati druge račune istim sertifikatom iz drugog programa.

## Prvi test

1. U modulu klikni **Proveri vezu i poresku oznaku**. Potreban je uspešan odgovor sa odgovarajućim JID-om i podešenom sandbox VAT stopom.
2. U razvojnom WHMCS-u napravi izmišljenog fizičkog klijenta, zemlja RS, bez naziva firme i PIB-a, valuta RSD.
3. Napravi oporezivu test fakturu: osnovica 1.000,00 RSD, test VAT 190,00 RSD, ukupno 1.190,00 RSD (za sandbox stopu 19%). Koristi **Exclusive** podešavanje samo u razvojnoj instanci; ne menjaj poreski model produkcije radi ovog testa.
4. Evidentiraj jednu punu test uplatu 1.190,00 kroz gateway koji si mapirao. Nemoj pokretati stvarno kartično terećenje. Hook dodaje fakturu u pregled.
5. Otvori fakturu preko modula ili dugmeta **ONET Fiskal — sandbox** u admin prikazu fakture. Ako dugme nije dostupno zbog teme, unesi WHMCS ID u samom modulu.
6. Pregledaj zahtev, potvrdi da je test izvršenog prometa bez avansa i klikni **Izdaj test Promet–Prodaja**.
7. Očekuješ stanje `issued`, PFR broj, originalni journal i link za verifikaciju. Otvori link i proveri iznos i PDV na sandbox portalu.
8. Ponovo otvori istu fakturu: mora prikazati isti račun bez novog POST-a.

Račun se izdaje sa sadašnjim vremenom Europe/Belgrade, ne sa proizvoljnim istorijskim datumom WHMCS fakture. Ne postoji backfill stvarnih starih uplata.

## Stanja i greške

| Stanje | Značenje |
|---|---|
| review | Čeka ručni pregled; zahtev još nije poslat |
| sending | Zahtev trajno sačuvan; slanje u toku ili je proces prekinut |
| issued | Primljen i sačuvan odgovarajući odgovor sa računom |
| rejected | Primljeno izričito odbijanje sa API greškom; nema automatskog ponavljanja |
| unknown | Nije potvrđeno da li je račun izdat; blokirano novo izdavanje |

Kod `unknown` ili zaostalog `sending`, klikni **Proveri ishod postojećeg zahteva (GET)**. U v0.1.2 dugme prvo proverava već sačuvan odgovor; ako odgovara originalnom zahtevu, potvrđuje ga lokalno bez ponovnog poziva. Potrebno je zadržati isti originalni PFX fajl. Prazan odgovor/404 ne pokreće ponovni POST i ne oslobađa zaključavanje. Proveri portal i uzrok sa podrškom Poreske. Ne briši evidenciju, ne resetuj bazu i ne izdaji drugi račun da bi zaobišao nepoznat ishod.

Kod `rejected` vidi sačuvan odgovor (npr. modelState) i ispravi konfiguraciju. v0.1 ne omogućava ponovno izdavanje odbijenog zahteva nad istom WHMCS fakturom; koristi novu izmišljenu test fakturu nakon što je odbijanje razjašnjeno. Ova konzervativna politika nije produkcioni retry mehanizam.

Ako OpenSSL ne otvara stari PFX format, poruka u modulu je generička. Administrator može lokalno konvertovati PFX sa podržanim algoritmima uz očuvanje istog sertifikata i ključa. Ne koristi onlajn konvertore i ne isključuj TLS verifikaciju. Konverziju uradi pre prvog testa; ne menjaj fajl dok postoji neizvestan zahtev.

## Provera i ograničenja validacije

U paketu su standalone testovi poslovne logike i state-machine toka sa lažnim V-PFR odgovorima. Pokretanje: `php tests/run.php` uz PHP mbstring. Odvojeno pokreni `php -l` za svaki PHP fajl.

Prvobitna lokalna validacija autora izvršena je na PHP 8.4 CLI. Kod ne koristi funkcije specifične za 8.4; tačna PHP 8.3/WHMCS verzija korisnika nije bila dostupna. WHMCS nije instaliran u razvojnom okruženju ovog paketa. Testovi baze proverava stvarni Capsule/SQLite sloj, migracije, izolaciju podataka, pregled UI-a i zajedničko zaključavanje; aktivacija, pristupne uloge i hook prikaz i dalje zahtevaju smoke test u tvojoj razvojnoj instanci. GitHub CI proverava i MySQL 8 i PHP 8.3/8.4. Nije izvršen autentifikovan poziv Poreskoj jer sertifikati nisu dostavljeni. Naknadno je korisnik potvrdio sandbox izdavanje i verifikaciju na svom serveru u fazi 1. Novi prikaz 0.3.0 još zahteva proveru na tom WHMCS-u.

WHMCS kompatibilnost nije sertifikovana. Testovi ne dokazuju usklađenost ESIR-a niti predstavljaju odobrenje Poreske uprave. Ovo je prvi integracioni korak; kompletan ESIR mora podržati sve obavezne funkcije za odobravanje i sve tokove koje koristiš u poslovanju.

## Izvori za dalji razvoj

- WHMCS addon struktura: https://developers.whmcs.com/addon-modules/getting-started/
- WHMCS hooks: https://developers.whmcs.com/hooks-reference/invoices-and-quotes/
- WHMCS instalacija addona: https://docs.whmcs.com/9-1/addon-modules/addon-modules/
- Poreska razvojni portal i tehnička dokumentacija: https://tap.sandbox.suf.purs.gov.rs/Help/
- Poreska: POS to SDC Protocol, Create Invoice, Get Status, Get Last Signed Invoice; postupak odobravanja za sopstvenu upotrebu.

Verzija paketa: 0.6.0, 14. novembar 2026.
