<?php
/**
 * MuseApi —— 给 Muse 用的 Typecho 评论/文章/图片管理 API（Token 鉴权）
 *
 * 做三件事：管理评论、管理文章、上传图片。编辑级权限，不碰用户、设置、
 * 链接、主题插件、备份和文件。v1.1 不提供删除文章接口（删文章请在后台
 * 手动操作）。图片上传只收图片（jpg/jpeg/png/webp/gif），单文件上限 10MB。
 *
 * 鉴权：每次请求在 X-Muse-Token 请求头里带 Token（见插件设置页）。
 * 路由：POST /index.php/action/muse-api，评论/文章走 JSON 请求体
 * {"action": "...", ...}；上传图片走 multipart/form-data（action=upload_image，
 * 文件字段 file）。
 *
 * @package MuseApi
 * @author Muse
 * @version 1.1.1
 * @link https://github.com/kangfuol/typecho-muse-api
 */

namespace TypechoPlugin\MuseApi;

use Typecho\Config;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Text;
use Utils\Helper;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Plugin implements PluginInterface
{
    /** 注册的 action 名，对应 /index.php/action/muse-api */
    public const ACTION_NAME = 'muse-api';

    /** Token 随机字节数（base64url 编码后正好 48 个字符） */
    public const TOKEN_BYTES = 36;

    /** 默认 API 用户名（必须是编辑或管理员） */
    public const DEFAULT_API_USER = 'muse';

    /**
     * 启用插件
     *
     * @return string
     */
    public static function activate(): string
    {
        // 先保证 Token 存在（没有就生成一个并保存），再注册路由
        $settings = self::getSettings();

        if (empty($settings['token'])) {
            $settings['token'] = self::generateToken();
        }

        if (empty($settings['apiUser'])) {
            $settings['apiUser'] = self::DEFAULT_API_USER;
        }

        Helper::configPlugin('MuseApi', $settings);
        Helper::addAction(self::ACTION_NAME, 'TypechoPlugin\MuseApi\Action');

        return _t('MuseApi 已启用。请打开插件设置，复制 API Token 到凭证库后再调用接口。');
    }

    /**
     * 停用插件
     */
    public static function deactivate()
    {
        Helper::removeAction(self::ACTION_NAME);

        // 注意：Typecho 停用插件时会删除插件配置（Token 一并删除）。
        // 重新启用后 Token 会重新生成，须重新复制到凭证库。
    }

    /**
     * 插件设置表单
     *
     * @param Form $form
     */
    public static function config(Form $form)
    {
        $settings = self::getSettings();
        $token = $settings['token'] ?? '';
        $apiUser = $settings['apiUser'] ?? self::DEFAULT_API_USER;

        $tokenInput = new Text(
            'token',
            null,
            $token,
            _t('API Token'),
            _t('调用接口时放在 X-Muse-Token 请求头里。只保存在凭证库中，不要发到聊天记录、不要放进 URL。')
        );
        $tokenInput->input->setAttribute('class', 'w-100 mono');
        $form->addInput($tokenInput);

        $userInput = new Text(
            'apiUser',
            null,
            $apiUser,
            _t('API 用户名'),
            _t('接口以该用户的身份执行操作，必须是编辑或管理员。默认 muse。')
        );
        $form->addInput($userInput);

        // 注意：Checkbox 的值必须是数组（即使是空数组）。
        // Form::getParams() 按输入当前值的类型给 Request::get() 传默认值；
        // Request::get() 要求默认值与提交值的类型一致（数组对数组），
        // 否则会丢弃提交的数组值，导致 regenerate 永远读不到。
        $regen = new Checkbox(
            'regenerate',
            ['1' => _t('重新生成 Token')],
            [],
            _t('重新生成 Token'),
            _t('勾选后点保存设置，立即生成新 Token，旧 Token 当即作废。生成后请重新复制到凭证库。')
        );
        $form->addInput($regen);
    }

    /**
     * 个人设置（无）
     *
     * @param Form $form
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 处理配置保存（含启用时的初始化）
     *
     * Typecho 启用插件时会先调 activate()，再以表单默认值调
     * configHandle($settings, true)；保存设置时调 configHandle($settings, false)。
     * 定义了本方法后，Typecho 不再自动保存，须自己持久化。
     *
     * @param array $settings 表单提交的配置值
     * @param bool $isInit 是否为启用时的初始化
     */
    public static function configHandle(array $settings, bool $isInit)
    {
        $regenerate = !empty($settings['regenerate']);

        // regenerate 是一次性开关，不持久化
        unset($settings['regenerate']);

        if ($regenerate || empty($settings['token'])) {
            $settings['token'] = self::generateToken();
        }

        if (empty($settings['apiUser'])) {
            $settings['apiUser'] = self::DEFAULT_API_USER;
        }

        Helper::configPlugin('MuseApi', $settings);
    }

    /**
     * 生成一个随机 Token（48 个字符，URL 安全的 base64）
     *
     * @return string
     */
    public static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    /**
     * 读取插件配置（options 表里 plugin:MuseApi 一行）
     *
     * 注意：Options::plugin() 返回的是 Typecho\Config 对象（不是数组），
     * 配置行不存在时抛 PluginException。这里统一返回普通数组。
     *
     * @return array
     */
    public static function getSettings(): array
    {
        try {
            $options = Options::alloc()->plugin('MuseApi');

            if ($options instanceof Config) {
                return $options->toArray();
            }

            return is_array($options) ? $options : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
