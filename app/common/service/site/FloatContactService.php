<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\site;

use app\common\support\AppTime;

use app\common\support\ServiceResult;
use app\common\service\site\FloatContactWidgetRenderService;
use app\common\service\site\FloatContactConfigService;
use app\common\service\static\StaticHtmlDispatch;

use app\common\model\FloatContactItem;

class FloatContactService
{

    public function __construct(
        private readonly FloatContactConfigService $floatContactConfigService,
        private readonly FloatContactWidgetRenderService $floatContactWidgetRenderService,
    ) {
    }

  public const TYPE_QQ     = 'qq';
  public const TYPE_PHONE  = 'phone';
  public const TYPE_WECHAT = 'wechat';
  public const TYPE_EMAIL  = 'email';
  public const TYPE_LINK   = 'link';
  public const TYPE_VIP    = 'vip';

  public function ensureAutoload(): void
  {
      // Plugin.php 已 registerAutoloadPublic
  }

  /** @return list<string> */
  public function typeLabels(): array
  {
      return [
          self::TYPE_QQ     => 'QQ',
          self::TYPE_PHONE  => '电话',
          self::TYPE_WECHAT => '微信',
          self::TYPE_EMAIL  => '邮箱',
          self::TYPE_LINK   => '自定义链接',
          self::TYPE_VIP    => 'VIP 专线',
      ];
  }

  public function renderWidget(): string
  {
      if (!$this->floatContactConfigService->isEnabled()) {
          return '';
      }
      $items = $this->listPublic();
      if ($items === []) {
          return '';
      }
      $cfg   = $this->floatContactConfigService->all();
      $style = $this->floatContactConfigService->normalizedStyle();
      return $this->floatContactWidgetRenderService->renderForStyle($items, $cfg, $style);
  }

  public function buildAction(array $item): array
  {
      $type  = (string) ($item['contact_type'] ?? '');
      $value = trim((string) ($item['value'] ?? ''));

      return match ($type) {
          self::TYPE_QQ => [
              'href'     => $value !== '' ? 'https://wpa.qq.com/msgrd?v=3&uin=' . rawurlencode($value) . '&site=qq&menu=yes' : '',
              'external' => true,
              'hint'     => '咨询',
          ],
          self::TYPE_PHONE => [
              'href'     => $value !== '' ? 'tel:' . preg_replace('/\s+/', '', $value) : '',
              'external' => false,
              'hint'     => '拨打',
          ],
          self::TYPE_EMAIL => [
              'href'     => $value !== '' ? 'mailto:' . $value : '',
              'external' => false,
              'hint'     => '邮件',
          ],
          self::TYPE_LINK => [
              'href'     => $this->normalizeUrl($value),
              'external' => true,
              'hint'     => '打开',
          ],
          self::TYPE_VIP => [
              'href'     => $this->normalizeUrl($value),
              'external' => true,
              'hint'     => 'VIP',
          ],
          default => ['href' => '', 'external' => false, 'hint' => ''],
      };
  }

  public function normalizeUrl(string $url): string
  {
      $url = trim($url);
      if ($url === '') {
          return '';
      }
      if (!preg_match('#^https?://#i', $url)) {
          return 'https://' . ltrim($url, '/');
      }

      return $url;
  }

  public function typeIcon(string $type): string
  {
      if (in_array($type, [self::TYPE_QQ, self::TYPE_WECHAT], true)) {
          return $this->typeGlyphHtml($type, 18);
      }

      return match ($type) {
          self::TYPE_PHONE  => '☎',
          self::TYPE_EMAIL  => '@',
          self::TYPE_LINK   => '↗',
          self::TYPE_VIP    => 'V',
          default           => '•',
      };
  }

