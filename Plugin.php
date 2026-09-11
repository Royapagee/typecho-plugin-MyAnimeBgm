<?php

namespace TypechoPlugin\MyAnimeBgm;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Hidden;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Text;
use Utils\Helper;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 通过 Bangumi API 拉取指定用户的追番（收藏）列表，并注册一个独立页面用于像照片墙一样展示这些番剧。
 *
 * @package MyAnimeBgm
 * @author 罗伊
 * @version 1.1.0
 * @link https://blog.roysgensokyo.space/technology/493.html
 */
final class Plugin implements PluginInterface
{
    /**
     * 插件名。必须与插件目录名、命名空间末段完全一致，Typecho 靠这三个名字
     * 互相定位，改动时三处要一起改：
     *  - 自动加载：TypechoPlugin\MyAnimeBgm\Plugin → usr/plugins/MyAnimeBgm/Plugin.php
     *  - 插件配置：options 表中以 plugin:MyAnimeBgm 为键存取
     *  - 后台面板：addPanel() 的路径以它开头，extending.php 会拆出目录名去 require
     */
    public const NAME = 'MyAnimeBgm';

    /**
     * 前台页面的路由名称，固定不变；实际访问地址由配置项 routePath 决定
     */
    public const ROUTE = 'bangumi';

    /**
     * 配置项的默认值。
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'apiUrl'      => 'https://api.bgm.tv',
            'userId'      => 'sai',
            'accessToken' => '',
            'subjectType' => '2',
            'cacheType'   => 'db',
            'cacheTTL'    => '12',
            'orderBy'     => 'collection',
            'pageTitle'   => '我的追番',
            'routePath'   => '/bangumi',
        ];
    }

    /**
     * 读取插件配置，未配置的项回落到默认值。
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        $saved = [];

        try {
            $saved = Options::alloc()->plugin(self::NAME)->toArray();
        } catch (\Throwable $e) {
            // 插件尚未配置时 Options::plugin() 会抛异常，这里静默回落到默认值
        }

        $settings = array_merge(self::defaults(), is_array($saved) ? $saved : []);

        // 表单里被清空的项会以空字符串保存，空值一律回落到默认值
        foreach ($settings as $key => $value) {
            if ($value === null || $value === '') {
                $settings[$key] = self::defaults()[$key] ?? '';
            }
        }

        return $settings;
    }

    /**
     * 判断用户是否真的保存过配置。
     *
     * 注意不能用 settings()——它会把默认值补齐，而默认的 userId 是 sai，
     * 那样在全新安装时也会被当成“已配置”，永远提示不出“请先进行api配置”。
     */
    public static function hasSavedConfig(): bool
    {
        try {
            $saved = Options::alloc()->plugin(self::NAME)->toArray();
        } catch (\Throwable $e) {
            return false;
        }

        return is_array($saved)
            && trim((string) ($saved['apiUrl'] ?? '')) !== ''
            && trim((string) ($saved['userId'] ?? '')) !== '';
    }

    /**
     * 判断是否具备发起同步的条件。
     */
    public static function isConfigured(): bool
    {
        return self::hasSavedConfig();
    }

    /**
     * 激活插件：建表、注册前台路由与后台面板。
     *
     * 返回值会被 Typecho 当作提示信息展示，因此这里用返回字符串而不是抛异常
     * 的方式反馈同步结果——即使同步失败插件也应该保持启用，方便用户去改配置。
     *
     * @return string
     */
    public static function activate()
    {
        try {
            Store::install();
        } catch (\Throwable $e) {
            throw new \Typecho\Plugin\Exception(_t('MyAnimeBgm 建表失败：%s', $e->getMessage()));
        }

        self::registerRoute();
        Helper::addPanel(3, self::NAME . '/template/admin.php', _t('追番管理'), _t('Bangumi 追番数据'), 'administrator');
        Helper::addAction('bangumi-refresh', Action::class);

        if (!self::isConfigured()) {
            return _t('插件已启动，请先进行api配置');
        }

        try {
            Store::refresh(self::settings());
        } catch (\Throwable $e) {
            return _t('插件已启动，但番剧信息同步失败：%s，请检查api配置', $e->getMessage());
        }

        return _t('启动成功，番剧信息已存入数据库！');
    }

