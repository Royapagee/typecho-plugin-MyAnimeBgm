<?php

namespace TypechoPlugin\MyAnimeBgm;

use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 前台展示的数据与格式化工具。
 *
 * 前台有两条渲染路径：插件自己注册的 /bangumi 路由，以及复制到主题里的独立
 * 页面模板。两条路径都通过这里取数据、取文案，保证展示完全一致。
 *
 * 所有方法都做了兜底：插件停用、数据表不存在、接口未配置时都只是返回空数据，
 * 不会把主题页面搞成致命错误。
 */
final class View
{
    /**
     * 请求内缓存，避免重复查库
     *
     * @var array<int, array<string, mixed>>|null
     */
    private static ?array $items = null;

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $settings = null;

    private static ?string $pluginUrl = null;

    /**
     * 插件配置（未配置时回落到默认值）。
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        if (self::$settings === null) {
            try {
                self::$settings = Plugin::settings();
            } catch (\Throwable $e) {
                self::$settings = Plugin::defaults();
            }
        }

        return self::$settings;
    }

    /**
     * 全部番剧，已按配置的规则排序。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function items(): array
    {
        if (self::$items === null) {
            try {
                self::$items = Store::all(self::settings());
            } catch (\Throwable $e) {
                self::$items = [];
            }
        }

        return self::$items;
    }

    /**
     * 最近一次同步时间。
     */
    public static function syncedAt(): int
    {
        try {
            return Store::syncedAt(self::settings());
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 页面标题。
     */
    public static function title(): string
    {
        $title = trim((string) (self::settings()['pageTitle'] ?? ''));

        return $title === '' ? _t('我的追番') : $title;
    }

    /**
     * 状态筛选标签。分页已取消，筛选完全在前端完成，这里只提供标签和数量。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tabs(): array
    {
        $counts = [];
        foreach (self::items() as $item) {
            $type = (int) ($item['collection_type'] ?? 0);
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        $tabs = [[
            'value' => '',
            'label' => _t('全部'),
            'count' => count(self::items()),
        ]];

        foreach (Api::collectionTypes() as $type => $label) {
            // 没有数据的状态不显示，避免一排空标签
            if (empty($counts[$type])) {
                continue;
            }

            $tabs[] = [
                'value' => (string) $type,
                'label' => $label,
                'count' => $counts[$type],
            ];
        }

        return $tabs;
    }

    /**
     * 插件的静态资源地址。
     */
    public static function asset(string $path): string
    {
        if (self::$pluginUrl === null) {
            try {
                self::$pluginUrl = rtrim((string) Options::alloc()->pluginUrl, '/');
            } catch (\Throwable $e) {
                self::$pluginUrl = '';
            }
        }

        return self::$pluginUrl . '/' . ltrim($path, '/');
    }

    /**
     * 渲染番剧墙的主体内容。
     *
     * @param array<string, mixed> $options 可传 title 覆盖标题文案，传 false 则不输出标题
     */
    public static function gallery(array $options = []): void
    {
        // 主题页面模板希望用自己的页面标题，这里允许调用方覆盖
        $galleryTitle = array_key_exists('title', $options) ? $options['title'] : self::title();

        require __DIR__ . '/template/gallery.php';
    }

    /**
     * HTML 转义。
     *
     * @param mixed $value
     */
    public static function escape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 显示名：优先中文名，没有则用原名。
     *
     * @param array<string, mixed> $item
     */
    public static function displayName(array $item): string
    {
        $nameCn = trim((string) ($item['name_cn'] ?? ''));

        return $nameCn !== '' ? $nameCn : (string) ($item['name'] ?? '');
    }

    /**
     * 原名。
     *
     * @param array<string, mixed> $item
     */
    public static function originalName(array $item): string
    {
        return trim((string) ($item['name'] ?? ''));
    }

    /**
     * 封面地址：本地缓存优先，没有缓存时回落到远端地址。
     *
     * @param array<string, mixed> $item
     */
    public static function cover(array $item): string
    {
        $subjectId = (int) ($item['subject_id'] ?? 0);
        $cached = ImageCache::cachedUrl($subjectId);

        if ($cached !== null) {
            return $cached;
        }

        // 取值顺序与 ImageCache::remoteSource() 保持一致
        return ImageCache::remoteSource($item);
    }

    /**
     * 该封面是否已经缓存到本地。
     *
     * @param array<string, mixed> $item
     */
    public static function isCoverCached(array $item): bool
    {
        return ImageCache::has((int) ($item['subject_id'] ?? 0));
    }

    /**
     * 追番状态文案。
     *
     * @param array<string, mixed> $item
     */
    public static function collectionLabel(array $item): string
    {
        $types = Api::collectionTypes();

        return $types[(int) ($item['collection_type'] ?? 0)] ?? _t('未分类');
    }

    /**
     * 追番状态对应的角标样式后缀。
     *
     * @param array<string, mixed> $item
     */
    public static function statusClass(array $item): string
    {
        $map = [
            Api::TYPE_WISH    => 'wish',
            Api::TYPE_DONE    => 'done',
            Api::TYPE_DOING   => 'doing',
            Api::TYPE_ON_HOLD => 'onhold',
            Api::TYPE_DROPPED => 'dropped',
        ];

        return $map[(int) ($item['collection_type'] ?? 0)] ?? 'done';
    }

    /**
     * 观看进度文案。
     *
     * @param array<string, mixed> $item
     */
    public static function progressLabel(array $item): string
    {
        $epStatus = (int) ($item['ep_status'] ?? 0);
        $eps = (int) ($item['eps'] ?? 0);

        // 一话没看时只说总话数，避免出现“看到第 0 话”这种别扭的说法
        if ($epStatus <= 0) {
            return $eps > 0 ? _t('共 %d 话', $eps) : '';
        }

        if ($eps > 0) {
            return _t('看到第 %d 话 / 共 %d 话', $epStatus, $eps);
        }

        return _t('看到第 %d 话', $epStatus);
    }

    /**
     * 观看进度百分比。
     *
     * @param array<string, mixed> $item
     */
    public static function progressPercent(array $item): float
    {
        $eps = (int) ($item['eps'] ?? 0);
        if ($eps <= 0) {
            return 0.0;
        }

        return max(0.0, min(100.0, (int) ($item['ep_status'] ?? 0) / $eps * 100));
    }

    /**
     * 条目地址。
     *
     * @param array<string, mixed> $item
     */
    public static function link(array $item): string
    {
        $url = trim((string) ($item['subject_url'] ?? ''));
        if ($url !== '') {
            return $url;
        }

        return 'https://bgm.tv/subject/' . (int) ($item['subject_id'] ?? 0);
    }
}
