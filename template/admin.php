<?php
/**
 * 后台「追番管理」面板。
 *
 * 分三块：同步状态、图片缓存管理、番剧数据列表。
 */

use TypechoPlugin\MyAnimeBgm\Api;
use TypechoPlugin\MyAnimeBgm\ImageCache;
use TypechoPlugin\MyAnimeBgm\Plugin;
use TypechoPlugin\MyAnimeBgm\Store;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

$user->pass('administrator');

include 'header.php';
include 'menu.php';

$options = Options::alloc();
$settings = Plugin::settings();
$configured = Plugin::isConfigured();
$syncedAt = Store::syncedAt($settings);
$items = Store::all($settings);
$itemCount = count($items);
$coverStats = ImageCache::stats();
$coverMissing = ImageCache::missingCount($items);
$coverWritable = is_dir(ImageCache::dir()) ? is_writable(ImageCache::dir()) : is_writable(dirname(ImageCache::dir()));
$actionUrl = $security->getIndex('/action/bangumi-refresh');
$configUrl = $options->adminUrl('options-plugin.php?config=' . Plugin::NAME, true);
$frontUrl = \Typecho\Router::url(Plugin::ROUTE, null, $options->index);
$statusTypes = Api::collectionTypes();

$counts = [];
foreach ($items as $item) {
    $type = (int) $item['collection_type'];
    $counts[$type] = ($counts[$type] ?? 0) + 1;
}

$humanSize = static function (int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return $bytes . ' B';
};
?>

<style>
    .bgm-admin-status {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 12px;
        margin-bottom: 16px;
    }

    .bgm-admin-card {
        padding: 12px 16px;
        border: 1px solid #e5e5e5;
        border-radius: 6px;
        background: #fff;
    }

    .bgm-admin-card .label {
        color: #999;
        font-size: 12px;
        margin-bottom: 6px;
    }

    .bgm-admin-card .value {
        font-size: 16px;
        font-weight: 600;
        word-break: break-all;
    }

    .bgm-admin-section {
        margin: 24px 0 8px;
        padding-top: 16px;
        border-top: 1px solid #eee;
    }

    .bgm-admin-section h3 {
        margin: 0 0 4px;
        font-size: 15px;
    }

    .bgm-admin-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
        margin: 12px 0;
    }

    .bgm-admin-progress {
        height: 8px;
        margin: 10px 0 0;
        border-radius: 999px;
        background: #eee;
        overflow: hidden;
        display: none;
    }

    .bgm-admin-progress.is-on {
        display: block;
    }

    .bgm-admin-progress span {
        display: block;
        height: 100%;
        width: 0;
        background: #467b96;
        transition: width .25s ease;
    }

    .bgm-admin-list {
        max-height: 620px;
        overflow: auto;
    }

    .bgm-admin-cover {
        width: 40px;
        height: 56px;
        object-fit: cover;
        border-radius: 4px;
        vertical-align: middle;
    }

    .bgm-admin-notice {
        margin-bottom: 16px;
    }
</style>

