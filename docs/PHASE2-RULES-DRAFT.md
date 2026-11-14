# Faza 2 — radna pravila obračuna i pitanja za potvrdu

Status: NACRT, nije odobreno za produkcioni obračun. Datum: 14.11.2026.

Ovaj dokument beleži potvrđene poslovne informacije, preporuke i otvorene odluke. Ne menja izvršavanje modula. Produkcija i automatsko izdavanje ostaju isključeni dok se ne završe potrebni tokovi, potvrde pravila i dobije odobrenje ESIR-a.

## Potvrđene informacije

- Hosting i domen usluge trenutno se naplaćuju godišnje.
- Predračun za obnovu šalje se oko mesec dana pre isteka.
- Kupci uključuju fizička lica, pravna lica/preduzetnike i inostrane kupce. Za firme se prikuplja PIB; pouzdanost klasifikacije i posebni SEF/PDV statusi tek se proveravaju.
- Kartična naplata preko Intese je planirana, nije potvrđeno da je aktivna. U sandboxu su provereni test načini CorvusPay i ONET IPS QR.
- Redovna prodaja za sada ne treba delimične uplate i kredit na nalogu. Povraćaje treba podržati.
- Trenutak prometa i obračun unapred plaćene godišnje usluge nisu potvrđeni sa knjigovođom.
- Uspešni sandbox testovi za prenos na račun i karticu, CI provere i spajanje PR-a #1 završili su osnovni integracioni korak faze 1. To nije regulatorno odobrenje.

## Radna tabela događaja

| Događaj | Predlog poslovnog ponašanja | Fiskalni/računovodstveni dokument | Status |
|---|---|---|---|
| Nova godišnja hosting narudžbina | Predračun; aktivacija posle potvrđene pune uplate. Datum početka/kraja čuvati posebno. | Samo nastanak predračuna ne tretirati kao uplatu/promet; konačan tok potvrđuje knjigovođa. | Aktivacija posle uplate je predlog, ne potvrđena postojeća praksa. |
| Uplata za hosting | Evidentirati stvarni prijem novca i uspeh/neuspeh aktivacije kao različite događaje. | Avansni ili konačni račun i eventualni periodični obračun prema potvrđenom trenutku prometa. | OTVORENO — ne zaključivati da godišnja usluga završava aktivacijom. |
| Najava obnove | Predračun oko mesec dana pre isteka; ne produžavati besplatno samo zato što je predračun nastao. | Predračun. | Potvrđen interval, detalji slanja podesivi. |
| Ranija uplata obnove hostinga | Predlog: novi godišnji period nastavlja postojeći, bez gubitka preostalih dana. Sačuvati datum uplate i početak novog perioda. | Moguć avans; konačni dokument i datum prema pravilu knjigovođe. | OTVORENO. |
| Registracija/obnova domena | Predlog: poslati registraru posle potvrđene pune uplate; zabeležiti stvarnu potvrdu registrara i period važenja. | Odvojiti prijem novca od uspešno izvršene registracije/obnove; dokument određuje potvrđeno poresko pravilo. | OTVORENO za trenutak prometa. |
| Registrar odbio registraciju | Otvoriti incident; obavestiti kupca. Predlog: ponuditi povraćaj ili zamenu uz saglasnost, bez automatskog pretvaranja u kredit. | Povraćaj i odgovarajuća refundacija prethodnog avansa/prometa, ako postoji takav dokument. | Poslovni predlog; dokument i identifikaciju potvrditi. |
| Registrar nije odgovorio | Proveriti status postojećeg zahteva pre ponavljanja, naročito za obnovu. | Ne izmišljati uspešan promet i ne duplirati fiskalni dokument. | Tehnički predlog. |
| QR/bankovna uplata | QR generisanje nije potvrda uplate. Evidentirati tek nakon bankarskog dokaza ili pouzdane integracije. | Šifra 4 za nalog za uplatu/prenos; posebna integrisana merchant IPS naplata ima šifru 6. | Tačan način potvrde bankovnih uplata još nije potvrđen. |
| Kartična uplata | Pouzdano potvrđen uspešan događaj naplate, ne samo povratak browsera. Intesa dobija zasebno mapiranje tek kada je integracija poznata. | Kartica, šifra 2; fiskalni tip dokumenta zavisi od posla/avansa. | Intesa je plan, nisu unesene pretpostavljene API funkcije. |
| Delimična/prevelika/pogrešna uplata | Nije podržan redovan tok; poslati operateru na obradu. Ne označiti kao punu uplatu i ne izgubiti stvarno primljen iznos. | Eventualna avansna obaveza ili povraćaj mora se razrešiti blagovremeno prema pravilu knjigovođe. | Izuzetak mora biti obrađen i kada rate nisu ponuđene. |
| Zahtev za refundaciju | Ručno odobravanje sa razlogom i iznosom; podržati i delimičan povraćaj, npr. domen nije uspeo a hosting jeste. | Povraćaj novca i fiskalna refundacija su dva povezana procesa. Referenca na original, iznos i identifikacija prema potvrđenim pravilima. | Potrebna funkcija, ne znači bezuslovno pravo na refund za svaku situaciju. |
| Domaće fizičko lice | Kandidat za domaći B2C fiskalni tok. | Fiskalni avans/promet/refund prema događaju. | Trenutak i obračunski period otvoreni. |
| Firma/preduzetnik | Sačuvati tip kupca, PIB, zemlju i relevantne SEF/PDV statuse. | Faktura/SEF/fiskalni tok po potvrđenom scenariju; PIB sam po sebi ne odlučuje sve. | OTVORENO. |
| Inostrani kupac | Razlikovati fizičko/pravno lice i zemlju; zadržati posebnu poresku klasifikaciju. | PDV/mesto prometa i fiskalizacija moraju se potvrditi za svaki tip. | OTVORENO; ne primenjivati domaćih 20% svima automatski. |
| Izdavanje uspelo, mejl nije dostavljen | Ponoviti dostavu istog sačuvanog računa. | Ne izdavati novi račun zbog greške mejla. | Tehnički predlog za kasniju fazu. |

