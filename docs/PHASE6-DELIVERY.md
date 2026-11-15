# Faza 6, prvi korak — pregled dostave (0.9.0)

Ova verzija dodaje **administratorski pregled**, ne slanje. Nema mail transporta, reda za slanje, javnog linka kupcu ili automatske fiskalizacije.

## Nadogradnja

Prekopiraj ceo `modules/addons/onetfiskal/` iz ZIP-a, uključujući novi `lib/Delivery.php`. Ne deaktiviraj addon i ne menjaj privatnu konfiguraciju. Nema nove šeme baze. Postojeća evidencija i fiskalna zaključavanja ostaju isti.

## Prvi pregled

1. Otvori ranije izdat račun u modulu.
2. Ispod dokumenta i preuzimanja klikni **Pregled dostave**. Isto možeš otvoriti iz sekcije **Dostava · pregled**, preko žurnala.
3. Proveri email, WHMCS broj i naslov. Za WHMCS dokument email se čita iz trenutno povezane fakture i klijenta; proizvoljni email iz URL-a ne menja primaoca.
4. Proveri prikaz poruke i tekstualnu verziju. Sve poruke imaju SANDBOX oznaku. U poruci nema administratorskog linka za preuzimanje.
5. Proveri postojeće priloge: HTML sa sačuvanim QR-om i originalni TXT. SHA-256 i veličina odnose se na tačne bajtove tih prikaza.
6. Otvori original preko postojećih zaštićenih linkova. Ponovno otvaranje ne izdaje račun.

**PDF:** za sada se čuva kroz prikaz za štampu u browseru. Ova verzija ne tvrdi da postoji serverski generisan PDF ili PDF prilog mejlu.

## Primaoci i ograničenja

- Za prodaju iz WHMCS-a email se čita iz `tblinvoices.userid → tblclients.id`.
- Refundacija i kopija prate reference do originalne WHMCS fakture. Nedostajući klijent, email ili originalna veza prikazuju problem; drugi klijent se ne bira automatski.
- Zasebni testovi i test avansi nemaju vezu sa stvarnim WHMCS kupcem. Prikazuje se `test@example.invalid`, bez slanja.
- Nacrti, zahtevi sa nepoznatim ishodom i odbijeni zahtevi nemaju pregled dostave.
- Interni AR za zatvaranje avansa nije za kupca. Njegova kopija je takođe blokirana u dostavi.
- Nedostajući/neispravan QR i nevažeći link provere daju upozorenje. Ne preuzima se novi QR sa mreže i ne ponavlja se fiskalni zahtev.
- Pristup zahteva aktivnog WHMCS administratora sa addon pravom. Ovo nije klijentski portal niti javna ruta za dokument.

## Šta se još ne dešava

Pregled ne šalje poruku, ne beleži dostavu kao uspešnu i ne upisuje zadatak za buduće slanje. Uspešno generisan pregled nije dokaz da je kupac dobio dokument. Originalni odgovor, stanje računa, bankarska evidencija i zaključavanje fiskalnog zahteva ne menjaju se.

## Sledeći delovi faze 6

1. Serversko generisanje i testiranje PDF-a sa ćirilicom, originalnim QR-om i potrebnim napomenama.
2. Posebna trajna evidencija dostave, nezavisna od fiskalizacije, i ponavljanje slanja bez ponovnog izdavanja.
3. Ručno test slanje na unapred odabranu adresu, uz podešenog pošiljaoca.
4. Klijentski pristup vezan za vlasnika WHMCS fakture i dogovorena pravila dostave.
5. Automatizacija tek posle potvrde poslovnih pravila i odvojenih uslova za produkciju.

U ovoj fazi nije potrebno unositi SMTP lozinku ili slati certifikat/PAK u repo.
