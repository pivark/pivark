<?php

/**

 * 元舟 PivArk — 企业全域原子化数字资产中枢

 * (c) 2024-2026 pivark.com. All rights reserved.

 * Author: Angelo

 * 未经允许，不可用于商业用途。

 */

declare(strict_types=1);



namespace app\common\service\member;



use app\common\service\config\ConfigService;

use app\common\service\front\FrontAuthService;



/** MemberRechargeService 会员身份/等级/配置依赖包（冗余审计 §2 batch 10） */

final class MemberRechargeMemberDeps

{

    public function __construct(

        public readonly ConfigService $configService,

        public readonly MemberConfigService $memberConfigService,

        public readonly MemberLevelService $memberLevelService,

        public readonly MemberService $memberService,

        public readonly FrontAuthService $frontAuthService,

    ) {

    }

}