    /**
     * 停用插件：移除路由与后台面板，数据库中的番剧数据保留。
     */
    public static function deactivate(): void
    {
        Helper::removeRoute(self::ROUTE);
        Helper::removePanel(3, self::NAME . '/template/admin.php');
        Helper::removeAction('bangumi-refresh');
    }

    /**
     * 注册（或重新注册）前台独立页面路由。
     *
     * @param string|null $path 访问路径，为空时读取已保存的配置
     */
    public static function registerRoute(?string $path = null): void
    {
        if ($path === null) {
            $path = (string) self::settings()['routePath'];
        }

        $path = trim($path);
        if ($path === '') {
            $path = 'bangumi';
        }
        $path = '/' . ltrim($path, '/');

        Helper::addRoute(self::ROUTE, $path, Widget\Page::class, 'render', 'index');
    }

    /**
     * 构建插件配置表单。
     */
    public static function config(Form $form): void
    {
        $form->addInput(
            (new Text(
                'apiUrl',
                null,
                self::defaults()['apiUrl'],
                _t('Bangumi API 地址'),
                _t('默认使用官方地址 https://api.bgm.tv，也可以填写自建反代地址。')
            ))->addRule('required', _t('API 地址不能为空'))
        );

        $form->addInput(
            (new Text(
                'userId',
                null,
                self::defaults()['userId'],
                _t('用户 ID'),
                _t('Bangumi 的用户名或 UID，例如官方测试账号 sai。')
            ))->addRule('required', _t('用户 ID 不能为空'))
        );

        $form->addInput(
            new Password(
                'accessToken',
                null,
                '',
                _t('Access Token（可选）'),
                _t('仅当该用户的收藏属于私密可见时才需要填写，公开收藏留空即可。')
            )
        );

        $form->addInput(
            new Select(
                'subjectType',
                [
                    '2' => _t('动画'),
                    '1' => _t('书籍'),
                    '3' => _t('音乐'),
                    '4' => _t('游戏'),
                    '6' => _t('三次元'),
                ],
                self::defaults()['subjectType'],
                _t('收藏分类'),
                _t('要同步哪一类收藏，追番请选择“动画”。')
            )
        );

        $form->addInput(
            new Select(
                'cacheType',
                [
                    'db'   => _t('数据库（Typecho 数据表）'),
                    'file' => _t('文件缓存（JSON）'),
                ],
                self::defaults()['cacheType'],
                _t('缓存数据库类型'),
                _t('番剧数据的存放位置，推荐使用数据库，随站点一起备份。切换类型后原有的数据不会自动迁移，请重新同步一次。')
            )
        );

        $form->addInput(
            (new Text(
                'cacheTTL',
                null,
                self::defaults()['cacheTTL'],
                _t('缓存（自动刷新）时间'),
                _t('单位：小时。前台页面发现缓存过期时会自动重新拉取一次，填 0 表示每次访问都刷新。')
            ))
                ->addRule('isInteger', _t('刷新时间必须是整数小时'))
                // 注意：Typecho 没有 min 规则，写 'min' 会被 is_callable() 匹配到 PHP 自带的
                // min() 函数（min('12', 0) 返回 0 即校验失败），所以这里用闭包自己判断
                ->addRule(static fn($value): bool => (int) $value >= 0, _t('刷新时间不能小于 0'))
        );

        $form->addInput(
            new Select(
                'orderBy',
                [
                    'collection' => _t('按追番状态（在看 → 想看 → 看过 → 搁置 → 抛弃）'),
                    'updated'    => _t('按收藏更新时间'),
                    'rating'     => _t('按 Bangumi 评分'),
                    'name'       => _t('按番剧名称'),
                ],
                self::defaults()['orderBy'],
                _t('展示顺序'),
                _t('同一排序规则下都会按收藏更新时间倒序作为次级排序。')
            )
        );

        $form->addInput(
            (new Text(
                'pageTitle',
                null,
                self::defaults()['pageTitle'],
                _t('页面标题'),
                _t('前台番剧页面与浏览器标题中使用的标题。')
            ))
        );

        $form->addInput(
            (new Text(
                'routePath',
                null,
                self::defaults()['routePath'],
                _t('页面访问路径'),
                _t('前台番剧页面的地址，例如 /bangumi。修改并保存后立即生效。')
            ))
                ->addRule('regexp', _t('访问路径只能包含字母、数字、连字符、下划线和斜杠'), '/^\/?[A-Za-z0-9_\-\/]*$/')
        );

        self::addLegacyInputs($form);
    }

