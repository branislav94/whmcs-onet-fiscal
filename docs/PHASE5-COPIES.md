# Faza 5, prvi deo — kopije računa (0.5.0 sandbox)

## Razlika između preuzimanja originala i izdavanja kopije

„Otvori za štampu / PDF”, HTML i TXT originala i dalje koriste samo sačuvani odgovor, bez novog zahteva Poreskoj.

„Pripremi sandbox kopiju” je nova, zasebna funkcija: čuva nacrt, prikazuje zahtev i traži izričitu potvrdu za novi dokument vrste **Kopija** preko V-PFR-a. Kopija ima sopstveni PFR broj i referencu na original; ne evidentira novu prodaju ili novi povraćaj novca. Prikazuje uvećano **ОВО НИЈЕ ФИСКАЛНИ РАЧУН** uz neizmenjeni originalni tekst koji je vraćen za kopiju.

Ova verzija podržava:

- Kopija–Prodaja potvrđene Promet–Prodaje, iz WHMCS ili zasebnog sandbox testa.
- Kopija–Refundacija potvrđene Promet–Refundacije. Referenca je PFR broj same refundacije, ne prvobitne prodaje.
- Originalne stavke, količine, cene, poreske oznake, način plaćanja i identifikacija kupca kada postoji. Ne uređuju se u kopiji.
- Posebna evidencija, prikaz u žurnalu, povezane kopije na originalu, QR i zaštićeno preuzimanje/štampa kopije.

Nema kopije kopije, kopije avansa, automatskog slanja, gotovinskog procesa isplate ili promene WHMCS transakcija. Poreska i poslovna pravila iz faze 4 ostaju otvorena do potvrde knjigovođe. Ovaj korak nije cela faza 5 niti odobrenje ESIR-a.

## Nadogradnja

1. Sačuvaj rezervnu kopiju modula i baze.
2. Prekopiraj ceo `modules/addons/onetfiskal` iz paketa preko postojeće instalacije. Novi `lib/Copy.php` i `lib/CopyAdmin.php` su obavezni; menjaju se i povezane biblioteke.
3. Ne deaktiviraj modul, ne briši evidenciju i ne menjaj privatnu konfiguraciju/PFX.
4. Otvori addon. Verzija je 0.5.0. Idempotentna nadogradnja dodaje `mod_onetfiskal_copies`; postojeće tabele i zaključavanje ostaju sačuvani.

## Test Kopija–Prodaja

1. Otvori već izdatu sandbox prodaju iz žurnala ili zasebnih testova. Ne izdavati novu prodaju samo radi ovog testa.
2. Klikni „Pripremi sandbox kopiju”. Ovo čuva nacrt, bez poziva V-PFR-u.
3. Proveri zahtev: `invoiceType: 2`, `transactionType: 0`, originalni PFR broj i vreme u referencama; iste stavke, ukupno i način plaćanja.
4. Potvrdi i klikni „Izdaj test kopiju”. Ovo šalje poseban sandbox dokument Poreskoj.
5. Očekuj `issued`, novi PFR broj, oznaku vrste Kopija–Prodaja i istu referencu. QR/link mora proveriti taj novi dokument na sandbox portalu.
6. Preuzmi HTML i otvori štampu. Upozorenje da ovo nije fiskalni račun mora biti istaknuto i dvostruko većim fontom od ostalih podataka. Original ostaje dostupan kao ranije.

## Test Kopija–Refundacija

1. Otvori već potvrđenu punu ili delimičnu refundaciju. Ne vraćaj novac ponovo.
2. Pripremi kopiju i proveri `invoiceType: 2`, `transactionType: 1` i referencu na PFR broj te refundacije.
3. ID kupca, refundirane količine, cene i iznos moraju ostati isti kao u refundaciji.
4. Potvrdi izdavanje. Portal treba da prikaže Kopija–Refundacija za novi PFR broj.
5. Originalna refundacija, preostali iznos prodaje i odvojena test evidencija povraćaja novca ostaju nepromenjeni.

Više zasebno potvrđenih kopija istog originala je dozvoljeno, ali svaka ima svoj nacrt i RequestId. Ponovni klik na isti već izdat nacrt ne šalje novi POST.

## Nepoznat ishod i pristup

- Kopije koriste zajedničko trajno zaključavanje sa prodajama i refundacijama. Nepoznat ishod bilo kog izvora blokira sva nova izdavanja.
- Za kopiju u stanju `sending`/`unknown` koristi „Proveri ishod kopije (GET)”. Isti RequestId i originalni PFX ostaju obavezni; nema automatskog ponovnog POST-a.
- Nacrt važi jedan sat i vezan je za autora. Promena stavki, iznosa, reference, konfiguracije ili sertifikata se odbija pri potvrdi.
- Izričito odbijen nacrt se ne šalje ponovo. Posle razjašnjenja može se namerno napraviti nov nacrt; ne koristiti to za zaobilaženje nepoznatog ishoda.
- Žurnal razlikuje izvor „Kopije”; nije prometni zbir. Kopije ne ulaze u zbir refundiranih količina i iznosa.
- Prikaz i preuzimanje zahtevaju aktivnog WHMCS administratora sa pristupom modulu; nema javnih klijentskih linkova.

## Provere

Automatski testovi proveravaju obe vrste kopije, cenu/količinu/refundaciju, reference, ID, nacrte, status, PFX/JID i ograničenje sandboxa; zajednički lock i oporavak, odbijanje odgovora druge vrste, žurnal, zaštićen izvoz i istaknuto upozorenje. Postojeći testovi refundacija i konkurentnih zahteva ostaju uključeni.

U lokalnom okruženju nema licenciranog WHMCS-a ni korisnikovog PFX-a. Prvi stvarni sandbox odgovor kopije, QR i izgled u aktivnoj temi/štampi zahtevaju proveru na korisnikovoj instalaciji. Produkcija i slanje mejla ostaju isključeni.

## Potvrđen prethodni korak

Korisnik je potvrdio punu refundaciju originala -3 računom -4 (1.190 RSD), delimičnu refundaciju originala -5 računom -6 (0.500 × 4.760 = 2.380 RSD) i prijavio da radi i povraćaj ostatka. Ovo je potvrda sandbox funkcionalnosti, ne produkciono poresko odobrenje.

## Zvanične reference

- https://tap.sandbox.suf.purs.gov.rs/help/Home/ExportToPdf?all=true&file=Create-Invoice&hash=1522287161 — `invoiceType=Copy/2`, tip transakcije i reference.
- https://www.purs.gov.rs/upload/media/2025/2/4/414144/Tehnickouputstvo-ESIRiliL-PFR.pdf — upitnik za ESIR, obavezne reference i uvećana poruka za Kopiju, Predračun i Obuku.
