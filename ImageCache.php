<?php

namespace TypechoPlugin\MyAnimeBgm;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 封面图片本地缓存。
 *
 * lain.bgm.tv 在国内访问受限，前台直接引用远端图片会大量裂图。这里把封面下载
 * 到插件目录下的 covers/ 子目录，前台改为引用本地文件；下载是访问站点时由服务器
 * 发起的，不受访客网络环境影响。
 *
 * 文件统一命名为 {条目ID}.jpg —— Bangumi 的封面实际都是 JPEG，已在实测中确认。
 */
final class ImageCache
{
    /**
     * 缓存目录名（位于插件目录下，需要能被 Web 直接访问）
     */
    private const DIR = 'covers';

    /**
     * 单批并发下载数量
     */
    private const CONCURRENCY = 8;

    /**
     * 单张图片的下载超时（秒）
     */
    private const TIMEOUT = 15;

    /**
     * 单张图片的大小上限，超过则放弃
     */
    private const MAX_BYTES = 3145728;

    private const USER_AGENT = 'Royapagee/Typecho-MyAnimeBgm (https://github.com/Royapagee/Typecho_Blog)';

    /**
     * 缓存目录的绝对路径。
     */
    public static function dir(): string
    {
        return __DIR__ . '/' . self::DIR;
    }

    /**
     * 某条番剧的封面本地路径。
     */
    public static function path(int $subjectId): string
    {
        return self::dir() . '/' . $subjectId . '.jpg';
    }

    /**
     * 某条番剧的封面本地访问地址。
     */
    public static function url(int $subjectId): string
    {
        // 目录名走 Plugin::NAME，避免这里和插件目录名各写一份、改名后对不上
        return rtrim((string) \Widget\Options::alloc()->pluginUrl, '/')
            . '/' . Plugin::NAME . '/' . self::DIR . '/' . $subjectId . '.jpg';
    }

    /**
     * 该封面是否已经缓存到本地。
     */
    public static function has(int $subjectId): bool
    {
        return $subjectId > 0 && is_file(self::path($subjectId));
    }

    /**
     * 已缓存时返回本地地址，否则返回 null。
     */
    public static function cachedUrl(int $subjectId): ?string
    {
        return self::has($subjectId) ? self::url($subjectId) : null;
    }

    /**
     * 一条番剧该缓存哪张封面。
     *
     * 和前台 View::cover() 的取值顺序必须一致，否则会出现“缓存了 A、页面却引用 B”
     * 的情况。这里优先取 400px 的 medium，页面上的卡片只有一两百像素宽，
     * 用大图纯属浪费（大图约 280KB，medium 只要 45KB 左右）。
     *
     * @param array<string, mixed> $item
     */
    public static function remoteSource(array $item): string
    {
        $medium = trim((string) ($item['image_medium'] ?? ''));
        if ($medium !== '') {
            return $medium;
        }

        return trim((string) ($item['image'] ?? ''));
    }

    /**
     * 确保缓存目录存在且可写。
     */
    public static function ensureDir(): bool
    {
        $dir = self::dir();

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return is_dir($dir) && is_writable($dir);
    }

    /**
     * 缓存统计。
     *
     * @return array{count: int, bytes: int}
     */
    public static function stats(): array
    {
        $files = self::files();
        $bytes = 0;

        foreach ($files as $file) {
            $bytes += (int) @filesize($file);
        }

        return ['count' => count($files), 'bytes' => $bytes];
    }

