CREATE TABLE IF NOT EXISTS `{{prefix}}weapp_doc_comment_comments` (
    `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
    `document_id` int unsigned NOT NULL DEFAULT 0 COMMENT '文档 ID',
    `parent_id` int unsigned NOT NULL DEFAULT 0 COMMENT '父评论 ID，0=顶级',
    `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '会员 ID，0=游客',
    `username` varchar(50) NOT NULL DEFAULT '' COMMENT '显示名',
    `content` text NOT NULL COMMENT '评论内容',
    `user_ip` varchar(45) NOT NULL DEFAULT '' COMMENT 'IP',
    `like_count` int unsigned NOT NULL DEFAULT 0 COMMENT '点赞数',
    `status` tinyint NOT NULL DEFAULT 0 COMMENT '0待审核 1已通过',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    KEY `idx_document_parent` (`document_id`, `parent_id`),
    KEY `idx_status` (`status`),
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评论插件评论表';

CREATE TABLE IF NOT EXISTS `{{prefix}}weapp_doc_comment_likes` (
    `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
    `comment_id` int unsigned NOT NULL DEFAULT 0 COMMENT '评论 ID',
    `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '会员 ID',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_comment_user` (`comment_id`, `user_id`),
    KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评论点赞表';

CREATE TABLE IF NOT EXISTS `{{prefix}}weapp_doc_comment_levels` (
    `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
    `member_level_id` int NOT NULL DEFAULT 0 COMMENT '会员等级 ID，0=游客',
    `can_comment` tinyint NOT NULL DEFAULT 1 COMMENT '是否允许评论',
    `need_review` tinyint NOT NULL DEFAULT 0 COMMENT '是否须审核',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_member_level` (`member_level_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评论会员等级权限表';

INSERT IGNORE INTO `{{prefix}}weapp_doc_comment_levels` (`member_level_id`, `can_comment`, `need_review`) VALUES (0, 0, 1);