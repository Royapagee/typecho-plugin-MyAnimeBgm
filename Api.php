<?php

namespace TypechoPlugin\MyAnimeBgm;

use Typecho\Http\Client;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Bangumi API v0 客户端。
 *
 * 目前只用到两个接口：
 *  - GET /v0/users/{username}                    读取用户信息，用于校验用户是否存在
 *  - GET /v0/users/{username}/collections        分页读取用户的收藏（追番）列表
 *
 * 文档参见 https://bangumi.github.io/api/
 */
final class Api
{
    /** 想看 */
    public const TYPE_WISH = 1;

    /** 看过 */
    public const TYPE_DONE = 2;

    /** 在看 */
    public const TYPE_DOING = 3;

    /** 搁置 */
    public const TYPE_ON_HOLD = 4;

    /** 抛弃 */
    public const TYPE_DROPPED = 5;

    /**
     * 单页拉取的最大条数，Bangumi API 的上限就是 100（再大会返回 400）
     */
    private const PAGE_SIZE = 100;

    /**
     * 单次同步最多拉取的页数，避免用户收藏量异常巨大时把请求拖死
     */
    private const MAX_PAGES = 60;

    /**
     * 单次请求的最大尝试次数（网络抖动时重试）
     */
    private const MAX_ATTEMPTS = 3;

    private string $baseUrl;
    private string $userId;
    private string $accessToken;
    private string $userAgent;
    private int $timeout;

    /**
     * @param string $baseUrl API 地址，例如 https://api.bgm.tv
     * @param string $userId Bangumi 用户名或 UID
     * @param string $accessToken 可选，访问私密收藏时使用
     * @param int $timeout 单次请求超时时间（秒）
     */
    public function __construct(string $baseUrl, string $userId, string $accessToken = '', int $timeout = 20)
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $this->baseUrl = $baseUrl === '' ? 'https://api.bgm.tv' : $baseUrl;
        $this->userId = trim($userId);
        $this->accessToken = trim($accessToken);
        $this->timeout = max(3, $timeout);