    /**
     * 清空全部缓存文件。
     *
     * @return int 删除的文件数
     */
    public static function clear(): int
    {
        $deleted = 0;
        foreach (self::files() as $file) {
            if (@unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * 删除已经不在收藏列表里的封面文件。
     *
     * @param array<int, int> $keepIds 需要保留的条目 ID
     * @return int 删除的文件数
     */
    public static function prune(array $keepIds): int
    {
        $keep = array_flip(array_map('intval', $keepIds));
        $deleted = 0;

        foreach (self::files() as $file) {
            $id = (int) basename($file, '.jpg');
            if (!isset($keep[$id]) && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * 批量缓存缺失的封面。
     *
     * 用 curl_multi 并发下载，单批最多处理 $limit 张，方便在后台面板里做进度条。
     *
     * @param array<int, array<string, mixed>> $items 番剧数据
     * @param int $limit 本批最多下载多少张
     * @param bool $force 是否连已缓存的也重新下载
     * @return array{cached: int, failed: int, remaining: int}
     */
    public static function cacheBatch(array $items, int $limit, bool $force = false): array
    {
        if (!self::ensureDir()) {
            throw new \RuntimeException(_t('无法创建图片缓存目录 %s，请检查插件目录的写权限', self::dir()));
        }

        // 这里显式拦一道：没装 curl 时下面无论走哪条下载路径都会撞上
        // “Call to undefined function curl_*”，直接抛个能看懂的提示更好。
        // 顺带一提，没有 curl 时 Api 那边也拉不到收藏，插件整体都跑不起来。
        if (!function_exists('curl_multi_init')) {
            throw new \RuntimeException(_t('封面缓存需要 PHP 的 cURL 扩展，请先安装并启用'));
        }

        $pending = [];
        $total = count($items);

        foreach ($items as $item) {
            $id = (int) ($item['subject_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (!$force && self::has($id)) {
                continue;
            }

            $source = self::remoteSource($item);
            if ($source === '') {
                continue;
            }

            $pending[$id] = $source;
        }

        $remaining = count($pending);
        if ($remaining === 0) {
            return ['cached' => 0, 'failed' => 0, 'remaining' => 0];
        }

        $batch = array_slice($pending, 0, max(1, $limit), true);
        $result = self::downloadAll($batch);

        $stillPending = $remaining - count($batch);

        return [
            'cached'    => $result['ok'],
            'failed'    => $result['fail'],
            'remaining' => max(0, $stillPending),
        ];
    }

    /**
     * 统计还有多少张封面没有缓存。
     *
     * @param array<int, array<string, mixed>> $items
     */
    public static function missingCount(array $items): int
    {
        $missing = 0;

        foreach ($items as $item) {
            $id = (int) ($item['subject_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (self::remoteSource($item) === '') {
                continue;
            }

            if (!self::has($id)) {
                $missing++;
            }
        }

        return $missing;
    }

    /**
     * 缓存目录下的所有图片文件。
     *
     * @return array<int, string>
     */
    private static function files(): array
    {
        if (!is_dir(self::dir())) {
            return [];
        }

        $files = glob(self::dir() . '/*.jpg');

        return $files === false ? [] : $files;
    }

    /**
     * 并发下载一批图片。
     *
     * @param array<int, string> $targets [条目ID => 图片地址]
     * @return array{ok: int, fail: int}
     */
    private static function downloadAll(array $targets): array
    {
        // curl 的存在性由 cacheBatch() 统一把关，这里不再重复判断
        $multi = curl_multi_init();
        $handles = [];

        foreach ($targets as $id => $url) {
            $handle = self::createHandle($url);
            if ($handle === null) {
                continue;
            }

            $handles[$id] = $handle;
            curl_multi_add_handle($multi, $handle);
        }

        if ($handles === []) {
            curl_multi_close($multi);

            return ['ok' => 0, 'fail' => count($targets)];
        }

        // 并发跑完所有请求
        $running = 0;
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $ok = 0;
        $fail = 0;

        foreach ($handles as $id => $handle) {
            $body = curl_multi_getcontent($handle);
            $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $error = curl_errno($handle);
            $type = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);

            $valid = $error === 0
                && $code >= 200 && $code < 300
                && is_string($body)
                && strlen($body) > 0
                && strlen($body) <= self::MAX_BYTES
                && str_starts_with(strtolower($type), 'image/')
                && @getimagesizefromstring($body) !== false;

            if ($valid && self::store($id, $body)) {
                $ok++;
            } else {
                $fail++;
            }

            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }

        curl_multi_close($multi);

        return ['ok' => $ok, 'fail' => $fail];
    }

    /**
     * 创建一个图片下载句柄。
     *
     * @return \CurlHandle|null
     */
    private static function createHandle(string $url)
    {
        $handle = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_MAXFILESIZE    => self::MAX_BYTES,
        ]);

        return $handle;
    }

    /**
     * 写入图片文件（先写临时文件再改名，避免出现半截文件）。
     */
    private static function store(int $subjectId, string $bytes): bool
    {
        $target = self::path($subjectId);
        $temp = $target . '.tmp';

        if (@file_put_contents($temp, $bytes) === false) {
            return false;
        }

        if (!@rename($temp, $target)) {
            @unlink($temp);

            return false;
        }

        return true;
    }
}
