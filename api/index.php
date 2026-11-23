<?php
/**
 * İzin API yönlendiricisi — web.config "Izin API" kuralı /api/... isteklerini buraya gönderir.
 * Rota: [metot, yol deseni (/api/ sonrası), işleyici, seçenekler]
 */

require_once __DIR__ . '/../includes/Api/Api.php';
require_once __DIR__ . '/../includes/Api/Formlar.php';
require_once __DIR__ . '/../includes/Api/Basvuru.php';
require_once __DIR__ . '/../includes/Api/Basvurular.php';
require_once __DIR__ . '/../includes/Api/Konum.php';

$U = '(?<uniqid>[A-Za-z0-9]{1,32})';
$ID = '(?<id>[1-9][0-9]{0,9})';

(new Api())->calistir([
    ['GET',  '#^formlar$#',                       [ApiFormlar::class, 'liste']],
    ['GET',  "#^formlar/$U$#",                    [ApiFormlar::class, 'desen']],
    ['POST', "#^formlar/$U/basvuru$#",            [ApiFormlar::class, 'basvuru']],
    ['GET',  "#^formlar/$U/basvurular$#",         [ApiBasvurular::class, 'liste']],
    ['POST', '#^basvuru/dogrula$#',               [ApiBasvuru::class, 'dogrula']],
    ['POST', '#^basvuru/kod-tekrar$#',            [ApiBasvuru::class, 'kodTekrar']],
    ['GET',  "#^basvurular/$ID$#",                [ApiBasvurular::class, 'detay']],
    ['POST', "#^basvurular/$ID/iptal$#",          [ApiBasvurular::class, 'iptal']],
    ['GET',  '#^izin-durumu$#',                   [ApiBasvurular::class, 'izinDurumu']],
    ['GET',  '#^iller$#',                         [ApiKonum::class, 'iller']],
    ['GET',  "#^iller/$ID/ilceler$#",             [ApiKonum::class, 'ilceler']],
]);
