<?php
/**
 * 元舟 PivArk — 企业全域原子化数字资产中枢
 * (c) 2024-2026 pivark.com. All rights reserved.
 * Author: Angelo
 * 未经允许，不可用于商业用途。
 */
declare(strict_types=1);

namespace app\common\service\ai;

/**
 * 内置大模型服务商（OpenAI 兼容 Chat Completions 协议为主）
 * 配置键：{id}_api_key / {id}_model / {id}_base_url
 */
final class AiProviderCatalog
{

    /** @return array<string, array{name:string,default_model:string,default_base_url:string,hint?:string}> */
    public function all(): array
    {
        return [
            'custom' => [
                'name'             => '自定义（OpenAI 兼容）',
                'default_model'    => '',
                'default_base_url' => '',
                'hint'             => '任意兼容 Chat Completions 的接口：填写 Base URL（通常以 /v1 结尾）、模型名与 API Key',
                'embedding'        => true,
            ],
            'deepseek' => [
                'name'              => 'DeepSeek',
                'default_model'     => 'deepseek-chat',
                'default_base_url'  => 'https://api.deepseek.com/v1',
                'embedding'         => false,
            ],
            'openai' => [
                'name'              => 'OpenAI',
                'default_model'     => 'gpt-4o-mini',
                'default_base_url'  => 'https://api.openai.com/v1',
                'embedding_model'   => 'text-embedding-3-small',
                'embedding'         => true,
            ],
            'doubao' => [
                'name'              => '豆包（火山方舟）',
                'default_model'     => 'doubao-1-5-pro-32k',
                'default_base_url'  => 'https://ark.cn-beijing.volces.com/api/v3',
                'hint'              => '模型名填方舟推理接入点 ID',
                'embedding_model'   => 'doubao-embedding-text-240715',
                'embedding'         => true,
            ],
            'zhipu' => [
                'name'              => '智谱 AI',
                'default_model'     => 'glm-4-flash',
                'default_base_url'  => 'https://open.bigmodel.cn/api/paas/v4',
                'embedding_model'   => 'embedding-3',
                'embedding'         => true,
            ],
            'qwen' => [
                'name'              => '通义千问',
                'default_model'     => 'qwen-turbo',
                'default_base_url'  => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
                'embedding_model'   => 'text-embedding-v3',
                'embedding'         => true,
            ],
            'moonshot' => [
                'name'              => 'Moonshot / Kimi',
                'default_model'     => 'moonshot-v1-8k',
                'default_base_url'  => 'https://api.moonshot.cn/v1',
                'embedding'         => false,
            ],
            'minimax' => [
                'name'              => 'MiniMax',
                'default_model'     => 'abab6.5s-chat',
                'default_base_url'  => 'https://api.minimax.chat/v1',
                'embedding'         => false,
            ],
            'baichuan' => [
                'name'              => '百川智能',
                'default_model'     => 'Baichuan4-Turbo',
                'default_base_url'  => 'https://api.baichuan-ai.com/v1',
                'embedding'         => false,
            ],
            'hunyuan' => [
                'name'              => '腾讯混元',
                'default_model'     => 'hunyuan-lite',
                'default_base_url'  => 'https://api.hunyuan.cloud.tencent.com/v1',
                'embedding'         => false,
            ],
            'siliconflow' => [
                'name'              => '硅基流动',
                'default_model'     => 'deepseek-ai/DeepSeek-V3',
                'default_base_url'  => 'https://api.siliconflow.cn/v1',
                'hint'              => '聚合多家开源模型',
                'embedding_model'   => 'BAAI/bge-m3',
                'embedding'         => true,
            ],
            'yi' => [
                'name'              => '零一万物',
                'default_model'     => 'yi-lightning',
                'default_base_url'  => 'https://api.lingyiwanwu.com/v1',
                'embedding'         => false,
            ],
        ];
    }

    public function exists(string $id): bool
    {
        return isset($this->all()[$id]);
    }

    /** @return array{name:string,default_model:string,default_base_url:string,hint?:string} */
    public function get(string $id): array
    {
        return $this->all()[$id] ?? $this->all()['deepseek'];
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->all());
    }

    /** 当前服务商是否提供 OpenAI 兼容 /embeddings 接口 */
    public function supportsEmbeddings(string $id): bool
    {
        $row = $this->all()[$id] ?? null;

        return is_array($row) && !empty($row['embedding']);
    }
}
