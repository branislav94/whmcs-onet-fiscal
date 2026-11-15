# Faza 6 — serverski PDF (0.10.0)

Ova verzija dodaje pravi `application/pdf` za već izdat dokument i PDF prilog u administratorskom pregledu dostave. Slanje je i dalje isključeno. Generisanje PDF-a ne poziva V-PFR i ne menja račun, uplatu ili postojeće zaključavanje.

## Nadogradnja

1. Prekopiraj **ceo** `modules/addons/onetfiskal/` iz ZIP-a, uključujući `lib/Pdf.php`, `lib/PdfEngine.php` i **ceo `assets/fonts/`** sa licencom.
2. Ne deaktiviraj addon i ne menjaj privatnu konfiguraciju ili evidenciju.
3. PHP koji pokreće WHMCS mora imati GD za originalni GIF QR. Modul koristi postojeću TCPDF klasu WHMCS-a; ne menja WHMCS vendor direktorijum i ne zahteva da na serveru pokrećeš Composer.
4. U dodatku otvori izdat dokument i klikni **Preuzmi PDF**. Datoteka treba da se preuzme direktno, bez browser štampe.
5. Otvori **Pregled dostave**: među prilozima treba da budu HTML, originalni TXT i PDF. Ako PDF mehanizam, font ili GD nisu dostupni, poruka objašnjava šta nedostaje, a HTML/TXT ostaju dostupni.

## Šta PDF sadrži

- Sačuvani žurnal u monospace fontu DejaVu Sans Mono, uključujući ćirilicu i srpsku latinicu. Izvorni TXT nije prepisan ili promenjen.
- Originalnu GIF sliku QR-a iz odgovora V-PFR-a. Ne generiše se novi QR. Unutrašnja konverzija slike u PDF je bez gubitka piksela.
- SANDBOX oznaku i broj stranice na svakoj strani, reference iz sačuvanog dokumenta i dozvoljen link za proveru.
- Posebno uvećanu oznaku za kopiju i sačuvane napomene avansnog postupka.
- Prelazak na novu stranicu za dugačak žurnal; QR i njegova radnja provere ostaju zajedno.

PDF ne računa ponovo stavke ili porez. Reprezentacija nije novo izdavanje niti digitalno potpisivanje novog fiskalnog dokumenta. Dokument sa nedostajućim QR-om ili žurnalom koji ne može ostati čitljiv ne dobija nepotpun PDF.

Isti sačuvani odgovor i verzija prikaza daju iste PDF bajtove u istom PDF mehanizmu, pa prilog može da se uporedi sa zaštićenim preuzimanjem. Promena verzije PDF mehanizma/prikaza može promeniti te bajtove; sačuvani fiskalni odgovor ostaje izvor istine.

## Administratorski pristup

`receipt.php?source=...&id=...&format=pdf` koristi isti aktivni admin nalog i addon pristup kao HTML/TXT. Nema javnog linka za klijenta. PDF se vraća kao prilog i ne čuva u javnom direktorijumu.

Interni AR može se preuzeti radi administratorske evidencije, ali je njegov pregled dostave kupcu i dalje blokiran, uključujući kopiju.

## Mejl pregled

HTML verzija ima dugme **Proveri račun** koje vodi na postojeći dozvoljeni sandbox URL. Tekstualna verzija zadržava pun URL. Iz HTML tela uklonjen je dugačak vidljivi URL, ne menja se njegova vrednost u vezi.

## Prihvatni testovi u WHMCS-u

- Preuzmi već izdatu prodaju, punu/delimičnu refundaciju, kopiju i konačni račun iz avansa.
- Proveri da se broj dokumenta, iznosi, poreske oznake i stavke poklapaju sa originalnim žurnalom.
- Proveri ćirilicu, decimalne kolone, QR i sandbox proveru. Za test fixture dokumente URL je izmišljen i nije potvrda Poreske.
- Kopija mora imati uvećanu poruku „ОВО НИЈЕ ФИСКАЛНИ РАЧУН”. Konačni avansni račun mora imati sačuvane napomene o avansu/doplati.
- Proveri da se PDF otvara sa administratorskim pravima, a da odjavljen nalog nema pristup.
- U pregledu dostave proveri PDF prilog i dugme provere. Nijedan mejl se ne šalje.

## Zavisnosti i izvori

Runtime PDF mehanizam dolazi iz WHMCS-a. U testovima se koristi `tecnickcom/tcpdf` 6.11.4, zaključan u `composer.lock`. Sam engine nije kopiran u addon. U addon su uključeni samo DejaVu Sans Mono podaci fonta za TCPDF i odgovarajuća licenca.

Reference: [WHMCS PDF invoice customization](https://docs.whmcs.com/9-1/billing-and-invoicing/custom-pdf-invoices/), [TCPDF](https://github.com/tecnickcom/TCPDF). Ova nadogradnja nije potvrda ESIR odobrenja ili završetak cele faze dostave.