  /** 前台彩色圆球 / 图标轨等样式用的 SVG 图标 */
  public function typeGlyphHtml(string $type, int $size = 22): string
  {
      $w = max(12, min(32, $size));
      $h = $w;

      return match ($type) {
          self::TYPE_QQ => '<svg viewBox="0 0 24 24" width="' . $w . '" height="' . $h . '" aria-hidden="true"><path fill="currentColor" d="M21.395 15.035a40 40 0 0 0-.803-2.264l-1.079-2.695c.001-.032.014-.562.014-.836C19.526 4.632 17.351 0 12 0S4.474 4.632 4.474 9.241c0 .274.013.804.014.836l-1.08 2.695a39 39 0 0 0-.802 2.264c-1.021 3.283-.69 4.643-.438 4.673.54.065 2.103-2.472 2.103-2.472 0 1.469.756 3.387 2.394 4.771-.612.188-1.363.479-1.845.835-.434.32-.379.646-.301.778.343.578 5.883.369 7.482.189 1.6.18 7.14.389 7.483-.189.078-.132.132-.458-.301-.778-.483-.356-1.233-.646-1.846-.836 1.637-1.384 2.393-3.302 2.393-4.771 0 0 1.563 2.537 2.103 2.472.251-.03.581-1.39-.438-4.673"/></svg>',
          self::TYPE_WECHAT => '<svg viewBox="0 0 24 24" width="' . $w . '" height="' . $h . '" aria-hidden="true"><path fill="currentColor" d="M8.691 2.188C3.891 2.188 0 5.476 0 9.53c0 2.212 1.17 4.203 3.002 5.55a.59.59 0 0 1 .213.665l-.39 1.48c-.019.07-.048.141-.048.213 0 .163.13.295.29.295a.326.326 0 0 0 .167-.054l1.903-1.114a.864.864 0 0 1 .717-.098 10.16 10.16 0 0 0 2.837.403c.276 0 .543-.027.811-.05-.857-2.578.157-4.972 1.932-6.446 1.703-1.415 3.882-1.98 5.853-1.838-.576-3.583-4.196-6.348-8.596-6.348zM5.785 5.991c.642 0 1.162.529 1.162 1.18a1.17 1.17 0 0 1-1.162 1.178A1.17 1.17 0 0 1 4.623 7.17c0-.651.52-1.18 1.162-1.18zm5.813 0c.642 0 1.162.529 1.162 1.18a1.17 1.17 0 0 1-1.162 1.178 1.17 1.17 0 0 1-1.162-1.178c0-.651.52-1.18 1.162-1.18zm5.34 2.867c-1.797-.052-3.746.512-5.28 1.786-1.72 1.428-2.687 3.72-1.78 6.22.942 2.453 3.666 4.229 6.884 4.229.826 0 1.622-.12 2.361-.336a.722.722 0 0 1 .598.082l1.584.926a.272.272 0 0 0 .14.047c.134 0 .24-.111.24-.247 0-.06-.023-.12-.038-.177l-.327-1.233a.582.582 0 0 1-.023-.156.49.49 0 0 1 .201-.398C23.024 18.48 24 16.82 24 14.98c0-3.21-2.931-5.837-6.656-6.088V8.89c-.135-.01-.27-.027-.407-.03zm-2.53 3.274c.535 0 .969.44.969.982a.976.976 0 0 1-.969.983.976.976 0 0 1-.969-.983c0-.542.434-.982.97-.982zm4.844 0c.535 0 .969.44.969.982a.976.976 0 0 1-.969.983.976.976 0 0 1-.969-.983c0-.542.434-.982.969-.982z"/></svg>',
          self::TYPE_PHONE => '<svg viewBox="0 0 24 24" width="' . $w . '" height="' . $h . '" aria-hidden="true"><path fill="currentColor" d="M6.6 10.8c1.5 2.8 3.9 5.1 6.8 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.7 3.6.7.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.3 21 3 13.7 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.2.2 2.4.7 3.6.1.3 0 .7-.2 1L6.6 10.8z"/></svg>',
          self::TYPE_EMAIL => '<svg viewBox="0 0 24 24" width="' . $w . '" height="' . $h . '" aria-hidden="true"><path fill="currentColor" d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4-8 5L4 8V6l8 5 8-5v2z"/></svg>',
          self::TYPE_VIP => '<span class="pv-fc-glyph-vip">VIP</span>',
          self::TYPE_LINK => '<svg viewBox="0 0 24 24" width="' . $w . '" height="' . $h . '" aria-hidden="true"><path fill="currentColor" d="M3.9 12c0-1.7 1.4-3.1 3.1-3.1h4V7H7c-2.8 0-5 2.2-5 5s2.2 5 5 5h4v-1.9H7c-1.7 0-3.1-1.4-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.7 0 3.1 1.4 3.1 3.1s-1.4 3.1-3.1 3.1h-4V17h4c2.8 0 5-2.2 5-5s-2.2-5-5-5z"/></svg>',
          default => '<span aria-hidden="true">•</span>',
      };
  }

