<?php
/**
 * MuseApi 的 Action：POST /index.php/action/muse-api
 *
 * 请求：POST，请求头 X-Muse-Token: <Token>。
 *   - 评论/文章 12 个动作：JSON 请求体 {"action": "list_comments", ...}。
 *   - 上传图片 upload_image / 上传视频 upload_video：multipart/form-data，
 *     字段 action=upload_image 或 upload_video，文件字段 file。
 * 响应：JSON，成功 {"success": true, ...}，失败 {"success": false, "error": "...", "code": "..."}。
 *
 * 鉴权后以配置的 API 用户（默认 muse，须为编辑或管理员）临时登录
 * （Widget\User::simpleLogin，不写 cookie/session），再复用 Typecho
 * 核心的评论/文章编辑组件完成操作。
 *
 * 注意：Typecho 1.3.0 的 XML-RPC 编辑文章路径（mwNewPost/mwEditPost）
 * 没有调 PostEdit::prepare()，按其写法更新文章会因为 have() 为假而
 * 走插入分支（产生重复文章）。本插件在 update_post 里先调 prepare()
 * 再调 writePost()，走后台表单同一条已验证的编辑路径。
 *
 * 另注意：Typecho\Widget::widget() 的 finally 里有 return，会吞掉沙箱
 * 回调里抛出的非 Terminal 异常（比如 prepare() 抛的 404/403）。
 * 所以写操作都在回调内捕获异常、回调外重新抛出，并且写完后都重新
 * 查库核对（状态/文本/modified），不只凭“没报错”就当成功。
 *
 * @package MuseApi
 */

namespace TypechoPlugin\MuseApi;

