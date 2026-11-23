<?php
/**
 * /api/iller, /api/iller/{id}/ilceler
 */

require_once __DIR__ . '/Api.php';
require_once __DIR__ . '/../FormBasvuru.php';

class ApiKonum
{
    public static function iller(Api $api, array $p)
    {
        return array_map(fn($r) => ['id' => (int)$r['il_id'], 'adi' => $r['il_adi']], (new FormBasvuru())->iller());
    }

    public static function ilceler(Api $api, array $p)
    {
        $servis = new FormBasvuru();
        $il = Database::getInstance()->fetchOne("SELECT il_id FROM dbo.tanim_Iller WHERE il_id = ? AND il_durum = 1", [(int)$p['id']]);
        if (!$il) {
            throw new ApiHata('ALAN_HATASI', 'İl bulunamadı.', ['alanlar' => ['il_id' => 'GET /api/iller listesindeki bir id.']]);
        }
        return array_map(fn($r) => ['id' => (int)$r['ilce_id'], 'adi' => $r['ilce_adi']], $servis->ilceler($il['il_id']));
    }
}
