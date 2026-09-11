<?php
/**
 * 主题没有提供成对的 header.php / footer.php 时的兜底外壳（上半部分）。
 *
 * 这里由 Chrome::render() 以静态方法 require，没有 $this 可用，所以站点信息
 * 一律走 Options::alloc()。
 */

use TypechoPlugin\MyAnimeBgm\View;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

$bgmShell = Options::alloc();

// 和 template/bangumi.php 用同一个标题：独立页面的标题优先，没给才用插件设置里的
$bgmShellHeading = (isset($bangumiHeading) && $bangumiHeading !== null && $bangumiHeading !== '')
    ? $bangumiHeading
    : View::title();
?>
<!DOCTYPE html>
<html lang="zh-Hans">
<head>
    <meta charset="<?php $bgmShell->charset(); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo View::escape($bgmShellHeading); ?> | <?php $bgmShell->title(); ?></title>
    <?php if ($bgmShell->description): ?>
        <meta name="description" content="<?php $bgmShell->description(); ?>">
    <?php endif; ?>
    <style>
        body {
            margin: 0;
            background: #f5f6f8;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC",
            "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
        }

        .bgm-shell {
            max-width: 1140px;
            margin: 0 auto;
            padding: 1rem;
        }
    </style>
</head>
<body>
<div class="bgm-shell">
