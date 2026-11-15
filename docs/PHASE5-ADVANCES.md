# Faza 5 — ručni sandbox avansi i zatvaranje (0.6.0)

Ovo je zaseban test ekran sa izmišljenim porudžbinama. Nije automatizacija godišnjeg hostinga/domena niti potvrda računovodstvenog trenutka prometa. Nema WHMCS finansijskih promena, bankarskog naloga, aktivacije usluge ili slanja mejla. Produkcija ostaje blokirana.

## Obim prve avansne verzije

Jedna avansna uplata po test porudžbini, domaće fizičko lice sa izmišljenim test ID-em, RSD, jedna aktivna podešena sandbox VAT stopa (19/20), kartica ili prenos na račun. Dodatne avansne rate, više stopa, više avansnih uplata u lancu, više isporuka i produkciono povezivanje sa WHMCS narudžbinom nisu uključeni.

Podržano je:

- Avans–Prodaja: bruto primljena uplata kao stavka `10: Аванс (oznaka)` i količina 1. Datum test uplate se čuva u zahtevu; test ekran prihvata prethodnih 31 dan i ne prihvata budući datum.
- Avans–Refundacija kupcu: proizvoljan pozitivan bruto deo ili ceo preostali avans, sa istim test identitetom i referencom na AP. Količina ostaje 1, cena je refundirani bruto iznos. Odvojena lokalna test evidencija isplate nije bankarski nalog.
- Interni Avans–Refundacija za zatvaranje: preostali avans poništava se radi konačnog obračuna. **Ovaj dokument nije vraćanje novca i ne dostavlja se kupcu.**
- Konačni Promet–Prodaja: referenca na potvrđeni interni AR; stvarna test usluga sa celom konačnom bruto vrednošću; u `payment` je samo eventualna doplata. Kada avans pokriva ceo iznos, nova uplata je 0.
- Kopije avansnih dokumenata i konačnog računa, zaštićen izvoz i žurnal. Posle konačnog PP običan povraćaj prometa radi se preko postojećeg toka refundacija, ne novom refundacijom već zatvorenog avansa.

## Primeri

| Test | AP | Povraćaj kupcu | Interni AR | Konačni PP | Nova uplata na konačnom |
|---|---:|---:|---:|---:|---:|
| Potpuno pokriven promet | 1.190 | 0 | 1.190 | 1.190 | 0 |
| Delimična avansna uplata | 1.190 | 0 | 1.190 | 2.380 | 1.190 |
| Delimičan povraćaj, zatim promet | 1.190 | 595 | 595 | 1.190 | 595 |
| Otkazana usluga | 1.190 | 1.190 | nema | nema | nema |

Svaki red je posebna test porudžbina. Iznosi su RSD, uključuju sandbox porez. AP + konačni PP se ne sabiraju kao dva nova prihoda: interni AR zatvara iskorišćeni avans. Ova tabela opisuje testne dokumente; knjiženje i primenu na stvarne ugovore potvrđuje knjigovođa.

## Nadogradnja

1. Sačuvaj rezervnu kopiju modula i baze.
2. Prekopiraj ceo `modules/addons/onetfiskal` preko postojećeg direktorijuma, uključujući nove `lib/Advance.php` i `lib/AdvanceAdmin.php` i izmenjene biblioteke.
3. Ne deaktiviraj modul i ne briši evidenciju. Privatni config, PFX i CA ostaju isti.
4. Otvori addon: verzija 0.6.0. Idempotentna nadogradnja dodaje `mod_onetfiskal_advances` i `mod_onetfiskal_advance_groups`. Stari računi i zaključavanje ostaju sačuvani.

## Test A — izdavanje avansa

1. Klikni „Avansi”. Izaberi test hosting/domen i bruto iznos 1190.00.
2. Izaberi stvarno zamišljen test datum/vreme prijema uplate (Europe/Belgrade), izmišljeni `20:000000000` i prenos na račun ili karticu.
3. Sačuvaj test porudžbinu i pregled. To ne poziva V-PFR.
4. Proveri `invoiceType=4`, `transactionType=0`, datum uplate, bruto cenu, količinu 1 i poresku oznaku iz statusa.
5. Potvrdi i izdaj samo taj test dokument. Očekuj `issued`, AP oznaku, PFR broj, QR i proveru „Avans Prodaja” na sandbox portalu.
6. Link „Test porudžbina ... i nastavak postupka” vodi na povezane dokumente i preostali saldo.

## Test B — potpuno zatvaranje bez doplate

1. U potvrđenoj test porudžbini izaberi pregled internog zatvaranja; konačna vrednost 1190.00.
2. Proveri da interni AR referiše na AP i koristi preostali bruto iznos.
3. Potvrdi izdavanje internog AR. **Ne beleži bankarski povraćaj i ne dostavljaj ovaj AR kupcu.**
4. Vrati se na istu test porudžbinu. Treba da piše da je zatvaranje potvrđeno, ali nedostaje konačni PP.
5. Pripremi konačni PP. Referenca mora biti broj internog AR, konačna stavka 1190.00, `payment.amount=0`.
6. Potvrdi i izdaj konačni PP. Proveri QR, reference i celu vrednost prometa na sandbox portalu.
7. HTML/štampa čuvaju neizmenjen PFR journal i zasebno prikazuju avansne napomene: plaćeno avansno, PDV zatvorenog avansa iz odgovora AR, doplata i broj/vreme AP. Ove dopunske napomene se ne šalju kao nepoznata API polja i ne prepravljaju sačuvan odgovor.

