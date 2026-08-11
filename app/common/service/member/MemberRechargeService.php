<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\support\MoneyMath;

use app\common\support\AppTime;

use app\common\support\QueryLimit;
use app\common\support\ServiceResult;

use app\common\model\MemberBalanceLog;
use app\common\model\MemberRechargeOrder;
use app\common\model\MemberRechargePackage;
use app\common\model\User;
use app\common\service\payment\PaymentOrderService;
use app\common\support\AdminListParams;
use app\common\support\DbTable;
use app\common\support\HtmlSanitizer;
use app\common\support\OpsLog;
use think\facade\Db;

/** 会员充值套餐 */
class MemberRechargeService
{

    public function __construct(
        private readonly MemberRechargeWalletDeps $wallet,
        private readonly MemberRechargeMemberDeps $member,
    ) {
    }

    private function mpWechatAuth(): MemberMpWechatAuthService
    {
        return app(MemberMpWechatAuthService::class);
    }

    public const TYPE_MEMBERSHIP = 'membership';
    public const TYPE_POINTS     = 'points';
    public const TYPE_BALANCE    = 'balance';

    private const DEFAULT_CUSTOM_RECHARGE_MIN_YUAN = 1.0;
    private const DEFAULT_CUSTOM_RECHARGE_MAX_YUAN = 5000.0;
    private const DEFAULT_POINTS_PER_YUAN          = 10;
    private const RECOMMEND_PACKAGE_LIMIT_MAX      = 12;

