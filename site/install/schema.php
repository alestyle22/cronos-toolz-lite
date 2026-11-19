<?php
/**
 * install/schema.php — Tablas base que ningún módulo crea solo (usuarios, configuración, contenidos, equipos…). Las usa instalar.php.
 * Las demás tablas las crea cada módulo la primera vez que se abre. Generado con dev/make_schema.sh desde la base local.
 */
return [
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_activity` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` enum('content_create','link_open','profile_update','login','post_create','focus_update','status_update') NOT NULL,
  `target_name` varchar(255) DEFAULT '',
  `target_url` varchar(500) DEFAULT '',
  `meta` varchar(500) DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `team_id` int(11) DEFAULT NULL,
  `visibility` enum('global','team') NOT NULL DEFAULT 'global',
  PRIMARY KEY (`id`),
  KEY `idx_activity_user` (`user_id`),
  KEY `idx_activity_date` (`created_at`),
  KEY `idx_team_visibility` (`team_id`,`visibility`),
  CONSTRAINT `g7_activity_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `g7_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_chat_bubbles` (
  `user_id` int(11) NOT NULL,
  `contact_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`contact_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_chat_group_members` (
  `group_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`group_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_chat_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `avatar_url` varchar(500) DEFAULT NULL,
  `owner_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_chat_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_id` int(11) NOT NULL,
  `to_id` int(11) DEFAULT NULL,
  `body` text NOT NULL,
  `seen` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `type` varchar(20) DEFAULT 'text',
  `file_url` varchar(500) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `file_mime` varchar(100) DEFAULT NULL,
  `ref_type` varchar(20) DEFAULT NULL,
  `ref_id` int(11) DEFAULT NULL,
  `ref_data` text DEFAULT NULL,
  `reply_to_id` int(11) DEFAULT NULL,
  `reply_to_name` varchar(100) DEFAULT NULL,
  `reply_to_text` text DEFAULT NULL,
  `group_id` int(11) DEFAULT NULL,
  `team_id` int(11) DEFAULT NULL,
  `scope` enum('global','team','private','group') NOT NULL DEFAULT 'global',
  PRIMARY KEY (`id`),
  KEY `idx_to` (`to_id`),
  KEY `idx_from` (`from_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_group` (`group_id`),
  KEY `idx_scope` (`scope`),
  KEY `idx_team_scope` (`team_id`,`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_chat_reactions` (
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `emoji` varchar(10) NOT NULL,
  PRIMARY KEY (`message_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cfg_key` varchar(100) NOT NULL,
  `cfg_value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `cfg_key` (`cfg_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_content` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(40) NOT NULL DEFAULT 'promo',
  `name` varchar(255) NOT NULL,
  `author` varchar(100) NOT NULL,
  `project` varchar(100) DEFAULT '',
  `projects` text DEFAULT '',
  `category` varchar(50) DEFAULT '',
  `media_type` enum('imagen','video','gif','otro','') DEFAULT '',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `img` varchar(500) DEFAULT '',
  `mailing` varchar(500) DEFAULT '',
  `graphics` varchar(500) DEFAULT '',
  `landing` text DEFAULT NULL,
  `created_at` date NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `custom_field` varchar(500) DEFAULT '',
  `monday_task_id` varchar(50) DEFAULT '',
  `monday_task_name` varchar(300) DEFAULT '',
  `monday_task_url` varchar(500) DEFAULT '',
  `collaborator` varchar(100) DEFAULT '',
  `content_type` varchar(50) DEFAULT NULL,
  `qty_graficas` int(11) NOT NULL DEFAULT 0,
  `qty_videos` int(11) NOT NULL DEFAULT 0,
  `qty_landings` int(11) NOT NULL DEFAULT 0,
  `qty_mailings` int(11) NOT NULL DEFAULT 0,
  `qty_creativos_paid` int(11) NOT NULL DEFAULT 0,
  `qty_assets_rrss` int(11) NOT NULL DEFAULT 0,
  `qty_assets_mobile` int(11) NOT NULL DEFAULT 0,
  `qty_revisiones` int(11) NOT NULL DEFAULT 0,
  `qty_rechazos` int(11) NOT NULL DEFAULT 0,
  `estado` enum('activo','reemplazado') NOT NULL DEFAULT 'activo',
  `parent_id` int(11) DEFAULT NULL,
  `edit_type` enum('revision','rechazo') DEFAULT NULL,
  `tiempo_entrega_dias` int(11) DEFAULT NULL,
  `qty_tareas` int(11) NOT NULL DEFAULT 0,
  `complications` text DEFAULT NULL,
  `complications_note` varchar(300) DEFAULT NULL,
  `edit_reasons` text DEFAULT NULL,
  `edit_notes` text DEFAULT NULL,
  `extra` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_type` (`type`),
  KEY `idx_project` (`project`),
  KEY `idx_end_date` (`end_date`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_estado` (`estado`),
  KEY `idx_proyecto` (`project`),
  CONSTRAINT `g7_content_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `g7_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_content_collaborators` (
  `content_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `added_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`content_id`,`user_id`),
  KEY `user_id` (`user_id`),
  KEY `added_by` (`added_by`),
  CONSTRAINT `g7_content_collaborators_ibfk_1` FOREIGN KEY (`content_id`) REFERENCES `g7_content` (`id`) ON DELETE CASCADE,
  CONSTRAINT `g7_content_collaborators_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `g7_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `g7_content_collaborators_ibfk_3` FOREIGN KEY (`added_by`) REFERENCES `g7_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_content_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `content_id` int(11) NOT NULL,
  `author_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `author_id` (`author_id`),
  KEY `idx_cc_content` (`content_id`),
  KEY `idx_cc_parent` (`parent_id`),
  CONSTRAINT `g7_content_comments_ibfk_1` FOREIGN KEY (`content_id`) REFERENCES `g7_content` (`id`) ON DELETE CASCADE,
  CONSTRAINT `g7_content_comments_ibfk_2` FOREIGN KEY (`author_id`) REFERENCES `g7_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `g7_content_comments_ibfk_3` FOREIGN KEY (`parent_id`) REFERENCES `g7_content_comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_content_likes` (
  `content_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`content_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `g7_content_likes_ibfk_1` FOREIGN KEY (`content_id`) REFERENCES `g7_content` (`id`) ON DELETE CASCADE,
  CONSTRAINT `g7_content_likes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `g7_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_drive_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `folder_id` int(11) DEFAULT NULL,
  `owner_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` bigint(20) NOT NULL DEFAULT 0,
  `mime_type` varchar(100) NOT NULL,
  `shared` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_folder` (`folder_id`),
  KEY `idx_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_drive_folders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `owner_id` int(11) NOT NULL,
  `shared` tinyint(1) DEFAULT 0,
  `color` varchar(10) DEFAULT '#ff6600',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_gamif_notif` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` enum('level_up','badge') NOT NULL,
  `data` text NOT NULL,
  `seen` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_seen` (`user_id`,`seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_link_clicks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `link_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `clicked_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_clicks_link` (`link_id`),
  KEY `idx_clicks_user` (`user_id`),
  KEY `idx_clicks_date` (`clicked_at`),
  CONSTRAINT `g7_link_clicks_ibfk_1` FOREIGN KEY (`link_id`) REFERENCES `g7_links` (`id`) ON DELETE CASCADE,
  CONSTRAINT `g7_link_clicks_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `g7_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_link_favorites` (
  `user_id` int(11) NOT NULL,
  `link_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`link_id`),
  KEY `link_id` (`link_id`),
  CONSTRAINT `g7_link_favorites_ibfk_1` FOREIGN KEY (`link_id`) REFERENCES `g7_links` (`id`) ON DELETE CASCADE,
  CONSTRAINT `g7_link_favorites_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `g7_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_link_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `link_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `note` text NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_link` (`link_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `g7_link_notes_ibfk_1` FOREIGN KEY (`link_id`) REFERENCES `g7_links` (`id`) ON DELETE CASCADE,
  CONSTRAINT `g7_link_notes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `g7_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT '',
  `url` varchar(500) NOT NULL,
  `img` varchar(500) DEFAULT '',
  `tags` varchar(500) DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `public_note` text DEFAULT '',
  `html_file` varchar(80) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_links_cat` (`category_id`),
  CONSTRAINT `g7_links_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `g7_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `g7_links_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `g7_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_mailing_exports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `username` varchar(80) NOT NULL,
  `platform` varchar(40) NOT NULL,
  `h1` varchar(255) DEFAULT '',
  `exported_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_plat` (`platform`),
  KEY `idx_date` (`exported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_monday_assignments` (
  `monday_item_id` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `note` varchar(300) DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`monday_item_id`,`user_id`),
  KEY `user_id` (`user_id`),
  KEY `assigned_by` (`assigned_by`),
  CONSTRAINT `g7_monday_assignments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `g7_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `g7_monday_assignments_ibfk_2` FOREIGN KEY (`assigned_by`) REFERENCES `g7_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `from_id` int(11) DEFAULT NULL,
  `type` varchar(30) NOT NULL,
  `target_id` int(11) DEFAULT NULL,
  `profile_id` int(11) DEFAULT NULL,
  `body` varchar(300) DEFAULT '',
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `from_id` (`from_id`),
  KEY `profile_id` (`profile_id`),
  KEY `idx_notif_user` (`user_id`,`read_at`),
  CONSTRAINT `g7_notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `g7_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `g7_notifications_ibfk_2` FOREIGN KEY (`from_id`) REFERENCES `g7_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `g7_notifications_ibfk_3` FOREIGN KEY (`profile_id`) REFERENCES `g7_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_pings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_id` int(11) NOT NULL,
  `to_id` int(11) NOT NULL,
  `message` varchar(200) DEFAULT '',
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `beeps` int(11) NOT NULL DEFAULT 3,
  PRIMARY KEY (`id`),
  KEY `from_id` (`from_id`),
  KEY `idx_pings_to` (`to_id`,`read_at`),
  CONSTRAINT `g7_pings_ibfk_1` FOREIGN KEY (`from_id`) REFERENCES `g7_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `g7_pings_ibfk_2` FOREIGN KEY (`to_id`) REFERENCES `g7_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_post_reactions` (
  `post_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `emoji` varchar(10) NOT NULL DEFAULT 'like',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`post_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `g7_post_reactions_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `g7_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `g7_post_reactions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `g7_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_post_replies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `post_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `body` varchar(280) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_post_replies` (`post_id`),
  CONSTRAINT `g7_post_replies_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `g7_posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `g7_post_replies_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `g7_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_posts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `img` varchar(500) DEFAULT '',
  `pinned` tinyint(1) NOT NULL DEFAULT 0,
  `pin_suggested` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_posts_user` (`user_id`),
  KEY `idx_posts_date` (`created_at`),
  CONSTRAINT `g7_posts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `g7_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_profile_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `profile_id` int(11) NOT NULL,
  `author_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `author_id` (`author_id`),
  KEY `idx_comments_profile` (`profile_id`),
  KEY `idx_comments_parent` (`parent_id`),
  CONSTRAINT `g7_profile_comments_ibfk_1` FOREIGN KEY (`profile_id`) REFERENCES `g7_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `g7_profile_comments_ibfk_2` FOREIGN KEY (`author_id`) REFERENCES `g7_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `g7_profile_comments_ibfk_3` FOREIGN KEY (`parent_id`) REFERENCES `g7_profile_comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_stock_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `cover_url` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_stock_favorites` (
  `user_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  PRIMARY KEY (`user_id`,`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_stock_folders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cat` (`category_id`),
  KEY `idx_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_stock_item_tags` (
  `item_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  PRIMARY KEY (`item_id`,`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_stock_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) DEFAULT NULL,
  `owner_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `file_url` varchar(500) NOT NULL,
  `thumb_url` varchar(500) DEFAULT NULL,
  `file_type` enum('image','video','gif') NOT NULL DEFAULT 'image',
  `file_size` bigint(20) DEFAULT 0,
  `mime_type` varchar(100) DEFAULT NULL,
  `width` int(11) DEFAULT NULL,
  `height` int(11) DEFAULT NULL,
  `duration` int(11) DEFAULT NULL,
  `downloads` int(11) DEFAULT 0,
  `views` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `folder_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cat` (`category_id`),
  KEY `idx_owner` (`owner_id`),
  KEY `idx_type` (`file_type`),
  KEY `idx_folder` (`folder_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_stock_tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_team_config` (
  `team_id` int(11) NOT NULL,
  `nav_links` text DEFAULT NULL,
  `nav_align` varchar(10) NOT NULL DEFAULT 'center',
  `logo_url` varchar(500) DEFAULT NULL,
  `shortcuts` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`team_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_team_members` (
  `team_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('admin','member') NOT NULL DEFAULT 'member',
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `joined_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`team_id`,`user_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_primary` (`user_id`,`is_primary`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_team_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `team_id` int(11) NOT NULL,
  `module` varchar(50) NOT NULL,
  `action` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_perm` (`team_id`,`module`,`action`),
  KEY `idx_team` (`team_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_teams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(7) NOT NULL DEFAULT '#ff6600',
  `avatar_url` varchar(500) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_slug` (`slug`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_user_badges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `badge_key` varchar(50) NOT NULL,
  `unlocked_at` datetime DEFAULT current_timestamp(),
  `notified` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_badge` (`user_id`,`badge_key`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_user_prefs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `pref_key` varchar(100) NOT NULL,
  `pref_value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_pref` (`user_id`,`pref_key`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_user_xp` (
  `user_id` int(11) NOT NULL,
  `xp_total` int(11) NOT NULL DEFAULT 0,
  `nivel` varchar(20) NOT NULL DEFAULT 'rookie',
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user') NOT NULL DEFAULT 'user',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  `session_start` datetime DEFAULT NULL,
  `last_link_id` int(11) DEFAULT NULL,
  `total_time_sec` int(11) NOT NULL DEFAULT 0,
  `display_name` varchar(100) DEFAULT '',
  `avatar` varchar(500) DEFAULT '',
  `phone` varchar(30) DEFAULT '',
  `bio` varchar(300) DEFAULT '',
  `position` varchar(100) DEFAULT '',
  `location` varchar(100) DEFAULT '',
  `instagram` varchar(100) DEFAULT '',
  `behance` varchar(100) DEFAULT '',
  `linkedin` varchar(100) DEFAULT '',
  `portfolio` varchar(300) DEFAULT '',
  `skills` varchar(500) DEFAULT '',
  `cover` varchar(500) DEFAULT '',
  `skills_json` text DEFAULT '',
  `availability` varchar(30) DEFAULT 'available',
  `focus_text` varchar(140) DEFAULT '',
  `mood` varchar(10) DEFAULT '',
  `last_seen` datetime DEFAULT NULL,
  `is_super` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `g7_xp_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `xp` int(11) NOT NULL,
  `reason` varchar(100) NOT NULL,
  `ref_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `placas_fondos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) DEFAULT NULL,
  `full_url` varchar(255) NOT NULL,
  `thumb_url` varchar(255) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `placas_ligas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `logo_url` varchar(255) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `logo_svg` longtext DEFAULT NULL,
  `logo_color1` varchar(20) DEFAULT '#ffffff',
  `logo_color2` varchar(20) DEFAULT '#ff4800',
  `bg_story_url` varchar(255) DEFAULT NULL,
  `bg_banner_url` varchar(255) DEFAULT NULL,
  `grad_start` varchar(20) DEFAULT NULL,
  `grad_mid` varchar(20) DEFAULT NULL,
  `grad_end` varchar(20) DEFAULT NULL,
  `pattern` varchar(50) DEFAULT 'none',
  `pat_opacity` tinyint(4) DEFAULT 10,
  `odd_color` varchar(20) DEFAULT '#22c55e',
  `bg_banner_mobile_url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
<<<'SQL'
CREATE TABLE IF NOT EXISTS `placas_plantillas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `thumb_url` varchar(255) DEFAULT NULL,
  `datos` longtext NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
