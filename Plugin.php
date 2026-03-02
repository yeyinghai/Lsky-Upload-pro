<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use Typecho\Common;
use Widget\Options;
use Widget\Upload;
use CURLFile;

/**
 * 可以直接在编辑时粘贴图片自动上传图片至兰空图床(LskyPro)，并返回markdown的图片地址，从isYangs的插件改版优化而来。
 *
 * @package LskyProUpload pro+
 * @author  yeying
 * @version 1.2.0
 * @link    https://www.yeyhome.com
 *
 * Changelog v1.2.0:
 *  - [安全] 增加登录鉴权，防止未授权上传
 *  - [安全] 增加 CSRF 防护（Referer + X-Requested-With 双重校验）
 *  - [安全] 增加文件真实 MIME 类型校验，防止 MIME 欺骗
 *  - [安全] 启用 SSL 证书验证，防止中间人攻击
 *  - [安全] 使用系统临时目录存放临时文件，避免在 Web 根目录写文件
 *  - [安全] 日志改用 error_log()，不再写入 Web 可访问目录
 *  - [逻辑] modifyHandle 改为先上传成功再删除旧图，避免旧图丢失
 *  - [逻辑] 增加文件大小校验（默认上限 10MB）
 *  - [逻辑] 增加插件配置有效性检查
 *  - [逻辑] 修正 size 字段单位（兰空 API 返回字节，去掉 *1024）
 *  - [逻辑] 修正 attachmentHandle 扩展名截断问题（使用 pathinfo）
 *  - [逻辑] 修正 _deleteImg 返回值（检查 API status 字段）
 *  - [质量] 合并 _curlPost/_curlDelete 为统一 _curlRequest，增加超时
 *  - [质量] IMAGE_EXTENSIONS 去除冗余大写（_getSafeName 已 strtolower）
 *  - [质量] _makeUploadDir 简化为 mkdir 递归模式
 *  - [质量] _getSafeName 拆分为 _sanitizeName + _getExtension，消除引用传参副作用
 */
class LskyProUpload_Plugin implements Typecho_Plugin_Interface
{
    const UPLOAD_DIR   = '/usr/uploads';
    const PLUGIN_NAME  = 'LskyProUpload';
    const VERSION      = '1.2.0';
    const MAX_IMG_SIZE = 10 * 1024 * 1024; // 10 MB，可按需调整

    // 只保留小写扩展名（_getExtension 已做 strtolower）
    const IMAGE_EXTENSIONS = ['gif', 'jpg', 'jpeg', 'png', 'tiff', 'bmp', 'ico', 'psd', 'webp'];

