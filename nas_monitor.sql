-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- 主机： localhost
-- 生成日期： 2026-05-31 21:13:59
-- 服务器版本： 10.11.11-MariaDB
-- PHP 版本： 8.2.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `nas_monitor`
--

-- --------------------------------------------------------

--
-- 表的结构 `log`
--

CREATE TABLE `log` (
  `id` bigint(20) NOT NULL,
  `ip` text NOT NULL,
  `type` text NOT NULL,
  `content` text NOT NULL,
  `times` text NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- 转存表中的数据 `log`
--

INSERT INTO `log` (`id`, `ip`, `type`, `content`, `times`) VALUES
(1, '127.0.0.1', '页面操作', '登录系统', '1780220372'),
(2, '127.0.0.1', '页面操作', '退出系统', '1780231921');

-- --------------------------------------------------------

--
-- 表的结构 `pc`
--

CREATE TABLE `pc` (
  `id` bigint(20) NOT NULL,
  `name` text NOT NULL,
  `type` text NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- 转存表中的数据 `pc`
--

INSERT INTO `pc` (`id`, `name`, `type`) VALUES
(1, '1', '模式管理'),
(2, '需要修改', '登录密码'),
(3, '300', '过期时间'),
(4, '3', '数据间隔'),
(5, '群晖NAS监控平台', '页面标题'),
(6, '需要修改', '登录账号'),
(7, '需要修改', '网址链接');

--
-- 转储表的索引
--

--
-- 表的索引 `log`
--
ALTER TABLE `log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id` (`id`);

--
-- 表的索引 `pc`
--
ALTER TABLE `pc`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id` (`id`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `log`
--
ALTER TABLE `log`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- 使用表AUTO_INCREMENT `pc`
--
ALTER TABLE `pc`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