Ako želiš test sa doplatom, u koraku 1 za novu test porudžbinu unesi konačnu vrednost 2380.00. Konačni PP će imati celu vrednost 2380.00 i samo novu uplatu 1190.00. Potrebna je dodatna potvrda izmišljene test doplate.

## Test C — povraćaj avansa kupcu

Koristi drugu otvorenu test porudžbinu, ne onu koja je interno zatvorena.

1. Izaberi „Povraćaj novca kupcu”, bruto deo npr. 595.00, razlog i bezgotovinski način povraćaja.
2. Pregled i potvrda izdaju AR koji referiše na AP. Proveri iznos i ID kupca na portalu.
3. Po potrebi sačuvaj zasebnu izmišljenu referencu isplate. To ne šalje bankarski nalog i ne potvrđuje stvarni transfer.
4. Preostali avans je 595.00. Može se kasnije vratiti ili upotrebiti za konačni promet. Ostatak nije obavezno refundirati radi završetka ovog testa.
5. Posle punog povraćaja avansa nema salda za interno zatvaranje/konačni račun ovog lanca.

## Prekid i oporavak

- Jedan zajednički guard pokriva prodaje, refundacije, kopije i avansne dokumente. `sending`/`unknown` se ne resetuju ručno i ne šalje se novi POST.
- „Proveri ishod avansnog dokumenta (GET)” koristi isti RequestId i originalni sertifikat. Sačuvan validan odgovor može potvrditi ishod bez mrežnog ponavljanja.
- Posle potvrđenog internog AR postupak se nastavlja iz iste test porudžbine, čak i posle zatvaranja browsera/restarta PHP-a. Ne izdaje se drugi interni AR ako konačni PP još nije završen.
- Ako je PP izričito odbijen, novi pregled koristi isti potvrđeni AR posle razjašnjenja greške. Ako je PP nepoznat, novi pregled se blokira do potvrde ishoda.
- Nacrt važi jedan sat, autor i payload su vezani za pregled; saldo se ponovo proverava pod zaključavanjem baze pre slanja. Izmenjeni/zastareli pregled ne troši novac.
- Konačna vrednost i način doplate zamrzavaju se potvrdom internog zatvaranja. Proveri ih pre slanja tog AR; ova verzija nema poseban tok naknadne ispravke greškom unetog plana.
- Za jednu test porudžbinu nema nove avansne rate. Za novu zamišljenu uplatu koristi novu porudžbinu; to ne sme biti zamena za nepoznat ishod stare uplate.

## Prikaz i granice

Interno zatvaranje ima istaknuto upozorenje i nema dugme za evidenciju isplate. Žurnal razlikuje AP, AR kupcu, interni AR i konačni PP u istom izvoru „Avansni postupak”. Sam žurnal nije poreski/prometni zbir.

Dopunski avansni podaci i reklamna referenca na AP prikazuju se u HTML-u i štampi, uključujući kopiju konačnog računa. TXT ostaje originalni PFR journal bez lokalnih napomena i sam nije kompletna elektronska dostava konačnog računa. Nijedan dokument se ne šalje kupcu automatski.

Prvi stvarni sandbox odgovori AP/AR/PP, QR, prikaz doplate 0 i reference moraju se proveriti na korisnikovoj instalaciji. Lokalno nema njegovog sertifikata niti licenciranog WHMCS-a. Ovo nije tvrdnja da je završena cela ESIR kontrolna lista ili dobijeno odobrenje.

## Provere

Testovi proveravaju datum stare bankovne uplate, bruto avansnu stavku, deduplikaciju, delimične/pune povraćaje, završetak sa/bez doplate, obavezne reference, razdvajanje internog zatvaranja od isplate, nastavak posle prekida, odbijen/nepoznat konačni račun, promenjen PFX/poresku konfiguraciju, ovlašćenja, kopije i originalne finansijske zapise. MySQL CI dodatno pokušava istovremeni pun povraćaj i zatvaranje istog avansa: samo jedan sme potrošiti saldo.

## Zvanični izvori

- https://purs.gov.rs/upload/media/2025/2/4/414144/Tehnickouputstvo-ESIRiliL-PFR.pdf — 9.1.1 i primeri na strani 41: avansni artikli/količina, AP → interni AR → PP, doplata i lokalna reklamna napomena.
- https://www.purs.gov.rs/eFiskalizacija/odgovori_najcesca_pitanja.html — datum prijema ranije bankarske uplate i reference.
- https://tap.sandbox.suf.purs.gov.rs/help/Home/ExportToPdf?all=true&file=Create-Invoice&hash=1522287161 — tipovi dokumenata, iznosi, reference i struktura odgovora.
