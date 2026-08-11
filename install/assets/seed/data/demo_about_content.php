<?php
/**
 * 华仪智控演示站 · 关于我们页正文（入库 site_pages.content）
 * 说明：华仪智控为 PivArk 工业装备行业演示样板，非真实上市公司；正文勿写股票代码等易误导信息。
 */
declare(strict_types=1);

$img = '/uploads/demo-seed';

$html = <<<'HTML'
<div class="pv-about-section pv-about-section--intro">
<div class="pv-about-intro">
<div class="pv-about-intro__media">
<img src="__DEMO_IMG__/gallery/03.jpg" alt="华仪智控工业测控现场" loading="lazy" width="480" height="360">
</div>
<div class="pv-about-intro__body">
<div class="pv-about-lead">
<p class="lead"><strong>华仪智控</strong>成立于 2008 年，总部位于上海张江科学城，专注工业自动化、过程测控与智能装备。我们以「让过程测控更可靠、更智能」为使命，为石化、电力、冶金、水处理等行业客户提供从智能仪表到系统集成的可交付方案。</p>
<p>历经十余年发展，公司已构建「传感芯体 — 智能仪表 — 系统集成 — 运维服务」的完整产品链，累计服务客户 3,200 余家，在役设备超过 120 万台套。</p>
</div>
<div class="pv-about-cert-inline" id="about-cert">
<ul class="pv-about-cert-badges">
<li>ISO 9001 / 14001 / 45001</li>
<li>防爆 Ex ia / Ex d</li>
<li>功能安全 SIL2</li>
<li>CE · RoHS</li>
<li>软件著作权与发明专利</li>
<li>大型能源集团合格供应商</li>
</ul>
</div>
</div>
</div>
<div class="pv-about-vision-grid">
<div class="pv-about-vision-card">
<span class="pv-about-vision-card__label">愿景</span>
<p>成为工业测控与智能装备领域值得信赖的长期伙伴</p>
</div>
<div class="pv-about-vision-card">
<span class="pv-about-vision-card__label">使命</span>
<p>以可靠产品与专业服务，帮助客户提升过程透明度、降低非计划停机</p>
</div>
<div class="pv-about-vision-card">
<span class="pv-about-vision-card__label">价值观</span>
<p>可靠 · 专业 · 开放 · 共赢</p>
</div>
</div>
</div>

<div class="pv-about-section pv-about-section--cap">
<h2 class="pv-about-section__title pv-about-section__title--center" id="about-capability">核心能力</h2>
<div class="row g-4 pv-about-cap-grid">
<div class="col-md-6">
<div class="pv-about-cap-card">
<span class="pv-about-cap-card__icon" aria-hidden="true"><i class="bi bi-cpu"></i></span>
<h3 class="h5">自主研发</h3>
<p>覆盖传感芯体、数字补偿算法、嵌入式固件与上位机软件。HY 系列智能变送器支持 HART、Modbus 及 OPC UA，精度与温漂指标满足流程工业严苛工况。</p>
</div>
</div>
<div class="col-md-6">
<div class="pv-about-cap-card">
<span class="pv-about-cap-card__icon" aria-hidden="true"><i class="bi bi-gear-wide-connected"></i></span>
<h3 class="h5">精益制造</h3>
<p>上海临港与苏州两处制造基地，配备 SMT、三坐标检测、高低温循环与 EMC 实验室。关键工序 MES 追溯，出厂产品 100% 标定并附带校准证书。</p>
</div>
</div>
<div class="col-md-6">
<div class="pv-about-cap-card">
<span class="pv-about-cap-card__icon" aria-hidden="true"><i class="bi bi-diagram-3"></i></span>
<h3 class="h5">系统集成</h3>
<p>从单机仪表到整厂测控改造的一站式交付，与多家 DCS、PLC 厂商完成互认证。可承接防爆分区设计、机柜成套、组态调试与 FAT/SAT 验收。</p>
</div>
</div>
<div class="col-md-6">
<div class="pv-about-cap-card">
<span class="pv-about-cap-card__icon" aria-hidden="true"><i class="bi bi-headset"></i></span>
<h3 class="h5">全生命周期服务</h3>
<p>华北、华东、华南、西南设有区域服务中心，提供 7×12 小时远程支持与 48 小时现场响应。年度巡检、备件保障与旧表升级方案覆盖客户全生命周期。</p>
</div>
</div>
</div>
</div>