    // 允许的图片 MIME 类型，用于真实文件内容校验
    const IMAGE_MIMES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'image/bmp', 'image/tiff', 'image/x-icon', 'image/vnd.adobe.photoshop',
    ];

    // -------------------------------------------------------------------------
    // 插件生命周期
    // -------------------------------------------------------------------------

    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Upload')->uploadHandle     = ['LskyProUpload_Plugin', 'uploadHandle'];
        Typecho_Plugin::factory('Widget_Upload')->modifyHandle     = ['LskyProUpload_Plugin', 'modifyHandle'];
        Typecho_Plugin::factory('Widget_Upload')->deleteHandle     = ['LskyProUpload_Plugin', 'deleteHandle'];
        Typecho_Plugin::factory('Widget_Upload')->attachmentHandle = ['LskyProUpload_Plugin', 'attachmentHandle'];

        Typecho_Plugin::factory('admin/write-post.php')->bottom = ['LskyProUpload_Plugin', 'injectScript'];
        Typecho_Plugin::factory('admin/write-page.php')->bottom = ['LskyProUpload_Plugin', 'injectScript'];
    }

    public static function deactivate() {}

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $desc = new Typecho_Widget_Helper_Form_Element_Text(
            'desc', null, '', '插件介绍：',
            '<p>本插件由 isYangs 基于泽泽站长的插件修改而来</p>'
        );
        $form->addInput($desc);

        $api = new Typecho_Widget_Helper_Form_Element_Text(
            'api', null, '', 'Api：',
            '兰空图床 API 地址，示例：https://lsky.pro'
        );
        $form->addInput($api);

        $token = new Typecho_Widget_Helper_Form_Element_Text(
            'token', null, '', 'Token：', '兰空 API Token'
        );
        $form->addInput($token);

        $strategy_id = new Typecho_Widget_Helper_Form_Element_Text(
            'strategy_id', null, '', 'Strategy_id：', '存储策略 ID（可选）'
        );
        $form->addInput($strategy_id);

        echo '<script>window.onload=function(){document.getElementsByName("desc")[0].type="hidden";}</script>';
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    // -------------------------------------------------------------------------
    // 前端脚本注入
    // -------------------------------------------------------------------------

    public static function injectScript()
    {
        echo '<script src="/usr/plugins/LskyProUpload/assets/paste-upload.js?v=' . self::VERSION . '"></script>' . "\n";
    }

    // -------------------------------------------------------------------------
    // 粘贴上传 AJAX 入口
    // -------------------------------------------------------------------------

    /**
     * 处理前端粘贴上传的 AJAX 请求
     */
    public static function pasteUploadHandle()
    {
        // 1. 登录鉴权
        $user = \Widget\User::alloc();
        if (!$user->hasLogin()) {
            self::jsonResponse(false, '未登录，无权上传');
        }

        // 2. CSRF 防护：双重校验
        //    a) X-Requested-With（JS 端设置，普通表单无法伪造）
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
            self::jsonResponse(false, '非法请求');
        }
        //    b) Referer 来源校验
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $siteUrl = Options::alloc()->siteUrl;
        if (!empty($referer) && strpos($referer, rtrim($siteUrl, '/')) !== 0) {
            self::jsonResponse(false, '非法请求来源');
        }

        // 3. 配置有效性检查
        if ($cfgErr = self::_validateConfig()) {
            self::jsonResponse(false, $cfgErr);
        }

        // 4. 文件存在性检查
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            self::jsonResponse(false, '未接收到文件或上传出错');
        }

        $file = $_FILES['file'];

        // 5. 文件大小校验（前端已拦截，后端兜底）
        if ($file['size'] > self::MAX_IMG_SIZE) {
            self::jsonResponse(false, '图片大小不能超过 ' . (self::MAX_IMG_SIZE / 1024 / 1024) . 'MB');
        }

        // 6. 扩展名校验
        $name = $file['name'];
        $ext  = self::_getExtension($name);
        if (!self::_isImage($ext)) {
            self::jsonResponse(false, '仅支持上传图片格式');
        }

        // 7. 真实 MIME 校验，防止 MIME 欺骗（如把 PHP 改名为 .jpg）
        if (!self::_isRealImage($file['tmp_name'])) {
            self::jsonResponse(false, '文件内容不是合法图片');
        }

        // 8. 处理自定义文件名
        $customName = !empty($_POST['name']) ? trim($_POST['name']) : null;
        if ($customName) {
            $customName   = pathinfo($customName, PATHINFO_FILENAME); // 去掉可能带的扩展名
            $file['name'] = self::_sanitizeName($customName) . '.' . $ext;
        }

        // 9. 上传到图床
        $result = self::_uploadImg($file, $ext);
        if (!$result) {
            self::jsonResponse(false, '图片上传失败，请检查图床配置');
        }

        $imageName = $customName ?: pathinfo($result['name'], PATHINFO_FILENAME);
        $markdown  = '![' . $imageName . '](' . $result['path'] . ')';

        self::jsonResponse(true, '上传成功', [
            'markdown' => $markdown,
            'url'      => $result['path'],
            'name'     => $imageName,
        ]);
    }

    // -------------------------------------------------------------------------
    // Typecho 上传 Hook 实现
    // -------------------------------------------------------------------------

    public static function uploadHandle($file)
    {
        if (empty($file['name'])) {
            return false;
        }

        $name = $file['name'];
        $ext  = self::_getExtension($name);
        $file['name'] = self::_sanitizeName($name) . '.' . $ext;

        if (!Upload::checkFileType($ext) || Common::isAppEngine()) {
            return false;
        }

        if (self::_isImage($ext)) {
            return self::_uploadImg($file, $ext);
        }

        return self::_uploadOtherFile($file, $ext);
    }

    public static function deleteHandle(array $content): bool
    {
        $ext = $content['attachment']->type;

        if (self::_isImage($ext)) {
            return self::_deleteImg($content);
        }

        $path = $content['attachment']->path;
        // 修复：删除前先检查文件是否存在，避免产生 PHP Warning
        return file_exists($path) && unlink($path);
    }

    public static function modifyHandle($content, $file)
    {
        if (empty($file['name'])) {
            return false;
        }

        $name = $file['name'];
        $ext  = self::_getExtension($name);

        if ($content['attachment']->type !== $ext || Common::isAppEngine()) {
            return false;
        }

        if (!self::_getUploadFile($file)) {
            return false;
        }

        if (self::_isImage($ext)) {
            // 修复：先上传新图，成功后再删除旧图，避免上传失败导致旧图永久丢失
            $newResult = self::_uploadImg($file, $ext);
            if ($newResult) {
                self::_deleteImg($content);
            }
            return $newResult;
        }

        return self::_uploadOtherFile($file, $ext);
    }

    public static function attachmentHandle(array $content): string
    {
        // 修复：使用 pathinfo 获取扩展名，避免 substr 截断 webp/tiff/jpeg 等
        // 修复：unserialize 失败时返回 false，需做类型校验避免 PHP Warning
        $arr = unserialize($content['text']);
        if (!is_array($arr) || empty($arr['path'])) {
            return $content['attachment']->path ?? '';
        }

        $ext = strtolower(pathinfo($arr['path'], PATHINFO_EXTENSION));

        if (self::_isImage($ext)) {
            return $content['attachment']->path ?? '';
        }

        $ret = explode(self::UPLOAD_DIR, $arr['path']);
        return Common::url(self::UPLOAD_DIR . ($ret[1] ?? ''), Options::alloc()->siteUrl);
    }

    // -------------------------------------------------------------------------
    // 私有辅助方法
    // -------------------------------------------------------------------------

    /**
     * 校验插件配置是否完整，返回错误信息或 null
     */
    private static function _validateConfig(): ?string
    {
        $options = Options::alloc()->plugin(self::PLUGIN_NAME);
        if (empty($options->api)) {
            return '请先在插件设置中填写 API 地址';
        }
        if (empty($options->token)) {
            return '请先在插件设置中填写 Token';
        }
        return null;
    }

    /**
     * 校验文件真实 MIME 类型（防止 MIME 欺骗）
     */
    private static function _isRealImage(string $tmpPath): bool
    {
        if (!function_exists('finfo_open')) {
            // 若 finfo 不可用，降级为 getimagesize 校验
            return @getimagesize($tmpPath) !== false;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmpPath);
        return in_array($mime, self::IMAGE_MIMES, true);
    }

    /**
     * 净化文件名（去除危险字符，返回无扩展名的安全文件名）
     */
    private static function _sanitizeName(string $name): string
    {
        $name = str_replace(['"', '<', '>', '\\', '/', "\0"], '', $name);
        $name = pathinfo($name, PATHINFO_FILENAME);
        return $name ?: 'image';
    }

    /**
     * 提取并返回小写扩展名
     */
    private static function _getExtension(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }

    private static function _isImage(string $ext): bool
    {
        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }

    private static function _getUploadFile(array $file): string
    {
        return $file['tmp_name'] ?? ($file['bytes'] ?? ($file['bits'] ?? ''));
    }

    private static function _getUploadDir(string $ext = ''): string
    {
        if (self::_isImage($ext)) {
            $url = parse_url(Options::alloc()->siteUrl);
            $dir = str_replace('.', '_', $url['host'] ?? 'local');
            return '/' . $dir . self::UPLOAD_DIR;
        }
        if (defined('__TYPECHO_UPLOAD_DIR__')) {
            return __TYPECHO_UPLOAD_DIR__;
        }
        return Common::url(self::UPLOAD_DIR, __TYPECHO_ROOT_DIR__);
    }

    /**
     * 简化版目录创建（利用 mkdir 的 recursive 参数）
     */
    private static function _makeUploadDir(string $path): bool
    {
        return is_dir($path) || mkdir($path, 0755, true);
    }

    private static function _uploadOtherFile(array $file, string $ext)
    {
        $dir = self::_getUploadDir($ext) . '/' . date('Y') . '/' . date('m');

        if (!self::_makeUploadDir($dir)) {
            return false;
        }

        $path = sprintf('%s/%u.%s', $dir, crc32(uniqid()), $ext);

        if (!isset($file['tmp_name']) || !@move_uploaded_file($file['tmp_name'], $path)) {
            return false;
        }

        return [
            'name' => $file['name'],
            'path' => $path,
            'size' => $file['size'] ?? filesize($path),
            'type' => $ext,
            'mime' => Common::mimeContentType($path),
        ];
    }

    private static function _uploadImg(array $file, string $ext)
    {
        $options    = Options::alloc()->plugin(self::PLUGIN_NAME);
        $api        = rtrim($options->api, '/') . '/api/v1/upload';
        $token      = 'Bearer ' . $options->token;
        $strategyId = $options->strategy_id ?? '';

        $tmp = self::_getUploadFile($file);
        if (empty($tmp)) {
            return false;
        }

        // 使用系统临时目录，避免在 Web 可访问目录创建文件
        // tempnam() 会创建一个占位文件（无扩展名），我们只需要它生成的唯一路径，
        // 再附加扩展名作为实际文件路径，并立即删除占位文件，避免临时文件泄露
        $tmpBase = tempnam(sys_get_temp_dir(), 'lsky_');
        $img     = $tmpBase . '.' . $ext;
        @unlink($tmpBase); // 删除 tempnam 创建的占位文件

        if (!rename($tmp, $img)) {
            return false;
        }

        // 获取 MIME 类型（与 _isRealImage 保持一致，兼容未安装 fileinfo 扩展的环境）
        if (function_exists('finfo_open')) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($img);
        } else {
            $imageInfo = @getimagesize($img);
            $mime      = $imageInfo['mime'] ?? 'application/octet-stream';
        }
        $params = ['file' => new CURLFile($img, $mime, $file['name'])];
        if (!empty($strategyId)) {
            $params['strategy_id'] = $strategyId;
        }

        $res = self::_curlRequest('POST', $api, $params, $token);

        // 确保临时文件被清理
        if (file_exists($img)) {
            unlink($img);
        }

        if (!$res) {
            return false;
        }

        $json = json_decode($res, true);

        // 修复：用 empty() 替代 === false，同时捕获 status 为 0/null/false 的情况
        if (empty($json) || empty($json['status'])) {
            error_log('[LskyProUpload] 上传失败: ' . json_encode($json, JSON_UNESCAPED_UNICODE));
            return false;
        }

        $data = $json['data'];
        return [
            'img_key'     => $data['key'],
            'img_id'      => $data['md5'],
            'name'        => $data['origin_name'],
            'path'        => $data['links']['url'],
            'size'        => $data['size'],       // 修复：兰空 API 返回单位为字节，无需 *1024
            'type'        => $data['extension'],
            'mime'        => $data['mimetype'],
            'description' => $data['mimetype'],
        ];
    }

    private static function _deleteImg(array $content): bool
    {
        $options = Options::alloc()->plugin(self::PLUGIN_NAME);
        $api     = rtrim($options->api, '/') . '/api/v1/images';
        $token   = 'Bearer ' . $options->token;
        $id      = $content['attachment']->img_key ?? '';

        if (empty($id)) {
            return false;
        }

        $res  = self::_curlRequest('DELETE', $api . '/' . $id, ['key' => $id], $token);
        $json = json_decode($res, true);

        return is_array($json) && ($json['status'] === true);
    }

    /**
     * 统一 cURL 请求方法（合并原 _curlPost / _curlDelete，增加超时 & SSL 校验）
     *
     * @param string $method  HTTP 方法，如 POST / DELETE
     * @param string $api     请求 URL
     * @param array  $post    请求体数据
     * @param string $token   Bearer Token
     * @return string|false   响应内容，失败返回 false
     */
    private static function _curlRequest(string $method, string $api, array $post, string $token)
    {
        $headers = [
            'Content-Type: multipart/form-data',
            'Accept: application/json',
            'Authorization: ' . $token,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $api,
            // 修复：启用 SSL 证书验证，防止中间人攻击
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            // 修复：仅 POST 请求才设置 CURLOPT_POST，避免与 CURLOPT_CUSTOMREQUEST=DELETE 冲突
            CURLOPT_POST           => strtoupper($method) === 'POST',
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => 'LskyProUpload/' . self::VERSION,
        ]);

        $res      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            error_log('[LskyProUpload] cURL 错误 (' . $method . ' ' . $api . '): ' . curl_error($ch));
            curl_close($ch);
            return false;
        }

        curl_close($ch);

        // 修复：非 2xx 响应视为失败
        if ($httpCode < 200 || $httpCode >= 300) {
            error_log('[LskyProUpload] HTTP 错误 ' . $httpCode . ' (' . $method . ' ' . $api . ')');
            return false;
        }

        return $res;
    }

    /**
     * 输出 JSON 响应并终止脚本
     */
    private static function jsonResponse(bool $status, string $message, array $data = [])
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => $status,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// -------------------------------------------------------------------------
// AJAX 粘贴上传入口（文件末尾触发，避免污染类作用域）
// -------------------------------------------------------------------------
if (
    isset($_GET['action'])
    && $_GET['action'] === 'lsky_paste_upload'
    && $_SERVER['REQUEST_METHOD'] === 'POST'
) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    LskyProUpload_Plugin::pasteUploadHandle();
}
