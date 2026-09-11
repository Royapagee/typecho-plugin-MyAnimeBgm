<?php

namespace TypechoPlugin\MyAnimeBgm;

use Typecho\Db;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 番剧数据的存取层。
 *
 * 支持两种缓存数据库类型：
 *  - db   写入 Typecho 自身的数据库表（默认，随站点一起备份）
 *  - file 写入插件目录下的 JSON 缓存文件
 *
 * 无论使用哪种后端，对外暴露的数据结构都是一致的。
 */
final class Store
{
    /**
     * 数据表名，table. 前缀会由 Typecho 替换成站点实际的前缀
     */
    public const TABLE = 'table.bangumi';

    /**
     * 文件缓存目录
     */
    private const CACHE_DIR = __DIR__ . '/cache';

    /**
     * 文件缓存路径
     */
    private const CACHE_FILE = self::CACHE_DIR . '/bangumi.json';

    /**
     * 最近一次同步“尝试”的时间戳文件。
     *
     * 记录尝试而不是成功，是为了在 API 长时间不可用时不会每次访问页面都去重试。
     */
    private const ATTEMPT_FILE = self::CACHE_DIR . '/last-attempt';

    /**
     * 按追番状态展示时的顺序权重：在看 → 想看 → 看过 → 搁置 → 抛弃
     */
    private const COLLECTION_ORDER = [3 => 0, 1 => 1, 2 => 2, 4 => 3, 5 => 4];

