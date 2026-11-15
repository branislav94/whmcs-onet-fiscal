<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
foreach (['Core', 'Admin', 'Receipt', 'Pdf'] as $file) { require __DIR__ . '/../modules/addons/onetfiskal/lib/' . $file . '.php'; }
function pdfCheck(bool $ok, string $name): void { if (!$ok) { throw new RuntimeException($name); } echo 'PASS ' . $name . PHP_EOL; }
function pixels(string $bytes): string {
    $im = imagecreatefromstring($bytes); if (!$im) { throw new RuntimeException('Image decoding failed'); }
    $data = imagesx($im) . 'x' . imagesy($im) . ':';
    for ($y=0; $y<imagesy($im); $y++) { for ($x=0; $x<imagesx($im); $x++) {
        $rgb=imagecolorsforindex($im,imagecolorat($im,$x,$y)); $data.=chr($rgb['red']).chr($rgb['green']).chr($rgb['blue']);
    }} imagedestroy($im); return hash('sha256',$data);
}
$r=json_decode(file_get_contents(__DIR__.'/fixtures/pdf-response.json'),true,512,JSON_THROW_ON_ERROR);
$dir=sys_get_temp_dir().'/onet-pdf-'.bin2hex(random_bytes(8));mkdir($dir,0700);
try {
    $pdf=\Onet\Fiskal\Pdf::render($r);
    pdfCheck(str_starts_with($pdf,'%PDF-'),'genuine server PDF output');
    pdfCheck($pdf===\Onet\Fiskal\Pdf::render($r),'stable PDF bytes for the same saved response');
    file_put_contents($dir.'/receipt.pdf',$pdf);
    exec('pdftotext -layout '.escapeshellarg($dir.'/receipt.pdf').' '.escapeshellarg($dir.'/text.txt'),$unused,$code);
    pdfCheck($code===0,'PDF text extraction tool'); $text=file_get_contents($dir.'/text.txt');
    pdfCheck(str_contains($text,'ФИСКАЛНИ РАЧУН') && str_contains($text,'ЧЋЂШЖ чћђшж') && str_contains($text,'ČĆĐŠŽ čćđšž'),'embedded Serbian Cyrillic and Latin glyphs');
    pdfCheck(str_contains($text,'1.190,00') && str_contains($text,'190,00') && str_contains($text,'ПФР број'),'amounts and original journal text survive export');
    exec('pdfimages -png '.escapeshellarg($dir.'/receipt.pdf').' '.escapeshellarg($dir.'/image'),$unused,$code);
    $expected=pixels(base64_decode($r['verificationQRCode']));$match=false;
    foreach (glob($dir.'/image-*.png') as $image) { if (pixels(file_get_contents($image))===$expected) { $match=true; } }
    pdfCheck($code===0 && $match,'embedded QR pixels exactly match the saved original GIF');
    $copy=$r;$copy['invoiceCounterExtension']='КП';
    file_put_contents($dir.'/copy.pdf',\Onet\Fiskal\Pdf::render($copy));
    exec('pdftotext '.escapeshellarg($dir.'/copy.pdf').' '.escapeshellarg($dir.'/copy.txt'),$unused,$code);
    pdfCheck(str_contains(file_get_contents($dir.'/copy.txt'),'ОВО НИЈЕ ФИСКАЛНИ РАЧУН'),'copy warning is rendered in Cyrillic');
    $long=$r;$long['journal']=str_repeat($r['journal'],7);
    file_put_contents($dir.'/long.pdf',\Onet\Fiskal\Pdf::render($long,['Plaćeno avansno: 1190.00','INTERNI DOKUMENT - ne dostavljati kupcu.']));
    exec('pdfinfo '.escapeshellarg($dir.'/long.pdf'),$info,$code);
    pdfCheck($code===0 && preg_match('/Pages:\s+([2-9][0-9]*)/',implode("\n",$info))===1,'long journals produce multiple readable pages');
    exec('pdftotext '.escapeshellarg($dir.'/long.pdf').' '.escapeshellarg($dir.'/long.txt'),$unused,$code);
    $longText=file_get_contents($dir.'/long.txt');
    pdfCheck(substr_count($longText,'ФИСКАЛНИ РАЧУН')===7 && str_contains($longText,'Plaćeno avansno: 1190.00'),'all long-journal content and advance notes preserved');
    foreach (['no-qr','invalid-qr','wide','no-time'] as $bad) {
        $test=$r;if($bad==='no-qr')unset($test['verificationQRCode']);elseif($bad==='invalid-qr')$test['verificationQRCode']=base64_encode('not-an-image');elseif($bad==='wide')$test['journal']=str_repeat('W',300);else unset($test['sdcDateTime']);
        $blocked=false;try{\Onet\Fiskal\Pdf::render($test);}catch(RuntimeException){$blocked=true;}
        pdfCheck($blocked,'reject incomplete or unreadably wide input: '.$bad);
    }
    if (getenv('ONET_PDF_FIXTURE_DIR')) {
        $out=getenv('ONET_PDF_FIXTURE_DIR');if(!is_dir($out))mkdir($out,0700,true);
        foreach(['receipt.pdf','copy.pdf','long.pdf'] as $f)copy($dir.'/'.$f,$out.'/'.$f);
    }
    echo "13 PDF checks passed. No live fiscal requests made.\n";
} finally { foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir); }
