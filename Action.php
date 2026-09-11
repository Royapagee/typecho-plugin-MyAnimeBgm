<?php

namespace TypechoPlugin\MyAnimeBgm;

use Typecho\Widget\Exception;
use Widget\ActionInterface;
use Widget\Base;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 后台追番管理面板的动作入口。
 *
 * 只接受管理员的 POST 请求，统一返回 JSON 给模板页里的脚本消费。
 */
final class Action extends Base implements ActionInterface
{
    /**
     * 单批图片缓存的默认张数
     */
    private const COVER_BATCH = 60;

    /**
     * 处理面板请求。
     */
    public function action(): void
    {
        $status = 200;
        $response = ['success' => true];

        try {
            $this->user->pass('administrator');
            $this->security->protect();

            if (!$this->request->isPost()) {
                throw new Exception(_t('请求方式错误'), 405);
            }

            $response += match ((string) $this->request->get('do')) {
                'refresh'     => $this->refresh(),
                'clear'       => $this->clear(),
                'status'      => $this->status(),
                'coverCache'  => $this->coverCache(),
                'coverClear'  => $this->coverClear(),
                'coverPrune'  => $this->coverPrune(),
                default       => throw new Exception(_t('未知操作'), 400),
            };
        } catch (\Throwable $exception) {
            $status = (int) $exception->getCode();
            $response = [
                'success' => false,
                'message' => $exception->getMessage() ?: _t('请求失败'),
            ];
        }

        // 兜底修正异常码，避免非标准 code 影响前端错误处理
        if (!$response['success'] && ($status < 400 || $status >= 600)) {
            $status = 500;
        }

        $this->response
            ->setStatus($status)
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->throwJson($response);
    }

    /**
     * 立即重新拉取一次番剧数据。
     *
     * @return array<string, mixed>
     */
    private function refresh(): array
    {
        if (!Plugin::isConfigured()) {
            throw new Exception(_t('请先填写 Bangumi API 地址与用户 ID'), 400);
        }

        $settings = Plugin::settings();
        $count = Store::refresh($settings);

        return [
            'message' => _t('启动成功，番剧信息已存入数据库！'),
            'data'    => $this->snapshot($settings, $count),
        ];
    }

    /**
     * 清空本地缓存的番剧数据。
     *
     * @return array<string, mixed>
     */
    private function clear(): array
    {
        $settings = Plugin::settings();
        Store::clear($settings);

        return [
            'message' => _t('番剧数据已清空'),
            'data'    => $this->snapshot($settings, 0),
        ];
    }

    /**
     * 只读取当前状态，不触发同步。
     *
     * @return array<string, mixed>
     */
    private function status(): array
    {
        $settings = Plugin::settings();
        $items = Store::all($settings);

        return [
            'message' => _t('状态已更新'),
            'data'    => $this->snapshot($settings, count($items), $items),
        ];
    }

    /**
     * 缓存一批封面图片。
     *
     * 前端会反复调用直到 remaining 归零，这样既能显示进度，也不会撑爆单次请求。
     *
     * @return array<string, mixed>
     */
    private function coverCache(): array
    {
        $settings = Plugin::settings();
        $items = Store::all($settings);

        if (empty($items)) {
            throw new Exception(_t('还没有番剧数据，请先同步'), 400);
        }

        $limit = (int) $this->request->get('limit', self::COVER_BATCH);
        $limit = max(1, min(200, $limit));
        $force = (string) $this->request->get('force') === '1';

        $result = ImageCache::cacheBatch($items, $limit, $force);

        return [
            'message' => _t('本批缓存 %d 张，失败 %d 张', $result['cached'], $result['failed']),
            'data'    => $this->snapshot($settings, count($items), $items) + [
                'batchCached'    => $result['cached'],
                'batchFailed'    => $result['failed'],
                'remaining'      => $result['remaining'],
            ],
        ];
    }

    /**
     * 清空图片缓存。
     *
     * @return array<string, mixed>
     */
    private function coverClear(): array
    {
        $settings = Plugin::settings();
        $deleted = ImageCache::clear();
        $items = Store::all($settings);

        return [
            'message' => _t('已删除 %d 张缓存图片', $deleted),
            'data'    => $this->snapshot($settings, count($items), $items),
        ];
    }

    /**
     * 删除已经不在收藏列表里的缓存图片。
     *
     * @return array<string, mixed>
     */
    private function coverPrune(): array
    {
        $settings = Plugin::settings();
        $items = Store::all($settings);

        $keep = [];
        foreach ($items as $item) {
            $keep[] = (int) ($item['subject_id'] ?? 0);
        }

        $deleted = ImageCache::prune($keep);

        return [
            'message' => _t('已清理 %d 张无主图片', $deleted),
            'data'    => $this->snapshot($settings, count($items), $items),
        ];
    }

    /**
     * 组装返回给前端的状态快照。
     *
     * @param array<string, mixed> $settings
     * @param array<int, array<string, mixed>>|null $items
     * @return array<string, mixed>
     */
    private function snapshot(array $settings, int $count, ?array $items = null): array
    {
        $syncedAt = Store::syncedAt($settings);

        if ($items === null) {
            $items = Store::all($settings);
        }

        $stats = ImageCache::stats();

        return [
            'count'      => $count,
            'syncedAt'   => $syncedAt,
            'syncedText' => $syncedAt > 0 ? date('Y-m-d H:i:s', $syncedAt) : _t('从未同步'),
            'cacheType'  => (string) $settings['cacheType'],
            'userId'     => (string) $settings['userId'],
            'covers'     => [
                'cached'   => $stats['count'],
                'bytes'    => $stats['bytes'],
                'sizeText' => self::humanSize($stats['bytes']),
                'missing'  => ImageCache::missingCount($items),
                'total'    => $count,
            ],
        ];
    }

    /**
     * 把字节数格式化成可读文本。
     */
    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }
}
