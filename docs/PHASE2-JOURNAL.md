# Faza 2 — žurnal i prikaz računa (0.3.0 sandbox)

## Nadogradnja postojeće 0.2.0 instalacije

1. Napravi rezervnu kopiju postojećeg direktorijuma modula i njegove baze.
2. Iz ZIP-a prekopiraj **ceo** `modules/addons/onetfiskal` preko postojećeg direktorijuma istog imena u WHMCS-u. Novi `receipt.php`, `lib/Receipt.php` i `lib/Journal.php` su obavezni. Ne kopiraj README, tests ili config u javni direktorijum.
3. Ne deaktiviraj modul. Ne briši tabele. Privatni config, sertifikati, PAK i CA bundle ostaju postojeći.
4. Otvori Addons → ONET Fiskal. U „O modulu i identifikacija” proveri verziju 0.3.0.
5. Otvori „Elektronski žurnal”. Otvori postojeći potvrđen račun — nije potrebno izdavati novi test radi provere prikaza.

Nema promene šeme baze niti promene originalnih sačuvanih zahteva, odgovora i zaključavanja. Prikaz i preuzimanje koriste samo bazu, bez poziva V-PFR-u. Sačuvani račun je dostupan i kada trenutni privatni config/PFX nije dostupan ili originalna WHMCS faktura više ne postoji.

## Žurnal

- Objedinjene evidencije WHMCS faktura i zasebnih sandbox testova, sa jasnim izvorom.
- Podrazumevano: svi potvrđeni izdati računi iz cele sačuvane istorije. Nema automatskog brisanja stare evidencije.
- Filter izvora, stanja, početnog i završnog datuma; 25 rezultata po stranici.
- Datum filtera je **nastanak evidencije u UTC**, ne PFR datum izdavanja. PFR vreme se posebno prikazuje iz originalnog odgovora, u originalnom obliku. Naknadna provera ishoda ne menja datum nastanka evidencije.
- Kratak broj pretražuje tačan WHMCS/test ID. Ostali upiti pretražuju tekst sačuvanog odgovora (uključujući PFR broj) i tačan RequestId. `%` i `_` su doslovni znaci, ne SQL obrasci.
- Prikaz nije ukupni promet niti poreski izveštaj: sadrži i izmišljene sandbox testove. Ne predstavlja zamenu za računovodstvenu evidenciju.

Pretraga teksta koristi postojeće JSON odgovore, bez naknadnog menjanja istorije. Veoma velika istorija može zahtevati posebne indeksirane kolone u budućoj nadogradnji.

## Račun, QR i preuzimanje

- Originalni `journal` se prikazuje sa sačuvanim razmacima i prelomima; HTML sadržaj se bezbedno escapuje.
- QR se uzima isključivo iz `verificationQRCode` odgovora V-PFR-a, dokumentovanog kao base64 GIF. Proveravaju se format i veličina; nema eksternog QR servisa.
- Nedostajući/neispravan QR prikazuje jasno upozorenje. Ne izmišljamo zamenu i ne šaljemo novi zahtev. Postojeće stanje `issued` ostaje sačuvano; nepotpun prikaz nije spreman za dostavu kupcu.
- „Otvori za štampu / PDF”: zaseban prikaz za browser. Ctrl+P / Cmd+P → štampač ili Sačuvaj kao PDF. Ovo nije serversko generisanje PDF-a.
- „Preuzmi HTML sa QR kodom”: samostalan HTML, sa ugrađenim QR kodom kada je vraćen. Otvara se bez poziva Poreskoj.
- „Preuzmi originalni tekst”: originalni journal bez prepravljanja. TXT nema QR sliku i sam nije kompletna elektronska dostava računa.
- Sve izlazne forme su jasno označene **SANDBOX — SAMO ZA TESTIRANJE** izvan originalnog fiskalnog teksta.
- `receipt.php` je administratorski endpoint: zahteva aktivnog WHMCS administratora i ulogu kojoj je dozvoljen pristup addonu. Nema javnog klijentskog linka, slanja mejla ni fiskalnog POST-a.
- HTTP odgovori zabranjuju keširanje; HTML koristi CSP i nema skripte ili spoljne slike. Preuzeti fajl ipak treba čuvati kao račun sa poslovnim podacima.
- Interni ID instalacije je razvojni hash putanje instalacije. Nije zvanični serijski/IB broj odobrenog ESIR-a; promena putanje ga menja. Modul nema odobrenje i to izričito prikazuje.

## Provera na stvarnom WHMCS-u pre prihvatanja

1. Žurnal mora prikazati prethodne potvrđene WHMCS i zasebne test račune bez novog izdavanja.
2. Pretraži postojeći PFR broj, WHMCS ID i RequestId; isprobaj izvor i stanje. Proveri datume uz jasno označen UTC.
3. Na računu sa sačuvanim QR kodom skeniraj QR telefonom: mora voditi na isti sandbox račun kao dugme za proveru.
4. Preuzmi HTML i TXT, uporedi originalni tekst sa sačuvanim odgovorom. HTML mora da otvori QR bez interneta.
5. Otvori štampu i izaberi A4/PDF. Proveri da su sve kolone i QR vidljivi. Izgled testirati i u aktivnoj WHMCS temi i pregledaču korisnika.
6. Odjavi se i otvori isti `receipt.php` link: račun ne sme biti prikazan. Ponovi sa admin ulogom bez pristupa modulu.
7. Ponovo proveri RequestId, broj računa i zaključavanje: otvaranje/preuzimanje ih ne menja.

## Automatska provera

`composer test`: postojeći testovi izdavanja i oporavka, plus baze/UI testovi starije istorije, filtera, granica datuma, SQL wildcard-a, XSS-a, QR formata, prava pristupa i nepromenljivosti pri preuzimanju. CI izvršava SQLite i MySQL 8 na PHP 8.3/8.4.

U ovom razvojnom okruženju nema licencirane WHMCS instalacije niti pristupa pravom PFX-u. Lokalni testovi koriste izmišljene podatke i ne dokazuju odobrenje ESIR-a.

Tehnička referenca za `verificationQRCode` i `journal`: https://tap.sandbox.suf.purs.gov.rs/Help/Home/ExportToPdf?file=Create-Invoice&hash=1522287161