    /**
     * 建表，并准备好文件缓存目录。可重复调用。
     *
     * @throws Db\Exception
     */
    public static function install(): void
    {
        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0755, true);
        }

        $db = Db::get();
        $table = $db->getPrefix() . 'bangumi';
        $adapter = strtolower($db->getAdapterName());

        if (str_contains($adapter, 'sqlite')) {
            $driver = 'sqlite';
        } elseif (str_contains($adapter, 'pgsql') || str_contains($adapter, 'postgres')) {
            $driver = 'pgsql';
        } else {
            $driver = 'mysql';
        }

        // 这一段 DDL 在 MySQL / SQLite / PostgreSQL 下都通用，
        // 只有 MySQL 需要额外指定存储引擎与字符集。
        $columns = [
            '`subject_id` INTEGER NOT NULL',
            '`subject_type` INTEGER NOT NULL DEFAULT 0',
            "`name` VARCHAR(255) NOT NULL DEFAULT ''",
            "`name_cn` VARCHAR(255) NOT NULL DEFAULT ''",
            "`image` VARCHAR(512) NOT NULL DEFAULT ''",
            "`image_medium` VARCHAR(512) NOT NULL DEFAULT ''",
            '`collection_type` INTEGER NOT NULL DEFAULT 0',
            '`ep_status` INTEGER NOT NULL DEFAULT 0',
            '`vol_status` INTEGER NOT NULL DEFAULT 0',
            '`eps` INTEGER NOT NULL DEFAULT 0',
            '`rate` INTEGER NOT NULL DEFAULT 0',
            '`score` DECIMAL(4,1) NOT NULL DEFAULT 0',
            '`rank` INTEGER NOT NULL DEFAULT 0',
            '`comment` TEXT',
            "`air_date` VARCHAR(32) NOT NULL DEFAULT ''",
            "`subject_url` VARCHAR(255) NOT NULL DEFAULT ''",
            '`updated_at` INTEGER NOT NULL DEFAULT 0',
            '`synced_at` INTEGER NOT NULL DEFAULT 0',
            'PRIMARY KEY (`subject_id`)',
        ];

        if ($driver === 'pgsql') {
            $quote = '"';
            $columns = array_map(static fn(string $column): string => str_replace('`', '"', $column), $columns);
        } else {
            $quote = '`';
        }

        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        try {
            $db->query(
                'CREATE TABLE IF NOT EXISTS ' . $quote . $table . $quote
                . ' (' . implode(', ', $columns) . ')' . $suffix,
                Db::WRITE
            );
        } catch (\Throwable $e) {
            // 少数老版本 MySQL 不支持 utf8mb4，退回 utf8 再试一次
            if ($driver !== 'mysql' || !str_contains($e->getMessage(), 'utf8mb4')) {
                throw $e;
            }

            $db->query(
                'CREATE TABLE IF NOT EXISTS `' . $table . '` (' . implode(', ', $columns) . ')'
                . ' ENGINE=InnoDB DEFAULT CHARSET=utf8',
                Db::WRITE
            );
        }
    }

    /**
     * 从 Bangumi 拉取最新数据并整体覆盖本地缓存。
     *
     * @param array<string, mixed> $settings 插件配置
     * @return int 本次同步的番剧数量
     */
    public static function refresh(array $settings): int
    {
        // 先记下尝试时间，这样即使同步失败也不会每次访问页面都重试
        self::markAttempt();

        // 收藏较多时同步会持续十几秒，放宽执行时间上限避免写到一半被中断
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $api = new Api(
            (string) $settings['apiUrl'],
            (string) $settings['userId'],
            (string) ($settings['accessToken'] ?? '')
        );

        // 先确认用户存在，这样报错信息会比“收藏为空”更明确
        $api->fetchUser();

        $subjectType = (int) ($settings['subjectType'] ?? 2);
        $items = $api->fetchCollections($subjectType);

        $syncedAt = time();
        foreach ($items as $index => $item) {
            $items[$index]['synced_at'] = $syncedAt;
        }

        if (self::typeOf($settings) === 'file') {
            self::writeFile($items, $syncedAt);
        } else {
            self::writeDatabase($items);
        }

        return count($items);
    }

    /**
     * 读取全部番剧数据，按配置的规则排好序。
     *
     * 数据量通常在几百到几千条之间，因此统一在 PHP 侧排序，
     * 这样不同数据库之间的排序行为完全一致。
     *
     * @param array<string, mixed> $settings 插件配置
     * @return array<int, array<string, mixed>>
     */
    public static function all(array $settings): array
    {
        if (self::typeOf($settings) === 'file') {
            $items = self::readFile();
        } else {
            $items = self::readDatabase();
        }

        return self::sortItems($items, (string) ($settings['orderBy'] ?? 'collection'));
    }

    /**
     * 最近一次同步的时间戳，未同步过时返回 0。
     *
     * @param array<string, mixed> $settings 插件配置
     */
    public static function syncedAt(array $settings): int
    {
        if (self::typeOf($settings) === 'file') {
            $payload = self::readFilePayload();

            return (int) ($payload['synced_at'] ?? 0);
        }

        try {
            $db = Db::get();
            $row = $db->fetchObject(
                $db->select(['MAX(`synced_at`)' => 'synced_at'])->from(self::TABLE)
            );
        } catch (\Throwable $e) {
            return 0;
        }

        return $row ? (int) $row->synced_at : 0;
    }

    /**
     * 判断缓存是否已经超过自动刷新时间。
     *
     * @param array<string, mixed> $settings 插件配置
     */
    public static function isStale(array $settings): bool
    {
        $ttl = (int) ($settings['cacheTTL'] ?? 12);
        // 填 0 表示每次访问都重新拉取，此时不做尝试时间限制
        if ($ttl <= 0) {
            return true;
        }

        // 以“最近一次尝试”为准，避免同步失败后每次访问都去打接口
        $latest = max(self::syncedAt($settings), self::lastAttempt());
        if ($latest <= 0) {
            return true;
        }

        return time() - $latest >= $ttl * 3600;
    }

    /**
     * 最近一次同步尝试的时间戳。
     */
    public static function lastAttempt(): int
    {
        if (is_file(self::ATTEMPT_FILE)) {
            return (int) @file_get_contents(self::ATTEMPT_FILE);
        }

        return 0;
    }

    /**
     * 记录一次同步尝试。
     */
    public static function markAttempt(): void
    {
        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0755, true);
        }

        @file_put_contents(self::ATTEMPT_FILE, (string) time(), LOCK_EX);
    }

    /**
     * 清空缓存数据。
     *
     * @param array<string, mixed> $settings 插件配置
     */
    public static function clear(array $settings): void
    {
        if (self::typeOf($settings) === 'file') {
            if (is_file(self::CACHE_FILE)) {
                @unlink(self::CACHE_FILE);
            }

            return;
        }

        try {
            $db = Db::get();
            $db->query($db->delete(self::TABLE));
        } catch (\Throwable $e) {
            // 表还不存在时无需处理
        }
    }

    /**
     * 按展示顺序排序。
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public static function sortItems(array $items, string $orderBy): array
    {
        usort($items, static function (array $left, array $right) use ($orderBy): int {
            switch ($orderBy) {
                case 'updated':
                    $result = ($right['updated_at'] ?? 0) <=> ($left['updated_at'] ?? 0);
                    break;
                case 'rating':
                    $result = ($right['score'] ?? 0) <=> ($left['score'] ?? 0)
                        ?: ($right['updated_at'] ?? 0) <=> ($left['updated_at'] ?? 0);
                    break;
                case 'name':
                    $result = strcasecmp(
                        (string) ($left['name_cn'] ?: $left['name']),
                        (string) ($right['name_cn'] ?: $right['name'])
                    );
                    break;
                case 'collection':
                default:
                    $result = (self::COLLECTION_ORDER[$left['collection_type'] ?? 0] ?? 9)
                        <=> (self::COLLECTION_ORDER[$right['collection_type'] ?? 0] ?? 9)
                        ?: ($right['updated_at'] ?? 0) <=> ($left['updated_at'] ?? 0);
                    break;
            }

            return $result;
        });

        return $items;
    }

    /**
     * 读取配置里的缓存类型。
     *
     * @param array<string, mixed> $settings 插件配置
     */
    private static function typeOf(array $settings): string
    {
        return ($settings['cacheType'] ?? 'db') === 'file' ? 'file' : 'db';
    }

    /**
     * 整表覆盖写入数据库。
     *
     * 清空与写入放在同一个事务里，避免中途出错时留下半份数据。
     *
     * @param array<int, array<string, mixed>> $items
     * @throws Db\Exception
     */
    private static function writeDatabase(array $items): void
    {
        $db = Db::get();

        // BEGIN 在 MySQL / SQLite / PostgreSQL 下都可用
        $db->query('BEGIN', Db::WRITE);

        try {
            $db->query($db->delete(self::TABLE));

            foreach ($items as $item) {
                $db->query($db->insert(self::TABLE)->rows(self::toRow($item)));
            }

            $db->query('COMMIT', Db::WRITE);
        } catch (\Throwable $e) {
            try {
                $db->query('ROLLBACK', Db::WRITE);
            } catch (\Throwable $rollbackError) {
                // 回滚本身失败时保留原始异常更有价值
            }

            throw $e;
        }
    }

    /**
     * 从数据库读取全部数据。
     *
     * @return array<int, array<string, mixed>>
     */
    private static function readDatabase(): array
    {
        try {
            $db = Db::get();
            $rows = $db->fetchAll($db->select()->from(self::TABLE));
        } catch (\Throwable $e) {
            return [];
        }

        return array_map(static function (array $row): array {
            return [
                'subject_id'      => (int) $row['subject_id'],
                'subject_type'    => (int) $row['subject_type'],
                'name'            => (string) $row['name'],
                'name_cn'         => (string) $row['name_cn'],
                'image'           => (string) $row['image'],
                'image_medium'    => (string) $row['image_medium'],
                'collection_type' => (int) $row['collection_type'],
                'ep_status'       => (int) $row['ep_status'],
                'vol_status'      => (int) $row['vol_status'],
                'eps'             => (int) $row['eps'],
                'rate'            => (int) $row['rate'],
                'score'           => (float) $row['score'],
                'rank'            => (int) $row['rank'],
                'comment'         => (string) $row['comment'],
                'air_date'        => (string) $row['air_date'],
                'subject_url'     => (string) $row['subject_url'],
                'updated_at'      => (int) $row['updated_at'],
                'synced_at'       => (int) $row['synced_at'],
            ];
        }, $rows);
    }

    /**
     * 写入 JSON 缓存文件。
     *
     * @param array<int, array<string, mixed>> $items
     */
    private static function writeFile(array $items, int $syncedAt): void
    {
        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0755, true);
        }

        $payload = json_encode(
            ['synced_at' => $syncedAt, 'items' => $items],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($payload === false || @file_put_contents(self::CACHE_FILE, $payload, LOCK_EX) === false) {
            throw new \RuntimeException(_t('无法写入缓存文件 %s，请检查插件 cache 目录的写权限', self::CACHE_FILE));
        }
    }

    /**
     * 读取 JSON 缓存文件的完整内容。
     *
     * @return array<string, mixed>
     */
    private static function readFilePayload(): array
    {
        if (!is_file(self::CACHE_FILE)) {
            return [];
        }

        $content = @file_get_contents(self::CACHE_FILE);
        if ($content === false || $content === '') {
            return [];
        }

        $payload = json_decode($content, true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * 读取 JSON 缓存文件里的番剧列表。
     *
     * @return array<int, array<string, mixed>>
     */
    private static function readFile(): array
    {
        $payload = self::readFilePayload();
        $items = $payload['items'] ?? [];

        return is_array($items) ? array_values($items) : [];
    }

    /**
     * 把一条归一化数据整理成数据库的行结构。
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private static function toRow(array $item): array
    {
        return [
            'subject_id'      => (int) $item['subject_id'],
            'subject_type'    => (int) ($item['subject_type'] ?? 0),
            'name'            => (string) ($item['name'] ?? ''),
            'name_cn'         => (string) ($item['name_cn'] ?? ''),
            'image'           => (string) ($item['image'] ?? ''),
            'image_medium'    => (string) ($item['image_medium'] ?? ''),
            'collection_type' => (int) ($item['collection_type'] ?? 0),
            'ep_status'       => (int) ($item['ep_status'] ?? 0),
            'vol_status'      => (int) ($item['vol_status'] ?? 0),
            'eps'             => (int) ($item['eps'] ?? 0),
            'rate'            => (int) ($item['rate'] ?? 0),
            'score'           => (float) ($item['score'] ?? 0),
            'rank'            => (int) ($item['rank'] ?? 0),
            'comment'         => (string) ($item['comment'] ?? ''),
            'air_date'        => (string) ($item['air_date'] ?? ''),
            'subject_url'     => (string) ($item['subject_url'] ?? ''),
            'updated_at'      => (int) ($item['updated_at'] ?? 0),
            'synced_at'       => (int) ($item['synced_at'] ?? 0),
        ];
    }
}