        // Bangumi 会拦截默认的 curl User-Agent，这里带上插件标识
        $this->userAgent = 'Royapagee/Typecho-MyAnimeBgm (https://github.com/Royapagee/Typecho_Blog)';
    }

    /**
     * 收藏类型的全部取值与中国区文案。
     *
     * @return array<int, string>
     */
    public static function collectionTypes(): array
    {
        return [
            self::TYPE_WISH     => _t('想看'),
            self::TYPE_DONE     => _t('看过'),
            self::TYPE_DOING    => _t('在看'),
            self::TYPE_ON_HOLD  => _t('搁置'),
            self::TYPE_DROPPED  => _t('抛弃'),
        ];
    }

    /**
     * 收藏分类（subject_type）的取值与文案。
     *
     * @return array<int, string>
     */
    public static function subjectTypes(): array
    {
        return [
            1 => _t('书籍'),
            2 => _t('动画'),
            3 => _t('音乐'),
            4 => _t('游戏'),
            6 => _t('三次元'),
        ];
    }

    /**
     * 读取用户信息。
     *
     * @return array<string, mixed>
     */
    public function fetchUser(): array
    {
        $response = $this->request('/v0/users/' . rawurlencode($this->userId));
        $data = $response['data'];

        if (!is_array($data) || !isset($data['id'])) {
            throw new \RuntimeException(_t('Bangumi 没有返回用户 %s 的信息', $this->userId));
        }

        return $data;
    }

    /**
     * 分页拉取某个分类下的全部收藏。
     *
     * @param int $subjectType 收藏分类，2 为动画
     * @return array<int, array<string, mixed>> 归一化后的番剧数据
     */
    public function fetchCollections(int $subjectType = 2): array
    {
        $path = '/v0/users/' . rawurlencode($this->userId) . '/collections';
        $offset = 0;
        $collected = [];
        $seen = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->request($path, [
                'subject_type' => $subjectType,
                'limit'        => self::PAGE_SIZE,
                'offset'       => $offset,
            ]);

            $items = $this->extractItems($response['data']);
            if (empty($items)) {
                break;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $row = $this->normalize($item, $subjectType);
                // 同一个条目理论上只会出现一次，这里做一次防御性去重
                if ($row !== null && !isset($seen[$row['subject_id']])) {
                    $seen[$row['subject_id']] = true;
                    $collected[] = $row;
                }
            }

            $offset += self::PAGE_SIZE;

            // 优先使用响应头里的总数判断是否已经拉完
            if ($response['total'] !== null && $offset >= $response['total']) {
                break;
            }

            if (count($items) < self::PAGE_SIZE) {
                break;
            }
        }

        if (empty($collected)) {
            throw new \RuntimeException(_t('用户 %s 的这个分类下没有任何收藏', $this->userId));
        }

        return $collected;
    }

    /**
     * 兼容 Bangumi 可能返回的两种结构：{data: [...], total: n} 或直接返回数组。
     *
     * @param mixed $data
     * @return array<int, mixed>
     */
    private function extractItems($data): array
    {
        if (!is_array($data)) {
            return [];
        }

        if (isset($data['data']) && is_array($data['data'])) {
            return array_values($data['data']);
        }

        return array_values($data);
    }

    /**
     * 读取 subject 下可能是数字也可能是字符串的字段。
     *
     * @param array<string, mixed> $source
     * @param array<int, string> $keys 依次尝试的字段名
     */
    private function number(array $source, array $keys): float
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && is_numeric($source[$key])) {
                return (float) $source[$key];
            }
        }

        return 0.0;
    }

    /**
     * 把 API 返回的一条收藏记录整理成统一的字段结构。
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function normalize(array $item, int $subjectType): ?array
    {
        $subject = isset($item['subject']) && is_array($item['subject']) ? $item['subject'] : [];
        $subjectId = (int) ($item['subject_id'] ?? $subject['id'] ?? 0);

        if ($subjectId <= 0) {
            return null;
        }

        $images = isset($subject['images']) && is_array($subject['images']) ? $subject['images'] : [];
        $fallbackImage = (string) ($subject['image'] ?? '');
        $large = (string) ($images['large'] ?? $images['common'] ?? $fallbackImage);
        $medium = (string) ($images['common'] ?? $images['medium'] ?? $large);

        return [
            'subject_id'      => $subjectId,
            'subject_type'    => $subjectType,
            'name'            => (string) ($subject['name'] ?? ''),
            'name_cn'         => (string) ($subject['name_cn'] ?? ''),
            'image'           => $large,
            'image_medium'    => $medium,
            'collection_type' => (int) ($item['type'] ?? 0),
            'ep_status'       => (int) ($item['ep_status'] ?? 0),
            'vol_status'      => (int) ($item['vol_status'] ?? 0),
            'eps'             => (int) $this->number($subject, ['eps', 'total_episodes', 'volumes']),
            'rate'            => (int) ($item['rate'] ?? 0),
            // 收藏列表接口把评分直接放在 subject 上；详情接口则包在 rating 里，两种都兼容
            'score'           => $this->number($subject, ['score']) ?: $this->number(
                isset($subject['rating']) && is_array($subject['rating']) ? $subject['rating'] : [],
                ['score']
            ),
            'rank'            => (int) ($this->number($subject, ['rank']) ?: $this->number(
                isset($subject['rating']) && is_array($subject['rating']) ? $subject['rating'] : [],
                ['rank']
            )),
            'comment'         => trim((string) ($item['comment'] ?? '')),
            'air_date'        => (string) ($subject['date'] ?? ''),
            'subject_url'     => 'https://bgm.tv/subject/' . $subjectId,
            'updated_at'      => $this->parseTime($item['updated_at'] ?? null),
        ];
    }

    /**
     * 解析 Bangumi 返回的 ISO8601 时间。
     *
     * @param mixed $value
     */
    private function parseTime($value): int
    {
        if (!is_string($value) || $value === '') {
            return time();
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? time() : $timestamp;
    }

    /**
     * 发起一次 GET 请求并解析 JSON。
     *
     * @param array<string, mixed> $query
     * @return array{data: mixed, total: int|null}
     */
    private function request(string $path, array $query = []): array
    {
        if (Client::get() === null) {
            throw new \RuntimeException(_t('当前 PHP 环境未启用 cURL 扩展，无法访问 Bangumi API'));
        }

        $url = $this->baseUrl . $path;
        if (!empty($query)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $client = null;
        $body = null;
        $lastError = '';

        // Bangumi 走 Cloudflare，偶发 TLS 连接被提前关闭，这里重试几次
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            // Client::get() 每次都会新建实例，避免上一次的响应头残留下来
            $client = Client::get();
            $client->setMethod(Client::METHOD_GET)
                ->setTimeout($this->timeout)
                ->setAgent($this->userAgent)
                ->setHeader('Accept', 'application/json');

            if ($this->accessToken !== '') {
                $client->setHeader('Authorization', 'Bearer ' . $this->accessToken);
            }

            try {
                $client->send($url);
                $body = $client->getResponseBody();
                break;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();

                if ($attempt < self::MAX_ATTEMPTS) {
                    usleep(200000 * $attempt);
                }
            }
        }

        if ($body === null || $client === null) {
            throw new \RuntimeException(_t('请求 %s 失败：%s', $url, $lastError));
        }

        $status = $client->getResponseStatus();

        if ($status === 404) {
            throw new \RuntimeException(_t('Bangumi 用户 %s 不存在', $this->userId));
        }

        if ($status === 401 || $status === 403) {
            throw new \RuntimeException(_t(
                'Bangumi 拒绝了本次请求（HTTP %d），该用户的收藏可能未公开，请在插件设置里填写 Access Token',
                $status
            ));
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(_t('Bangumi API 返回异常状态码：HTTP %d', $status));
        }

        try {
            $data = json_decode($client->getResponseBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(_t('无法解析 Bangumi API 返回的数据'));
        }

        $total = $client->getResponseHeader('X-Total-Count');
        if (($total === null || !is_numeric($total)) && is_array($data) && isset($data['total']) && is_numeric($data['total'])) {
            $total = $data['total'];
        }

        return [
            'data'  => $data,
            'total' => $total === null || !is_numeric($total) ? null : (int) $total,
        ];
    }
}
