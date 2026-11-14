# Faza 4 — pravila obračuna i pitanja za knjigovođu

Status: NACRT, nije odobreno za produkcioni obračun. Datum: 14.11.2026.

Ovaj dokument beleži potvrđene poslovne informacije, preporuke i otvorene odluke. Ne menja izvršavanje modula. Produkcija i automatsko izdavanje ostaju isključeni dok se ne završe potrebni tokovi, potvrde pravila i dobije odobrenje ESIR-a.

## Potvrđene informacije

- Hosting i domen usluge trenutno se naplaćuju godišnje.
- Predračun za obnovu šalje se oko mesec dana pre isteka.
- Kupci uključuju fizička lica, pravna lica/preduzetnike i inostrane kupce. Za firme se prikuplja PIB; pouzdanost klasifikacije i posebni SEF/PDV statusi tek se proveravaju.
- Kartična naplata preko Intese je planirana, nije potvrđeno da je aktivna. U sandboxu su provereni test načini CorvusPay i ONET IPS QR.
- Redovna prodaja za sada ne treba delimične uplate i kredit na nalogu. Povraćaje treba podržati.
- Trenutak prometa i obračun unapred plaćene godišnje usluge nisu potvrđeni sa knjigovođom.
- Puna refundacija 1.190 RSD potvrđena je na sandbox portalu: original završava sa -3, refundacija sa -4. Delimičan povraćaj prošao je automatske testove, ali još nije potvrđen ručno na korisnikovom sandboxu.
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


## Primeri za potvrdu pre automatizacije

| Scenario | Podaci koje već znamo | Šta knjigovođa treba da odredi |
|---|---|---|
| H1: novi hosting | Godišnja pretplata; predlog je aktivacija posle potvrđene pune uplate. | Da li je uplata avans, kada nastaje promet i kada zatvaramo avans; odvojeno knjiženje godišnjeg prihoda. |
| H2: rana obnova | Uplata 1.12; stari period ističe 31.12; novi počinje 1.1. | Dokumenti i datumi, posebno ako se prelazi poreska godina. |
| D1: domen uspešno registrovan | Uplata i potvrda registrara su odvojeni događaji. | Trenutak prometa i odgovarajući dokument pre/posle registracije. |
| D2: registracija odbijena | Novac primljen, domen nije registrovan. | Povraćaj avansa ili prometa prema prethodno izdatom dokumentu. |
| M1: hosting uspeo, domen nije | Jedna uplata, dva ishoda. | Raspodela uplate, odvojeni dokumenti i delimična refundacija. |
| C1: fizičko lice / firma / strani kupac | Potrebna pouzdana klasifikacija i zemlja; PIB za firme. | PDV, mesto prometa, fiskalni račun, SEF i eventualne evidencije za svaku grupu. |

## Tehničko pravilo do potvrde

Poreski status svakog gornjeg scenarija ostaje OTVOREN. Nije dozvoljen prećutni izbor konačnog računa samo zato što je WHMCS faktura Paid. Ovaj dokument ne uključuje automatizaciju, ne menja PDV i ne omogućava produkciju.

Šta je već implementirano: ručna sandbox prodaja; žurnal, QR i zaštićeno preuzimanje; ručna bezgotovinska refundacija i odvojena test evidencija povraćaja novca.

Šta tek sledi: potvrđeni avansni/konačni tokovi, kopije, potpuna primenljiva ESIR lista, automatizacija i korisnička dostava.

## Preostala ručna provera faze 3

Original -3 je već u potpunosti refundiran računom -4; ne pokušavati još jedan povraćaj istog originala. Za test delimične refundacije koristiti drugi postojeći nerefundirani zaseban test ili napraviti novu izmišljenu sandbox prodaju. Vratiti 0.500, proveriti dokument/QR, pa ostatak 0.500. Prvi i drugi povraćaj zajedno moraju biti jednaki originalu, a dalji povraćaj blokiran.

## Kada je faza 4 završena

Potrebni su pisani odgovori knjigovođe za gornje scenarije i potvrda vlasnika za poslovne predloge (aktivacija posle uplate, početak obnove, postupak neuspele registracije). Upisati ko je potvrdio pravilo i datum. Poslovni predlog nije poresko odobrenje.
