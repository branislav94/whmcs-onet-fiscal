# Faza 3 — ručne sandbox refundacije (0.4.0)

## Obim

- Promet–Refundacija domaćem fizičkom licu, vezana za potvrđenu Promet–Prodaju sačuvanu u modulu (WHMCS ili zaseban test).
- Cela originalna usluga ili deo količine, uz originalni naziv, cenu i poresku oznaku. Povraćaj na karticu (2) ili prenos na račun (4).
- Identifikacija u ovom razvojnom toku: `20:` + devet cifara izmišljenog broja lične karte, npr. `20:000000000`. Ne koristiti stvarne lične podatke u sandbox testovima.
- Nema avansne refundacije, ispravke greškom izdatog računa, gotovinskog povraćaja, povraćaja pravnom/stranom licu, proizvoljne promene cene niti slanja mejla. To su posebni tokovi, a ne varijante ovog dugmeta.
- Modul ne šalje bankarski nalog, ne poziva gateway refund API, ne menja WHMCS uplate/fakture i ne gasi hosting/domen.

## Nadogradnja

1. Sačuvaj rezervnu kopiju modula i baze.
2. Prekopiraj ceo `modules/addons/onetfiskal` preko postojeće instalacije. Novi `lib/Refund.php` i `lib/RefundAdmin.php` su obavezni.
3. Ne deaktiviraj modul, ne briši tabele, ne menjaj privatni config/PFX.
4. Otvori modul. Idempotentna nadogradnja dodaje tabelu `mod_onetfiskal_refunds`; postojeći računi i zaključavanje ostaju sačuvani.
5. Proveri verziju 0.4.0. Koristi isti namenski sandbox PFX i poresku oznaku kao kod originalnog računa.

## Prvi ručni test

1. Otvori već izdat **zaseban test** iz žurnala. Za početak koristi izmišljeni račun, ne račun sa stvarnim podacima klijenta.
2. Klikni „Pripremi sandbox refundaciju”.
3. Za pun povraćaj ostavi preostale količine. Za polovinu jedne stavke unesi `0.500`; stavke koje ne vraćaš postavi na `0`.
4. Unesi izmišljeni ID `20:000000000`, način povraćaja i razlog, pa sačuvaj nacrt.
5. Pregledaj iznos i JSON: `invoiceType=0`, `transactionType=1`, `buyerId`, originalni PFR broj i originalno PFR vreme u referencama. Izbor se čuva pre izdavanja.
6. Potvrdi test i klikni „Izdaj test Promet–Refundacija”. Ovo zaista šalje novi **sandbox** dokument Poreskoj.
7. Očekuj `issued`, novi PFR broj, originalni tekst refundacije, QR i link. Na sandbox portalu proveri vrstu dokumenta, ID, referencu i iznos.
8. Otvori original: treba da vidiš povezanu refundaciju. U žurnalu izaberi izvor „Refundacije”.
9. Za drugi test možeš vratiti preostali deo nakon prve delimične refundacije. Zbir potvrđenih/neizvesnih povraćaja ne sme preći originalnu količinu niti bruto iznos.
10. Odvojeno dugme „Sačuvaj test evidenciju povraćaja” čuva samo izmišljenu referencu. Ne dokazuje stvarnu isplatu i ne poziva banku. Izdavanje fiskalne refundacije samo po sebi ne potvrđuje da je novac vraćen.

Količine su preciznosti do tri decimale. Cena je originalna, pa iznos nije proizvoljno polje: primer original 1.190,00 RSD × 0.500 = 595,00 RSD. Originalne stavke ove verzije imaju količinu 1. Zaokruživanje je na dve decimale; posebno se proverava ograničenje u parama, da više malih povraćaja ne prekorači original. Poreski tretman godišnje usluge i eventualne korekcije cene mora se posebno potvrditi sa knjigovođom pre produkcije.

## Prekid veze, ponovni klik i zastareli nacrti

- Pre POST-a trajno se čuvaju zahtev, RequestId, original, izbor i identitet administratora. Jedna potvrđena refundacija se ne šalje ponovo istim nacrtom.
- Ista tabela zaključavanja pokriva prodaju, zasebne testove i refundacije. Nepoznat ishod zaustavlja sve nove fiskalne zahteve.
- Kod `unknown` ili `sending` koristi „Proveri ishod refundacije (GET)”. Ne briši zapis/lock i ne pravi zamensku refundaciju. Isti PFX mora ostati dostupan.
- `sending`, `unknown` i `issued` rezervišu povraćene količine/iznose. `review` i izričito `rejected` ne troše saldo, ali svaki pokušaj slanja ponovo proverava saldo pod zaključavanjem baze.
- Nacrti imaju rok jedan sat i vezani su za administratora koji ih je pripremio. Stari ili preveliki pregled se blokira; pripremi novi pregled samo ako prethodni zahtev nije slat/neizvestan.
- Izričito odbijen zahtev ne dobija novi POST na istom nacrtu. Prvo razjasni odbijanje, zatim pripremi novi nacrt ako nema izdatog/neizvesnog dokumenta.
- Evidencija isplate se beleži jednom, sa referencom, administratorom i UTC vremenom; ne menja fiskalni JSON i ne može se prepisati drugim klikom.

## Provera i ograničenja

Automatski testovi pokrivaju pun/delimičan povraćaj, više stavki, zbirna ograničenja, centovno zaokruživanje, nevažeće ID/načine plaćanja, deduplikaciju nacrta, zastarele preglede, promenjen sertifikat/JID, oporavak bez novog POST-a, lažan odgovor prodaje umesto refundacije, povezani žurnal/izvoz i nezavisnu evidenciju isplate. MySQL CI dodatno pokreće dva paralelna procesa koji pokušavaju pun povraćaj iste prodaje: samo jedan sme uspeti.

Lokalno nema licenciranog WHMCS-a niti pravog sertifikata. Izgled u temi i prvi stvarni sandbox odgovor refundacije mora proveriti korisnik posle nadogradnje. Testovi nisu odobrenje ESIR-a. Produkcioni endpoint ostaje blokiran.

## Zvanične reference

- https://purs.gov.rs/upload/media/2025/2/4/414144/Tehnickouputstvo-ESIRiliL-PFR.pdf — identifikacija i reference kod refundacije, šifarnik i razlika povraćaj/ispravka.
- https://tap.suf.purs.gov.rs/Help/Home/ExportToPdf?all=true&file=Примери-рачуна-у-специјалним-случајевима&hash=454320695 — kopija refundacije sa potpisom kod gotovinskog povraćaja na licu mesta; ne izdaje se za povraćaj na karticu ili drugi bezgotovinski način. Ova verzija zato blokira gotovinski povraćaj.
- https://tap.sandbox.suf.purs.gov.rs/help/Home/ExportToPdf?all=true&file=Create-Invoice&hash=1522287161 — struktura zahteva i odgovora API-ja.