    /**
     * 会员中心 / H5 微信支付附加参数（openid、client、mweb 场景 IP）
     *
     * @return array<string, mixed>
     */
    public function buildWechatPayExtras(int $userId, ?string $clientIp = null, ?string $userAgent = null): array
    {
        $extras  = [];
        $ua      = strtolower((string) $userAgent);
        $inWechat = str_contains($ua, 'micromessenger');
        $openid  = $this->mpWechatAuth()->openidForUser($userId);
        if ($openid !== '') {
            $extras['openid'] = $openid;
            $extras['client'] = $inWechat ? 'wechat_h5' : 'miniprogram';

            return $extras;
        }
        if ($inWechat) {
            return $extras;
        }

        // 外站 PC/手机浏览器：Native 扫码（V3）；H5(mweb) 仅显式 client=mweb/h5 时走网关
        return $extras;
    }
    /** @return list<array<string, mixed>> */
    public function listAdmin(): array
    {
        return $this->listAdminPaged(['limit' => QueryLimit::ADMIN_UNBOUNDED])['list'];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{list:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listAdminPaged(array $params = []): array
    {
        $p     = AdminListParams::parse($params);
        $query = MemberRechargePackage::order('sort', 'asc')->order('id', 'asc');
        AdminListParams::applyKeyword($query, $p['keyword'], 'title|description');
        $total = (int) $query->count();
        $list  = self::collectionToList($query->page($p['page'], $p['limit'])->select());

        return ['list' => $list, 'total' => $total, 'page' => $p['page'], 'limit' => $p['limit']];
    }

    /** @return list<array<string, mixed>> */
    public function listPublic(): array
    {
        return self::collectionToList(
            MemberRechargePackage::where('status', 1)
                ->order('sort', 'asc')->order('id', 'asc')->select()
        );
    }

    /**
     * @param list<array<string, mixed>> $packages
     * @return list<array<string, mixed>>
     */
    public function enrichPublicForUser(array $packages, int $userId): array
    {
        $balance = $this->wallet->memberBalanceService->balance($userId);
        $out     = [];
        foreach ($packages as $pkg) {
            $out[] = $this->decoratePublicPackage($pkg, $balance);
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $packages
     * @return array{membership:list<array<string,mixed>>,points:list<array<string,mixed>>,balance:list<array<string,mixed>>}
     */
    public function groupPublicByType(array $packages): array
    {
        $out = [
            self::TYPE_MEMBERSHIP => [],
            self::TYPE_POINTS     => [],
            self::TYPE_BALANCE    => [],
        ];
        foreach ($packages as $pkg) {
            $type = $this->inferPackageType($pkg);
            $out[$type][] = $pkg;
        }

        return [
            self::TYPE_MEMBERSHIP => $out[self::TYPE_MEMBERSHIP],
            self::TYPE_POINTS     => $out[self::TYPE_POINTS],
            self::TYPE_BALANCE    => $out[self::TYPE_BALANCE],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function recommendForUser(int $userId, int $limit = 3): array
    {
        $limit   = max(1, min(self::RECOMMEND_PACKAGE_LIMIT_MAX, $limit));
        $balance = $this->wallet->memberBalanceService->balance($userId);
        $out     = [];
        foreach ($this->listPublic() as $pkg) {
            if ($this->inferPackageType($pkg) !== self::TYPE_MEMBERSHIP) {
                continue;
            }
            $out[] = $this->decoratePublicPackage($pkg, $balance);
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** 自定义充值下限（元）；正式默认 1，联调可在 site.env 设 PIVARK_PAYMENT_SMOKE_RECHARGE_MIN=0.01 */
    public function customRechargeMinAmount(): float
    {
        $raw = trim((string) env('PIVARK_PAYMENT_SMOKE_RECHARGE_MIN', ''));
        if ($raw !== '' && is_numeric($raw)) {
            return max(0.01, round((float) $raw, 2));
        }

        return self::DEFAULT_CUSTOM_RECHARGE_MIN_YUAN;
    }

    /** 自定义充值上限（元）；后台 configs.member_recharge_custom_max 可覆盖 */
    public function customRechargeMaxAmount(): float
    {
        $raw = trim((string) $this->member->configService->get('member_recharge_custom_max', ''));
        if ($raw !== '' && is_numeric($raw)) {
            return max(0.01, round((float) $raw, 2));
        }

        return self::DEFAULT_CUSTOM_RECHARGE_MAX_YUAN;
    }

    /** @return array{points_enabled:int,balance_enabled:int,min:float,max:float,points_per_yuan:int} */
    public function customRechargeMeta(): array
    {
        $perYuan = max(1, (int) $this->member->configService->get(
            'member_recharge_points_per_yuan',
            (string) self::DEFAULT_POINTS_PER_YUAN,
        ));

        return [
            'points_enabled'  => $this->member->memberConfigService->isPointsEnabled() ? 1 : 0,
            'balance_enabled' => 1,
            'min'             => $this->customRechargeMinAmount(),
            'max'             => $this->customRechargeMaxAmount(),
            'points_per_yuan' => $perYuan,
        ];
    }

    /**
     * @param array<string, mixed> $pkg
     */
    public function inferPackageType(array $pkg): string
    {
        $explicit = trim((string) ($pkg['package_type'] ?? ''));
        if ($explicit !== '') {
            return $this->normalizePackageType($explicit);
        }
        $levelId = (int) ($pkg['level_id'] ?? 0);
        $days    = (int) ($pkg['days'] ?? 0);
        $points  = (int) ($pkg['points'] ?? 0);
        if ($levelId > 0 || $days > 0) {
            return self::TYPE_MEMBERSHIP;
        }
        if ($points > 0) {
            return self::TYPE_POINTS;
        }
        if ((float) ($pkg['grant_balance'] ?? 0) > 0) {
            return self::TYPE_BALANCE;
        }

        return self::TYPE_MEMBERSHIP;
    }

    public function normalizePackageType(string $type): string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            self::TYPE_POINTS, self::TYPE_BALANCE => $type,
            default => self::TYPE_MEMBERSHIP,
        };
    }

    public function packageTypeLabel(string $type): string
    {
        return match ($this->normalizePackageType($type)) {
            self::TYPE_POINTS  => '充积分',
            self::TYPE_BALANCE => '充余额',
            default            => '会员套餐',
        };
    }

    /**
     * @param array<string, mixed> $pkg
     * @return array<string, mixed>
     */
    private function decoratePublicPackage(array $pkg, float $balance): array
    {
        $type  = $this->inferPackageType($pkg);
        $price = round((float) ($pkg['price'] ?? 0), 2);
        $pkg['package_type']       = $type;
        $pkg['package_type_label'] = $this->packageTypeLabel($type);
        $pkg['price']              = $price;
        $pkg['price_text']         = MoneyMath::formatPlain($price);
        $pkg['can_afford']         = $balance + 0.001 >= $price ? 1 : 0;
        $pkg['benefit_text']       = $this->benefitSummary($pkg);
        $allowBalanceBuy           = $type === self::TYPE_POINTS && $price > 0;
        $pkg['show_balance_buy']       = $allowBalanceBuy && $pkg['can_afford'] === 1 ? 1 : 0;
        $pkg['show_balance_shortfall'] = $allowBalanceBuy && $pkg['can_afford'] === 0 ? 1 : 0;

        return $pkg;
    }

    private function pointsPerYuan(): int
    {
        return max(1, (int) $this->customRechargeMeta()['points_per_yuan']);
    }

    /**
     * @param array<string, mixed> $pkg
     */
    /**
     * @param array<string, mixed> $pkg
     */
    public function benefitSummary(array $pkg): string
    {
        $type = $this->inferPackageType($pkg);
        if ($type === self::TYPE_BALANCE) {
            $grant = (float) ($pkg['grant_balance'] ?? 0);
            $credit = $grant > 0 ? $grant : round((float) ($pkg['price'] ?? 0), 2);

            return '到账余额 ¥' . MoneyMath::formatPlain($credit);
        }
        if ($type === self::TYPE_POINTS) {
            $points = (int) ($pkg['points'] ?? 0);
            if ($points > 0) {
                return '获得 ' . $points . ' ' . $this->member->memberConfigService->pointsLabel();
            }
        }

        $parts   = [];
        $levelId = (int) ($pkg['level_id'] ?? 0);
        if ($levelId > 0) {
            $parts[] = '升级至「' . $this->member->memberLevelService->getName($levelId) . '」';
        }
        $days = (int) ($pkg['days'] ?? 0);
        if ($days > 0) {
            $parts[] = '会员时长 ' . $days . ' 天';
        }
        $points = (int) ($pkg['points'] ?? 0);
        if ($points > 0) {
            $parts[] = '赠送 ' . $points . ' ' . $this->member->memberConfigService->pointsLabel();
        }

        return $parts === [] ? '开通对应权益' : implode('，', $parts);
    }

    /**
     * @return ServiceResult
     */
    public function purchaseWithBalance(int $userId, int $packageId): ServiceResult
    {
        if ($userId < 1 || !$this->member->memberService->hasMemberRole($userId)) {
            return ServiceResult::fail('请先登录会员账号');
        }
        if ($packageId < 1) {
            return ServiceResult::fail('请选择套餐');
        }
        $pkg = self::packageToRow(MemberRechargePackage::where('id', $packageId)->where('status', 1)->find());
        if ($pkg === null) {
            return ServiceResult::fail('套餐不存在或已下架');
        }

        $price = round((float) ($pkg['price'] ?? 0), 2);
        if ($price < 0) {
            return ServiceResult::fail('套餐价格无效');
        }
        $balance = $this->wallet->memberBalanceService->balance($userId);
        if ($balance + 0.001 < $price) {
            return ServiceResult::fail('余额不足，当前余额 ' . MoneyMath::formatYuan($balance, true));
        }

        $type = $this->inferPackageType($pkg);
        if ($type !== self::TYPE_POINTS) {
            return ServiceResult::fail($type === self::TYPE_BALANCE
                ? '余额充值请使用微信/支付宝支付'
                : '会员套餐请使用微信/支付宝支付');
        }

        $title = (string) ($pkg['title'] ?? '套餐');

        Db::startTrans();
        try {
            $deduct = $this->wallet->memberBalanceService->adjust(
                $userId,
                -$price,
                '购买套餐：' . $title,
                0
            );
            if (!$deduct->isOk()) {
                Db::rollback();

                return ServiceResult::fail($deduct->message());
            }

            $apply = $this->applyPackageBenefits($userId, $pkg, $price, '余额购买：');
            if (!$apply->isOk()) {
                Db::rollback();

                return ServiceResult::fail($apply->message());
            }
            $this->wallet->memberPointGiftService->tryGrantConsume($userId, $price, '余额购买：' . $title);
            $this->recordRechargeOrder($userId, $packageId, $pkg, $price);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            OpsLog::businessWarning('member_recharge_purchase_failed', [
                'msg'  => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return ServiceResult::fail('购买失败，请稍后重试');
        }

        $this->member->frontAuthService->refreshCurrentMember();

        return ServiceResult::ok(['balance' => $this->wallet->memberBalanceService->balance($userId)], '购买成功，' . $this->benefitSummary($pkg));
    }

    /**
     * @return ServiceResult
     */
    public function purchaseCustomWithBalance(int $userId, string $rechargeType, float $customAmount): ServiceResult
    {
        if ($userId < 1 || !$this->member->memberService->hasMemberRole($userId)) {
            return ServiceResult::fail('请先登录会员账号');
        }
        $type = $this->normalizePackageType($rechargeType);
        if ($type !== self::TYPE_POINTS) {
            return ServiceResult::fail('该类型请使用微信/支付宝支付');
        }
        if (!$this->member->memberConfigService->isPointsEnabled()) {
            return ServiceResult::fail('积分功能未开启');
        }
        $meta = $this->customRechargeMeta();
        $amount = round($customAmount, 2);
        if ($amount < $meta['min'] - 0.001 || $amount > $meta['max'] + 0.001) {
            return ServiceResult::fail('金额需在 ' . MoneyMath::formatYuan($meta['min'], true) . '～' . MoneyMath::formatYuan($meta['max'], true) . ' 之间');
        }
        $balance = $this->wallet->memberBalanceService->balance($userId);
        if ($balance + 0.001 < $amount) {
            return ServiceResult::fail('余额不足，当前余额 ' . MoneyMath::formatYuan($balance, true));
        }
        $points = (int) floor($amount * $this->pointsPerYuan());
        if ($points < 1) {
            return ServiceResult::fail('兑换积分数量无效');
        }
        $title = '自定义' . $this->member->memberConfigService->pointsLabel() . '充值';

        Db::startTrans();
        try {
            $deduct = $this->wallet->memberBalanceService->adjust($userId, -$amount, $title, 0);
            if (!$deduct->isOk()) {
                Db::rollback();

                return ServiceResult::fail($deduct->message());
            }
            $grant = $this->wallet->memberPointService->grant($userId, $points, $title);
            if (!$grant->isOk() && $grant->message() !== 'skip') {
                Db::rollback();

                return ServiceResult::fail($grant->message());
            }
            $this->wallet->memberPointGiftService->tryGrantConsume($userId, $amount, $title);
            if ($this->ordersTableExists()) {
                MemberRechargeOrder::insert([
                    'user_id'       => $userId,
                    'package_id'    => 0,
                    'package_title' => HtmlSanitizer::cleanPlainText($title, 100),
                    'amount'        => $amount,
                    'level_id'      => 0,
                    'points'        => $points,
                    'days'          => 0,
                    'created_at'    => AppTime::now(),
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            OpsLog::businessWarning('member_recharge_purchase_failed', [
                'msg'  => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return ServiceResult::fail('购买失败，请稍后重试');
        }

        $this->member->frontAuthService->refreshCurrentMember();

        return ServiceResult::ok(['balance' => $this->wallet->memberBalanceService->balance($userId)], '购买成功，获得 ' . $points . ' ' . $this->member->memberConfigService->pointsLabel());
    }

    /**
     * @param array<string, mixed> $options custom_amount, custom_type, openid, client
     * @return ServiceResult
     */
    public function createPaymentOrder(int $userId, int $packageId, string $channel, array $options = []): ServiceResult
    {
        if ($userId < 1 || !$this->member->memberService->hasMemberRole($userId)) {
            return ServiceResult::fail('请先登录会员账号');
        }
        if (!$this->wallet->documentPaymentService->enabled()) {
            return ServiceResult::fail('在线支付未开启');
        }

        $customAmount = round((float) ($options['custom_amount'] ?? 0), 2);
        $customType   = $this->normalizePackageType(trim((string) ($options['custom_type'] ?? '')));

        if ($packageId < 1 && $customAmount > 0 && $customType !== '') {
            return $this->createCustomPaymentOrder($userId, $customAmount, $customType, $channel, $options);
        }
        if ($packageId < 1) {
            return ServiceResult::fail('请选择套餐');
        }

        $pkg = self::packageToRow(MemberRechargePackage::where('id', $packageId)->where('status', 1)->find());
        if ($pkg === null) {
            return ServiceResult::fail('套餐不存在或已下架');
        }

        $price = round((float) ($pkg['price'] ?? 0), 2);
        if ($price <= 0) {
            return ServiceResult::fail('该套餐不支持在线支付');
        }
        $type = $this->inferPackageType($pkg);

        return $this->wallet->paymentOrderFactory->createForFront(
            $userId,
            PaymentOrderService::SCENE_RECHARGE,
            $packageId,
            $price,
            $channel,
            array_merge($options, [
                'title'         => (string) ($pkg['title'] ?? '套餐'),
                'package_id'    => $packageId,
                'package_type'  => $type,
                'grant_balance' => $this->grantBalanceForPackage($pkg, $price),
                'grant_points'  => max(0, (int) ($pkg['points'] ?? 0)),
            ])
        );
    }

    /**
     * @param array<string, mixed> $options
     * @return ServiceResult
     */
    private function createCustomPaymentOrder(
        int $userId,
        float $amount,
        string $type,
        string $channel,
        array $options = [],
    ): ServiceResult {
        $meta = $this->customRechargeMeta();
        if ($amount < $meta['min'] - 0.001 || $amount > $meta['max'] + 0.001) {
            return ServiceResult::fail('金额需在 ' . MoneyMath::formatYuan($meta['min'], true) . '～' . MoneyMath::formatYuan($meta['max'], true) . ' 之间');
        }
        if ($type === self::TYPE_POINTS && !$this->member->memberConfigService->isPointsEnabled()) {
            return ServiceResult::fail('积分功能未开启');
        }
        $grantBalance = $type === self::TYPE_BALANCE ? $amount : 0.0;
        $grantPoints  = $type === self::TYPE_POINTS ? (int) floor($amount * $this->pointsPerYuan()) : 0;
        if ($type === self::TYPE_POINTS && $grantPoints < 1) {
            return ServiceResult::fail('兑换积分数量无效');
        }
        $title = match ($type) {
            self::TYPE_BALANCE => '余额充值 ¥' . MoneyMath::formatPlain($amount),
            self::TYPE_POINTS  => $this->member->memberConfigService->pointsLabel() . '充值 ¥' . MoneyMath::formatPlain($amount),
            default            => '会员充值 ¥' . MoneyMath::formatPlain($amount),
        };

        return $this->wallet->paymentOrderFactory->createForFront(
            $userId,
            PaymentOrderService::SCENE_RECHARGE,
            0,
            $amount,
            $channel,
            array_merge($options, [
                'title'           => $title,
                'package_id'      => 0,
                'custom_recharge' => 1,
                'package_type'    => $type,
                'grant_balance'   => $grantBalance,
                'grant_points'    => $grantPoints,
            ])
        );
    }

    /**
     * 支付订单履约入口
     *
     * @param array<string, mixed> $order
     * @return ServiceResult
     */
    public function fulfillOrder(array $order): ServiceResult
    {
        $userId = (int) ($order['user_id'] ?? 0);
        if ($userId < 1 || !$this->member->memberService->hasMemberRole($userId)) {
            return ServiceResult::fail('会员无效');
        }

        $payload = json_decode((string) ($order['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        if (!empty($payload['custom_recharge']) || (int) ($order['scene_id'] ?? 0) < 1) {
            return $this->fulfillCustomFromPayload(
                $userId,
                $payload,
                (float) ($order['amount'] ?? 0),
                (string) ($order['order_no'] ?? '')
            );
        }

        return $this->fulfillPackage($userId, (int) ($order['scene_id'] ?? 0), (string) ($order['order_no'] ?? ''));
    }

    /**
     * 支付成功履约（不扣余额）
     * @return ServiceResult
     */
    public function fulfillPackage(int $userId, int $packageId, string $payOrderNo = ''): ServiceResult
    {
        if ($userId < 1 || !$this->member->memberService->hasMemberRole($userId)) {
            return ServiceResult::fail('会员无效');
        }
        if ($packageId < 1) {
            return ServiceResult::fail('套餐无效');
        }
        $payOrderNo = trim($payOrderNo);
        if ($payOrderNo !== '' && $this->isPayOrderFulfilled($payOrderNo)) {
            return ServiceResult::ok(null, '已履约');
        }

        $pkg = self::packageToRow(MemberRechargePackage::where('id', $packageId)->find());
        if ($pkg === null) {
            return ServiceResult::fail('套餐不存在');
        }
        $title = (string) ($pkg['title'] ?? '套餐');
        $price = round((float) ($pkg['price'] ?? 0), 2);
        $payRef = $payOrderNo !== '' ? MemberBalanceService::payRef($payOrderNo) : '';

        Db::startTrans();
        try {
            $apply = $this->applyPackageBenefits($userId, $pkg, $price, '在线支付：', $payRef);
            if (!$apply->isOk()) {
                Db::rollback();

                return ServiceResult::fail($apply->message());
            }
            $this->wallet->memberPointGiftService->tryGrantConsume($userId, $price, '在线支付：' . $title);
            $this->recordRechargeOrder($userId, $packageId, $pkg, $price, $payOrderNo);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            OpsLog::businessWarning('member_recharge_fulfill_failed', [
                'msg'  => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return ServiceResult::fail('履约失败，请稍后重试');
        }

        $this->member->frontAuthService->refreshCurrentMember();

        return ServiceResult::ok(null, '购买成功，' . $this->benefitSummary($pkg));
    }

    /**
     * @param array<string, mixed> $payload
     * @return ServiceResult
     */
    private function fulfillCustomFromPayload(int $userId, array $payload, float $paidAmount, string $payOrderNo = ''): ServiceResult
    {
        $payOrderNo = trim($payOrderNo);
        if ($payOrderNo !== '' && $this->isPayOrderFulfilled($payOrderNo)) {
            return ServiceResult::ok(null, '已履约');
        }

        $type   = $this->normalizePackageType((string) ($payload['package_type'] ?? self::TYPE_BALANCE));
        $amount = round($paidAmount > 0 ? $paidAmount : (float) ($payload['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return ServiceResult::fail('金额无效');
        }

        $msg = '充值成功';
        $payRef = $payOrderNo !== '' ? MemberBalanceService::payRef($payOrderNo) : '';

        Db::startTrans();
        try {
            if ($type === self::TYPE_BALANCE) {
                $credit = round((float) ($payload['grant_balance'] ?? 0), 2);
                if ($credit <= 0) {
                    $credit = $amount;
                }
                $adj = $this->wallet->memberBalanceService->adjust($userId, $credit, '在线充值：余额', 0, $payRef);
                if (!$adj->isOk()) {
                    Db::rollback();

                    return ServiceResult::fail($adj->message());
                }
                $msg = '余额已到账 ¥' . MoneyMath::formatPlain($credit);
            } elseif ($type === self::TYPE_POINTS) {
                $points = max(0, (int) ($payload['grant_points'] ?? 0));
                if ($points < 1) {
                    $points = (int) floor($amount * $this->pointsPerYuan());
                }
                $grant = $this->wallet->memberPointService->grant($userId, $points, '在线充值：' . $this->member->memberConfigService->pointsLabel());
                if (!$grant->isOk() && $grant->message() !== 'skip') {
                    Db::rollback();

                    return ServiceResult::fail($grant->message());
                }
                $this->wallet->memberPointGiftService->tryGrantConsume($userId, $amount, '在线充值：积分');
                $msg = '获得 ' . $points . ' ' . $this->member->memberConfigService->pointsLabel();
            } else {
                Db::rollback();

                return ServiceResult::fail('不支持的自定义充值类型');
            }

            if ($this->ordersTableExists()) {
                MemberRechargeOrder::insert([
                    'user_id'       => $userId,
                    'package_id'    => 0,
                    'package_title' => HtmlSanitizer::cleanPlainText((string) ($payload['title'] ?? '自定义充值'), 100),
                    'amount'        => $amount,
                    'pay_order_no'  => $payOrderNo !== '' ? mb_substr($payOrderNo, 0, 64) : null,
                    'level_id'      => 0,
                    'points'        => $type === self::TYPE_POINTS ? $points : 0,
                    'days'          => 0,
                    'created_at'    => AppTime::now(),
                ]);
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            OpsLog::businessWarning('member_recharge_fulfill_failed', [
                'msg'  => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return ServiceResult::fail('履约失败，请稍后重试');
        }

        $this->member->frontAuthService->refreshCurrentMember();

        return ServiceResult::ok(null, $msg);
    }

    /**
     * @param array<string, mixed> $pkg
     * @return ServiceResult
     */
    private function applyPackageBenefits(int $userId, array $pkg, float $paidAmount, string $reasonPrefix, string $payRef = ''): ServiceResult
    {
        $type  = $this->inferPackageType($pkg);
        $title = (string) ($pkg['title'] ?? '套餐');

        if ($type === self::TYPE_BALANCE) {
            $credit = $this->grantBalanceForPackage($pkg, $paidAmount);
            if ($credit <= 0) {
                return ServiceResult::fail('到账金额无效');
            }
            $adj = $this->wallet->memberBalanceService->adjust($userId, $credit, $reasonPrefix . $title, 0, $payRef);
            if (!$adj->isOk()) {
                return ServiceResult::fail($adj->message());
            }

            return ServiceResult::ok(null, 'ok');
        }

        if ($type === self::TYPE_POINTS) {
            $points = max(0, (int) ($pkg['points'] ?? 0));
            if ($points < 1) {
                return ServiceResult::fail('积分数量无效');
            }
            $grant = $this->wallet->memberPointService->grant($userId, $points, $reasonPrefix . $title);
            if (!$grant->isOk() && $grant->message() !== 'skip') {
                return ServiceResult::fail($grant->message());
            }

            return ServiceResult::ok(null, 'ok');
        }

        $levelId = (int) ($pkg['level_id'] ?? 0);
        $points  = max(0, (int) ($pkg['points'] ?? 0));
        $days    = max(0, (int) ($pkg['days'] ?? 0));
        if ($levelId > 0 && !$this->member->memberLevelService->isValidLevelId($levelId)) {
            return ServiceResult::fail('套餐关联等级无效');
        }

        $userUpdate = [];
        if ($levelId > 0) {
            $userUpdate['member_level_id'] = $levelId;
        }
        if ($days > 0) {
            $userUpdate['member_level_expire_at'] = $this->member->memberService->extendLevelExpireAt($userId, $days);
        }
        if ($userUpdate !== []) {
            User::where('id', $userId)->update($userUpdate);
        }
        if ($points > 0) {
            $grant = $this->wallet->memberPointService->grant($userId, $points, $reasonPrefix . $title);
            if (!$grant->isOk() && $grant->message() !== 'skip') {
                return ServiceResult::fail($grant->message());
            }
        }

        return ServiceResult::ok(null, 'ok');
    }

    /**
     * @param array<string, mixed> $pkg
     */
    private function grantBalanceForPackage(array $pkg, float $paidAmount): float
    {
        $grant = round((float) ($pkg['grant_balance'] ?? 0), 2);

        return $grant > 0 ? $grant : round((float) ($pkg['price'] ?? $paidAmount), 2);
    }

    /**
     * @param array<string, mixed> $pkg
     */
    private function recordRechargeOrder(int $userId, int $packageId, array $pkg, float $amount, string $payOrderNo = ''): void
    {
        if (!$this->ordersTableExists()) {
            return;
        }
        $payOrderNo = trim($payOrderNo);
        MemberRechargeOrder::insert([
            'user_id'       => $userId,
            'package_id'    => $packageId,
            'package_title' => HtmlSanitizer::cleanPlainText((string) ($pkg['title'] ?? '套餐'), 100),
            'amount'        => $amount,
            'pay_order_no'  => $payOrderNo !== '' ? mb_substr($payOrderNo, 0, 64) : null,
            'level_id'      => (int) ($pkg['level_id'] ?? 0),
            'points'        => max(0, (int) ($pkg['points'] ?? 0)),
            'days'          => max(0, (int) ($pkg['days'] ?? 0)),
            'created_at'    => AppTime::now(),
        ]);
    }

    private function isPayOrderFulfilled(string $payOrderNo): bool
    {
        $payOrderNo = trim($payOrderNo);
        if ($payOrderNo === '') {
            return false;
        }

        if ($this->ordersTableExists()) {
            $exists = MemberRechargeOrder::where('pay_order_no', $payOrderNo)->value('id');
            if ($exists !== null) {
                return true;
            }
        }

        $ref = MemberBalanceService::payRef($payOrderNo);
        if ($ref === '') {
            return false;
        }

        return MemberBalanceLog::where('ref', $ref)->value('id') !== null;
    }

    private function ordersTableExists(): bool
    {
        static $exists = null;

        return $exists ??= DbTable::modelExists(MemberRechargeOrder::class);
    }

    /** @return array<string, mixed>|null */
    public function findAdmin(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = MemberRechargePackage::where('id', $id)->find();

        return self::packageToRow($row);
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $id = max(0, (int) ($data['id'] ?? 0));
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return ServiceResult::fail('请填写套餐名称');
        }
        $price = max(0, (float) ($data['price'] ?? 0));
        $levelId = max(0, (int) ($data['level_id'] ?? 0));
        $days = max(0, (int) ($data['days'] ?? 0));
        if ($levelId > 0 && !$this->member->memberLevelService->isValidLevelId($levelId)) {
            return ServiceResult::fail('关联等级无效');
        }
        if ($days > 0 && $levelId < 1) {
            return ServiceResult::fail('含有效天数的套餐必须选择升级等级');
        }

        $packageType = $this->normalizePackageType((string) ($data['package_type'] ?? ''));
        if ($packageType === self::TYPE_MEMBERSHIP && $levelId < 1 && (int) ($data['points'] ?? 0) < 1) {
            $packageType = $this->inferPackageType([
                'level_id' => $levelId,
                'days'     => $days,
                'points'   => (int) ($data['points'] ?? 0),
                'grant_balance' => (float) ($data['grant_balance'] ?? 0),
            ]);
        }
        $grantBalance = max(0, round((float) ($data['grant_balance'] ?? 0), 2));

        $payload = [
            'title'          => HtmlSanitizer::cleanPlainText($title, 100),
            'price'          => MoneyMath::formatPlain($price),
            'package_type'   => $packageType,
            'grant_balance'  => MoneyMath::formatPlain($grantBalance),
            'level_id'       => $levelId,
            'points'         => max(0, (int) ($data['points'] ?? 0)),
            'days'           => $days,
            'description'    => HtmlSanitizer::cleanPlainText((string) ($data['description'] ?? ''), 500),
            'sort'           => (int) ($data['sort'] ?? 0),
            'status'         => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
            'updated_at'     => AppTime::now(),
        ];

        if ($id > 0) {
            if (!$this->findAdmin($id)) {
                return ServiceResult::fail('套餐不存在');
            }
            MemberRechargePackage::where('id', $id)->update($payload);
        } else {
            $payload['created_at'] = $payload['updated_at'];
            $id = (int) MemberRechargePackage::insertGetId($payload);
        }

        return ServiceResult::ok(['id' => $id], '保存成功');
    }

    /** @return ServiceResult */
    public function deleteAdmin(int $id): ServiceResult
    {
        if ($id < 1 || !$this->findAdmin($id)) {
            return ServiceResult::fail('套餐不存在');
        }
        MemberRechargePackage::where('id', $id)->delete();

        return ServiceResult::ok(null, '删除成功');
    }

    /** @return ServiceResult */
    public function updateSortAdmin(int $id, int $sort): ServiceResult
    {
        if ($id < 1 || !$this->findAdmin($id)) {
            return ServiceResult::fail('套餐不存在');
        }
        MemberRechargePackage::where('id', $id)->update([
            'sort'       => $sort,
            'updated_at' => AppTime::now(),
        ]);

        return ServiceResult::ok(null, '排序已更新');
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function packageToRow(mixed $model): ?array
    {
        if (is_array($model)) {
            return $model;
        }
        if (!is_object($model) || !method_exists($model, 'toArray')) {
            return null;
        }
        $row = $model->toArray();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function collectionToList(mixed $collection): array
    {
        if (!is_object($collection) || !method_exists($collection, 'toArray')) {
            return [];
        }
        $rows = $collection->toArray();
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