use Typecho\Common;
use Typecho\Config;
use Typecho\Db;
use Typecho\Widget\Exception as WidgetException;
use Typecho\Widget\Terminal;
use Widget\ActionInterface;
use Widget\Base;
use Widget\Comments\Admin as CommentsAdmin;
use Widget\Comments\Edit as CommentsEdit;
use Widget\Contents\From as PostFrom;
use Widget\Contents\Post\Edit as PostEdit;
use Widget\Upload;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Action extends Base implements ActionInterface
{
    /**
     * JSON 动作分发表：请求里的 action 名 => 本类方法名
     * （只做评论和文章；没有用户/设置/链接/主题插件/备份操作，
     *  也没有删除文章接口。upload_image / upload_video 是 multipart 请求，
     *  不走这张表，见 handleMultipart()。）
     */
    private const DISPATCH = [
        'list_comments'   => 'listComments',
        'get_comment'     => 'getComment',
        'approve_comment' => 'approveComment',
        'spam_comment'    => 'spamComment',
        'wait_comment'    => 'waitComment',
        'delete_comment'  => 'deleteComment',
        'reply_comment'   => 'replyComment',
        'edit_comment'    => 'editComment',
        'list_posts'      => 'listPosts',
        'get_post'        => 'getPost',
        'create_post'     => 'createPost',
        'update_post'     => 'updatePost',
    ];

    /** 评论状态（数据库里的值） */
    private const COMMENT_STATUSES = ['approved', 'waiting', 'spam'];

    /** 文章列表 status 参数的合法值 */
    private const POST_LIST_STATUSES = ['all', 'publish', 'draft', 'waiting', 'private', 'hidden'];

    /** 文章写操作 status 参数的合法值 */
    private const POST_WRITE_STATUSES = ['draft', 'publish', 'private', 'waiting', 'hidden'];

    /** 单张图片上传上限（10MB） */
    private const MAX_IMAGE_SIZE = 10 * 1024 * 1024;

    /** 单个视频上传上限（100MB） */
    private const MAX_VIDEO_SIZE = 100 * 1024 * 1024;

    /**
     * 允许上传的图片扩展名 => 检测到的 MIME（扩展名与实测 MIME 必须一致）。
     * 要支持新图片格式时只改这张表，不用加新接口。
     */
    private const IMAGE_MIME_BY_EXT = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'bmp'  => 'image/bmp',
    ];

    /**
     * 允许上传的视频扩展名 => 检测到的 MIME（扩展名与实测 MIME 必须一致）。
     * 要支持新视频格式时只改这张表，不用加新接口。
     */
    private const VIDEO_MIME_BY_EXT = [
        'mp4'  => 'video/mp4',
        'm4v'  => 'video/mp4',
        'mov'  => 'video/quicktime',
        'webm' => 'video/webm',
        'mkv'  => 'video/x-matroska',
        'avi'  => 'video/x-msvideo',
        'flv'  => 'video/x-flv',
        'wmv'  => 'video/x-ms-asf',
    ];

    /**
     * Action 入口
     */
    public function action()
    {
        try {
            $this->handle();
        } catch (WidgetException $e) {
            // 核心组件抛的异常自带近似 HTTP 的状态码（404/403 等）
            $this->fail($e->getMessage(), $this->httpStatus($e), 'widget_error');
        } catch (Db\Exception $e) {
            $this->fail('数据库错误：' . $e->getMessage(), 500, 'db_error');
        } catch (\Throwable $e) {
            $this->fail('服务器内部错误：' . $e->getMessage(), 500, 'internal_error');
        }
    }

    /**
     * 请求总流程：方法校验 -> JSON 解析 -> Token 鉴权 -> API 用户登录 -> 分发
     */
    private function handle(): void
    {
        if (!$this->request->isPost()) {
            $this->fail('只接受 POST 请求', 405, 'method_not_allowed');
        }

        // multipart/form-data 只走文件上传分支（upload_image / upload_video）；
        // 其余 12 个动作一律走下面的 JSON 分发，不动。
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_starts_with(strtolower(trim($contentType)), 'multipart/form-data')) {
            $this->handleMultipart();
            return;
        }

        $raw = file_get_contents('php://input');
        $params = json_decode(is_string($raw) ? $raw : '', true);

        if (!is_array($params)) {
            $this->fail('请求体必须是 JSON 对象', 400, 'bad_json');
        }

        $this->authenticate();
        $this->loginApiUser();

        $action = $params['action'] ?? null;

        if (!is_string($action) || !isset(self::DISPATCH[$action])) {
            $this->fail('未知的 action：' . (is_string($action) ? $action : ''), 400, 'unknown_action');
        }

        $method = self::DISPATCH[$action];
        $this->$method($params);
    }

    // ------------------------------------------------------------------
    // 响应
    // ------------------------------------------------------------------

    /**
     * 成功响应（不返回；throwJson 会结束本次请求）
     */
    private function ok(array $data, int $status = 200): never
    {
        $this->response->setStatus($status);
        $this->response->throwJson(array_merge(['success' => true], $data));
        exit;
    }

    /**
     * 失败响应（不返回；throwJson 会结束本次请求）
     */
    private function fail(string $message, int $status = 400, string $code = ''): never
    {
        $this->response->setStatus($status);
        $this->response->throwJson([
            'success' => false,
            'error'   => $message,
            'code'    => '' !== $code ? $code : 'http_' . $status,
        ]);
        exit;
    }

    /**
     * 把核心组件异常的 code 映射为 HTTP 状态码
     */
    private function httpStatus(WidgetException $e): int
    {
        $code = (int)$e->getCode();
        return in_array($code, [400, 401, 403, 404, 405, 409, 422], true) ? $code : 500;
    }

    // ------------------------------------------------------------------
    // 鉴权与 API 用户
    // ------------------------------------------------------------------

    /**
     * Token 鉴权：只认 X-Muse-Token 请求头，不认 URL 参数
     */
    private function authenticate(): void
    {
        $settings = Plugin::getSettings();
        $expected = $settings['token'] ?? '';

        if (!is_string($expected) || '' === $expected) {
            $this->fail('插件未配置 Token（请在插件设置里查看/生成）', 500, 'token_not_configured');
        }

        $given = $this->request->getHeader('X-Muse-Token');

        if (!is_string($given) || '' === $given) {
            $this->fail('缺少 X-Muse-Token 请求头', 401, 'unauthorized');
        }

        if (strlen($given) !== strlen($expected) || !hash_equals($expected, $given)) {
            $this->fail('Token 无效', 401, 'unauthorized');
        }
    }

    /**
     * 以配置的 API 用户临时登录（不写 cookie、不写 session）
     */
    private function loginApiUser(): void
    {
        $settings = Plugin::getSettings();
        $apiUser = $settings['apiUser'] ?? Plugin::DEFAULT_API_USER;

        if (!is_string($apiUser) || '' === $apiUser) {
            $apiUser = Plugin::DEFAULT_API_USER;
        }

        $user = $this->db->fetchRow($this->db->select('uid', 'group')
            ->from('table.users')->where('name = ?', $apiUser)->limit(1));

        if (!$user) {
            $this->fail('API 用户不存在：' . $apiUser, 500, 'api_user_not_found');
        }

        if (!in_array($user['group'], ['administrator', 'editor'], true)) {
            $this->fail('API 用户必须是编辑或管理员：' . $apiUser, 500, 'api_user_forbidden');
        }

        if (!$this->user->simpleLogin((int)$user['uid'])) {
            $this->fail('API 用户登录失败：' . $apiUser, 500, 'api_user_login_failed');
        }
    }

    // ------------------------------------------------------------------
    // 文件上传（multipart）
    // ------------------------------------------------------------------

    /**
     * multipart 入口：只接受 action=upload_image / upload_video。
     * 鉴权在碰文件之前完成（与 JSON 路径一致）。
     */
    private function handleMultipart(): void
    {
        $action = $_POST['action'] ?? null;

        if (!is_string($action)
            || !in_array($action, ['upload_image', 'upload_video'], true)) {
            $this->fail('multipart 请求只支持 upload_image / upload_video', 400, 'bad_request');
        }

        $this->authenticate();
        $this->loginApiUser();

        if ('upload_video' === $action) {
            $this->uploadVideo();
            return;
        }

        $this->uploadImage();
    }

    /**
     * 上传图片：multipart/form-data，文件字段名 file。
     *
     * 格式表 + 上限见 IMAGE_MIME_BY_EXT / MAX_IMAGE_SIZE（图片 10MB）。
     * 校验与存储都走共用的 uploadFile()，见下。
     */
    private function uploadImage(): void
    {
        $this->uploadFile(
            self::IMAGE_MIME_BY_EXT,
            self::MAX_IMAGE_SIZE,
            '图片',
            $this->detectImageMime(...)
        );
    }

    /**
     * 文件上传总流程（图片/视频共用；格式表 + 上限是唯一的扩展点）。
     *
     * $mimeByExt：允许的扩展名 => 实测 MIME；$maxSize：单文件上限（字节）；
     * $kindLabel：报错里用的称呼（“图片”/“视频”）；$detectFallback：
     * fileinfo 失效时用的兜底检测，签名 detectXxxMime(路径, 期望 MIME)。
     * 存储走核心 Widget\Upload::uploadHandle()（后台上传同一条路：
     * usr/uploads/YYYY/MM/、crc32 命名、重名不覆盖），再按核心 upload()
     * 的结构把附件行写入 contents 表，见 storeUpload()。
     */
    private function uploadFile(array $mimeByExt, int $maxSize, string $kindLabel, callable $detectFallback): void
    {
        $file = $_FILES['file'] ?? null;

        if (!is_array($file)) {
            $this->fail('缺少文件字段 file', 400, 'missing_file');
        }

        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if (UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error) {
            $this->fail('文件超过服务器允许的上传大小（upload_max_filesize='
                . ini_get('upload_max_filesize') . '，post_max_size='
                . ini_get('post_max_size') . '）', 400, 'file_too_large');
        }

        if (UPLOAD_ERR_OK !== $error) {
            $this->fail('文件上传失败（错误码 ' . $error . '）', 400, 'upload_error');
        }

        $tmpName = (string)($file['tmp_name'] ?? '');

        if ('' === $tmpName || !is_uploaded_file($tmpName)) {
            $this->fail('文件上传失败', 400, 'upload_error');
        }

        $size = (int)($file['size'] ?? 0);

        if ($size <= 0) {
            $size = (int)@filesize($tmpName);
        }

        if ($size > $maxSize) {
            $this->fail($kindLabel . '超过 ' . $this->formatSizeMb($maxSize) . ' 上限', 400, 'file_too_large');
        }

        $originalName = (string)($file['name'] ?? '');
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!isset($mimeByExt[$ext])) {
            $this->fail('只接受' . $kindLabel . '（' . implode('、', array_keys($mimeByExt)) . '）', 400, 'bad_file_type');
        }

        // 实测 MIME（不信任客户端传的 MIME）：必须与扩展名对应
        $mime = Common::mimeContentType($tmpName);

        if ($mime !== $mimeByExt[$ext]) {
            // 有的服务器 fileinfo 的 magic 库损坏，会把所有文件都认成
            // application/octet-stream（在某些服务器上实测即如此）；此时改用
            // 文件头特征码二次核对，假文件过不了这一关。
            if ('application/octet-stream' === $mime || '' === $mime) {
                $mime = $detectFallback($tmpName, $mimeByExt[$ext]);
            }

            if ($mime !== $mimeByExt[$ext]) {
                $this->fail('文件内容与扩展名不符（检测到 ' . $mime . '）', 400, 'bad_file_type');
            }
        }

        $this->storeUpload($file);
    }

    /** 字节数格式化为 MB（如 10485760 → 10MB），只用于报错文本 */
    private function formatSizeMb(int $bytes): string
    {
        return (string)($bytes / (1024 * 1024)) . 'MB';
    }

    /**
     * 上传视频：multipart/form-data，文件字段名 file。
     *
     * 格式表 + 上限见 VIDEO_MIME_BY_EXT / MAX_VIDEO_SIZE（视频 100MB）。
     * 校验与存储都走共用的 uploadFile()，见上。
     */
    private function uploadVideo(): void
    {
        $this->uploadFile(
            self::VIDEO_MIME_BY_EXT,
            self::MAX_VIDEO_SIZE,
            '视频',
            $this->detectVideoMime(...)
        );
    }

    /**
     * 核心上传并写附件记录（图片/视频共用）。
     *
     * 走核心 Widget\Upload::uploadHandle()（后台上传同一条路：
     * usr/uploads/YYYY/MM/、crc32 命名、重名不覆盖），再按核心
     * upload() 的结构把附件行写入 contents 表，最后核对并返回。
     */
    private function storeUpload(array $file): void
    {
        // 核心上传：命名、建目录、移动文件（与后台上传同一条路）
        $result = Upload::uploadHandle($file);

        if (false === $result || !is_array($result)) {
            $this->fail('上传失败（该类型可能不在站点允许的附件类型中）', 500, 'upload_failed');
        }

        // 按核心 upload() 的结构写附件行（title/slug 取原文件名）
        $struct = [
            'title'        => $result['name'],
            'slug'         => $result['name'],
            'type'         => 'attachment',
            'status'       => 'publish',
            'text'         => json_encode($result),
            'allowComment' => 1,
            'allowPing'    => 0,
            'allowFeed'    => 1,
        ];

        // 用 Upload 组件自己的 insert() 写行（与核心一致的默认值与 slug 处理）。
        // 注意：Widget::widget() 的 finally 里有 return，会吞掉沙箱回调里
        // 抛出的异常，所以在回调内捕获、回调外重新抛出（与文章写操作同款）。
        $caught = null;
        $aid = 0;
        Upload::alloc(null, [], function (Upload $u) use ($struct, &$aid, &$caught) {
            try {
                $aid = $u->insert($struct);
            } catch (\Throwable $e) {
                $caught = $e;
            }
        });

        if ($caught) {
            @unlink(__TYPECHO_ROOT_DIR__ . $result['path']);
            throw $caught;
        }

        if ($aid <= 0) {
            @unlink(__TYPECHO_ROOT_DIR__ . $result['path']);
            $this->fail('上传失败（附件记录写入失败）', 500, 'upload_failed');
        }

        // 核对：附件行必须确实存在且路径一致
        $row = $this->db->fetchRow($this->db->select()->from('table.contents')
            ->where('cid = ?', $aid)->where('type = ?', 'attachment')->limit(1));

        if (!$row) {
            @unlink(__TYPECHO_ROOT_DIR__ . $result['path']);
            $this->fail('上传失败（附件记录写入失败）', 500, 'upload_failed');
        }

        $stored = json_decode((string)($row['text'] ?? ''), true);

        if (!is_array($stored) || ($stored['path'] ?? '') !== $result['path']) {
            $this->fail('上传失败（附件记录校验失败）', 500, 'upload_failed');
        }

        $this->ok(['attachment' => [
            'aid'          => $aid,
            'url'          => Upload::attachmentHandle(new Config($result)),
            'filename'     => basename($result['path']),
            'originalName' => $result['name'],
            'path'         => $result['path'],
            'size'         => (int)$result['size'],
            'mime'         => $result['mime'],
            'type'         => $result['type'],
        ]]);
    }

    /**
     * 检测图片的真实 MIME（fileinfo 失效时的兜底）。
     *
     * 先用 getimagesize() 解析图片结构（假图片过不了），再不济按文件头
     * 特征码判断。只认 jpg/png/gif/webp/avif/bmp，认不出返回空字符串。
     * $expectedMime 仅为与视频兜底统一签名而保留，图片每种格式都有
     * 唯一特征码，用不上它。
     */
    private function detectImageMime(string $path, string $expectedMime = ''): string
    {
        $info = @getimagesize($path);

        if (is_array($info) && isset($info['mime'])) {
            return (string)$info['mime'];
        }

        $head = @file_get_contents($path, false, null, 0, 12);

        if (!is_string($head)) {
            return '';
        }

        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }

        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'image/gif';
        }

        if (str_starts_with($head, 'RIFF') && 'WEBP' === substr($head, 8, 4)) {
            return 'image/webp';
        }

        // AVIF：ftyp 盒子，主品牌在第 9–12 字节（avif / avis）
        if (strlen($head) >= 12 && 'ftyp' === substr($head, 4, 4)
            && in_array(substr($head, 8, 4), ['avif', 'avis'], true)) {
            return 'image/avif';
        }

        // BMP：文件头 BM
        if (str_starts_with($head, 'BM')) {
            return 'image/bmp';
        }

        return '';
    }

    /**
     * 检测视频的真实 MIME（fileinfo 失效时的兜底）。
     *
     * 只按容器族的文件头特征码判断：mp4/m4v/mov 看 ftyp 盒子，
     * webm/mkv 看 EBML 头，avi 看 RIFF+AVI，flv 看 FLV 头，
     * wmv 看 ASF GUID。同一容器族无法从文件头区分具体格式
     * （比如 mp4/mov 都是 ftyp），所以通过时返回调用方期望的
     * MIME（$expectedMime），不通过返回空字符串。
     */
    private function detectVideoMime(string $path, string $expectedMime): string
    {
        $head = @file_get_contents($path, false, null, 0, 16);

        if (!is_string($head) || strlen($head) < 12) {
            return '';
        }

        // mp4 / m4v / mov：ISO BMFF，盒子类型在第 5–8 字节
        if ('ftyp' === substr($head, 4, 4)) {
            return $expectedMime;
        }

        // webm / mkv：EBML 头 1A 45 DF A3
        if (str_starts_with($head, "\x1A\x45\xDF\xA3")) {
            return $expectedMime;
        }

        // avi：RIFF 头 + 第 9–12 字节 AVI␣
        if (str_starts_with($head, 'RIFF') && 'AVI ' === substr($head, 8, 4)) {
            return $expectedMime;
        }

        // flv：FLV 头
        if (str_starts_with($head, 'FLV')) {
            return $expectedMime;
        }

        // wmv：ASF GUID
        if (str_starts_with($head, "\x30\x26\xB2\x75\x8E\x66\xCF\x11\xA6\xD9\x00\xAA\x00\x62\xCE\x6C")) {
            return $expectedMime;
        }

        return '';
    }

    // ------------------------------------------------------------------
    // 参数校验
    // ------------------------------------------------------------------

    private function reqInt(array $params, string $key): int
    {
        if (!array_key_exists($key, $params)) {
            $this->fail('缺少参数 ' . $key, 400, 'missing_param');
        }

        $value = $params[$key];

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            return (int)trim($value);
        }

        $this->fail('参数 ' . $key . ' 必须是整数', 400, 'bad_param');
    }

    private function optInt(array $params, string $key, int $default, int $min, int $max): int
    {
        if (!array_key_exists($key, $params)) {
            return $default;
        }

        $value = $params[$key];

        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            $int = (int)trim($value);
        } else {
            $this->fail('参数 ' . $key . ' 必须是整数', 400, 'bad_param');
        }

        return max($min, min($max, $int));
    }

    private function reqString(array $params, string $key, int $maxLen, bool $required = false, string $default = ''): string
    {
        if (!array_key_exists($key, $params) || null === $params[$key]) {
            if ($required) {
                $this->fail('缺少参数 ' . $key, 400, 'missing_param');
            }

            return $default;
        }

        $value = $params[$key];

        if (!is_string($value)) {
            $this->fail('参数 ' . $key . ' 必须是字符串', 400, 'bad_param');
        }

        if (mb_strlen($value) > $maxLen) {
            $this->fail('参数 ' . $key . ' 过长（最多 ' . $maxLen . ' 字符）', 400, 'bad_param');
        }

        return $value;
    }

    private function boolParam(array $params, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $params)) {
            return $default;
        }

        $value = $params[$key];

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return 1 === $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        $this->fail('参数 ' . $key . ' 必须是布尔值', 400, 'bad_param');
    }

    // ------------------------------------------------------------------
    // 评论
    // ------------------------------------------------------------------

    /**
     * 列评论：{"action": "list_comments", "status": "all|approved|waiting|spam",
     *          "page": 1, "pageSize": 20, "cid": 123}
     *
     * status=all 时合并三类结果再按 coid 倒序分页（核心组件没有“全部”筛选）。
     */
    private function listComments(array $params): void
    {
        $status = $params['status'] ?? 'all';

        if (!is_string($status) || !in_array($status, array_merge(['all'], self::COMMENT_STATUSES), true)) {
            $this->fail('status 只能是 all、approved、waiting、spam', 400, 'bad_param');
        }

        $page = $this->optInt($params, 'page', 1, 1, 100000);
        $pageSize = $this->optInt($params, 'pageSize', 20, 1, 100);
        $cid = array_key_exists('cid', $params) ? $this->reqInt($params, 'cid') : null;

        $statuses = 'all' === $status ? self::COMMENT_STATUSES : [$status];
        $items = [];
        $total = 0;

        foreach ($statuses as $oneStatus) {
            // 合并时每类多取，保证合并排序后分页正确
            $fetchSize = 'all' === $status ? $page * $pageSize : $pageSize;
            $fetchPage = 'all' === $status ? 1 : $page;

            $request = [
                'status'                  => $oneStatus,
                '__typecho_all_comments'  => 'on',
                'page'                    => $fetchPage,
            ];

            if (null !== $cid) {
                $request['cid'] = $cid;
            }

            $widget = CommentsAdmin::allocWithAlias(
                'muse_api_' . $oneStatus,
                'pageSize=' . $fetchSize,
                $request,
                false
            );

            while ($widget->next()) {
                $items[] = $this->commentRowFromWidget($widget);
            }

            $total += $this->countComments($oneStatus, $cid);
        }

        if ('all' === $status) {
            usort($items, static fn(array $a, array $b) => $b['coid'] <=> $a['coid']);
            $items = array_slice($items, ($page - 1) * $pageSize, $pageSize);
        }

        $this->ok([
            'comments' => $items,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
        ]);
    }

    /**
     * 取一条评论：{"action": "get_comment", "coid": 123}
     */
    private function getComment(array $params): void
    {
        $coid = $this->reqInt($params, 'coid');

        $widget = CommentsEdit::alloc(null, ['coid' => $coid], function (CommentsEdit $c) {
            $c->getComment();
        });

        if (!$widget || !$widget->have()) {
            $this->fail('评论不存在：' . $coid, 404, 'comment_not_found');
        }

        if (!$widget->commentIsWriteable()) {
            $this->fail('无权操作该评论', 403, 'forbidden');
        }

        $this->ok(['comment' => $this->commentRowFromWidget($widget)]);
    }

    /**
     * 标记评论状态：approve/spam/wait
     */
    private function approveComment(array $params): void
    {
        $this->setCommentStatus($params, 'approvedComment', 'approved');
    }

    private function spamComment(array $params): void
    {
        $this->setCommentStatus($params, 'spamComment', 'spam');
    }

    private function waitComment(array $params): void
    {
        $this->setCommentStatus($params, 'waitingComment', 'waiting');
    }

    private function setCommentStatus(array $params, string $method, string $expectedStatus): void
    {
        $coid = $this->reqInt($params, 'coid');

        if (!$this->fetchCommentRow($coid)) {
            $this->fail('评论不存在：' . $coid, 404, 'comment_not_found');
        }

        CommentsEdit::alloc(null, ['coid' => [$coid]], function (CommentsEdit $c) use ($method) {
            $c->$method();
        });

        // 核对：状态必须确实变了
        $row = $this->fetchCommentRow($coid);

        if (!$row || $row['status'] !== $expectedStatus) {
            $this->fail('标记评论状态失败', 500, 'mark_failed');
        }

        $this->ok(['comment' => $this->commentRowFromDb($row)]);
    }

    /**
     * 删除评论：{"action": "delete_comment", "coid": 123}
     */
    private function deleteComment(array $params): void
    {
        $coid = $this->reqInt($params, 'coid');

        if (!$this->fetchCommentRow($coid)) {
            $this->fail('评论不存在：' . $coid, 404, 'comment_not_found');
        }

        CommentsEdit::alloc(null, ['coid' => [$coid]], function (CommentsEdit $c) {
            $c->deleteComment();
        });

        if ($this->fetchCommentRow($coid)) {
            $this->fail('删除评论失败', 500, 'delete_failed');
        }

        $this->ok(['deleted' => $coid]);
    }

    /**
     * 回复评论：{"action": "reply_comment", "coid": 123, "text": "..."}
     * 回复以 API 用户的身份发表，状态为已通过。
     */
    private function replyComment(array $params): void
    {
        $coid = $this->reqInt($params, 'coid');
        $text = $this->reqString($params, 'text', 10000, true);

        $parent = $this->fetchCommentRow($coid);

        if (!$parent) {
            $this->fail('评论不存在：' . $coid, 404, 'comment_not_found');
        }

        $beforeMax = (int)($this->db->fetchObject(
            $this->db->select(['MAX(coid)' => 'num'])->from('table.comments')
        )->num ?? 0);

        CommentsEdit::alloc(null, ['coid' => $coid, 'text' => $text], function (CommentsEdit $c) {
            $c->replyComment();
        });

        // 找出本次新增的回复（父评论相同、作者是 API 用户、coid 大于调用前最大值）
        $row = $this->db->fetchRow($this->db->select()->from('table.comments')
            ->where('parent = ?', $coid)
            ->where('authorId = ?', $this->user->uid)
            ->where('coid > ?', $beforeMax)
            ->order('coid', Db::SORT_ASC)
            ->limit(1));

        if (!$row) {
            $this->fail('回复评论失败', 500, 'reply_failed');
        }

        $this->ok(['comment' => $this->commentRowFromDb($row)]);
    }

    /**
     * 编辑评论：{"action": "edit_comment", "coid": 123, "text": "...",
     *           "author": "...", "mail": "...", "url": "...", "created": 1234567890}
     * 只传要改的字段；没传的沿用原值（核心 editComment 不补默认值，空值会清空字段）。
     */
    private function editComment(array $params): void
    {
        $coid = $this->reqInt($params, 'coid');

        $existing = $this->fetchCommentRow($coid);

        if (!$existing) {
            $this->fail('评论不存在：' . $coid, 404, 'comment_not_found');
        }

        $input = [
            'coid'   => $coid,
            'text'   => $this->reqString($params, 'text', 10000, false, (string)$existing['text']),
            'author' => $this->reqString($params, 'author', 200, false, (string)$existing['author']),
            'mail'   => $this->reqString($params, 'mail', 200, false, (string)$existing['mail']),
            'url'    => $this->reqString($params, 'url', 200, false, (string)$existing['url']),
        ];

        if (array_key_exists('created', $params)) {
            $input['created'] = $this->reqInt($params, 'created');
        }

        CommentsEdit::alloc(null, $input, function (CommentsEdit $c) {
            $c->editComment();
        });

        $row = $this->fetchCommentRow($coid);

        if (!$row || $row['text'] !== $input['text']) {
            $this->fail('编辑评论失败', 500, 'edit_failed');
        }

        $this->ok(['comment' => $this->commentRowFromDb($row)]);
    }

    // ------------------------------------------------------------------
    // 评论：取数与序列化
    // ------------------------------------------------------------------

    private function fetchCommentRow(int $coid): ?array
    {
        $row = $this->db->fetchRow($this->db->select()->from('table.comments')
            ->where('coid = ?', $coid)->limit(1));

        return $row ?: null;
    }

    private function countComments(string $status, ?int $cid): int
    {
        $select = $this->db->select(['COUNT(coid)' => 'num'])->from('table.comments')
            ->where('status = ?', $status);

        if (null !== $cid) {
            $select->where('cid = ?', $cid);
        }

        $row = $this->db->fetchObject($select);

        return (int)($row->num ?? 0);
    }

    /**
     * 从评论组件当前行序列化（list/get 用；带文章标题与链接）
     */
    private function commentRowFromWidget($widget): array
    {
        return [
            'coid'      => (int)$widget->coid,
            'cid'       => (int)$widget->cid,
            'parent'    => (int)($widget->parent ?? 0),
            'authorId'  => (int)($widget->authorId ?? 0),
            'status'    => $widget->status,
            'type'      => $widget->type ?? 'comment',
            'author'    => $widget->author,
            'mail'      => $widget->mail,
            'url'       => $widget->url,
            'ip'        => $widget->ip,
            'text'      => $widget->text,
            'created'   => (int)$widget->created,
            'title'     => $widget->title ?? '',
            'permalink' => $widget->permalink ?? '',
        ];
    }

    /**
     * 从数据库行序列化（写操作核对后用；不带文章标题与链接）
     */
    private function commentRowFromDb(array $row): array
    {
        return [
            'coid'     => (int)$row['coid'],
            'cid'      => (int)$row['cid'],
            'parent'   => (int)($row['parent'] ?? 0),
            'authorId' => (int)($row['authorId'] ?? 0),
            'status'   => $row['status'],
            'type'     => $row['type'] ?? 'comment',
            'author'   => (string)($row['author'] ?? ''),
            'mail'     => (string)($row['mail'] ?? ''),
            'url'      => (string)($row['url'] ?? ''),
            'ip'       => (string)($row['ip'] ?? ''),
            'text'     => (string)($row['text'] ?? ''),
            'created'  => (int)$row['created'],
        ];
    }

    // ------------------------------------------------------------------
    // 文章
    // ------------------------------------------------------------------

    /**
     * 列文章：{"action": "list_posts", "status": "all|publish|draft|waiting|private|hidden",
     *          "page": 1, "pageSize": 20, "category_id": 3, "keywords": "南京"}
     *
     * 用核心 Db 查询构造器取 cid 列表（PostAdmin 组件没有 publish/private
     * 筛选，直接用它再客户端过滤会破坏分页），再用 Widget\Contents\From
     * 逐篇取计算字段（链接、分类、标签）。
     */
    private function listPosts(array $params): void
    {
        $status = $params['status'] ?? 'all';

        if (!is_string($status) || !in_array($status, self::POST_LIST_STATUSES, true)) {
            $this->fail('status 只能是 all、publish、draft、waiting、private、hidden', 400, 'bad_param');
        }

        $page = $this->optInt($params, 'page', 1, 1, 100000);
        $pageSize = $this->optInt($params, 'pageSize', 20, 1, 100);
        $categoryId = array_key_exists('category_id', $params) ? $this->reqInt($params, 'category_id') : null;
        $keywords = array_key_exists('keywords', $params)
            ? $this->reqString($params, 'keywords', 200, false, '')
            : '';

        $select = $this->db->select()->from('table.contents');
        $this->applyPostListFilters($select, $status, $categoryId, $keywords);

        $countSelect = $this->db->select(['COUNT(DISTINCT table.contents.cid)' => 'num'])
            ->from('table.contents');
        $this->applyPostListFilters($countSelect, $status, $categoryId, $keywords);
        $total = (int)($this->db->fetchObject($countSelect)->num ?? 0);

        $select->order('table.contents.cid', Db::SORT_DESC)->page($page, $pageSize);
        $rows = $this->db->fetchAll($select);

        $authorNames = $this->authorNames(array_map(
            static fn(array $row) => (int)$row['authorId'],
            $rows
        ));

        $posts = [];

        foreach ($rows as $row) {
            $posts[] = $this->postRow((int)$row['cid'], $authorNames);
        }

        $this->ok([
            'posts'    => $posts,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
        ]);
    }

    /**
     * 取一篇文章：{"action": "get_post", "cid": 123}
     *
     * 返回最新可编辑版本：如果存在草稿/修订，text 取草稿内容，hasDraft 为 true。
     */
    private function getPost(array $params): void
    {
        $cid = $this->reqInt($params, 'cid');

        $widget = PostEdit::alloc(null, ['cid' => $cid], function (PostEdit $p) {
            $p->prepare();
        });

        if (!$widget || !$widget->have()) {
            $this->fail('文章不存在：' . $cid, 404, 'post_not_found');
        }

        $this->ok(['post' => $this->serializePost($widget)]);
    }

    /**
     * 新建文章：{"action": "create_post", "title": "...", "text": "...",
     *   "status": "draft|publish", "slug": "...", "categories": ["随笔"],
     *   "category_ids": [3], "tags": ["南京", "旅行"], "allowComment": true,
     *   "allowPing": true, "allowFeed": true, "password": "...",
     *   "markdown": true, "created": 1730000000}
     *
     * 默认建草稿（status 省略时）。不传 categories 时用站点默认分类。
     */
    private function createPost(array $params): void
    {
        $status = $params['status'] ?? 'draft';

        if (!is_string($status) || !in_array($status, ['draft', 'publish'], true)) {
            $this->fail('新建文章的 status 只能是 draft 或 publish', 400, 'bad_param');
        }

        $input = $this->buildPostInput($params, null, $status);

        // 注意：Widget::widget() 的 finally 里有 return，会吞掉沙箱回调里
        // 抛出的非 Terminal 异常，所以在回调内捕获、回调外重新抛出。
        $caught = null;
        $widget = PostEdit::alloc(null, $input, function (PostEdit $p) use (&$caught) {
            try {
                $p->writePost();
            } catch (Terminal $e) {
                // 正常结束：writePost() 以 redirect/throwJson 收尾
            } catch (\Throwable $e) {
                $caught = $e;
            }
        });

        if ($caught) {
            throw $caught;
        }

        $cid = (int)($widget->cid ?? 0);

        if ($cid <= 0) {
            $this->fail('创建文章失败', 500, 'create_failed');
        }

        $this->ok(['post' => $this->postRow($cid)]);
    }

    /**
     * 更新文章：{"action": "update_post", "cid": 123, ...与 create_post 相同的字段}
     *
     * 只传要改的字段；没传的沿用原值。status 省略时保持原状态。
     * 已发布文章传 status=draft 不会下架，而是保存一份草稿修订（返回 draftCid）。
     */
    private function updatePost(array $params): void
    {
        $cid = $this->reqInt($params, 'cid');

        $existing = $this->fetchPostRow($cid);

        if (!$existing) {
            $this->fail('文章不存在：' . $cid, 404, 'post_not_found');
        }

        $targetStatus = $params['status']
            ?? ('post_draft' === $existing['type'] ? 'draft' : $existing['status']);

        if (!is_string($targetStatus) || !in_array($targetStatus, self::POST_WRITE_STATUSES, true)) {
            $this->fail('status 只能是 draft、publish、private、waiting、hidden', 400, 'bad_param');
        }

        $input = $this->buildPostInput($params, $existing, $targetStatus);
        $input['cid'] = $cid;

        // 先加载已有文章并校验编辑权限（核心 XML-RPC 路径漏了这一步，
        // 不调 prepare() 更新会走插入分支产生重复文章）。
        // 注意：Widget::widget() 的 finally 里有 return，会吞掉沙箱回调里
        // 抛出的非 Terminal 异常，所以在回调内捕获、回调外重新抛出。
        $caught = null;
        $before = time();
        PostEdit::alloc(null, $input, function (PostEdit $p) use (&$caught) {
            try {
                $p->prepare();
                $p->writePost();
            } catch (Terminal $e) {
                // 正常结束：writePost() 以 redirect/throwJson 收尾
            } catch (\Throwable $e) {
                $caught = $e;
            }
        });

        if ($caught) {
            throw $caught;
        }

        // 核对：文章必须确实被改过（modified 被刷新）
        $updated = $this->fetchPostRow($cid);

        if (!$updated || (int)$updated['modified'] < $before) {
            $this->fail('更新文章失败', 500, 'update_failed');
        }

        $result = ['post' => $this->postRow($cid)];

        if ('save' === $input['do'] && 'post' === $existing['type']) {
            // 已发布文章保存草稿：核心会新建/更新一条 revision 行
            $revision = $this->db->fetchRow($this->db->select('cid')->from('table.contents')
                ->where('parent = ?', $cid)->where('type = ?', 'revision')
                ->order('modified', Db::SORT_DESC)->limit(1));

            if ($revision) {
                $result['draftCid'] = (int)$revision['cid'];
            }
        }

        $this->ok($result);
    }

    // ------------------------------------------------------------------
    // 文章：取数与序列化
    // ------------------------------------------------------------------

    /**
     * 取一篇文章的数据库行（只认 post / post_draft；页面不在 v1 范围内）
     */
    private function fetchPostRow(int $cid): ?array
    {
        $row = $this->db->fetchRow($this->db->select()->from('table.contents')
            ->where('cid = ?', $cid)->limit(1));

        if (!$row || !in_array($row['type'], ['post', 'post_draft'], true)) {
            return null;
        }

        return $row;
    }

    /**
     * 用 From 组件取一篇文章并序列化（带链接、分类、标签）
     */
    private function postRow(int $cid, array $authorNames = []): array
    {
        $post = PostFrom::allocWithAlias('muse_api_post_' . $cid, ['cid' => $cid], null, false);

        if (!$post || !$post->have()) {
            $this->fail('文章不存在：' . $cid, 404, 'post_not_found');
        }

        return $this->serializePost($post, $authorNames);
    }

    private function serializePost($post, array $authorNames = []): array
    {
        $authorId = (int)$post->authorId;
        $author = $authorNames[$authorId] ?? $this->authorName($authorId);

        $categories = [];

        foreach ((array)$post->categories as $category) {
            $categories[] = [
                'mid'  => (int)$category['mid'],
                'name' => $category['name'],
                'slug' => $category['slug'],
            ];
        }

        $tags = [];

        foreach ((array)$post->tags as $tag) {
            $tags[] = $tag['name'];
        }

        return [
            'cid'          => (int)$post->cid,
            'title'        => $post->title,
            'slug'         => $post->slug,
            'text'         => $post->text,
            'isMarkdown'   => (bool)$post->isMarkdown,
            'status'       => $post->status,
            'type'         => $post->type,
            'created'      => (int)$post->created,
            'modified'     => (int)$post->modified,
            'authorId'     => $authorId,
            'author'       => $author,
            'categories'   => $categories,
            'tags'         => $tags,
            'allowComment' => (int)$post->allowComment,
            'allowPing'    => (int)$post->allowPing,
            'allowFeed'    => (int)$post->allowFeed,
            'hasPassword'  => !empty($post->password),
            'hasDraft'     => !empty($post->draft),
            'commentsNum'  => (int)$post->commentsNum,
            'permalink'    => $post->permalink,
        ];
    }

    private function authorName(int $uid): string
    {
        $names = $this->authorNames([$uid]);
        return $names[$uid] ?? '';
    }

    private function authorNames(array $uids): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));

        if (empty($uids)) {
            return [];
        }

        $rows = $this->db->fetchAll($this->db->select('uid', 'screenName')
            ->from('table.users')->where('uid IN ?', $uids));

        $names = [];

        foreach ($rows as $row) {
            $names[(int)$row['uid']] = $row['screenName'];
        }

        return $names;
    }

    /**
     * 给文章列表查询加筛选（数据查询与计数查询共用，保证 total 与分页一致）
     */
    private function applyPostListFilters($select, string $status, ?int $categoryId, string $keywords): void
    {
        if ('draft' === $status) {
            $select->where('table.contents.type = ?', 'post_draft');
        } elseif ('waiting' === $status) {
            $select->where(
                '(table.contents.type = ? OR table.contents.type = ?) AND table.contents.status = ?',
                'post',
                'post_draft',
                'waiting'
            );
        } elseif (in_array($status, ['publish', 'private', 'hidden'], true)) {
            $select->where('table.contents.type = ?', 'post')
                ->where('table.contents.status = ?', $status);
        } else {
            $select->where(
                'table.contents.type = ? OR table.contents.type = ?',
                'post',
                'post_draft'
            );
        }

        if (null !== $categoryId) {
            $select->join('table.relationships', 'table.contents.cid = table.relationships.cid')
                ->where('table.relationships.mid = ?', $categoryId);
        }

        if ('' !== $keywords) {
            $words = array_filter(explode(' ', $keywords), static fn($w) => '' !== trim($w));
            $args = [implode(' OR ', array_fill(0, count($words), 'table.contents.title LIKE ?'))];

            foreach ($words as $word) {
                $args[] = '%' . trim($word) . '%';
            }

            $select->where(...$args);
        }
    }

    // ------------------------------------------------------------------
    // 文章：写操作输入组装
    // ------------------------------------------------------------------

    /**
     * 组装传给 PostEdit::writePost() 的输入。
     *
     * 核心 writePost() 不补默认值：没传的字段会被置空/置 0，
     * 所以更新时必须用原值填满；新建时用站点默认配置填满。
     *
     * @param array $params 请求参数
     * @param array|null $existing 现有文章行（新建时为 null）
     * @param string $targetStatus 目标状态
     * @return array
     */
    private function buildPostInput(array $params, ?array $existing, string $targetStatus): array
    {
        $isNew = null === $existing;
        $existingText = $isNew ? '' : (string)($existing['text'] ?? '');
        $hasMarker = str_starts_with($existingText, '<!--markdown-->');

        $text = $this->reqString($params, 'text', 1000000, false, $existingText);
        $text = $this->stripMarkdownMarker($text);

        // markdown：调用方指定优先，否则沿用原文格式；新建默认 markdown
        $markdown = array_key_exists('markdown', $params)
            ? $this->boolParam($params, 'markdown', true)
            : ($isNew ? true : $hasMarker);

        $password = array_key_exists('password', $params)
            ? $this->reqString($params, 'password', 64, false, '')
            : ($isNew ? '' : (string)($existing['password'] ?? ''));

        // visibility 决定 checkStatus() 写出的 status：
        // publish 且有密码 -> 'password'（保留密码）；private/waiting/hidden 直传
        // （注意：private/waiting/hidden 会清空密码，这是核心行为）
        if ('draft' === $targetStatus) {
            $do = 'save';
            $visibility = 'publish';
        } elseif ('publish' === $targetStatus) {
            $do = 'publish';
            $visibility = '' !== $password ? 'password' : 'publish';
        } else {
            $do = 'publish';
            $visibility = $targetStatus;
        }

        $categoryMids = $this->resolveCategories(
            $params,
            $isNew ? null : $this->existingCategoryMids((int)$existing['cid'])
        );

        $input = [
            'do'           => $do,
            'title'        => $this->reqString($params, 'title', 200, false,
                $isNew ? '未命名文档' : (string)($existing['title'] ?? '未命名文档')),
            'text'         => $text,
            'slug'         => $this->reqString($params, 'slug', 200, false,
                $isNew ? '' : (string)($existing['slug'] ?? '')),
            'tags'         => $this->normalizeTags(
                $params,
                $isNew ? null : $this->existingTagNames((int)$existing['cid'])
            ),
            'allowComment' => $this->boolParam($params, 'allowComment',
                $isNew ? (bool)$this->options->defaultAllowComment : 1 === (int)$existing['allowComment']) ? '1' : '0',
            'allowPing'    => $this->boolParam($params, 'allowPing',
                $isNew ? (bool)$this->options->defaultAllowPing : 1 === (int)$existing['allowPing']) ? '1' : '0',
            'allowFeed'    => $this->boolParam($params, 'allowFeed',
                $isNew ? (bool)$this->options->defaultAllowFeed : 1 === (int)$existing['allowFeed']) ? '1' : '0',
            'password'     => $password,
            'visibility'   => $visibility,
            'markdown'     => $markdown ? '1' : '0',
        ];

        if (null !== $categoryMids) {
            $input['category'] = $categoryMids;
        }

        if (array_key_exists('created', $params)) {
            $created = $this->reqInt($params, 'created');

            if ($created <= 0 || $created > time() + 86400) {
                $this->fail('参数 created 必须是合理的时间戳', 400, 'bad_param');
            }

            $input['created'] = $created;
        } elseif ($isNew) {
            // 新建默认用当前时间（核心对无 created 的草稿会写 0，不便展示）
            $input['created'] = time();
        }

        return $input;
    }

    /**
     * 去掉文本开头的 <!--markdown--> 标记（writePost 会按 markdown 参数重加）
     */
    private function stripMarkdownMarker(string $text): string
    {
        return str_starts_with($text, '<!--markdown-->') ? substr($text, 15) : $text;
    }

    /**
     * 解析分类参数：categories（名字数组）或 category_ids（mid 数组）。
     * 返回 mids 数组；没传时返回 $fallback（新建为 null=用默认分类，
     * 更新为现有分类）。名字不存在会报错，不自动创建。
     */
    private function resolveCategories(array $params, ?array $fallback): ?array
    {
        if (array_key_exists('category_ids', $params)) {
            $ids = $params['category_ids'];

            if (!is_array($ids)) {
                $this->fail('category_ids 必须是整数数组', 400, 'bad_param');
            }

            $mids = [];

            foreach ($ids as $id) {
                if (!is_int($id) && !(is_string($id) && ctype_digit((string)$id))) {
                    $this->fail('category_ids 必须是整数数组', 400, 'bad_param');
                }

                $mids[] = (int)$id;
            }

            $this->assertCategoriesExist($mids);
            return $mids;
        }

        if (array_key_exists('categories', $params)) {
            $names = $params['categories'];

            if (!is_array($names)) {
                $this->fail('categories 必须是字符串数组', 400, 'bad_param');
            }

            $mids = [];

            foreach ($names as $name) {
                if (!is_string($name) || '' === trim($name)) {
                    $this->fail('categories 必须是字符串数组', 400, 'bad_param');
                }

                $row = $this->db->fetchRow($this->db->select('mid')->from('table.metas')
                    ->where('type = ?', 'category')->where('name = ?', trim($name))->limit(1));

                if (!$row) {
                    $this->fail('分类不存在：' . $name, 400, 'bad_param');
                }

                $mids[] = (int)$row['mid'];
            }

            return $mids;
        }

        return $fallback;
    }

    private function assertCategoriesExist(array $mids): void
    {
        if (empty($mids)) {
            return;
        }

        $rows = $this->db->fetchAll($this->db->select('mid')->from('table.metas')
            ->where('type = ?', 'category')->where('mid IN ?', $mids));

        $found = array_map(static fn(array $row) => (int)$row['mid'], $rows);

        foreach ($mids as $mid) {
            if (!in_array($mid, $found, true)) {
                $this->fail('分类不存在（mid）：' . $mid, 400, 'bad_param');
            }
        }
    }

    private function existingCategoryMids(int $cid): array
    {
        $rows = $this->db->fetchAll($this->db->select('table.metas.mid')->from('table.metas')
            ->join('table.relationships', 'table.relationships.mid = table.metas.mid')
            ->where('table.relationships.cid = ?', $cid)
            ->where('table.metas.type = ?', 'category'));

        return array_map(static fn(array $row) => (int)$row['mid'], $rows);
    }

    private function existingTagNames(int $cid): array
    {
        $rows = $this->db->fetchAll($this->db->select('table.metas.name')->from('table.metas')
            ->join('table.relationships', 'table.relationships.mid = table.metas.mid')
            ->where('table.relationships.cid = ?', $cid)
            ->where('table.metas.type = ?', 'tag'));

        return array_map(static fn(array $row) => $row['name'], $rows);
    }

    /**
     * 标签参数：字符串（逗号分隔）或字符串数组；没传时用 $fallback（更新时为现有标签）
     */
    private function normalizeTags(array $params, ?array $fallback): string
    {
        if (array_key_exists('tags', $params)) {
            $tags = $params['tags'];

            if (is_string($tags)) {
                $list = array_filter(
                    array_map('trim', explode(',', $tags)),
                    static fn(string $tag) => '' !== $tag
                );
            } elseif (is_array($tags)) {
                $list = [];

                foreach ($tags as $tag) {
                    if (!is_string($tag)) {
                        $this->fail('tags 必须是字符串或字符串数组', 400, 'bad_param');
                    }

                    $tag = trim($tag);

                    if ('' !== $tag) {
                        $list[] = $tag;
                    }
                }
            } else {
                $this->fail('tags 必须是字符串或字符串数组', 400, 'bad_param');
            }

            if (count($list) > 50) {
                $this->fail('标签太多（最多 50 个）', 400, 'bad_param');
            }

            return implode(',', $list);
        }

        if (null !== $fallback) {
            return implode(',', $fallback);
        }

        return '';
    }
}