    /**
     * 为历史版本里残留、当前表单已不再提供的配置项补一个隐藏字段。
     *
     * Typecho 渲染插件设置页时，会把已保存的每个配置项回填到表单上
     * （Widget\Plugins\Config 里 `$form->getInput($key)->value($val)`），
     * 而 Form::getInput() 没有做空值保护——只要某个已保存的键在当前表单里
     * 找不到对应元素，设置页就会直接 500，且用户无法通过保存来自救。
     *
     * 所以这里把“已保存但表单没有”的键补成隐藏字段，让旧配置可以平滑降级。
     */
    private static function addLegacyInputs(Form $form): void
    {
        try {
            $saved = Options::alloc()->plugin(self::NAME)->toArray();
        } catch (\Throwable $e) {
            return;
        }

        if (!is_array($saved)) {
            return;
        }

        // 这里用 getInputs() 取全部字段再比对，避免直接调 getInput() 触发未定义键的警告
        $existing = $form->getInputs();

        foreach (array_keys($saved) as $key) {
            $key = (string) $key;
            if (!array_key_exists($key, $existing)) {
                $form->addInput(new Hidden($key, null, '', null));
            }
        }
    }

    /**
     * 保存配置前的校验与同步。
     *
     * Typecho 在保存插件配置前会先调用 configCheck()，这里直接使用表单里
     * 待保存的新参数去拉取一次数据：既完成了“保存即生效”的首次同步，也
     * 把同步结果作为提示信息返回。返回非空字符串时 Typecho 会跳过默认的
     * “插件设置已经保存”提示，正好用来展示这里的结果。
     *
     * @param array<string, mixed> $settings 表单提交的配置
     * @return string|null
     */
    public static function configCheck(array $settings): ?string
    {
        // 表单里被清空的项会以空字符串保存，这里先合并默认值再校验
        $settings = array_merge(self::defaults(), $settings);
        foreach ($settings as $key => $value) {
            if ($value === null || $value === '') {
                $settings[$key] = self::defaults()[$key] ?? '';
            }
        }

        if (trim((string) $settings['apiUrl']) === '' || trim((string) $settings['userId']) === '') {
            return _t('配置已保存，请填写 Bangumi API 地址与用户 ID 后再试');
        }

        try {
            Store::refresh($settings);
        } catch (\Throwable $e) {
            return _t('配置已保存，但番剧信息同步失败：%s', $e->getMessage());
        }

        // configCheck 会在配置真正写库前执行，这里直接用新值重新注册路由
        self::registerRoute((string) $settings['routePath']);

        return _t('启动成功，番剧信息已存入数据库！');
    }

    /**
     * 注意：这里刻意不实现 configHandle()。
     *
     * Typecho\Widget\Plugins\Edit::configHandle() 只要发现插件定义了同名方法，
     * 就会无条件返回 true 并跳过自己的写库逻辑，把保存完全交给插件。一旦定义了
     * 它却又不自己写 options，插件就会处于“已启用但没有配置行”的状态，配置页面
     * 读取 Options::plugin() 时会直接抛 500。
     *
     * 因此配置的落库交回给 Typecho 自己处理，插件只用 configCheck() 做校验与同步。
     */

    /**
     * 插件未提供个人配置项，这里保留空实现以符合接口约定。
     */
    public static function personalConfig(Form $form): void
    {
    }
}
