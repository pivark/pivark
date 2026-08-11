<?php
/**
 * 安装/演示种子：写入站点联系方式 + 对应内置自定义变量（cv_*）。
 * 模板统一用 {$site_phone} 等；后台「自定义变量」可改。
 *
 * @param PDO    $pdo
 * @param string $tCfg   configs 表名（含前缀）
 * @param array{
 *   site_phone?: string,
 *   site_email?: string,
 *   site_address?: string,
 *   site_wechat_qr?: string,
 *   site_wechat_mp_qr?: string
 * } $contact
 * @param bool $overwrite 已有非空值时是否覆盖
 */
function pivark_seed_contact_config(PDO $pdo, string $tCfg, array $contact, bool $overwrite = true): void
{
    $builtins = [
        'phone' => [
            'title'    => '站点电话',
            'type'     => 'text',
            'site_key' => 'site_phone',
        ],
        'email' => [
            'title'    => '站点邮箱',
            'type'     => 'text',
            'site_key' => 'site_email',
        ],
        'address' => [
            'title'    => '站点地址',
            'type'     => 'textarea',
            'site_key' => 'site_address',
        ],
        'wechat_qr' => [
            'title'    => '微信二维码',
            'type'     => 'image',
            'site_key' => 'site_wechat_qr',
        ],
        'wechat_mp_qr' => [
            'title'    => '公众号二维码',
            'type'     => 'image',
            'site_key' => 'site_wechat_mp_qr',
        ],
    ];

    $upsert = static function (string $key, string $value) use ($pdo, $tCfg, $overwrite): void {
        $stmt = $pdo->prepare("SELECT `value` FROM {$tCfg} WHERE `key` = ? LIMIT 1");
        $stmt->execute([$key]);
        $cur = $stmt->fetchColumn();
        if ($cur === false) {
            $pdo->prepare("INSERT INTO {$tCfg} (`group`,`key`,`value`,`type`,`created_at`,`updated_at`) VALUES ('system',?,?,?,NOW(),NOW())")
                ->execute([$key, $value, 'string']);

            return;
        }
        if (!$overwrite && trim((string) $cur) !== '') {
            return;
        }
        $pdo->prepare("UPDATE {$tCfg} SET `value`=?, `updated_at`=NOW() WHERE `key`=?")
            ->execute([$value, $key]);
    };

    foreach ($builtins as $name => $meta) {
        $siteKey = $meta['site_key'];
        $value = (string) ($contact[$siteKey] ?? '');
        $upsert($siteKey, $value);
        $upsert('cv_' . $name . '_title', $meta['title']);
        $upsert('cv_' . $name . '_type', $meta['type']);
        $upsert('cv_' . $name . '_value', $value);
    }
}
