-- 签到加成：补齐 mac_sign_bonus / mac_sign_bonus_log 结构与出厂规则
-- 可重复执行：建表使用 IF NOT EXISTS，种子仅在规则表为空时写入

CREATE TABLE IF NOT EXISTS `mac_sign_bonus` (
  `bonus_id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '规则ID',
  `bonus_type` tinyint(1) unsigned NOT NULL DEFAULT '1' COMMENT '类型 1=连续签到加成 2=月度累计奖 3=月度满勤奖',
  `bonus_days` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '门槛：连续天数/当月签到次数；类型3填0',
  `bonus_points` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '奖励金币',
  `bonus_name` varchar(100) NOT NULL DEFAULT '' COMMENT '规则名称',
  `bonus_desc` varchar(255) NOT NULL DEFAULT '' COMMENT '规则描述',
  `bonus_sort` int(10) NOT NULL DEFAULT '0' COMMENT '排序',
  `bonus_status` tinyint(1) unsigned NOT NULL DEFAULT '1' COMMENT '状态 0=禁用 1=启用',
  `bonus_time_add` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
  `bonus_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`bonus_id`),
  KEY `bonus_type` (`bonus_type`),
  KEY `bonus_status` (`bonus_status`),
  KEY `bonus_sort` (`bonus_sort`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='签到加成规则表';

CREATE TABLE IF NOT EXISTS `mac_sign_bonus_log` (
  `log_id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `user_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '用户ID',
  `bonus_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '规则ID',
  `bonus_type` tinyint(1) unsigned NOT NULL DEFAULT '1' COMMENT '类型快照',
  `bonus_period` varchar(20) NOT NULL DEFAULT '' COMMENT '发放周期 连续=YYYY-MM-DD 月度=YYYY-MM',
  `log_points` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '发放金币',
  `log_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '发放时间',
  PRIMARY KEY (`log_id`),
  UNIQUE KEY `user_bonus_period` (`user_id`, `bonus_id`, `bonus_period`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='签到加成发放记录表';

INSERT INTO `mac_sign_bonus` (`bonus_type`,`bonus_days`,`bonus_points`,`bonus_name`,`bonus_desc`,`bonus_sort`,`bonus_status`,`bonus_time_add`,`bonus_time`)
SELECT * FROM (
  SELECT 1 AS bonus_type, 3 AS bonus_days, 2 AS bonus_points, '连续签到3天' AS bonus_name, '连续签到满3天当天额外奖励2金币' AS bonus_desc, 1 AS bonus_sort, 1 AS bonus_status, 0 AS bonus_time_add, 0 AS bonus_time
  UNION ALL SELECT 1, 7, 5, '连续签到7天', '连续签到满7天当天额外奖励5金币', 2, 1, 0, 0
  UNION ALL SELECT 1, 15, 10, '连续签到15天', '连续签到满15天当天额外奖励10金币', 3, 1, 0, 0
  UNION ALL SELECT 1, 30, 20, '连续签到30天', '连续签到满30天当天额外奖励20金币', 4, 1, 0, 0
  UNION ALL SELECT 2, 15, 30, '月度签到15次', '当月累计签到满15次奖励30金币', 1, 1, 0, 0
  UNION ALL SELECT 2, 20, 60, '月度签到20次', '当月累计签到满20次奖励60金币', 2, 1, 0, 0
  UNION ALL SELECT 3, 0, 150, '月度全勤', '当月每日签到全勤奖励150金币', 1, 1, 0, 0
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `mac_sign_bonus`);