<div class="pv-about-section pv-about-section--history">
<h2 class="pv-about-section__title pv-about-section__title--center" id="about-history">发展历程</h2>
<div class="pv-about-timeline pv-about-timeline--wave" data-pv-about-timeline>
<p class="pv-about-timeline__subtitle">左右滑动浏览关键节点 · 支持触屏拖动</p>
<div class="pv-about-timeline__stage">
<button type="button" class="pv-about-timeline__btn pv-about-timeline__btn--prev pv-about-timeline__btn--float" aria-label="上一节点" disabled><i class="bi bi-chevron-left"></i></button>
<div class="pv-about-timeline__viewport" tabindex="0" aria-label="发展历程时间轴">
<div class="pv-about-timeline__track">
<svg class="pv-about-timeline__wave" viewBox="0 0 1440 100" preserveAspectRatio="none" aria-hidden="true">
<path d="M0,50 C120,15 240,85 360,50 S600,15 720,50 S960,85 1080,50 S1320,15 1440,50" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
</svg>
<div class="pv-about-timeline__node pv-about-timeline__node--top">
<div class="pv-about-timeline__slot pv-about-timeline__slot--above">
<div class="pv-about-timeline__content">
<h3 class="pv-about-timeline__title">公司创立</h3>
<p class="pv-about-timeline__desc">公司在上海浦东成立，推出首款 HY-600 系列压力变送器。</p>
</div>
</div>
<div class="pv-about-timeline__axis">
<span class="pv-about-timeline-year">2008</span>
<span class="pv-about-timeline__dot" aria-hidden="true"></span>
</div>
<div class="pv-about-timeline__slot pv-about-timeline__slot--below" aria-hidden="true"></div>
</div>
<div class="pv-about-timeline__node pv-about-timeline__node--bottom">
<div class="pv-about-timeline__slot pv-about-timeline__slot--above" aria-hidden="true"></div>
<div class="pv-about-timeline__axis">
<span class="pv-about-timeline__dot" aria-hidden="true"></span>
<span class="pv-about-timeline-year">2012</span>
</div>
<div class="pv-about-timeline__slot pv-about-timeline__slot--below">
<div class="pv-about-timeline__content">
<h3 class="pv-about-timeline__title">制造基地投产</h3>
<p class="pv-about-timeline__desc">苏州制造基地投产，通过 ISO 9001 质量管理体系认证。</p>
</div>
</div>
</div>
<div class="pv-about-timeline__node pv-about-timeline__node--top">
<div class="pv-about-timeline__slot pv-about-timeline__slot--above">
<div class="pv-about-timeline__content">
<h3 class="pv-about-timeline__title">记录仪上市</h3>
<p class="pv-about-timeline__desc">无纸记录仪 HY-R 系列上市，进入电力与冶金大型项目供应链。</p>
</div>
</div>
<div class="pv-about-timeline__axis">
<span class="pv-about-timeline-year">2016</span>
<span class="pv-about-timeline__dot" aria-hidden="true"></span>
</div>
<div class="pv-about-timeline__slot pv-about-timeline__slot--below" aria-hidden="true"></div>
</div>
<div class="pv-about-timeline__node pv-about-timeline__node--bottom">
<div class="pv-about-timeline__slot pv-about-timeline__slot--above" aria-hidden="true"></div>
<div class="pv-about-timeline__axis">
<span class="pv-about-timeline__dot" aria-hidden="true"></span>
<span class="pv-about-timeline-year">2019</span>
</div>
<div class="pv-about-timeline__slot pv-about-timeline__slot--below">
<div class="pv-about-timeline__content">
<h3 class="pv-about-timeline__title">高新认定</h3>
<p class="pv-about-timeline__desc">获国家高新技术企业认定，HART 与 SIL2 认证产品批量出货。</p>
</div>
</div>
</div>
<div class="pv-about-timeline__node pv-about-timeline__node--top">
<div class="pv-about-timeline__slot pv-about-timeline__slot--above">
<div class="pv-about-timeline__content">
<h3 class="pv-about-timeline__title">智能工厂启用</h3>
<p class="pv-about-timeline__desc">临港智能工厂启用，数据采集与边缘网关产品线正式发布。</p>
</div>
</div>
<div class="pv-about-timeline__axis">
<span class="pv-about-timeline-year">2022</span>
<span class="pv-about-timeline__dot" aria-hidden="true"></span>
</div>
<div class="pv-about-timeline__slot pv-about-timeline__slot--below" aria-hidden="true"></div>
</div>
<div class="pv-about-timeline__node pv-about-timeline__node--bottom">
<div class="pv-about-timeline__slot pv-about-timeline__slot--above" aria-hidden="true"></div>
<div class="pv-about-timeline__axis">
<span class="pv-about-timeline__dot" aria-hidden="true"></span>
<span class="pv-about-timeline-year">2024</span>
</div>
<div class="pv-about-timeline__slot pv-about-timeline__slot--below">
<div class="pv-about-timeline__content">
<h3 class="pv-about-timeline__title">海外布局</h3>
<p class="pv-about-timeline__desc">海外办事处拓展至东南亚与中东，服务网络持续完善。</p>
</div>
</div>
</div>
</div>
</div>
<button type="button" class="pv-about-timeline__btn pv-about-timeline__btn--next pv-about-timeline__btn--float" aria-label="下一节点"><i class="bi bi-chevron-right"></i></button>
</div>
</div>
</div>

<div class="pv-about-culture">
<h2 class="pv-about-section__title pv-about-section__title--center" id="about-culture">企业文化</h2>
<div class="pv-about-culture__row">
<figure class="pv-about-culture__media">
<img src="__DEMO_IMG__/gallery/01.jpg" alt="华仪智控制造基地与现场应用" loading="lazy" width="420" height="315">
<figcaption>临港制造基地 · 典型石化现场测控机柜成套项目</figcaption>
</figure>
<div class="pv-about-culture__text">
<p>华仪智控倡导「工程师文化」与「客户成功」并重。我们相信可靠的产品来自对细节的长期坚守，也来自与客户在现场并肩解决问题。公司每年投入营收的 8% 以上用于研发，并与多所高校共建联合实验室。</p>
<p class="pv-about-culture__contact">如需产品选型、方案评估、商务合作或售后支持，欢迎通过 <a href="/contact">联系我们</a> 提交留言，或拨打服务热线 <strong>400-800-6688</strong>。</p>
</div>
</div>
</div>
HTML;

return str_replace('__DEMO_IMG__', $img, $html);
