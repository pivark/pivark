<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\payment\gateway;

use app\common\support\ServiceResult;

interface PaymentGatewayInterface
{
    public function channel(): string;

    /**
     * @param array<string, mixed> $order
     * @param array<string, string> $config
     */
    public function createPayment(array $order, array $config): ServiceResult;

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $config
     * @param array<string, string> $notifyHeaders
     * @return array{ok:bool,order_no?:string,txn_id?:string,raw?:array<string,mixed>,msg?:string}
     */
    public function parseNotify(array $input, string $rawBody, array $config, array $notifyHeaders = []): array;

    /**
     * 按商户订单号查询渠道支付状态（live 查单；demo 未支付）。
     *
     * @param array<string, string> $config
     * @return array{ok:bool,paid:bool,order_no?:string,txn_id?:string,msg?:string,raw?:array<string,mixed>}
     */
    public function queryPayment(string $orderNo, array $config): array;

    /**
     * 原路退款（微信/支付宝 live；demo 模式仅本地标记）。
     *
     * @param array<string, mixed> $order
     * @param array<string, string> $config
     */
    public function refundPayment(array $order, array $config, string $reason = ''): ServiceResult;
}
