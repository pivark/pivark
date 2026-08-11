<?php
/**
 * 元舟 PivArk — 上传场景与类型配置（全站 SSOT）
 *
 * 概念分层：
 *   type（类型）    → 允许后缀、MIME（对应后台三条格式配置）
 *   scene（场景）   → 存储子目录、业务归属（全端共用，与终端无关）
 *   terminal（终端）→ 谁发起请求、走哪条 HTTP、何种鉴权（见 terminals）
 *
 * UploadService / MediaLibraryService 在 app/common，**不绑定 admin**。
 * 后台 mediaPicker 仅是 admin 终端的一种 UI。
 */
declare(strict_types=1);

return [
    'public_dir' => 'uploads',

    // 默认禁止 SVG 上传（防存储型 XSS）；须在配置式 true 且后台格式含 svg 时才允许（上传时仍经 SvgSanitizer）
    'allow_svg_upload' => false,

    /**
     * 终端/渠道（分发面）— 同一 scene 可被多终端调用，鉴权在入口层
     */
    'terminals' => [
        'admin' => [
            'label'       => '管理后台',
            'auth'        => 'admin_session',
            'upload_http' => ['/admin/upload/image', '/admin/upload/file', '/admin/upload/video', '/admin/document/upload'],
            'media_http'  => 'GET /admin/media/list',
            'ui'          => 'vue.mediaLibrary',
            'status'      => 'active',
        ],
        'home' => [
            'label'       => '前台站点 / H5',
            'auth'        => 'member',
            'upload_http' => ['POST /api/v1/upload'],
            'media_http'  => 'GET /api/v1/media',
            'ui'          => '各模板/组件自选',
            'status'      => 'planned',
        ],
        'dealer' => [
            'label'       => '经销商端',
            'auth'        => 'dealer',
            'upload_http' => ['POST /api/v1/upload'],
            'media_http'  => 'GET /api/v1/media',
            'status'      => 'planned',
        ],
        'oa' => [
            'label'       => 'OA / 办公',
            'auth'        => 'oa_user',
            'upload_http' => ['POST /api/v1/upload'],
            'media_http'  => 'GET /api/v1/media',
            'status'      => 'planned',
        ],
        'miniprogram' => [
            'label'       => '微信小程序等',
            'auth'        => 'member|admin',
            'upload_http' => ['POST /api/v1/upload'],
            'status'      => 'planned',
        ],
        'plugin' => [
            'label'       => '插件子系统',
            'auth'        => 'plugin_policy',
            'upload_http' => ['POST /api/v1/upload', '/admin/upload/image'],
            'status'      => 'planned',
        ],
    ],

    'config_keys' => [
        'max_size'  => 'upload_max_size',
        'name_rule' => 'upload_name_rule',
        'dir_rule'  => 'upload_dir_rule',
    ],

    /**
     * 上传类型（与后台三个格式输入框一一对应）
     */
    'types' => [
        'image' => [
            'label'           => '图片',
            'formats_key'     => 'upload_image_format',
            'default_formats' => 'jpg|gif|png|bmp|jpeg|ico|webp',
            'description'     => '站点图片：LOGO、缩略图、编辑器插图、自定义变量图片等',
            'mimes'           => [
                'jpg'  => ['image/jpeg', 'image/pjpeg'],
                'jpeg' => ['image/jpeg', 'image/pjpeg'],
                'png'  => ['image/png'],
                'gif'  => ['image/gif'],
                'bmp'  => ['image/bmp', 'image/x-ms-bmp'],
                'ico'  => ['image/x-icon', 'image/vnd.microsoft.icon'],
                'webp' => ['image/webp'],
                'svg'  => ['image/svg+xml', 'text/xml', 'application/xml'],
            ],
            'security_note' => 'svg 可含脚本，仅信任来源；前台输出需防 XSS',
        ],
        'software' => [
            'label'           => '软件附件',
            'formats_key'     => 'upload_software_format',
            'default_formats' => 'zip|gz|rar|doc|docx|xls|xlsx|ppt|wps|pdf|txt',
            'description'     => '可下载文档、压缩包等（非图片、非音视频）',
            'mimes'           => [
                'zip'  => ['application/zip', 'application/x-zip-compressed'],
                'gz'   => ['application/gzip', 'application/x-gzip'],
                'rar'  => ['application/x-rar-compressed', 'application/vnd.rar'],
                'doc'  => ['application/msword'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                'xls'  => ['application/vnd.ms-excel'],
                'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                'ppt'  => ['application/vnd.ms-powerpoint'],
                'wps'  => ['application/vnd.ms-works', 'application/octet-stream'],
                'pdf'  => ['application/pdf'],
                'txt'  => ['text/plain'],
            ],
        ],
        'video' => [
            'label'           => '多媒体',
            'formats_key'     => 'upload_video_format',
            'default_formats' => 'swf|mpg|mp3|rm|rmvb|wmv|wma|wav|mid|mov|mp|mp4',
            'description'     => '音频、视频及历史格式（swf/rm 等）',
            'mimes'           => [
                'swf'  => ['application/x-shockwave-flash'],
                'mpg'  => ['video/mpeg'],
                'mp3'  => ['audio/mpeg', 'audio/mp3'],
                'rm'   => ['application/vnd.rn-realmedia', 'audio/x-pn-realaudio'],
                'rmvb' => ['application/vnd.rn-realmedia-vbr', 'video/x-pn-realvideo'],
                'wmv'  => ['video/x-ms-wmv'],
                'wma'  => ['audio/x-ms-wma'],
                'wav'  => ['audio/wav', 'audio/x-wav'],
                'mid'  => ['audio/midi', 'audio/mid'],
                'mov'  => ['video/quicktime'],
                'mp'   => ['video/mpeg'],
                'mp4'  => ['video/mp4'],
            ],
        ],
    ],

    /**
     * 业务场景登记表
     *
     * picker：后台是否推荐「素材库选图」（image 类一般为 true）
     * upload_route：后台表单直传使用的 POST 地址
     * upload_scene_param：直传时附加的场景参数名（与 upload_route 配合）
     */
    'scenes' => [
        'general' => [
            'type'               => 'image',
            'subdir'             => '',
            'use_dir_rule'       => true,
            'label'              => '通用图片',
            'usage'              => '网站 LOGO、系统配置、自定义变量（图片类型）',
            'picker'             => true,
            'upload_route'       => '/admin/upload/image',
            'allowed_terminals'  => ['admin', 'home', 'miniprogram', 'plugin'],
            'status'             => 'active',
        ],
        'document' => [
            'type'               => 'image',
            'subdir'             => '',
            'use_dir_rule'       => true,
            'label'              => '文章内容图',
            'usage'              => '文章缩略图 litpic；正文插图（编辑器接入后同场景）',
            'picker'             => true,
            'upload_route'       => '/admin/document/upload',
            'allowed_terminals'  => ['admin', 'home'],
            'status'             => 'active',
        ],
        'editor' => [
            'type'               => 'image',
            'subdir'             => '',
            'use_dir_rule'       => true,
            'label'              => '编辑器插图',
            'usage'              => 'wangEditor / Vditor 正文插图',
            'picker'             => true,
            'upload_route'       => '/admin/upload/image',
            'upload_scene_param' => 'scene',
            'allowed_terminals'  => ['admin'],
            'status'             => 'active',
        ],
        'attachment' => [
            'type'               => 'software',
            'subdir'             => '',
            'use_dir_rule'       => true,
            'label'              => '软件附件',
            'usage'              => '资料下载、表单附件；规划 attachments 表后入库',
            'picker'             => false,
            'upload_route'       => '/admin/upload/file',
            'allowed_terminals'  => ['admin', 'oa', 'plugin'],
            'status'             => 'active',
        ],
        'media' => [
            'type'               => 'video',
            'subdir'             => '',
            'use_dir_rule'       => true,
            'label'              => '音视频',
            'usage'              => '自定义变量「多媒体」、视频字段；大文件走 URL 或嵌入代码',
            'picker'             => false,
            'upload_route'       => '/admin/upload/video',
            'allowed_terminals'  => ['admin', 'home', 'plugin'],
            'status'             => 'active',
        ],
        'user' => [
            'type'               => 'image',
            'subdir'             => '',
            'use_dir_rule'       => true,
            'label'              => '用户头像',
            'usage'              => '前台会员中心头像',
            'picker'             => false,
            'upload_route'       => '/api/v1/member/upload/image',
            'upload_scene_param' => 'scene',
            'allowed_terminals'  => ['home'],
            'status'             => 'active',
        ],
        'dealer' => [
            'type'               => 'image',
            'subdir'             => '',
            'use_dir_rule'       => true,
            'label'              => '经销商',
            'usage'              => '经销商后台证照、门头图等（预留）',
            'picker'             => true,
            'upload_route'       => '/admin/upload/image',
            'upload_scene_param' => 'scene',
            'allowed_terminals'  => ['dealer'],
            'status'             => 'reserved',
        ],
        'oa' => [
            'type'               => 'software',
            'subdir'             => '',
            'use_dir_rule'       => true,
            'label'              => 'OA 附件',
            'usage'              => 'OA 审批附件、公文（预留，可按模块再分子场景）',
            'picker'             => false,
            'upload_route'       => '/admin/upload/file',
            'upload_scene_param' => 'scene',
            'allowed_terminals'  => ['oa'],
            'status'             => 'reserved',
        ],
    ],

    // 插件场景命名：plugin.{identifier}，type/subdir 在插件 install 时 registerScene 或合并配置
    'plugin_scene_pattern' => '/^plugin\.[a-z][a-z0-9_-]{1,31}$/',

    'editor_content_keys' => [
        'wap_adapt'   => 'upload_wap_adapt',
        'add_title'   => 'upload_add_title',
        'add_alt'     => 'upload_add_alt',
        'alt_replace' => 'upload_alt_replace',
    ],

    'dangerous_extensions' => [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phps', 'pht', 'phar', 'shtml', 'inc',
        'exe', 'sh', 'bat', 'cmd', 'com', 'dll', 'js', 'jsp', 'asp', 'aspx', 'htaccess',
    ],

    /** 内容哈希去重（media_assets 表） */
    'dedup' => [
        'enabled' => true,
    ],

    
    'purge' => [
        'scan_mode' => 'db',
    ],

    /** 分片上传 / 断点续传 */
    'chunk' => [
        'enabled'       => true,
        'threshold_mb'  => 10,
        'part_size_mb'  => 2,
        'ttl_seconds'   => 86400,
        'max_parts'     => 10000,
        'max_file_gb'   => 2,
    ],
];
