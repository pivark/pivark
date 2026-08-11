-- PivArk Community empty schema (DDL only)
-- INTENTIONAL DUAL-TRACK: generated from live structure for customer install zip.
-- Dev SSOT remains devtools/daily/schema/migrations/. See docs/_team/05-协作/会话落盘/2026-07-20-Community装库双轨.md
-- Generated: 2026-08-11T12:00:03+00:00
-- Source tables: 88
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE `__DB_PREFIX__admin_user_nav_scopes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '后台用户ID',
  `nav_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '可管理的栏目ID（含子孙）',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_nav` (`user_id`,`nav_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_nav_id` (`nav_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员栏目数据范围';

CREATE TABLE `__DB_PREFIX__admin_user_tag_scopes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '后台用户ID',
  `tag_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '可管理的标签ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_tag` (`user_id`,`tag_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_tag_id` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员标签数据范围';

CREATE TABLE `__DB_PREFIX__ai_chunks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int(10) unsigned NOT NULL,
  `chunk_index` int(10) unsigned NOT NULL DEFAULT '0',
  `content` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `content_hash` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `token_estimate` int(10) unsigned DEFAULT NULL,
  `embedding_json` json DEFAULT NULL COMMENT '无 pgvector 时可选存本地向量',
  `meta_json` json DEFAULT NULL,
  `created_at` int(10) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_doc` (`document_id`),
  KEY `idx_doc_idx` (`document_id`,`chunk_index`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `__DB_PREFIX__ai_config_process` (
  `document_id` int(10) unsigned NOT NULL COMMENT '文档 ID',
  `status` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT 'pending|processing|ok|extract_failed|chunk_failed|meta_failed',
  `plain_chars` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '纯文本字符数',
  `chunk_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '分块数量',
  `error_msg` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '错误信息',
  `updated_at` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`document_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='AI文档处理状态表';

CREATE TABLE `__DB_PREFIX__catalog_facet_stats` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `domain` varchar(64) NOT NULL COMMENT '业务域 items|erp_inventory_ledger|…',
  `scope_key` varchar(64) NOT NULL DEFAULT '' COMMENT '范围键 tag slug / 仓库 ID 等',
  `param_key` varchar(64) NOT NULL COMMENT '筛选参数键',
  `attr_value` varchar(255) NOT NULL COMMENT '参数值',
  `row_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '命中行数',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_domain_scope_param_value` (`domain`,`scope_key`,`param_key`,`attr_value`),
  KEY `idx_domain_scope_param` (`domain`,`scope_key`,`param_key`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='跨域 Catalog Facet 计数';

CREATE TABLE `__DB_PREFIX__configs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `group` varchar(50) NOT NULL DEFAULT 'system' COMMENT '配置分组',
  `key` varchar(100) NOT NULL COMMENT '配置键名',
  `value` text COMMENT '配置值',
  `description` varchar(255) DEFAULT NULL COMMENT '配置说明',
  `type` varchar(20) NOT NULL DEFAULT 'string' COMMENT '值类型（string/json/number/boolean）',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='系统配置表';

CREATE TABLE `__DB_PREFIX__config_secrets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `key` varchar(100) NOT NULL COMMENT 'configs 键名',
  `value` text NOT NULL COMMENT 'AppCipher 加密值',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统凭据（加密存储）';

CREATE TABLE `__DB_PREFIX__cron_jobs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` varchar(100) NOT NULL COMMENT '任务名称',
  `handler` varchar(64) NOT NULL COMMENT '内置处理器标识',
  `interval_minutes` int(10) unsigned NOT NULL DEFAULT '60' COMMENT '执行间隔（分钟）',
  `payload` json DEFAULT NULL COMMENT '扩展参数',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '0禁用 1启用',
  `last_run_at` datetime DEFAULT NULL COMMENT '上次执行',
  `next_run_at` datetime DEFAULT NULL COMMENT '下次计划执行',
  `last_status` varchar(20) DEFAULT NULL COMMENT '上次结果 ok|fail|skip',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_handler` (`handler`),
  KEY `idx_status_next` (`status`,`next_run_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='定时任务';

CREATE TABLE `__DB_PREFIX__cron_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `job_id` int(10) unsigned NOT NULL COMMENT '任务ID',
  `status` varchar(20) NOT NULL COMMENT 'ok|fail|skip',
  `message` text COMMENT 'ժҪ',
  `started_at` datetime NOT NULL COMMENT '开始时间',
  `finished_at` datetime DEFAULT NULL COMMENT '结束时间',
  `duration_ms` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '耗时毫秒',
  PRIMARY KEY (`id`),
  KEY `idx_job_started` (`job_id`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='定时任务日志';

CREATE TABLE `__DB_PREFIX__documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `title` varchar(200) NOT NULL COMMENT '文档标题',
  `subtitle` varchar(200) NOT NULL DEFAULT '' COMMENT '副标题',
  `summary` varchar(500) DEFAULT NULL COMMENT '摘要（列表/API用）',
  `search_text` mediumtext COMMENT '全文检索纯文本（保存时从正文抽取，非 HTML）',
  `source` varchar(100) NOT NULL DEFAULT '' COMMENT '来源说明',
  `extra_json` json DEFAULT NULL COMMENT '文档扩展字段键值JSON',
  `attr_flags` varchar(120) NOT NULL DEFAULT '' COMMENT '文档属性 CSV：headline,recommend,push,bold,has_image,external',
  `external_url` varchar(500) NOT NULL DEFAULT '' COMMENT '外链地址；勾选 external 且前台打开时跳转',
  `external_open_new_tab` tinyint(4) NOT NULL DEFAULT '0' COMMENT '外链打开方式：0当前窗口 1新窗口',
  `read_perm` tinyint(4) NOT NULL DEFAULT '0' COMMENT '阅读权限：0开放 1受限（登录或等级）',
  `read_level_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '最低会员等级ID，0=仅登录',
  `tpl_name` varchar(100) NOT NULL DEFAULT '' COMMENT '前台模板文件名，如 document_view.php',
  `html_name` varchar(120) NOT NULL DEFAULT '' COMMENT '自定义 URL 段；空则 /documents/{id}',
  `url_path` varchar(100) NOT NULL DEFAULT '' COMMENT '前台自定义路径，留空走 /documents',
  `content` longtext COMMENT 'PC 正文（富文本 HTML 或 Markdown 源码）',
  `content_mobile` longtext COMMENT '手机端正文；空则前台用 PC 正文',
  `litpic` varchar(255) DEFAULT '' COMMENT '缩略图',
  `seo_title` varchar(200) DEFAULT '' COMMENT 'SEO 标题',
  `seo_keywords` varchar(255) DEFAULT '' COMMENT 'SEO 关键词',
  `seo_description` varchar(500) DEFAULT '' COMMENT 'SEO 描述',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态：0草稿 1发布',
  `author_id` int(10) unsigned DEFAULT '0' COMMENT '后台作者用户 ID',
  `nav_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '真分类 site_nav.id，0=未归类',
  `author_name` varchar(50) NOT NULL DEFAULT '' COMMENT '前台展示署名',
  `click` int(10) unsigned DEFAULT '0' COMMENT '点击数',
  `published_at` datetime DEFAULT NULL COMMENT '发布时间',
  `schedule_publish_at` datetime DEFAULT NULL COMMENT '定时发布时间',
  `schedule_offline_at` datetime DEFAULT NULL COMMENT '定时下线时间',
  `deleted_at` datetime DEFAULT NULL COMMENT '软删除时间，NULL=未删',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_title` (`title`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_doc_admin_list` (`deleted_at`,`status`,`id`),
  KEY `idx_doc_public_list` (`deleted_at`,`status`,`published_at`),
  KEY `idx_nav_id` (`nav_id`),
  FULLTEXT KEY `ft_document_search` (`search_text`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='文档表';

CREATE TABLE `__DB_PREFIX__document_attr_flags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `document_id` int(10) unsigned NOT NULL COMMENT '文档 ID',
  `flag` varchar(32) NOT NULL COMMENT '属性标记',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_flag` (`document_id`,`flag`),
  KEY `idx_flag_document` (`flag`,`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文档属性标记索引';

CREATE TABLE `__DB_PREFIX__document_item_refs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `document_id` int(10) unsigned NOT NULL COMMENT '文档 ID',
  `item_id` int(10) unsigned NOT NULL COMMENT '品项 ID',
  `role` varchar(32) NOT NULL DEFAULT 'primary' COMMENT 'primary/drawing/gallery/cert…',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_document` (`document_id`),
  KEY `idx_item` (`item_id`),
  KEY `idx_role` (`role`),
  KEY `idx_dir_item_doc` (`item_id`,`document_id`),
  KEY `idx_dir_document_sort` (`document_id`,`sort`),
  KEY `idx_dir_item` (`item_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='文档-品项关联';

CREATE TABLE `__DB_PREFIX__document_navs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '文档ID',
  `nav_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '附加栏目 site_nav.id',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_nav` (`document_id`,`nav_id`),
  KEY `idx_nav_id` (`nav_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文档附加栏目';

CREATE TABLE `__DB_PREFIX__document_param_group_refs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `document_id` int(10) unsigned NOT NULL COMMENT '文档 ID',
  `group_id` int(10) unsigned NOT NULL COMMENT 'product_param_groups.id',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_doc_group` (`document_id`,`group_id`),
  KEY `idx_document` (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文档启用的产品参数组';

CREATE TABLE `__DB_PREFIX__document_product_images` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '文档ID',
  `url` varchar(512) NOT NULL DEFAULT '' COMMENT '图片路径',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序升序',
  `is_cover` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '1=封面(与 documents.litpic 一致)',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_document_sort` (`document_id`,`sort`),
  KEY `idx_document_cover` (`document_id`,`is_cover`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='文档产品多图(非图集)';

CREATE TABLE `__DB_PREFIX__document_product_settings` (
  `document_id` int(10) unsigned NOT NULL COMMENT '文档 ID',
  `layout_mode` varchar(20) NOT NULL DEFAULT 'single' COMMENT 'single单型号 multi_spec多规格 multi_model多型号',
  `accessory_section_label` varchar(64) NOT NULL DEFAULT '零配件' COMMENT '第5步关联品项区块标题（可自定义）',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文档产品展示模式';

CREATE TABLE `__DB_PREFIX__document_related_refs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `document_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '源文档 ID',
  `related_document_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '相关文档 ID',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_doc_related` (`document_id`,`related_document_id`),
  KEY `idx_drr_document` (`document_id`),
  KEY `idx_drr_related` (`related_document_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='文档手动相关阅读';

CREATE TABLE `__DB_PREFIX__document_tags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `document_id` int(10) unsigned NOT NULL COMMENT '文档 ID',
  `tag_id` int(10) unsigned NOT NULL COMMENT '标签 ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_document_tag` (`document_id`,`tag_id`),
  KEY `idx_document_id` (`document_id`),
  KEY `idx_tag_id` (`tag_id`),
  KEY `idx_dt_tag_document` (`tag_id`,`document_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='文档标签关联表';

CREATE TABLE `__DB_PREFIX__domain_event_dispatch_dlq` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `queue_id` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '原队列 id',
  `event_name` varchar(64) NOT NULL COMMENT '事件名',
  `payload_json` mediumtext NOT NULL COMMENT 'JSON 载荷',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '失败前尝试次数',
  `last_error` varchar(255) DEFAULT NULL COMMENT '末次错误',
  `failed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '迁入 DLQ 时间',
  PRIMARY KEY (`id`),
  KEY `idx_event_failed` (`event_name`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='领域事件 Hook 死信队列';

CREATE TABLE `__DB_PREFIX__domain_event_dispatch_queue` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `event_name` varchar(64) NOT NULL COMMENT '白名单事件名',
  `payload_json` mediumtext NOT NULL COMMENT 'JSON 载荷',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '已尝试次数',
  `last_error` varchar(255) DEFAULT NULL COMMENT '上次错误',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '入队时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_event_drain` (`attempts`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='领域事件 Hook 异步队列';

CREATE TABLE `__DB_PREFIX__domain_event_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `event_name` varchar(64) NOT NULL DEFAULT '' COMMENT '事件名',
  `payload_json` mediumtext COMMENT 'payload JSON',
  `source` varchar(32) NOT NULL DEFAULT 'core' COMMENT '来源 core|error|插件标识',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_event_created` (`event_name`,`created_at`),
  KEY `idx_del_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='领域事件日志（薄实现）';

CREATE TABLE `__DB_PREFIX__favorite_actions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `document_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '文档 ID',
  `action` varchar(16) NOT NULL DEFAULT '' COMMENT 'like|collect',
  `visitor_key` varchar(64) NOT NULL DEFAULT '' COMMENT '访客指纹 hash',
  `user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '会员 ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_doc_action_visitor` (`document_id`,`action`,`visitor_key`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='点赞收藏去重';

CREATE TABLE `__DB_PREFIX__favorite_stats` (
  `document_id` int(10) unsigned NOT NULL COMMENT '文档 ID',
  `like_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '点赞数',
  `collect_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '收藏数',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文档点赞收藏计数';

CREATE TABLE `__DB_PREFIX__float_contact_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `contact_type` varchar(20) NOT NULL DEFAULT 'phone' COMMENT 'qq|phone|wechat|email|link',
  `label` varchar(80) NOT NULL DEFAULT '' COMMENT '显示名称',
  `value` varchar(255) NOT NULL DEFAULT '' COMMENT '号码/账号/链接',
  `qrcode` varchar(500) NOT NULL DEFAULT '' COMMENT '微信二维码图片路径',
  `tip` varchar(255) NOT NULL DEFAULT '' COMMENT '副标题/工作时间',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序，越小越靠前',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '1启用 0禁用',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='悬浮联系方式条目';

CREATE TABLE `__DB_PREFIX__forms` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `slug` varchar(64) NOT NULL COMMENT '前台标识',
  `title` varchar(200) NOT NULL COMMENT '表单名称',
  `fields_json` json NOT NULL COMMENT '字段定义',
  `settings_json` json DEFAULT NULL COMMENT '通知/验证码等',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '1启用 0停用',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='自定表单';

CREATE TABLE `__DB_PREFIX__form_submissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `form_id` int(10) unsigned NOT NULL COMMENT '表单 ID',
  `payload_json` json NOT NULL COMMENT '提交内容',
  `ip_hash` char(64) NOT NULL DEFAULT '' COMMENT 'IP 哈希',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '0未读 1已读 2已处理',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '提交时间',
  PRIMARY KEY (`id`),
  KEY `idx_form` (`form_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='表单提交';

CREATE TABLE `__DB_PREFIX__items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `code` varchar(64) NOT NULL COMMENT '货号',
  `name` varchar(200) NOT NULL COMMENT '名称',
  `slug` varchar(120) NOT NULL COMMENT 'ǰ̨ slug',
  `item_type` varchar(32) NOT NULL DEFAULT 'physical' COMMENT 'physical/service/digital/component/kit',
  `status` varchar(20) NOT NULL DEFAULT 'draft' COMMENT 'draft/active/discontinued',
  `attrs` json DEFAULT NULL COMMENT '规格 JSON',
  `flags` json DEFAULT NULL COMMENT 'sellable/purchasable/manufacturable',
  `primary_document_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '默认详情文档',
  `nav_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '真分类 site_nav.id，0=未归类',
  `cover_litpic` varchar(512) NOT NULL DEFAULT '' COMMENT '列表封面图路径',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  UNIQUE KEY `uk_slug` (`slug`),
  KEY `idx_status_sort` (`status`,`sort`),
  KEY `idx_items_public_list` (`status`,`sort`,`id`),
  KEY `idx_item_public_list` (`status`,`id`),
  KEY `idx_nav_id` (`nav_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='品项（产品/服务/物料）';

CREATE TABLE `__DB_PREFIX__item_attr_values` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `item_id` int(10) unsigned NOT NULL COMMENT '品项 ID',
  `param_key` varchar(64) NOT NULL COMMENT '参数键',
  `attr_value` varchar(255) NOT NULL COMMENT '参数值',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_item_param_value` (`item_id`,`param_key`,`attr_value`),
  KEY `idx_param_value` (`param_key`,`attr_value`),
  KEY `idx_item` (`item_id`),
  KEY `idx_param_value_item` (`param_key`,`attr_value`,`item_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='品项规格 EAV';

CREATE TABLE `__DB_PREFIX__item_filter_facets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `param_key` varchar(64) NOT NULL COMMENT '参数键',
  `attr_value` varchar(255) NOT NULL COMMENT '参数值',
  `item_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '在售品项数',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_param_value` (`param_key`,`attr_value`),
  KEY `idx_param` (`param_key`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='品项筛选 Facet 计数';

CREATE TABLE `__DB_PREFIX__item_navs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `item_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '品项ID',
  `nav_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '附加栏目 site_nav.id',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_item_nav` (`item_id`,`nav_id`),
  KEY `idx_nav_id` (`nav_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='品项附加栏目';

CREATE TABLE `__DB_PREFIX__item_tags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `item_id` int(10) unsigned NOT NULL COMMENT '品项 ID',
  `tag_id` int(10) unsigned NOT NULL COMMENT '标签 ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_item_tag` (`item_id`,`tag_id`),
  KEY `idx_tag` (`tag_id`),
  KEY `idx_it_tag_item` (`tag_id`,`item_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='品项-标签';

CREATE TABLE `__DB_PREFIX__item_variants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `item_id` int(10) unsigned NOT NULL COMMENT '品项 ID（SPU）',
  `variant_code` varchar(64) NOT NULL COMMENT '订货编码（全站唯一，对外报号）',
  `spec_label` varchar(255) NOT NULL DEFAULT '' COMMENT '规格展示文案，如 220V/左进',
  `spec_map` json DEFAULT NULL COMMENT '规格轴键值 JSON',
  `is_default` tinyint(4) NOT NULL DEFAULT '0' COMMENT '是否默认规格（每品项至多一个）',
  `status` varchar(20) NOT NULL DEFAULT 'active' COMMENT 'active/discontinued',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_variant_code` (`variant_code`),
  KEY `idx_item_status_sort` (`item_id`,`status`,`sort`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='品项规格（可订货单元）';

CREATE TABLE `__DB_PREFIX__logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id` int(10) unsigned DEFAULT NULL COMMENT '操作用户ID',
  `username` varchar(50) DEFAULT NULL COMMENT '操作用户名',
  `type` varchar(50) NOT NULL COMMENT '日志类型（login/operate/security）',
  `action` varchar(100) NOT NULL COMMENT '操作行为',
  `module` varchar(50) DEFAULT NULL COMMENT '操作模块',
  `request_method` varchar(10) DEFAULT NULL COMMENT '请求方法',
  `request_url` varchar(255) DEFAULT NULL COMMENT '请求URL',
  `request_params` text COMMENT '请求参数（JSON）',
  `ip` varchar(45) DEFAULT NULL COMMENT '操作IP',
  `user_agent` varchar(500) DEFAULT NULL COMMENT 'UA信息',
  `result` tinyint(4) NOT NULL DEFAULT '1' COMMENT '操作结果：0失败 1成功',
  `duration` int(11) DEFAULT NULL COMMENT '执行耗时(ms)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_logs_created` (`created_at`),
  KEY `idx_logs_module_created` (`module`,`created_at`),
  KEY `idx_logs_user_created` (`user_id`,`created_at`),
  KEY `idx_logs_module_user_created` (`module`,`user_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='操作日志表';

CREATE TABLE `__DB_PREFIX__logs_archive` (
  `id` bigint(20) unsigned NOT NULL COMMENT 'ԭ logs.id',
  `user_id` int(10) unsigned DEFAULT NULL COMMENT '操作用户ID',
  `username` varchar(50) DEFAULT NULL COMMENT '操作用户名',
  `type` varchar(50) NOT NULL COMMENT '日志类型',
  `action` varchar(100) NOT NULL COMMENT '操作行为',
  `module` varchar(50) DEFAULT NULL COMMENT '操作模块',
  `request_method` varchar(10) DEFAULT NULL COMMENT '请求方法',
  `request_url` varchar(255) DEFAULT NULL COMMENT '请求URL',
  `request_params` text COMMENT '请求参数',
  `ip` varchar(45) DEFAULT NULL COMMENT '操作IP',
  `user_agent` varchar(500) DEFAULT NULL COMMENT 'UA',
  `result` tinyint(4) NOT NULL DEFAULT '1' COMMENT '0失败 1成功',
  `duration` int(11) DEFAULT NULL COMMENT '耗时ms',
  `created_at` datetime NOT NULL COMMENT '原创建时间',
  `archived_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '归档时间',
  PRIMARY KEY (`id`),
  KEY `idx_archive_created` (`created_at`),
  KEY `idx_archive_module_created` (`module`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志归档';

CREATE TABLE `__DB_PREFIX__media_assets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `content_hash` char(64) NOT NULL COMMENT 'SHA256 十六进制',
  `file_size` bigint(20) unsigned NOT NULL DEFAULT '0' COMMENT '字节',
  `mime` varchar(120) NOT NULL DEFAULT '' COMMENT 'MIME',
  `ext` varchar(20) NOT NULL DEFAULT '' COMMENT '扩展名',
  `scene` varchar(64) NOT NULL DEFAULT 'general' COMMENT '上传场景',
  `path` varchar(500) NOT NULL COMMENT '相对路径 uploads/…',
  `url` varchar(500) NOT NULL COMMENT '访问 URL',
  `original_name` varchar(255) NOT NULL DEFAULT '' COMMENT '原始文件名',
  `ref_count` int(10) unsigned NOT NULL DEFAULT '1' COMMENT '引用次数',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '入库时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_content_hash` (`content_hash`),
  KEY `idx_scene` (`scene`),
  KEY `idx_path` (`path`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='上传媒体资产索引（去重）';

CREATE TABLE `__DB_PREFIX__media_asset_aliases` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `media_asset_id` int(10) unsigned NOT NULL DEFAULT '0',
  `display_name` varchar(255) NOT NULL DEFAULT '',
  `created_by` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_asset` (`media_asset_id`),
  KEY `idx_name` (`display_name`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='媒体资产展示别名';

CREATE TABLE `__DB_PREFIX__media_asset_refs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `media_asset_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT 'media_assets.id',
  `ref_type` varchar(48) NOT NULL DEFAULT '' COMMENT 'document_litpic|document_content|document_download|document_video|library',
  `ref_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '业务主键，如 document_id',
  `field_key` varchar(64) NOT NULL DEFAULT '' COMMENT '字段或子项标识',
  `path_snapshot` varchar(500) NOT NULL DEFAULT '' COMMENT '冗余 path 便于查询',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_asset` (`media_asset_id`),
  KEY `idx_ref` (`ref_type`,`ref_id`),
  KEY `idx_path` (`path_snapshot`(191)),
  KEY `idx_mar_asset` (`media_asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='媒体资产影子引用';

CREATE TABLE `__DB_PREFIX__media_orphan_queue` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `path` varchar(500) NOT NULL COMMENT 'uploads/… 相对路径',
  `media_asset_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT 'media_assets.id',
  `kind` varchar(16) NOT NULL DEFAULT 'auto' COMMENT 'image|software|auto',
  `reason` varchar(32) NOT NULL DEFAULT 'ref_zero' COMMENT 'ref_zero|released|manual',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_path` (`path`(191)),
  KEY `idx_created` (`created_at`),
  KEY `idx_kind` (`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='待回收媒体路径队列';

CREATE TABLE `__DB_PREFIX__member_api_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '会员用户 ID',
  `token_hash` char(64) NOT NULL DEFAULT '' COMMENT 'SHA256(token)',
  `client` varchar(32) NOT NULL DEFAULT 'miniprogram' COMMENT '客户端标识',
  `expires_at` datetime NOT NULL COMMENT '过期时间',
  `last_used_at` datetime DEFAULT NULL COMMENT '最近使用',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_api_token_hash` (`token_hash`),
  KEY `idx_member_api_token_user` (`user_id`),
  KEY `idx_member_api_token_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员 API Token（小程序等）';

CREATE TABLE `__DB_PREFIX__member_balance_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id` int(10) unsigned NOT NULL COMMENT '会员用户ID',
  `delta` decimal(10,2) NOT NULL COMMENT '变动金额（正负，元）',
  `balance` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '变动后余额',
  `reason` varchar(200) NOT NULL DEFAULT '' COMMENT '说明',
  `ref` varchar(64) DEFAULT NULL COMMENT '幂等引用（如 pay:订单号）',
  `admin_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '操作管理员ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_balance_log_ref` (`ref`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_mbl_consumption_list` (`admin_id`,`delta`,`id`),
  KEY `idx_mbl_user_created` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员余额流水';

CREATE TABLE `__DB_PREFIX__member_cancel_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id` int(10) unsigned NOT NULL COMMENT '申请会员ID',
  `reason` varchar(500) NOT NULL DEFAULT '' COMMENT '注销原因',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '状态：0待审 1已通过 2已驳回',
  `admin_remark` varchar(200) NOT NULL DEFAULT '' COMMENT '审核备注',
  `handled_by` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '处理管理员ID',
  `handled_at` datetime DEFAULT NULL COMMENT '处理时间',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '申请时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员注销申请';

CREATE TABLE `__DB_PREFIX__member_enterprise_profiles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id` int(10) unsigned NOT NULL COMMENT '会员用户ID',
  `company_name` varchar(200) NOT NULL DEFAULT '' COMMENT '企业名称',
  `contact_name` varchar(100) NOT NULL DEFAULT '' COMMENT '联系人',
  `contact_phone` varchar(32) NOT NULL DEFAULT '' COMMENT '联系电话',
  `usci` varchar(32) NOT NULL DEFAULT '' COMMENT '统一社会信用代码',
  `job_title` varchar(100) NOT NULL DEFAULT '' COMMENT '职务',
  `company_email` varchar(120) NOT NULL DEFAULT '' COMMENT '企业邮箱',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`),
  KEY `idx_company_name` (`company_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员企业资料';

CREATE TABLE `__DB_PREFIX__member_fields` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `label` varchar(50) NOT NULL COMMENT '字段名称',
  `field_key` varchar(32) NOT NULL COMMENT '字段标识（英文）',
  `field_type` varchar(20) NOT NULL DEFAULT 'text' COMMENT '类型：text/textarea/select/number',
  `options` varchar(500) NOT NULL DEFAULT '' COMMENT 'select 选项，逗号分隔',
  `default_value` varchar(500) NOT NULL DEFAULT '' COMMENT '默认值',
  `is_required` tinyint(4) NOT NULL DEFAULT '0' COMMENT '是否必填：0否 1是',
  `show_register` tinyint(4) NOT NULL DEFAULT '1' COMMENT '注册页显示',
  `show_profile` tinyint(4) NOT NULL DEFAULT '1' COMMENT '个人中心可编辑',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态：0禁用 1启用',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_field_key` (`field_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员自定义字段';

CREATE TABLE `__DB_PREFIX__member_field_values` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id` int(10) unsigned NOT NULL COMMENT '会员用户ID',
  `field_id` int(10) unsigned NOT NULL COMMENT '字段ID',
  `value` text COMMENT '字段值',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_field` (`user_id`,`field_id`),
  KEY `idx_field_id` (`field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员字段值';

CREATE TABLE `__DB_PREFIX__member_levels` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` varchar(50) NOT NULL COMMENT '等级名称',
  `rank` int(11) NOT NULL DEFAULT '0' COMMENT '权限权重，越大越高',
  `is_default` tinyint(4) NOT NULL DEFAULT '0' COMMENT '是否注册默认等级：0否 1是',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态：0禁用 1启用',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_member_level_rank` (`rank`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='会员等级表';

CREATE TABLE `__DB_PREFIX__member_point_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id` int(10) unsigned NOT NULL COMMENT '会员用户ID',
  `delta` int(11) NOT NULL COMMENT '变动积分（正负）',
  `balance` int(11) NOT NULL DEFAULT '0' COMMENT '变动后余额',
  `reason` varchar(200) NOT NULL DEFAULT '' COMMENT '说明',
  `admin_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '操作管理员ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_mpl_consumption_list` (`admin_id`,`delta`,`id`),
  KEY `idx_mpl_user_created` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员积分流水';

CREATE TABLE `__DB_PREFIX__member_recharge_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '会员用户 ID',
  `package_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '套餐 ID',
  `package_title` varchar(100) NOT NULL DEFAULT '' COMMENT '套餐名称快照',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '实付金额',
  `pay_order_no` varchar(64) DEFAULT NULL COMMENT '关联支付单号 weapp_pay_orders.order_no',
  `level_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '升级等级',
  `points` int(11) NOT NULL DEFAULT '0' COMMENT '赠送积分',
  `days` int(11) NOT NULL DEFAULT '0' COMMENT '顺延天数',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '购买时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_recharge_pay_order_no` (`pay_order_no`),
  KEY `idx_recharge_order_user` (`user_id`),
  KEY `idx_recharge_order_pkg` (`package_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员充值套餐购买记录';

CREATE TABLE `__DB_PREFIX__member_recharge_packages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `title` varchar(100) NOT NULL COMMENT '套餐名称',
  `package_type` varchar(20) NOT NULL DEFAULT 'membership' COMMENT 'membership|points|balance',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '售价（元）',
  `grant_balance` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '余额类到账金额，0=按售价',
  `level_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '赠送/升级到的等级ID',
  `points` int(11) NOT NULL DEFAULT '0' COMMENT '赠送积分',
  `days` int(11) NOT NULL DEFAULT '0' COMMENT '有效天数，0=永久',
  `description` varchar(500) NOT NULL DEFAULT '' COMMENT '说明',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态：0下架 1上架',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员充值套餐';

CREATE TABLE `__DB_PREFIX__member_signin_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `sign_date` date NOT NULL,
  `points` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_date` (`user_id`,`sign_date`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员每日签到';

CREATE TABLE `__DB_PREFIX__menus` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `title` varchar(100) NOT NULL COMMENT '菜单名称',
  `permission_code` varchar(100) DEFAULT NULL COMMENT '关联权限标识',
  `parent_id` int(10) unsigned DEFAULT NULL COMMENT '父级菜单ID',
  `icon` varchar(50) DEFAULT NULL COMMENT '图标',
  `route` varchar(255) DEFAULT NULL COMMENT '路由路径',
  `params` text COMMENT '路由参数（JSON）',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态：0禁用 1正常',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='菜单表';

CREATE TABLE `__DB_PREFIX__password_reset_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT '0',
  `email` varchar(120) NOT NULL DEFAULT '',
  `token_hash` char(64) NOT NULL DEFAULT '',
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `request_ip` varchar(64) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_token_hash` (`token_hash`),
  KEY `idx_user_expires` (`user_id`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='密码重置令牌';

CREATE TABLE `__DB_PREFIX__payment_notify_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `channel` varchar(16) NOT NULL DEFAULT '' COMMENT 'alipay|wechat',
  `order_no` varchar(64) NOT NULL DEFAULT '' COMMENT '商户订单号',
  `verified` tinyint(4) NOT NULL DEFAULT '0' COMMENT '验签是否通过',
  `payload_json` mediumtext COMMENT '原始回调内容',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_order_no` (`order_no`),
  KEY `idx_channel_created` (`channel`,`created_at`),
  KEY `idx_pay_notify_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付异步通知日志';

CREATE TABLE `__DB_PREFIX__payment_orders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `order_no` varchar(64) NOT NULL DEFAULT '' COMMENT '商户订单号',
  `user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '会员 ID',
  `scene` varchar(32) NOT NULL DEFAULT '' COMMENT '业务场景 recharge 等',
  `scene_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '场景关联 ID',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '订单金额（元）',
  `channel` varchar(16) NOT NULL DEFAULT '' COMMENT 'alipay|wechat',
  `status` varchar(16) NOT NULL DEFAULT 'pending' COMMENT 'pending|paid|failed|closed',
  `channel_txn_id` varchar(64) NOT NULL DEFAULT '' COMMENT '渠道交易号',
  `payload_json` text COMMENT '业务扩展 JSON',
  `paid_at` datetime DEFAULT NULL COMMENT '支付完成时间',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_user_status` (`user_id`,`status`),
  KEY `idx_scene` (`scene`,`scene_id`),
  KEY `idx_channel_status` (`channel`,`status`),
  KEY `idx_pay_admin_list` (`status`,`id`),
  KEY `idx_pay_stale_close` (`status`,`created_at`),
  KEY `idx_pay_user_created` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='支付订单';

CREATE TABLE `__DB_PREFIX__permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` varchar(100) NOT NULL COMMENT '权限名称',
  `code` varchar(100) NOT NULL COMMENT '权限标识（如 admin.user.create）',
  `parent_id` int(10) unsigned DEFAULT NULL COMMENT '父级权限ID',
  `module` varchar(50) NOT NULL DEFAULT 'admin' COMMENT '所属模块（admin/api/plugin）',
  `icon` varchar(50) DEFAULT NULL COMMENT '图标',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态：0禁用 1正常',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='权限表';

CREATE TABLE `__DB_PREFIX__plugins` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` varchar(100) NOT NULL COMMENT '插件名称',
  `identifier` varchar(50) NOT NULL COMMENT '插件标识（唯一）',
  `package` varchar(100) NOT NULL DEFAULT '' COMMENT '包 ID vendor/slug',
  `instance_id` varchar(64) NOT NULL DEFAULT '' COMMENT '安装实例 ID',
  `kind` varchar(32) NOT NULL DEFAULT 'document-addon' COMMENT '插件形态',
  `commercial_tier` varchar(8) NOT NULL DEFAULT '' COMMENT '商业层 B0/B2/B3/B4',
  `business_domain` varchar(8) DEFAULT NULL COMMENT '经营域 E/M/C/X 或 NULL',
  `version` varchar(20) NOT NULL DEFAULT '1.0.0' COMMENT '当前版本',
  `description` text COMMENT '插件描述',
  `author` varchar(100) DEFAULT NULL COMMENT '作者',
  `edition` varchar(20) NOT NULL DEFAULT 'community' COMMENT '版本归属：community/enterprise',
  `price` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '标价（0=免费）',
  `commercial_model` varchar(20) NOT NULL DEFAULT 'free' COMMENT '商业模式：free/paid/subscription',
  `period_days` int(10) unsigned DEFAULT NULL COMMENT '订阅天数，NULL=永久',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态：0停用 1启用',
  `installed` tinyint(4) NOT NULL DEFAULT '0' COMMENT '是否已安装',
  `enabled` tinyint(4) NOT NULL DEFAULT '0' COMMENT '是否已启用（且授权有效）',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plugins_identifier` (`identifier`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='插件注册表';

CREATE TABLE `__DB_PREFIX__product_item_relations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `parent_item_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '主产品品项 ID',
  `child_item_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '配件/辅件品项 ID',
  `relation_type` varchar(20) NOT NULL DEFAULT 'accessory' COMMENT 'accessory/spare/component',
  `note` varchar(200) NOT NULL DEFAULT '' COMMENT '备注',
  `qty` decimal(10,3) NOT NULL DEFAULT '1.000' COMMENT '展示用量',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_parent_child` (`parent_item_id`,`child_item_id`),
  KEY `idx_pir_parent` (`parent_item_id`),
  KEY `idx_pir_child` (`child_item_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='主品项配件辅件关联';

CREATE TABLE `__DB_PREFIX__product_param_defs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `group_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '参数组 ID',
  `param_key` varchar(64) NOT NULL COMMENT '参数键 如 color',
  `label` varchar(100) NOT NULL COMMENT '显示名',
  `input_type` varchar(32) NOT NULL DEFAULT 'text' COMMENT 'text/select/number',
  `options_json` json DEFAULT NULL COMMENT 'select 选项',
  `filterable` tinyint(4) NOT NULL DEFAULT '1' COMMENT '是否参与前台筛选',
  `default_value` varchar(500) NOT NULL DEFAULT '' COMMENT '默认值（填 attrs 时的初始值）',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`param_key`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='产品参数定义';

CREATE TABLE `__DB_PREFIX__product_param_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `group_key` varchar(64) NOT NULL COMMENT '组键',
  `label` varchar(100) NOT NULL COMMENT '显示名',
  `description` varchar(500) NOT NULL DEFAULT '' COMMENT '参数组描述',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '1启用 0停用',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_group_key` (`group_key`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='产品参数组';

CREATE TABLE `__DB_PREFIX__roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` varchar(100) NOT NULL COMMENT '角色名称',
  `code` varchar(50) NOT NULL COMMENT '角色编码（唯一）',
  `description` text COMMENT '角色描述',
  `is_system` tinyint(4) NOT NULL DEFAULT '0' COMMENT '是否系统预置（不可删除）',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态：0禁用 1正常',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='角色表';

CREATE TABLE `__DB_PREFIX__role_permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `role_id` int(10) unsigned NOT NULL COMMENT '角色ID',
  `permission_id` int(10) unsigned NOT NULL COMMENT '权限ID',
  `data_scope` enum('self','dept','dept_tree','all') NOT NULL DEFAULT 'self' COMMENT '数据范围：self/dept/dept_tree/all',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_perm` (`role_id`,`permission_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='角色权限关联表';

CREATE TABLE `__DB_PREFIX__schema_migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` varchar(100) NOT NULL COMMENT '迁移脚本名',
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '执行时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_migration_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='已执行迁移版本';

CREATE TABLE `__DB_PREFIX__search_click_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `keyword` varchar(240) NOT NULL DEFAULT '' COMMENT '搜索关键词',
  `target_type` varchar(32) NOT NULL DEFAULT 'link' COMMENT '目标类型',
  `target_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '目标ID',
  `target_url` varchar(512) NOT NULL DEFAULT '' COMMENT '目标URL',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_keyword` (`keyword`(64)),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='搜索点击日志';

CREATE TABLE `__DB_PREFIX__search_index_queue` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `document_id` int(10) unsigned NOT NULL COMMENT '文档 ID',
  `action` varchar(16) NOT NULL DEFAULT 'upsert' COMMENT 'upsert|delete',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '已尝试次数',
  `last_error` varchar(255) DEFAULT NULL COMMENT '上次错误摘要',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '入队时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_doc_action` (`document_id`,`action`),
  KEY `idx_queue_drain` (`attempts`,`id`),
  KEY `idx_siq_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='文档搜索索引异步队列';

CREATE TABLE `__DB_PREFIX__search_query_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `keyword` varchar(255) NOT NULL DEFAULT '' COMMENT '搜索关键词',
  `hit_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '命中条数',
  `product_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '品项命中数',
  `doc_count` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '文档命中数',
  `mode` varchar(32) NOT NULL DEFAULT '' COMMENT '模式',
  `zero_result` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '是否零结果：0否 1是',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_zero` (`zero_result`,`created_at`),
  KEY `idx_sql_zero_created` (`zero_result`,`created_at`),
  KEY `idx_sql_keyword` (`keyword`(64)),
  KEY `idx_sql_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='前台搜索词日志';

CREATE TABLE `__DB_PREFIX__site_ad_slots` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL COMMENT '调用标识 slot=',
  `name` varchar(120) NOT NULL DEFAULT '' COMMENT '广告位名称',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '说明',
  `default_creative_type` varchar(32) NOT NULL DEFAULT 'image_text' COMMENT '新建素材默认创意类型',
  `sort` int(11) NOT NULL DEFAULT '0',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '0禁用 1启用',
  `effective_start_at` datetime DEFAULT NULL COMMENT '生效开始，空=不限',
  `effective_end_at` datetime DEFAULT NULL COMMENT '生效结束，空=不限',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_status_sort` (`status`,`sort`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='站点广告位';

CREATE TABLE `__DB_PREFIX__site_domains` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `host` varchar(255) NOT NULL COMMENT '访问域名（不含协议与路径）',
  `tag_group_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '绑定的标签分组',
  `is_primary` tinyint(4) NOT NULL DEFAULT '0' COMMENT '1=主域',
  `default_tag_slug` varchar(120) NOT NULL DEFAULT '' COMMENT '该域首页可选直达标签 slug',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '0禁用 1启用',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_host` (`host`),
  KEY `idx_tag_group` (`tag_group_id`),
  KEY `idx_status_sort` (`status`,`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='同站多域绑定标签分组';

CREATE TABLE `__DB_PREFIX__site_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `title` varchar(100) NOT NULL DEFAULT '' COMMENT '链接名称',
  `url` varchar(500) NOT NULL DEFAULT '' COMMENT '链接地址',
  `logo_url` varchar(500) NOT NULL DEFAULT '' COMMENT 'Logo 图片（可选）',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序（越小越靠前）',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '0禁用 1启用',
  `open_new_tab` tinyint(4) NOT NULL DEFAULT '1' COMMENT '1新窗口打开',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_sort` (`sort`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='友情链接';

CREATE TABLE `__DB_PREFIX__site_nav` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `parent_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '上级ID，0为顶级',
  `title` varchar(100) NOT NULL COMMENT '导航标题',
  `nav_type` varchar(20) NOT NULL DEFAULT 'route' COMMENT 'route内部路径 tag标签 external外链 none仅分组',
  `content_kind` varchar(20) NOT NULL DEFAULT '' COMMENT '入口类型 home|document|product|page|external；空=旧行待回填',
  `target` varchar(255) NOT NULL DEFAULT '' COMMENT '路径/slug/URL',
  `url_path` varchar(100) NOT NULL DEFAULT '' COMMENT '前台列表路径（无首尾斜杠）',
  `tpl_name` varchar(100) NOT NULL DEFAULT '' COMMENT '列表页模板文件名',
  `view_tpl_name` varchar(100) NOT NULL DEFAULT '' COMMENT '内容页默认模板',
  `seo_keywords` varchar(255) NOT NULL DEFAULT '' COMMENT 'SEO关键词',
  `seo_description` varchar(500) NOT NULL DEFAULT '' COMMENT 'SEO描述',
  `litpic` varchar(255) NOT NULL DEFAULT '' COMMENT '栏目封面图',
  `read_perm` tinyint(4) NOT NULL DEFAULT '0' COMMENT '阅读：0开放 1会员',
  `read_level_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '会员等级ID，0=登录即可',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序（越小越靠前）',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '0禁用 1启用',
  `open_new_tab` tinyint(4) NOT NULL DEFAULT '0' COMMENT '1新窗口打开',
  `extra_json` json DEFAULT NULL COMMENT '导航扩展字段键值JSON',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_parent_sort` (`parent_id`,`sort`),
  KEY `idx_status` (`status`),
  KEY `idx_url_path` (`url_path`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='前台站点导航';

CREATE TABLE `__DB_PREFIX__site_pages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `title` varchar(100) NOT NULL COMMENT '页面标题',
  `path` varchar(100) NOT NULL COMMENT '前台访问路径（不含域名，如 guanyu）',
  `tpl_name` varchar(100) NOT NULL COMMENT '模板文件名（不含.php，如 about）',
  `content` mediumtext COMMENT '单页正文 HTML',
  `seo_title` varchar(200) NOT NULL DEFAULT '' COMMENT 'SEO标题',
  `seo_keywords` varchar(255) NOT NULL DEFAULT '' COMMENT 'SEO关键词',
  `seo_description` varchar(500) NOT NULL DEFAULT '' COMMENT 'SEO描述',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '0禁用 1启用',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_path` (`path`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='前台单页（自定义URL）';

CREATE TABLE `__DB_PREFIX__site_plugin_entitlements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `plugin_identifier` varchar(50) NOT NULL COMMENT '插件标识',
  `status` varchar(20) NOT NULL DEFAULT 'active' COMMENT '状态：active有效 expired过期 disabled禁用',
  `license_type` varchar(20) NOT NULL DEFAULT 'free' COMMENT '授权类型：free/trial/paid/bundled',
  `expire_at` datetime DEFAULT NULL COMMENT '到期时间，NULL=永久',
  `granted_by` varchar(100) DEFAULT NULL COMMENT '授权来源：install/manual/order',
  `capability_snapshot_json` text COMMENT 'granted_features/SKU 快照 JSON',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_spe_plugin` (`plugin_identifier`),
  KEY `idx_spe_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='站点插件授权表';

CREATE TABLE `__DB_PREFIX__site_plugin_wallets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `plugin_identifier` varchar(50) NOT NULL COMMENT '插件标识',
  `wallet_mode` varchar(20) NOT NULL DEFAULT 'quota' COMMENT 'quota=计次 unlimited=不限次',
  `quota_remaining` int(11) DEFAULT NULL COMMENT '剩余次数，NULL=不限',
  `quota_total` int(11) DEFAULT NULL COMMENT '当前周期总额（展示/审计）',
  `period_start` datetime DEFAULT NULL COMMENT '订阅周期起',
  `period_end` datetime DEFAULT NULL COMMENT '订阅周期止',
  `active_sku_id` varchar(64) DEFAULT NULL COMMENT '最近生效 SKU',
  `status` varchar(20) NOT NULL DEFAULT 'active' COMMENT 'active/disabled',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_spw_plugin` (`plugin_identifier`),
  KEY `idx_spw_period_end` (`period_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='站点插件计量钱包';

CREATE TABLE `__DB_PREFIX__site_plugin_wallet_ledger` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `plugin_identifier` varchar(50) NOT NULL COMMENT '插件标识',
  `delta` int(11) NOT NULL DEFAULT '0' COMMENT '变动（正=充值，负=扣减）',
  `balance_after` int(11) DEFAULT NULL COMMENT '变动后余额，NULL=不限',
  `reason` varchar(40) NOT NULL DEFAULT '' COMMENT 'grant_trial/order/consume/admin',
  `ref` varchar(120) DEFAULT NULL COMMENT '关联单号/项目ID',
  `detail` varchar(255) DEFAULT NULL COMMENT '说明',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_spwl_plugin_reason_ref` (`plugin_identifier`,`reason`,`ref`),
  KEY `idx_spwl_plugin` (`plugin_identifier`),
  KEY `idx_spwl_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='插件钱包流水';

CREATE TABLE `__DB_PREFIX__site_slides` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `slot` varchar(64) NOT NULL DEFAULT 'home_carousel',
  `creative_type` varchar(32) NOT NULL DEFAULT 'carousel' COMMENT '创意类型：carousel/single_image/image_text/html',
  `display_scope` varchar(16) NOT NULL DEFAULT 'all' COMMENT '展示范围 all=全站 home=仅首页（全屏/悼念类）',
  `title` varchar(200) NOT NULL DEFAULT '' COMMENT '标题',
  `subtitle` varchar(500) NOT NULL DEFAULT '' COMMENT '副标题/描述',
  `image_url` varchar(500) NOT NULL DEFAULT '' COMMENT '图片 URL',
  `link_url` varchar(500) NOT NULL DEFAULT '' COMMENT '跳转链接',
  `link_text` varchar(100) NOT NULL DEFAULT '' COMMENT '按钮文字',
  `html_body` mediumtext COMMENT 'HTML/第三方代码（creative_type=html）',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序（越小越靠前）',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '0禁用 1启用',
  `effective_start_at` datetime DEFAULT NULL COMMENT '生效开始，空=不限',
  `effective_end_at` datetime DEFAULT NULL COMMENT '生效结束，空=不限',
  `open_new_tab` tinyint(4) NOT NULL DEFAULT '0' COMMENT '1新窗口打开',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_sort` (`sort`),
  KEY `idx_status` (`status`),
  KEY `idx_slot_status` (`slot`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='首页幻灯Ƭ';

CREATE TABLE `__DB_PREFIX__static_build_queue` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `work_key` varchar(96) NOT NULL COMMENT '幂等键 doc:1 / tag:2:1 / home',
  `payload` json NOT NULL COMMENT 'StaticHtmlService runWorkItem 结构',
  `priority` tinyint(3) unsigned NOT NULL DEFAULT '10' COMMENT '越大越先',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '重试次数',
  `last_error` varchar(255) DEFAULT NULL COMMENT '上次错误',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '入队时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_work_key` (`work_key`),
  KEY `idx_drain` (`priority`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='静态 HTML 构建队列';

CREATE TABLE `__DB_PREFIX__stats_behavior_daily` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `stat_date` date NOT NULL,
  `path` varchar(500) NOT NULL DEFAULT '',
  `visits` int(10) unsigned NOT NULL DEFAULT '0',
  `bounces` int(10) unsigned NOT NULL DEFAULT '0',
  `dwell_total_sec` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_date_path` (`stat_date`,`path`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='停留跳出日汇总';

CREATE TABLE `__DB_PREFIX__stats_crawler_daily` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `stat_date` date NOT NULL,
  `bot_key` varchar(32) NOT NULL DEFAULT '',
  `hits` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_date_bot` (`stat_date`,`bot_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='爬虫访问日汇总';

CREATE TABLE `__DB_PREFIX__stats_daily` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `stat_date` date NOT NULL COMMENT '日期',
  `pv` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '浏览量',
  `uv` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '访客数',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_date` (`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='访问统计日汇总';

CREATE TABLE `__DB_PREFIX__stats_heatmap_daily` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `stat_date` date NOT NULL,
  `path` varchar(500) NOT NULL DEFAULT '',
  `cell_key` varchar(16) NOT NULL DEFAULT '',
  `hits` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_date_path_cell` (`stat_date`,`path`(120),`cell_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='点击热力格日汇总';

CREATE TABLE `__DB_PREFIX__stats_hits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `path` varchar(500) NOT NULL DEFAULT '' COMMENT '访问路径',
  `referer` varchar(500) NOT NULL DEFAULT '' COMMENT '来源',
  `object_type` varchar(32) NOT NULL DEFAULT '' COMMENT 'document/tag/home',
  `object_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '对象 ID',
  `ip_hash` char(64) NOT NULL DEFAULT '' COMMENT 'IP 哈希',
  `ua_hash` char(64) NOT NULL DEFAULT '' COMMENT 'UA 哈希',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '访问时间',
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_path` (`path`(191)),
  KEY `idx_object` (`object_type`,`object_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='访问统计明细';

CREATE TABLE `__DB_PREFIX__tags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` varchar(100) NOT NULL COMMENT '标签名称',
  `slug` varchar(100) NOT NULL DEFAULT '' COMMENT 'URL/API标识（唯一）',
  `url_path` varchar(100) NOT NULL DEFAULT '' COMMENT '前台访问路径',
  `kind` varchar(20) NOT NULL DEFAULT 'label' COMMENT 'topic主题标签 label标注标签',
  `group_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '所属分组ID',
  `parent_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '父级标签ID，0为顶级栏目',
  `description` text COMMENT '导语/简介',
  `litpic` varchar(255) NOT NULL DEFAULT '' COMMENT '封面图',
  `tpl_name` varchar(100) NOT NULL DEFAULT '' COMMENT '列表页模板文件名',
  `view_tpl_name` varchar(100) NOT NULL DEFAULT '' COMMENT '内容页默认模板（文档/品项详情）',
  `seo_title` varchar(200) NOT NULL DEFAULT '' COMMENT 'SEO标题',
  `seo_keywords` varchar(255) NOT NULL DEFAULT '' COMMENT 'SEO关键词',
  `seo_description` varchar(500) NOT NULL DEFAULT '' COMMENT 'SEO描述',
  `extra_json` json DEFAULT NULL COMMENT '频道扩展字段键值JSON',
  `nav_sort` int(11) NOT NULL DEFAULT '0' COMMENT '导航排序（越小越靠前）',
  `read_perm` tinyint(4) NOT NULL DEFAULT '0' COMMENT '阅读权限：0开放 1受限',
  `read_level_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '最低会员等级ID，0=仅登录',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态：0禁用 1启用',
  `use_count` int(10) unsigned DEFAULT '0' COMMENT '使用次数',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`),
  UNIQUE KEY `uk_slug` (`slug`),
  KEY `idx_status` (`status`),
  KEY `idx_use_count` (`use_count`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_tag_public_list` (`status`,`use_count`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='标签表';

CREATE TABLE `__DB_PREFIX__tag_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` varchar(100) NOT NULL COMMENT '分组名称',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序（越小越靠前）',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '0禁用 1启用',
  `requires_entitlement` json DEFAULT NULL COMMENT '启用本站托管域时须已授权的插件 identifier 列表',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_sort` (`sort`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='标签分组';

CREATE TABLE `__DB_PREFIX__users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `username` varchar(50) NOT NULL COMMENT '用户名（唯一）',
  `password` varchar(255) NOT NULL COMMENT '密码（bcrypt）',
  `totp_secret` varchar(64) NOT NULL DEFAULT '' COMMENT 'TOTP secret(base32)',
  `totp_enabled` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT 'TOTP enabled',
  `email` varchar(100) DEFAULT NULL COMMENT '邮箱',
  `email_verify_token` varchar(64) DEFAULT NULL COMMENT '注册邮件验证令牌',
  `email_verify_sent_at` datetime DEFAULT NULL COMMENT '验证邮件发送时间',
  `email_verified_at` datetime DEFAULT NULL COMMENT '邮箱验证通过时间',
  `mobile` varchar(20) DEFAULT NULL COMMENT '手机号',
  `nickname` varchar(100) DEFAULT NULL COMMENT '昵称',
  `avatar` varchar(255) DEFAULT NULL COMMENT '头像URL',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态：0禁用 1正常',
  `account_kind` varchar(16) NOT NULL DEFAULT 'personal' COMMENT '账号类型 personal|enterprise',
  `department_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '所属部门ID（OA）',
  `member_level_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '会员等级ID，0=未分配',
  `member_points` int(11) NOT NULL DEFAULT '0' COMMENT '会员积分余额',
  `member_growth` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '成长值',
  `member_remark` varchar(500) NOT NULL DEFAULT '' COMMENT '会员备注',
  `member_level_expire_at` datetime DEFAULT NULL COMMENT '会员等级到期时间，NULL=永久',
  `member_balance` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '会员余额（元）',
  `must_change_password` tinyint(4) NOT NULL DEFAULT '0' COMMENT '1=登录后须改密',
  `security_level` tinyint(4) NOT NULL DEFAULT '1' COMMENT '密级：1公开~5绝密',
  `last_login_ip` varchar(45) DEFAULT NULL COMMENT '最后登录IP',
  `register_ip` varchar(45) DEFAULT NULL COMMENT '注册 IP',
  `last_login_time` datetime DEFAULT NULL COMMENT '最后登录时间',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_users_member_admin` (`member_level_id`,`status`,`id`),
  KEY `idx_users_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='用户表';

CREATE TABLE `__DB_PREFIX__user_oauth_bindings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id` int(10) unsigned NOT NULL COMMENT '本站用户 ID',
  `provider` varchar(32) NOT NULL COMMENT '平台标识',
  `provider_uid` varchar(128) NOT NULL COMMENT '第三方用户 ID',
  `union_id` varchar(128) DEFAULT NULL COMMENT '同生态合并 ID',
  `nickname` varchar(100) DEFAULT NULL COMMENT '第三方昵称',
  `avatar` varchar(255) DEFAULT NULL COMMENT '头像 URL',
  `extra_json` json DEFAULT NULL COMMENT 'ԭʼ profile',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '绑定时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_oauth_provider_uid` (`provider`,`provider_uid`),
  KEY `idx_oauth_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='第三方账号绑定表';

CREATE TABLE `__DB_PREFIX__user_roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `user_id` int(10) unsigned NOT NULL COMMENT '用户ID',
  `role_id` int(10) unsigned NOT NULL COMMENT '角色ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_role` (`user_id`,`role_id`),
  KEY `idx_ur_user` (`user_id`),
  KEY `idx_ur_role` (`role_id`),
  KEY `idx_ur_role_user` (`role_id`,`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='用户角色关联表';

CREATE TABLE `__DB_PREFIX__weapp_doc_gallery_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `document_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '文档 ID',
  `group_key` varchar(64) NOT NULL DEFAULT 'default' COMMENT '分组标识 slug',
  `title` varchar(200) NOT NULL DEFAULT '' COMMENT '分组标题',
  `display_style` varchar(32) NOT NULL DEFAULT 'grid' COMMENT '展现样式 grid/masonry/carousel…',
  `pack_download` tinyint(4) NOT NULL DEFAULT '0' COMMENT '1=同步打包到下载区',
  `pack_access_mode` varchar(32) NOT NULL DEFAULT 'free' COMMENT '打包下载权限 free/login/member/points',
  `pack_member_level_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '会员等级门槛（access=member）',
  `pack_points_cost` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '积分消耗（access=points）',
  `download_bundle_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '关联 download 资源包 ID',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '0禁用 1启用',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_doc_group_key` (`document_id`,`group_key`),
  KEY `idx_document_id` (`document_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='文档图集分组';

CREATE TABLE `__DB_PREFIX__weapp_doc_talent_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `document_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '关联文档 ID（0=独立招聘岗位）',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '岗位名称',
  `job_title` varchar(120) NOT NULL DEFAULT '' COMMENT '兼容旧字段：部门/地点摘要',
  `department` varchar(120) NOT NULL DEFAULT '' COMMENT '部门',
  `location` varchar(120) NOT NULL DEFAULT '' COMMENT '工作地点',
  `employment_type` varchar(32) NOT NULL DEFAULT 'fulltime' COMMENT 'fulltime|parttime|intern|contract',
  `experience` varchar(80) NOT NULL DEFAULT '' COMMENT '经验要求',
  `education` varchar(80) NOT NULL DEFAULT '' COMMENT '学历要求',
  `salary_text` varchar(120) NOT NULL DEFAULT '' COMMENT '薪资展示（含面议）',
  `avatar_path` varchar(500) NOT NULL DEFAULT '' COMMENT '可选封面/图标',
  `bio` text COMMENT '兼容旧字段：要求摘要',
  `responsibilities` text COMMENT '岗位职责',
  `requirements` text COMMENT '任职要求',
  `benefits` text COMMENT '福利待遇',
  `contact` varchar(200) NOT NULL DEFAULT '' COMMENT '兼容旧字段：投递说明摘要',
  `apply_email` varchar(200) NOT NULL DEFAULT '' COMMENT '投递邮箱',
  `apply_url` varchar(500) NOT NULL DEFAULT '' COMMENT '投递外链',
  `apply_note` varchar(500) NOT NULL DEFAULT '' COMMENT '投递说明',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态：0停招 1招聘中',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_document_id` (`document_id`),
  KEY `idx_status` (`status`),
  KEY `idx_location` (`location`),
  KEY `idx_employment_type` (`employment_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='招聘岗位';

CREATE TABLE `__DB_PREFIX__weapp_plugin_schema_versions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `identifier` varchar(64) NOT NULL COMMENT '插件 identifier',
  `version` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '已应用最高台阶版本',
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '最后应用时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_identifier` (`identifier`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='weapp 插件 schema 版本';

CREATE TABLE `__DB_PREFIX__weapp_web_sim_ui_install_entries` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL DEFAULT '',
  `status` tinyint(4) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='weapp_web_sim_ui_install 示例业务表';

SET FOREIGN_KEY_CHECKS=1;