## Šta može da se razvija pre potvrde knjigovođe

- Model godišnjeg perioda i nezavisni datumi uplate, aktivacije, registracije i početka obnove.
- Klasifikacija kupaca i lista obaveznih podataka, uz nerazjašnjene poreske profile označene za pregled.
- Poslovni događaji i plan očekivanih dokumenata u sandboxu; klasifikacija nepoznata = ručni pregled, bez prećutnog izbora konačnog računa.
- Red za ručno odobravanje refundacije, vezan za originalni dokument, uz odvojene statuse bankarskog i fiskalnog povraćaja.
- Podesivi adapteri načina plaćanja; bez izmišljanja Intesa callback-a ili potvrde bankovne uplate.
- Jasne blokade za rate/kredit i operaterski tok za neočekivane delimične ili prevelike uplate.

Ovo je specifikacija za narednu implementaciju, ne tvrdnja da su nove funkcije već napravljene. Produkciono čekanje na klasifikaciju ne sme postati način odlaganja zakonski obaveznog izdavanja; pre puštanja mora postojati blagovremen ručni ili automatski postupak.

## Pitanja za knjigovođu — tražiti pisane odgovore uz primere

1. Godišnji hosting: da li je uplata pre aktivacije avans? Kada nastaje promet za fiskalizaciju i PDV: aktivacija, periodični obračun ili drugi datum? Molimo primer uplate 10.12.2026. za period 10.12.2026–09.12.2027.
2. Rana obnova: uplata 01.12.2026, postojeći period ističe 31.12.2026, novi počinje 01.01.2027. Koje dokumente i kog datuma izdajemo, uključujući prelazak poreskog perioda/godine?
3. Domeni: kada nastaje promet za registraciju i obnovu? Kako tretiramo uplatu pre odgovora registrara i neuspelu registraciju? Da li konkretan ugovorni model preprodaje ili posredovanja menja odgovor?
4. Mešovita narudžbina: hosting i domen na jednom predračunu sa različitim datumima izvršenja — da li odvajati fiskalne dokumente i kako raspodeliti uplatu i zajednički popust?
5. Za svaki tip kupca (domaće fizičko lice, domaća firma/preduzetnik u/van PDV/SEF sistema, javni sektor, strano fizičko/pravno lice): koji dokument, PDV tretman i mesto prometa? Kada je potreban SEF i kako izbeći dupliranje prometa/PDV-a?
6. Koji su rokovi za avansne račune, naročito bankarsku uplatu koja je primećena narednog dana? Šta kada uplata i promet nastanu istog dana?
7. Koji tačno tok i podaci su potrebni za potpuno/delimično vraćanje avansa i prometa, poništavanje pogrešnog računa, pogrešnog kupca ili iznosa? Da li i kada tražimo identifikaciju primaoca refundacije?
8. Kako knjižiti neočekivane delimične i prevelike uplate kada ne nudimo rate ni kredit, i kako postupiti ako je uplata na izvodu evidentirana sa zakašnjenjem?
9. Potvrditi pravilne načine plaćanja za naš statični NBS QR nalog, eventualnu buduću merchant IPS integraciju i Intesa kartice; šta je dokaz uspešne naplate?
10. Da li su trenutna WHMCS faktura/predračun, nazivi usluga, periodi i prikaz cena ispravni? Odvojeno potvrditi računovodstveno razgraničenje godišnjeg prihoda od datuma fiskalnog dokumenta.

