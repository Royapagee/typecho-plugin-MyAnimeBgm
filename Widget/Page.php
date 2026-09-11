<?php

namespace TypechoPlugin\MyAnimeBgm\Widget;

use TypechoPlugin\MyAnimeBgm\Chrome;
use TypechoPlugin\MyAnimeBgm\Plugin;
use TypechoPlugin\MyAnimeBgm\Store;
use TypechoPlugin\MyAnimeBgm\View;
use Widget\Archive;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 插件自带路由 /bangumi 的页面组件。
 *
 * 继承 Widget\Archive 是为了让主题的 header.php / footer.php 能正常工作——
 * 主题模板里的 $this->need()、$this->header()、$this->is() 等都由它提供。
 * 页面数据则来自插件的缓存，不参与 Typecho 的文章查询。
 *
 * 如果更希望页面和其它独立页面完全一致，用 theme/page-bangumi.php 那个主题
 * 页面模板（见 README），这里只是没往主题放文件时的兜底。
 */
final class Page extends Archive
{
    /**
     * 走一遍父类的初始化流程，让主题相关的东西（主题目录、feed 地址、
     * functions.php 等）都准备好，然后再处理自动刷新。
     */
    public function execute(): void
    {
        // 父类会按 page 参数去查询文章，文章数量不足时会直接抛 404。
        // 本页不分页，这里把 page 固定为 1，避免用户手改 URL 把页面搞成 404。
        $this->request->setParam('page', 1);

        parent::execute();

        // 让主题知道当前既不是首页也不是归档页
        $this->setArchiveType('page');
        $this->setArchiveSlug(Plugin::ROUTE);

        // 主题用 $this->archiveTitle() 输出浏览器标题
        $this->setArchiveTitle(View::title());

        $this->autoRefresh();
    }

    /**
     * 缓存过期时自动重新拉取一次；失败也不影响页面展示已有数据。
     */
    private function autoRefresh(): void
    {
        if (!Plugin::isConfigured() || !Store::isStale(View::settings())) {
            return;
        }

        $settings = View::settings();

        // php-fpm 环境下先把页面发给浏览器，再在后台完成同步，
        // 这样首次访问不会因为要拉几百条收藏而变慢。
        if (function_exists('fastcgi_finish_request')) {
            register_shutdown_function(static function () use ($settings): void {
                fastcgi_finish_request();
                try {
                    Store::refresh($settings);
                } catch (\Throwable $e) {
                    // 后台刷新失败时静默处理，下一次访问会重新尝试
                }
            });

            return;
        }

        // 其它环境（php -S、mod_php）只能在当前请求里同步执行，一次全量同步
        // 可能要十几秒，因此只让管理员触发；普通访客始终直接读缓存，不会被拖慢。
        if (!$this->user->pass('administrator', true)) {
            return;
        }

        try {
            Store::refresh($settings);
        } catch (\Throwable $e) {
            // 管理员看到的失败信息由后台面板负责展示，这里不打断页面渲染
        }
    }

    /**
     * 渲染页面：主题头部 + 番剧墙 + 主题底部。
     *
     * 主题外壳的探测与拼装统一交给 Chrome，和主题里的独立页面模板
     * （theme/page-bangumi.php）走同一套逻辑，换主题时两边都不用手改。
     */
    public function render()
    {
        Chrome::render(
            (string) $this->getThemeDir(),
            function (string $file): void {
                $this->need($file);
            }
        );
    }
}