<main class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>
        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12 typecho-list">

                <div class="bgm-admin-notice" id="bgm-admin-notice" style="display:none;"></div>

                <?php if (!$configured): ?>
                    <div class="message notice">
                        <ul>
                            <li>
                                <?php _e('还没有完成 api 配置，请先前往'); ?>
                                <a href="<?php echo htmlspecialchars($configUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php _e('插件设置'); ?></a>
                                <?php _e('填写 Bangumi API 地址与用户 ID。'); ?>
                            </li>
                        </ul>
                    </div>
                <?php endif; ?>

                <h3><?php _e('同步状态'); ?></h3>
                <div class="bgm-admin-status">
                    <div class="bgm-admin-card">
                        <div class="label"><?php _e('Bangumi 用户'); ?></div>
                        <div class="value"><?php echo htmlspecialchars((string) $settings['userId'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="bgm-admin-card">
                        <div class="label"><?php _e('缓存数据库类型'); ?></div>
                        <div class="value">
                            <?php if ($settings['cacheType'] === 'file'): ?>
                                <?php _e('文件缓存（JSON）'); ?>
                            <?php else: ?>
                                <?php _e('数据库（Typecho 数据表）'); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="bgm-admin-card">
                        <div class="label"><?php _e('自动刷新间隔'); ?></div>
                        <div class="value">
                            <?php if ((int) $settings['cacheTTL'] === 0): ?>
                                <?php _e('每次访问'); ?>
                            <?php else: ?>
                                <?php _e('%d 小时', (int) $settings['cacheTTL']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="bgm-admin-card">
                        <div class="label"><?php _e('已同步番剧'); ?></div>
                        <div class="value"><span id="bgm-admin-count"><?php echo $itemCount; ?></span> <?php _e('部'); ?></div>
                    </div>
                    <div class="bgm-admin-card">
                        <div class="label"><?php _e('最近同步时间'); ?></div>
                        <div class="value" id="bgm-admin-synced">
                            <?php echo $syncedAt > 0
                                ? htmlspecialchars(date('Y-m-d H:i:s', $syncedAt), ENT_QUOTES, 'UTF-8')
                                : _e('从未同步'); ?>
                        </div>
                    </div>
                </div>

                <div class="bgm-admin-toolbar">
                    <button type="button" class="btn btn-s btn-primary" id="bgm-admin-refresh"
                            <?php echo $configured ? '' : 'disabled'; ?>><?php _e('立即刷新'); ?></button>
                    <button type="button" class="btn btn-s" id="bgm-admin-clear"><?php _e('清空数据'); ?></button>
                    <a class="btn btn-s" href="<?php echo htmlspecialchars($configUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php _e('插件设置'); ?></a>
                    <?php if ($configured): ?>
                        <a class="btn btn-s" target="_blank" rel="noopener"
                           href="<?php echo htmlspecialchars($frontUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php _e('查看前台页面'); ?></a>
                    <?php endif; ?>
                    <span class="description"><?php _e('同步会拉取该用户在 Bangumi 上的全部收藏。'); ?></span>
                </div>

                <?php if (!empty($counts)): ?>
                    <p class="description">
                        <?php foreach ($statusTypes as $type => $label): ?>
                            <?php if (empty($counts[$type])): continue; endif; ?>
                            <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>：<?php echo (int) $counts[$type]; ?>&nbsp;&nbsp;
                        <?php endforeach; ?>
                    </p>
                <?php endif; ?>

                <div class="bgm-admin-section">
                    <h3><?php _e('图片缓存'); ?></h3>
                    <p class="description">
                        <?php _e('Bangumi 的图片站（lain.bgm.tv）在国内访问受限，把封面缓存到本地后访客才能正常看到图片。缓存由服务器发起，不受访客网络影响。'); ?>
                    </p>

                    <?php if (!$coverWritable): ?>
                        <div class="message error">
                            <ul>
                                <li><?php _e('图片缓存目录不可写，请给插件目录下的 covers 目录写入权限。'); ?></li>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <div class="bgm-admin-status">
                        <div class="bgm-admin-card">
                            <div class="label"><?php _e('已缓存封面'); ?></div>
                            <div class="value">
                                <span id="bgm-cover-cached"><?php echo (int) $coverStats['count']; ?></span>
                                / <span id="bgm-cover-total"><?php echo $itemCount; ?></span>
                            </div>
                        </div>
                        <div class="bgm-admin-card">
                            <div class="label"><?php _e('占用空间'); ?></div>
                            <div class="value" id="bgm-cover-size"><?php echo htmlspecialchars($humanSize($coverStats['bytes']), ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="bgm-admin-card">
                            <div class="label"><?php _e('待缓存'); ?></div>
                            <div class="value"><span id="bgm-cover-missing"><?php echo (int) $coverMissing; ?></span> <?php _e('张'); ?></div>
                        </div>
                    </div>

                    <div class="bgm-admin-toolbar">
                        <button type="button" class="btn btn-s btn-primary" id="bgm-cover-cache"
                                <?php echo $itemCount > 0 ? '' : 'disabled'; ?>><?php _e('缓存缺失图片'); ?></button>
                        <button type="button" class="btn btn-s" id="bgm-cover-recache"
                                <?php echo $itemCount > 0 ? '' : 'disabled'; ?>><?php _e('全部重新缓存'); ?></button>
                        <button type="button" class="btn btn-s" id="bgm-cover-prune"><?php _e('清理无主图片'); ?></button>
                        <button type="button" class="btn btn-s" id="bgm-cover-clear"><?php _e('清空图片缓存'); ?></button>
                    </div>

                    <div class="bgm-admin-progress" id="bgm-cover-progress">
                        <span></span>
                    </div>
                </div>

                <div class="bgm-admin-section">
                    <h3><?php _e('番剧数据'); ?></h3>
                </div>

                <div class="bgm-admin-list">
                    <table class="typecho-list-table">
                        <colgroup>
                            <col width="70"/>
                            <col width="30%"/>
                            <col width="12%"/>
                            <col width="16%"/>
                            <col width="10%"/>
                            <col width="16%"/>
                        </colgroup>
                        <thead>
                        <tr>
                            <th><?php _e('封面'); ?></th>
                            <th><?php _e('番剧名称'); ?></th>
                            <th><?php _e('追番状态'); ?></th>
                            <th><?php _e('观看进度'); ?></th>
                            <th><?php _e('评分'); ?></th>
                            <th><?php _e('收藏更新时间'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="6" class="none"><?php _e('还没有同步到任何番剧'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <?php
                                $name = trim((string) $item['name_cn']) !== '' ? (string) $item['name_cn'] : (string) $item['name'];
                                $progress = (int) $item['eps'] > 0
                                    ? (int) $item['ep_status'] . ' / ' . (int) $item['eps']
                                    : (string) (int) $item['ep_status'];
                                $localCover = ImageCache::cachedUrl((int) $item['subject_id']);
                                $coverSrc = $localCover ?? (string) $item['image_medium'];
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($coverSrc !== ''): ?>
                                            <img class="bgm-admin-cover" loading="lazy"
                                                 src="<?php echo htmlspecialchars($coverSrc, ENT_QUOTES, 'UTF-8'); ?>"
                                                 alt="<?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>"
                                                 onerror="this.remove()"/>
                                        <?php endif; ?>
                                        <?php if ($localCover === null): ?>
                                            <div class="description" style="font-size:11px;"><?php _e('未缓存'); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a target="_blank" rel="noopener"
                                           href="<?php echo htmlspecialchars((string) $item['subject_url'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                        <?php if ((string) $item['name'] !== '' && (string) $item['name'] !== $name): ?>
                                            <div class="description"><?php echo htmlspecialchars((string) $item['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($statusTypes[(int) $item['collection_type']] ?? _t('未分类'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($progress, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo (float) $item['score'] > 0 ? htmlspecialchars(number_format((float) $item['score'], 1), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                                    <td><?php echo (int) $item['updated_at'] > 0 ? htmlspecialchars(date('Y-m-d H:i', (int) $item['updated_at']), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>
</main>

<?php include 'copyright.php'; ?>
<?php include 'common-js.php'; ?>
<script>
    (function ($) {
        $(function () {
            var actionUrl = <?php echo json_encode($actionUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            var joiner = actionUrl.indexOf('?') >= 0 ? '&' : '?';
            var running = false;

            var text = {
                syncing: <?php echo json_encode(_t('同步中…'), JSON_UNESCAPED_UNICODE); ?>,
                refresh: <?php echo json_encode(_t('立即刷新'), JSON_UNESCAPED_UNICODE); ?>,
                synced: <?php echo json_encode(_t('同步完成'), JSON_UNESCAPED_UNICODE); ?>,
                syncFailed: <?php echo json_encode(_t('同步失败'), JSON_UNESCAPED_UNICODE); ?>,
                clearFailed: <?php echo json_encode(_t('清空失败'), JSON_UNESCAPED_UNICODE); ?>,
                confirmClear: <?php echo json_encode(_t('确定要清空本地已缓存的番剧数据吗？'), JSON_UNESCAPED_UNICODE); ?>,
                confirmCoverClear: <?php echo json_encode(_t('确定要删除全部已缓存的封面图片吗？'), JSON_UNESCAPED_UNICODE); ?>,
                requestFailed: <?php echo json_encode(_t('请求失败'), JSON_UNESCAPED_UNICODE); ?>
            };

            function notice(message, type) {
                var $notice = $('<div class="message ' + (type || 'success') + '"><ul><li></li></ul></div>');

                $notice.find('li').text(message);
                $('#bgm-admin-notice').empty().append($notice).show();
            }

            function request(action, data) {
                return $.ajax({
                    url: actionUrl + joiner + 'do=' + encodeURIComponent(action),
                    type: 'POST',
                    data: data || {},
                    dataType: 'json'
                });
            }

            function failMessage(xhr, fallback) {
                var response = xhr.responseJSON || {};

                return response.message || fallback || text.requestFailed;
            }

            function applySnapshot(data) {
                if (!data) {
                    return;
                }

                $('#bgm-admin-count').text(data.count);
                $('#bgm-admin-synced').text(data.syncedText);

                if (data.covers) {
                    $('#bgm-cover-cached').text(data.covers.cached);
                    $('#bgm-cover-total').text(data.covers.total);
                    $('#bgm-cover-missing').text(data.covers.missing);
                    $('#bgm-cover-size').text(data.covers.sizeText);

                    var percent = data.covers.total > 0
                        ? Math.round(data.covers.cached / data.covers.total * 100)
                        : 0;
                    $('#bgm-cover-progress').addClass('is-on').find('span').css('width', percent + '%');
                }
            }

            /* ---------- 番剧数据 ---------- */

            $('#bgm-admin-refresh').on('click', function () {
                if (running) {
                    return;
                }

                running = true;
                var $button = $(this).prop('disabled', true).text(text.syncing);

                request('refresh').done(function (response) {
                    if (response && response.success) {
                        // 列表内容需要重新渲染，直接刷新页面最省事也最准确
                        notice(response.message || text.synced);
                        setTimeout(function () {
                            window.location.reload();
                        }, 800);
                        return;
                    }

                    notice(response && response.message ? response.message : text.syncFailed, 'error');
                }).fail(function (xhr) {
                    notice(failMessage(xhr, text.syncFailed), 'error');
                }).always(function () {
                    running = false;
                    $button.prop('disabled', false).text(text.refresh);
                });
            });

            $('#bgm-admin-clear').on('click', function () {
                if (running || !window.confirm(text.confirmClear)) {
                    return;
                }

                running = true;

                request('clear').done(function (response) {
                    if (response && response.success) {
                        window.location.reload();
                        return;
                    }

                    notice(response && response.message ? response.message : text.clearFailed, 'error');
                }).fail(function (xhr) {
                    notice(failMessage(xhr, text.clearFailed), 'error');
                }).always(function () {
                    running = false;
                });
            });

            /* ---------- 图片缓存 ---------- */

            // 一批一批地缓存，既能显示进度，也不会让单个请求跑太久
            function runCoverCache(force) {
                if (running) {
                    return;
                }

                running = true;
                $('#bgm-cover-progress').addClass('is-on');

                var cached = 0;
                var failed = 0;
                var $buttons = $('#bgm-cover-cache, #bgm-cover-recache, #bgm-cover-prune, #bgm-cover-clear');
                var $host = $('#bgm-cover-cache');

                $buttons.prop('disabled', true);
                $host.text(text.syncing);

                function finish() {
                    running = false;
                    $buttons.prop('disabled', false);
                    $host.text(<?php echo json_encode(_t('缓存缺失图片'), JSON_UNESCAPED_UNICODE); ?>);
                }

                function step() {
                    request('coverCache', {limit: 60, force: force ? 1 : 0}).done(function (response) {
                        if (!response || !response.success) {
                            notice(response && response.message ? response.message : text.requestFailed, 'error');
                            finish();
                            return;
                        }

                        cached += response.data.batchCached || 0;
                        failed += response.data.batchFailed || 0;
                        applySnapshot(response.data);

                        if (response.data.remaining > 0) {
                            step();
                            return;
                        }

                        var message = <?php echo json_encode(_t('已缓存 %d 张'), JSON_UNESCAPED_UNICODE); ?>
                            .replace('%d', cached);
                        if (failed > 0) {
                            message += <?php echo json_encode(_t('，%d 张下载失败（可稍后重试）'), JSON_UNESCAPED_UNICODE); ?>
                                .replace('%d', failed);
                        }
                        notice(message);
                        finish();
                    }).fail(function (xhr) {
                        notice(failMessage(xhr), 'error');
                        finish();
                    });
                }

                step();
            }

            $('#bgm-cover-cache').on('click', function () {
                runCoverCache(false);
            });

            $('#bgm-cover-recache').on('click', function () {
                runCoverCache(true);
            });

            $('#bgm-cover-prune').on('click', function () {
                if (running) {
                    return;
                }

                request('coverPrune').done(function (response) {
                    notice(response && response.success ? response.message : text.requestFailed,
                        response && response.success ? 'success' : 'error');
                    applySnapshot(response && response.data);
                }).fail(function (xhr) {
                    notice(failMessage(xhr), 'error');
                });
            });

            $('#bgm-cover-clear').on('click', function () {
                if (running || !window.confirm(text.confirmCoverClear)) {
                    return;
                }

                request('coverClear').done(function (response) {
                    notice(response && response.success ? response.message : text.requestFailed,
                        response && response.success ? 'success' : 'error');
                    applySnapshot(response && response.data);
                }).fail(function (xhr) {
                    notice(failMessage(xhr), 'error');
                });
            });
        });
    })(jQuery);
</script>
<?php include 'footer.php'; ?>