## Pitanja za pravnika/uslove poslovanja

- Kako se godišnji hosting i registracija/obnova domena pravno klasifikuju za pravo potrošača na odustanak?
- Koji obrazac, obaveštenja, zahtev za početak izvršenja i eventualne izričite saglasnosti su potrebni? Ne pretpostavljati da registracija domena automatski ukida pravo na povraćaj.
- Koji su osnov, iznos, rok i način povraćaja kod odustanka, neizvršenja i nesaobraznosti? Razdvojiti potrošače od B2B kupaca.
- Ako je domen već registrovan i registrar ne vraća naknadu, kakva su prava kupca prema nama? Trošak registrara sam po sebi nije potvrđena zakonska zabrana povraćaja.

Zakon o zaštiti potrošača predviđa pravo na odustanak kod ugovora na daljinu sa uslovima i izuzecima, kao i posebna pravila za usluge i digitalne usluge. Pravnik treba da potvrdi primenu na konkretne ugovore; knjigovođa potvrđuje dokumente i knjiženje. Zakonski rokovi se ne zamenjuju internom politikom prodavca.

## Put ka produkciji sopstvenog ESIR-a

1. Dovršiti program i sve obavezne funkcije odgovarajućeg upitnika/klase, ne samo testirana dva načina plaćanja.
2. Kroz razvojni portal prijaviti proizvod za sopstvenu upotrebu (neprenosivo odobrenje), priložiti tražene podatke/dokumentaciju i proći tehnički i administrativni pregled.
3. Dobiti rešenje o odobrenju. Sandbox registracija i uspešna verifikacija test računa nisu zamena za ovo rešenje.
4. Za DOO preko ePorezi prijaviti odgovarajući poslovni prostor/online prodaju i zatražiti produkcioni bezbednosni element u fajlu za taj prostor. Ovo je zaseban uslov od odobravanja samog programa.
5. Podesiti produkcioni PFX/PAK, odgovarajuće endpoint-e i stvarne poreske oznake, pa kontrolisano proveriti prvi stvarni promet. Današnji modul namerno blokira produkciju.

V-PFR nije komercijalni API ključ koji se kupuje; pristup koristi produkcione sertifikate. Zvanični FAQ PU navodi da je korišćenje V-PFR-a besplatno. Troškovi razvoja, održavanja, hostinga, mail dostave, banke i eventualnih stručnih usluga su odvojeni. Aktuelan iznos eventualne takse za postupak odobrenja nije pouzdano potvrđen ovim istraživanjem; tražiti od PU instrukciju i iznos pre podnošenja, bez pretpostavke da je sve besplatno.

Zvanično tehničko uputstvo navodi rok od 15 dana za rešenje po podnošenju zahteva nakon uspešno okončanog tehničkog pregleda. To nije garancija da celokupan razvoj, tehnički pregled, dopune i produkcioni sertifikat traju 15 dana. Za tehnički pregled i eventualne dopune nije utvrđen pouzdan ukupni operativni rok. Za plan projekta ostaviti vremensku rezervu, a trenutni red čekanja i eventualne takse proveriti kroz podršku razvojnog portala PU.

## Izvori provereni 14.11.2026.

- [PU FAQ: V-PFR, poslovni prostori, bezbednosni elementi](https://www.purs.gov.rs/eFiskalizacija/odgovori_najcesca_pitanja.html)
- [PU tehničko uputstvo: postupak i rok za rešenje](https://www.purs.gov.rs/sw4i/download/files/box/_id_71684/Tehnicko%20uputstvo%20-%20ESIR%20ili%20L-PFR.pdf), odeljak 7. Aktuelne zahteve pre prijave proveriti na razvojnom portalu.
- [Razvojni portal PU](https://tap.sandbox.suf.purs.gov.rs/)
- [Narodna skupština: Zakon o zaštiti potrošača iz 2026, čl. 29–38 i 220](https://www.parlament.gov.rs/upload/archive/files/lat/pdf/zakoni/14_saziv/1317-26-lat..pdf)

Sledeći status dokumenta: potvrđen od strane vlasnika za poslovne odluke, pa dopunjen pisanim odgovorima knjigovođe i pravnika za poreska i potrošačka pitanja.
