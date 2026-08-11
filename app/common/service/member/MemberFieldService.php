<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\member;

use app\common\support\AppTime;

use app\common\support\QueryLimit;
use app\common\support\ServiceResult;
use app\common\model\MemberFieldValue;

use app\common\model\MemberField;
use app\common\service\admin\AdminSpaMemberFormMetaCacheService;
use app\common\support\AdminListParams;
use app\common\support\HtmlSanitizer;

/** 会员自定义字段（系统默认：用户名/手机/邮箱等；此处为扩展字段） */
class MemberFieldService
{

    public function __construct(
        private readonly AdminSpaMemberFormMetaCacheService $memberFormMetaCache,
    ) {
    }

    private const FIELD_KEY_HASH_LENGTH = 8;

    /** @var list<string> */
    public const TYPES = ['text', 'textarea', 'select', 'radio', 'checkbox', 'number', 'date'];

    /** @var array<string, string> */
    public const TYPE_LABELS = [
        'text'     => '单行文本',
        'textarea' => '多行文本',
        'select'   => '下拉框',
        'radio'    => '单选项',
        'checkbox' => '多选项',
        'number'   => '数字',
        'date'     => '日期',
    ];

    /**
     * @return list<array{value:string,label:string,disabled?:bool,hint?:string}>
     */
    public function typeOptionsForAdmin(): array
    {
        $out = [];
        foreach (self::TYPES as $type) {
            $out[] = [
                'value' => $type,
                'label' => self::TYPE_LABELS[$type] ?? $type,
            ];
        }
        foreach (
            [
                ['image', '单张图'],
                ['images', '多张图'],
                ['file', '附件'],
                ['datetime', '日期和时间'],
            ] as [$value, $label]
        ) {
            $out[] = ['value' => $value, 'label' => $label, 'disabled' => true, 'hint' => '即将支持'];
        }

        return $out;
    }

    public function typeLabel(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? $type;
    }

    public function needsOptions(string $type): bool
    {
        return in_array($type, ['select', 'radio', 'checkbox'], true);
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
        $query = MemberField::order('sort', 'asc')->order('id', 'asc');
        AdminListParams::applyKeyword($query, $p['keyword'], 'field_key|label|field_type');
        $total = (int) $query->count();
        $rows  = $query->page($p['page'], $p['limit'])->select()->toArray();
        foreach ($rows as &$row) {
            $row['field_type_label'] = $this->typeLabel((string) ($row['field_type'] ?? ''));
        }
        unset($row);

        return ['list' => $rows, 'total' => $total, 'page' => $p['page'], 'limit' => $p['limit']];
    }

    /** @return list<array<string, mixed>> */
    public function listActiveForRegister(): array
    {
        return MemberField::where('status', 1)->where('show_register', 1)
            ->order('sort', 'asc')->select()->toArray();
    }

    /** @return list<array<string, mixed>> */
    public function listActiveAll(): array
    {
        return MemberField::where('status', 1)->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
    }

