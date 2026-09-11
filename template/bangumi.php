<?php
/**
 * 番剧墙的外层容器（两条前台渲染路径共用）。
 *
 * 番剧墙本身由 template/gallery.php 输出，这里按当前主题的页面结构套壳。
 * 全部输入都由 Chrome::render() 传进来——主题是两栏还是单栏、有没有导航栏、
 * 标题用哪一个，都在那里判定，所以本文件不需要知道当前跑的是哪个主题。
 */

use TypechoPlugin\MyAnimeBgm\View;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/** @var bool $bangumiThemeChrome 是否套上了主题自己的 header / footer */
$bangumiThemeChrome = $bangumiThemeChrome ?? false;
/** @var bool $bangumiHasSidebar 主题是否有左侧栏 */
$bangumiHasSidebar = $bangumiHasSidebar ?? false;
/** @var string|null $bangumiNavbar 主题导航栏模板的相对路径 */
$bangumiNavbar = $bangumiNavbar ?? null;
/** @var string|null $bangumiHeading 页面标题；为空时用插件设置里的「页面标题」 */
$bangumiHeading = $bangumiHeading ?? null;

$bgmHeading = ($bangumiHeading !== null && $bangumiHeading !== '') ? $bangumiHeading : View::title();
?>
<?php if ($bangumiThemeChrome && $bangumiHasSidebar): ?>
<?php // 主题是两栏结构：正文收进 #middle，和主题的其它独立页面保持一致 ?>
<div class="col-md-12 col-lg-8" id="middle">
<?php elseif ($bangumiThemeChrome): ?>
<?php // 单栏主题：用插件自己的容器撑开留白，免得正文贴着屏幕边缘 ?>
<div class="bgm-container">
<?php endif; ?>
    <?php if ($bangumiNavbar !== null): ?>
        <?php $need($bangumiNavbar); ?>
    <?php endif; ?>
    <?php // bgm-stack / bgm-stack-tight 见 static/bangumi.css：主题只带了一部分 Bootstrap
          // 工具类时（例如只有 .d-flex 没有 .flex-column），靠它们兜住纵向排布 ?>
    <div class="container-fluid p-4 d-flex flex-column row-gap-3 bgm-stack">
        <div class="card border-0 py-3 col-12">
            <div class="d-flex column-gap-2">
                <div class="card-body p-0 d-flex flex-column justify-content-between row-gap-1 overflow-hidden bgm-stack-tight">
                    <?php if ($bangumiThemeChrome): ?>
                        <?php // 套了主题外壳：标题用主题的 <h3>，样式与其它页面一致 ?>
                        <h3><?php echo View::escape($bgmHeading); ?></h3>
                        <?php View::gallery(['title' => false]); ?>
                    <?php else: ?>
                        <?php // 兜底外壳不加载 Bootstrap，交给 gallery 自己渲染 .bgm-title ?>
                        <?php View::gallery(['title' => $bgmHeading]); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php if ($bangumiThemeChrome): ?>
</div>
<?php endif; ?>
