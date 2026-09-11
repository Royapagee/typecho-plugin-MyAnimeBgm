<?php
/**
 * 番剧墙主体模板（两条前台渲染路径共用）。
 *
 * 这里只输出番剧墙本身，外层的容器由调用方决定：
 *  - 插件自带的 /bangumi 路由走 template/bangumi.php
 *  - 主题里的独立页面模板走 theme/page-bangumi.php
 *
 * 不分页，一次把全部番剧渲染出来；筛选和搜索都在浏览器里完成。
 */

use TypechoPlugin\MyAnimeBgm\ImageCache;
use TypechoPlugin\MyAnimeBgm\Plugin;
use TypechoPlugin\MyAnimeBgm\Theme;
use TypechoPlugin\MyAnimeBgm\View;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

$items = View::items();
$tabs = View::tabs();
$total = count($items);
$syncedAt = View::syncedAt();
$missingCovers = ImageCache::missingCount($items);

// 调用方可以传 title=false 表示不输出标题（主题页面模板会用自己的页面标题）
if (!isset($galleryTitle)) {
    $galleryTitle = View::title();
}
?>
<link rel="stylesheet" href="<?php echo View::escape(View::asset(Plugin::NAME . '/static/bangumi.css')); ?>"/>

<?php // data-bgm-theme 决定走哪一档配色，见 static/bangumi.css 与 Theme.php ?>
<div class="bgm-page" data-bgm-theme="<?php echo View::escape(Theme::flavor()); ?>">
    <?php // 走主题独立页面模板时标题由主题的 <h3> 输出，调用方传 title=false ?>
    <?php if ($galleryTitle !== false && $galleryTitle !== ''): ?>
        <h3 class="bgm-title"><?php echo View::escape($galleryTitle); ?></h3>
    <?php endif; ?>

    <div class="bgm-meta">
        <?php if ($syncedAt > 0): ?>
            <?php echo View::escape(_t('共 %d 部，最近同步于 %s', $total, date('Y-m-d H:i', $syncedAt))); ?>
        <?php else: ?>
            <?php echo View::escape(_t('还没有同步数据')); ?>
        <?php endif; ?>
    </div>

    <?php if ($total === 0): ?>
        <div class="bgm-empty">
            <p><?php echo View::escape(_t('这里还什么都没有。')); ?></p>
            <p><?php echo View::escape(_t('请先在后台插件设置里填写 Bangumi API 地址与用户 ID，保存后即可自动同步追番数据。')); ?></p>
        </div>
    <?php else: ?>
        <?php // 筛选标签与搜索框同一行：标签靠左，搜索框靠右 ?>
        <div class="bgm-filter">
            <?php if (count($tabs) > 1): ?>
                <div class="bgm-tabs" id="bgm-tabs">
                    <?php foreach ($tabs as $index => $tab): ?>
                        <button type="button"
                                class="bgm-tab<?php echo $index === 0 ? ' is-active' : ''; ?>"
                                data-bgm-filter="<?php echo View::escape($tab['value']); ?>">
                            <?php echo View::escape($tab['label']); ?>
                            <span class="bgm-count"><?php echo (int) $tab['count']; ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <input type="search" id="bgm-search" class="bgm-search" autocomplete="off"
                   placeholder="<?php echo View::escape(_t('搜索番剧名')); ?>"/>
        </div>

        <div class="bgm-grid" id="bgm-grid">
            <?php foreach ($items as $item): ?>
                <?php
                $name = View::displayName($item);
                $original = View::originalName($item);
                $cover = View::cover($item);
                $progress = View::progressLabel($item);
                $percent = View::progressPercent($item);
                $score = (float) ($item['score'] ?? 0);
                $type = (int) ($item['collection_type'] ?? 0);
                $subjectId = (int) ($item['subject_id'] ?? 0);
                ?>
                <a class="bgm-card"
                   href="<?php echo View::escape(View::link($item)); ?>"
                   target="_blank" rel="noopener noreferrer"
                   title="<?php echo View::escape($name); ?>"
                   data-bgm-name="<?php echo View::escape(mb_strtolower($name . ' ' . $original)); ?>"
                   data-bgm-type="<?php echo $type; ?>">
                    <span class="bgm-thumb">
                        <?php // 占位块垫在底下：图片没加载出来（未缓存或远端不通）时就能看到番剧名 ?>
                        <span class="bgm-placeholder"><span><?php echo View::escape($name); ?></span></span>
                        <?php if ($cover !== ''): ?>
                            <img class="bgm-cover"
                                 src="<?php echo View::escape($cover); ?>"
                                 alt="<?php echo View::escape($name); ?>"
                                 loading="lazy" decoding="async"
                                 onerror="this.remove()"/>
                        <?php endif; ?>
                    </span>

                    <span class="bgm-badge bgm-badge--type bgm-badge--<?php echo View::escape(View::statusClass($item)); ?>">
                        <?php echo View::escape(View::collectionLabel($item)); ?>
                    </span>

                    <?php if ($score > 0): ?>
                        <span class="bgm-badge bgm-badge--score"><?php echo View::escape(number_format($score, 1)); ?></span>
                    <?php endif; ?>

                    <span class="bgm-overlay">
                        <span class="bgm-name"><?php echo View::escape($name); ?></span>
                        <?php if ($original !== '' && $original !== $name): ?>
                            <span class="bgm-sub"><?php echo View::escape($original); ?></span>
                        <?php endif; ?>
                        <?php if ($progress !== ''): ?>
                            <span class="bgm-sub"><?php echo View::escape($progress); ?></span>
                            <span class="bgm-progress"><span style="width:<?php echo View::escape(number_format($percent, 2, '.', '')); ?>%"></span></span>
                        <?php endif; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="bgm-empty" id="bgm-no-result" hidden>
            <?php echo View::escape(_t('没有匹配的番剧。')); ?>
        </div>

        <?php if ($missingCovers > 0): ?>
            <p class="bgm-note" id="bgm-cover-note">
                <?php echo View::escape(_t('有 %d 张封面尚未缓存到本地，访客可能加载不出图片；可在后台「追番管理」里一键缓存。', $missingCovers)); ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
    (function () {
        // 同一页面上可能被多次初始化（主题带 PJAX 时），用标记保证只绑一次
        var grid = document.getElementById('bgm-grid');
        if (!grid || grid.getAttribute('data-bgm-ready') === '1') {
            return;
        }
        grid.setAttribute('data-bgm-ready', '1');

        var cards = Array.prototype.slice.call(grid.querySelectorAll('.bgm-card'));
        var input = document.getElementById('bgm-search');
        var tabs = Array.prototype.slice.call(document.querySelectorAll('.bgm-tab'));
        var empty = document.getElementById('bgm-no-result');
        var activeType = '';

        function apply() {
            var keyword = input ? input.value.trim().toLowerCase() : '';
            var visible = 0;

            cards.forEach(function (card) {
                var matchType = activeType === '' || card.getAttribute('data-bgm-type') === activeType;
                var matchName = keyword === ''
                    || (card.getAttribute('data-bgm-name') || '').indexOf(keyword) !== -1;
                var show = matchType && matchName;

                card.hidden = !show;
                if (show) {
                    visible++;
                }
            });

            if (empty) {
                empty.hidden = visible !== 0;
            }
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabs.forEach(function (other) {
                    other.classList.remove('is-active');
                });
                tab.classList.add('is-active');
                activeType = tab.getAttribute('data-bgm-filter') || '';
                apply();
            });
        });

        if (input) {
            input.addEventListener('input', apply);
        }
    })();
</script>