  public function mediaUrl(string $path): string
  {
      $path = trim($path);
      if ($path === '') {
          return '';
      }
      if (preg_match('#^https?://#i', $path)) {
          return $path;
      }

      if (str_starts_with($path, '/')) {
          return $path;
      }

      return '/uploads/' . ltrim($path, '/');
  }

  /** @return list<array<string, mixed>> */
  public function listPublic(): array
  {
      $rows = FloatContactItem::where('status', 1)
          ->order('sort', 'asc')
          ->order('id', 'asc')
          ->select()
          ->toArray();

      return array_map([self::class, 'formatRow'], $rows);
  }

  /** @return list<array<string, mixed>> */
  public function listAdmin(): array
  {
      $rows = FloatContactItem::order('sort', 'asc')
          ->order('id', 'asc')
          ->select()
          ->toArray();

      return array_map([self::class, 'formatRow'], $rows);
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  private function formatRow(array $row): array
  {
      $type = (string) ($row['contact_type'] ?? self::TYPE_PHONE);
      if (!isset($this->typeLabels()[$type])) {
          $type = self::TYPE_PHONE;
      }

      return [
          'id'           => (int) ($row['id'] ?? 0),
          'contact_type' => $type,
          'type_text'    => $this->typeLabels()[$type],
          'label'        => (string) ($row['label'] ?? ''),
          'value'        => (string) ($row['value'] ?? ''),
          'qrcode'       => (string) ($row['qrcode'] ?? ''),
          'qrcode_url'   => $this->mediaUrl((string) ($row['qrcode'] ?? '')),
          'tip'          => (string) ($row['tip'] ?? ''),
          'sort'         => (int) ($row['sort'] ?? 0),
          'status'       => (int) ($row['status'] ?? 0),
      ];
  }

  /**
   * 模板 / API 用：在 listPublic 基础上附加 href、hint
   *
   * @return list<array<string, mixed>>
   */
  public function listPublicForTemplate(): array
  {
      $out = [];
      foreach ($this->listPublic() as $row) {
          if (!is_array($row)) {
              continue;
          }
          $out[] = $this->enrichPublicRow($row);
      }

      return $out;
  }

  /**
   * @param array<string, mixed> $criteria id | type+index | label
   * @return array<string, mixed>|null
   */
  public function resolvePublicItem(array $criteria): ?array
  {
      return $this->resolveFromList($this->listPublicForTemplate(), $criteria);
  }

  /**
   * @param list<array<string, mixed>> $items
   * @param array<string, mixed>       $criteria
   * @return array<string, mixed>|null
   */
  public function resolveFromList(array $items, array $criteria): ?array
  {
      if ($items === []) {
          return null;
      }

      $id = (int) ($criteria['id'] ?? 0);
      if ($id > 0) {
          foreach ($items as $item) {
              if ((int) ($item['id'] ?? 0) === $id) {
                  return $item;
              }
          }

          return null;
      }

      $label = trim((string) ($criteria['label'] ?? ''));
      if ($label !== '') {
          foreach ($items as $item) {
              if ((string) ($item['label'] ?? '') === $label) {
                  return $item;
              }
          }

          return null;
      }

      $type = strtolower(trim((string) ($criteria['type'] ?? '')));
      if ($type === '') {
          return null;
      }

      $index = max(0, (int) ($criteria['index'] ?? 0));
      $matched = [];
      foreach ($items as $item) {
          if ((string) ($item['contact_type'] ?? '') === $type) {
              $matched[] = $item;
          }
      }

      return $matched[$index] ?? null;
  }

  /**
   * @param array<string, mixed> $item
   * @return array{href:string,external:bool,hint:string}
   */
  public function publicAction(array $item): array
  {
      return $this->buildAction($item);
  }

  /**
   * @param array<string, mixed> $row
   * @return array<string, mixed>
   */
  public function enrichPublicRow(array $row): array
  {
      $action = $this->publicAction($row);

      return array_merge($row, [
          'href'          => (string) ($action['href'] ?? ''),
          'href_external' => !empty($action['external']) ? 1 : 0,
          'action_hint'   => (string) ($action['hint'] ?? ''),
      ]);
  }

  /**
   * @param array<string, mixed> $data
   * @return ServiceResult
   */
  public function saveAdmin(array $data): ServiceResult
  {
      $id    = (int) ($data['id'] ?? 0);
      $type  = (string) ($data['contact_type'] ?? '');
      if (!isset($this->typeLabels()[$type])) {
          return ServiceResult::fail('联系方式类型无效');
      }
      $label = mb_substr(trim((string) ($data['label'] ?? '')), 0, 80);
      $value = mb_substr(trim((string) ($data['value'] ?? '')), 0, 255);
      if ($label === '' || $value === '') {
          return ServiceResult::fail('请填写显示名称与联系内容');
      }
      $qrcode = mb_substr(
          app(\app\common\service\media\MediaUrlService::class)->formatForStorage(
              trim((string) ($data['qrcode'] ?? ''))
          ),
          0,
          500
      );
      if ($type === self::TYPE_WECHAT && $qrcode === '') {
          return ServiceResult::fail('微信请上传二维码图片');
      }
      if ($type === self::TYPE_VIP && $this->normalizeUrl($value) === '') {
          return ServiceResult::fail('VIP 专线请填写跳转链接');
      }
      $tip    = mb_substr(trim((string) ($data['tip'] ?? '')), 0, 255);
      $sort   = (int) ($data['sort'] ?? 0);
      $status = (int) ($data['status'] ?? 1) === 1 ? 1 : 0;
      $now    = AppTime::now();

      $payload = [
          'contact_type' => $type,
          'label'        => $label,
          'value'        => $value,
          'qrcode'       => $type === self::TYPE_WECHAT ? $qrcode : '',
          'tip'          => $tip,
          'sort'         => $sort,
          'status'       => $status,
          'updated_at'   => $now,
      ];

      if ($id > 0) {
          FloatContactItem::where('id', $id)->update($payload);
          $this->syncStaticHtmlAfterChange();

          return ServiceResult::ok(['id' => $id], '已更新');
      }

      $payload['created_at'] = $now;
      $newId = (int) FloatContactItem::insertGetId($payload);
      $this->syncStaticHtmlAfterChange();

      return ServiceResult::ok(['id' => $newId], '已添加');
  }

  /** @return ServiceResult */
  public function deleteAdmin(int $id): ServiceResult
  {
      if ($id < 1) {
          return ServiceResult::fail('参数无效');
      }
      FloatContactItem::where('id', $id)->delete();
      $this->syncStaticHtmlAfterChange();

      return ServiceResult::ok(null, '已删除');
  }

  /** @return ServiceResult */
  public function updateStatusAdmin(int $id, int $status): ServiceResult
  {
      if ($id < 1) {
          return ServiceResult::fail('参数无效');
      }
      FloatContactItem::where('id', $id)->update([
          'status'     => $status === 1 ? 1 : 0,
          'updated_at' => AppTime::now(),
      ]);
      $this->syncStaticHtmlAfterChange();

      return ServiceResult::ok(null, '状态已更新');
  }

  /** @return ServiceResult */
  public function updateSortAdmin(int $id, int $sort): ServiceResult
  {
      if ($id < 1) {
          return ServiceResult::fail('参数无效');
      }
      FloatContactItem::where('id', $id)->update([
          'sort'       => $sort,
          'updated_at' => AppTime::now(),
      ]);
      $this->syncStaticHtmlAfterChange();

      return ServiceResult::ok(null, '排序已更新');
  }

  private function syncStaticHtmlAfterChange(): void
  {
      app(StaticHtmlDispatch::class)->afterGlobalEmbedChange();
  }
}