    /**
     * @param list<array<string, mixed>> $fields
     * @param array<int, string> $valuesMap
     */
    public function renderFrontFieldsHtml(array $fields, array $valuesMap = []): string
    {
        $html = '';
        foreach ($fields as $field) {
            $fid = (int) ($field['id'] ?? 0);
            $key = (string) ($field['field_key'] ?? '');
            $label = htmlspecialchars((string) ($field['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $name = 'mf_' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
            $default = htmlspecialchars((string) ($field['default_value'] ?? ''), ENT_QUOTES, 'UTF-8');
            $val = (string) ($valuesMap[$fid] ?? '');
            if ($val === '' && $default !== '') {
                $val = $default;
            }
            $valEsc = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
            $req = (int) ($field['is_required'] ?? 0) === 1 ? ' required' : '';
            $type = (string) ($field['field_type'] ?? 'text');
            $html .= '<div class="mb-3"><label class="form-label">' . $label;
            if ($req !== '') {
                $html .= ' <span class="text-danger">*</span>';
            }
            $html .= '</label>';
            if ($type === 'textarea') {
                $html .= '<textarea name="' . $name . '" class="form-control" rows="3"' . $req . '>' . $valEsc . '</textarea>';
            } elseif ($type === 'select') {
                $html .= '<select name="' . $name . '" class="form-select"' . $req . '><option value="">请选择</option>';
                foreach ($this->optionsList((string) ($field['options'] ?? '')) as $opt) {
                    $optEsc = htmlspecialchars($opt, ENT_QUOTES, 'UTF-8');
                    $sel = $valEsc === $optEsc ? ' selected' : '';
                    $html .= '<option value="' . $optEsc . '"' . $sel . '>' . $optEsc . '</option>';
                }
                $html .= '</select>';
            } elseif ($type === 'radio') {
                foreach ($this->optionsList((string) ($field['options'] ?? '')) as $i => $opt) {
                    $optEsc = htmlspecialchars($opt, ENT_QUOTES, 'UTF-8');
                    $idAttr = htmlspecialchars($name . '_' . $i, ENT_QUOTES, 'UTF-8');
                    $chk = $valEsc === $optEsc ? ' checked' : '';
                    $html .= '<div class="form-check"><input class="form-check-input" type="radio" name="' . $name
                        . '" id="' . $idAttr . '" value="' . $optEsc . '"' . $chk . $req . '><label class="form-check-label" for="'
                        . $idAttr . '">' . $optEsc . '</label></div>';
                }
            } elseif ($type === 'checkbox') {
                $selected = array_flip(array_map('trim', explode(',', $val)));
                foreach ($this->optionsList((string) ($field['options'] ?? '')) as $i => $opt) {
                    $optEsc = htmlspecialchars($opt, ENT_QUOTES, 'UTF-8');
                    $idAttr = htmlspecialchars($name . '_' . $i, ENT_QUOTES, 'UTF-8');
                    $chk = isset($selected[$opt]) ? ' checked' : '';
                    $html .= '<div class="form-check"><input class="form-check-input" type="checkbox" name="' . $name
                        . '[]" id="' . $idAttr . '" value="' . $optEsc . '"' . $chk . '><label class="form-check-label" for="'
                        . $idAttr . '">' . $optEsc . '</label></div>';
                }
            } elseif ($type === 'number') {
                $html .= '<input type="number" name="' . $name . '" class="form-control" value="' . $valEsc . '"' . $req . '>';
            } elseif ($type === 'date') {
                $html .= '<input type="date" name="' . $name . '" class="form-control" value="' . $valEsc . '"' . $req . '>';
            } else {
                $html .= '<input type="text" name="' . $name . '" class="form-control" value="' . $valEsc . '"' . $req . '>';
            }
            $html .= '</div>';
        }

        return $html;
    }

    /** @return list<string> */
    public function optionsList(string $options): array
    {
        $parts = array_map('trim', explode(',', $options));
        $out = [];
        foreach ($parts as $part) {
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function listActiveForProfile(): array
    {
        return MemberField::where('status', 1)->where('show_profile', 1)
            ->order('sort', 'asc')->select()->toArray();
    }

    /** @return array<string, mixed>|null */
    public function findAdmin(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = MemberField::find($id);

        return $row ? $row->toArray() : null;
    }

    public function suggestFieldKey(string $label): string
    {
        $slug = strtolower(trim($label));
        $slug = preg_replace('/[^a-z0-9_]+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');
        if ($slug !== '' && preg_match('/^[a-z][a-z0-9_]{1,30}$/', $slug)) {
            return $slug;
        }

        return 'field_' . substr(md5($label . microtime(true)), 0, self::FIELD_KEY_HASH_LENGTH);
    }

    /**
     * @param array<string, mixed> $data
     * @return ServiceResult
     */
    public function saveAdmin(array $data): ServiceResult
    {
        $id = max(0, (int) ($data['id'] ?? 0));
        $label = trim((string) ($data['label'] ?? ''));
        $fieldKey = strtolower(trim((string) ($data['field_key'] ?? '')));
        if ($label === '' || mb_strlen($label) > 50) {
            return ServiceResult::fail('字段标题须为 1~50 字');
        }
        if ($fieldKey === '') {
            $fieldKey = $this->suggestFieldKey($label);
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,30}$/', $fieldKey)) {
            return ServiceResult::fail('字段标识须为小写字母开头 2~31 位');
        }
        $fieldType = (string) ($data['field_type'] ?? 'text');
        if (!in_array($fieldType, self::TYPES, true)) {
            return ServiceResult::fail('字段类型无效');
        }
        if ($this->needsOptions($fieldType) && trim((string) ($data['options'] ?? '')) === '') {
            return ServiceResult::fail('请填写选项（逗号分隔）');
        }

        $exists = MemberField::where('field_key', $fieldKey);
        if ($id > 0) {
            $exists->where('id', '<>', $id);
        }
        if ($exists->count() > 0) {
            return ServiceResult::fail('字段标识已存在');
        }

        $payload = [
            'label'          => $label,
            'field_key'      => $fieldKey,
            'field_type'     => $fieldType,
            'options'        => HtmlSanitizer::cleanPlainText((string) ($data['options'] ?? ''), 500),
            'default_value'  => HtmlSanitizer::cleanPlainText((string) ($data['default_value'] ?? ''), 500),
            'is_required'    => (int) ($data['is_required'] ?? 0) === 1 ? 1 : 0,
            'show_register'  => (int) ($data['show_register'] ?? 0) === 1 ? 1 : 0,
            'show_profile'   => (int) ($data['show_profile'] ?? 1) === 1 ? 1 : 0,
            'sort'           => (int) ($data['sort'] ?? 0),
            'status'         => (int) ($data['status'] ?? 1) === 1 ? 1 : 0,
            'updated_at'     => AppTime::now(),
        ];

        if ($id > 0 && !$this->findAdmin($id)) {
            return ServiceResult::fail('字段不存在');
        }

        if ($id > 0) {
            MemberField::where('id', $id)->update($payload);
        } else {
            $payload['created_at'] = $payload['updated_at'];
            $id = (int) MemberField::insertGetId($payload);
        }
        $this->memberFormMetaCache->bust();

        return ServiceResult::ok(['id' => $id], '保存成功');
    }

    /** @return ServiceResult */
    public function deleteAdmin(int $id): ServiceResult
    {
        if ($id < 1 || !$this->findAdmin($id)) {
            return ServiceResult::fail('字段不存在');
        }
        MemberFieldValue::where('field_id', $id)->delete();
        MemberField::where('id', $id)->delete();
        $this->memberFormMetaCache->bust();

        return ServiceResult::ok(null, '删除成功');
    }

    /** @return array<int, string> field_id => value */
    public function valuesMapForUser(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }
        $rows = MemberFieldValue::where('user_id', $userId)->select()->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['field_id']] = (string) ($row['value'] ?? '');
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $post
     * @return ServiceResult
     */
    public function validateAndSyncUser(int $userId, array $post, string $scene): ServiceResult
    {
        if ($userId < 1) {
            return ServiceResult::fail('用户无效');
        }
        $fields = match ($scene) {
            'register' => $this->listActiveForRegister(),
            'admin'    => $this->listActiveAll(),
            default    => $this->listActiveForProfile(),
        };
        $now = AppTime::now();
        foreach ($fields as $field) {
            $fid = (int) ($field['id'] ?? 0);
            $key = (string) ($field['field_key'] ?? '');
            $type = (string) ($field['field_type'] ?? 'text');
            if ($type === 'checkbox') {
                $raw = $post['mf_' . $key] ?? $post[$key] ?? [];
                $val = is_array($raw)
                    ? implode(',', array_map('trim', $raw))
                    : trim((string) $raw);
            } else {
                $val = trim((string) ($post['mf_' . $key] ?? $post[$key] ?? ''));
            }
            if ((int) ($field['is_required'] ?? 0) === 1 && $val === '') {
                return ServiceResult::fail('请填写' . ($field['label'] ?? '扩展字段'));
            }
            if ($val === '') {
                MemberFieldValue::where('user_id', $userId)->where('field_id', $fid)->delete();
                continue;
            }
            $exists = MemberFieldValue::where('user_id', $userId)->where('field_id', $fid)->find();
            if ($exists) {
                MemberFieldValue::where('id', (int) $exists['id'])->update(['value' => $val, 'updated_at' => $now]);
            } else {
                MemberFieldValue::insert([
                    'user_id'    => $userId,
                    'field_id'   => $fid,
                    'value'      => $val,
                    'updated_at' => $now,
                ]);
            }
        }

        return ServiceResult::ok(null, 'ok');
    }
}
