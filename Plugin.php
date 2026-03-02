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
 * @author yeying
 * @version 1.1.0
 * @link https://www.yeyhome.com
 */
class LskyProUpload_Plugin implements Typecho_Plugin_Interface
{
    const UPLOAD_DIR  = '/usr/uploads';
    const PLUGIN_NAME = 'LskyProUpload';

    // 修复：去掉冗余的大写扩展名（_getSafeName 已做 strtolower 处理）
    const IMAGE_EXTENSIONS = ['gif', 'jpg', 'jpeg', 'png', 'tiff', 'bmp', 'ico', 'psd', 'webp'];

    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Upload')->uploadHandle     = ['LskyProUpload_Plugin', 'uploadHandle'];
        Typecho_Plugin::factory('Widget_Upload')->modifyHandle     = ['LskyProUpload_Plugin', 'modifyHandle'];
        Typecho_Plugin::factory('Widget_Upload')->deleteHandle     = ['LskyProUpload_Plugin', 'deleteHandle'];
        Typecho_Plugin::factory('Widget_Upload')->attachmentHandle = ['LskyProUpload_Plugin', 'attachmentHandle'];

        Typecho_Plugin::factory('admin/write-post.php')->bottom = ['LskyProUpload_Plugin', 'injectScript'];
        Typecho_Plugin::factory('admin/write-page.php')->bottom = ['LskyProUpload_Plugin', 'injectScript'];
    }

    public static function deactivate()
    {
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $desc = new Typecho_Widget_Helper_Form_Element_Text('desc', NULL, '', '插件介绍：', '<p>本插件由isYangs基于泽泽站长的插件修改而来</p>');
        $form->addInput($desc);

        $api = new Typecho_Widget_Helper_Form_Element_Text('api', NULL, '', 'Api：', '兰空图床 API 地址，示例：https://lsky.pro');
        $form->addInput($api);

        $token = new Typecho_Widget_Helper_Form_Element_Text('token', NULL, '', 'Token：', '兰空 API Token');
        $form->addInput($token);

        $strategy_id = new Typecho_Widget_Helper_Form_Element_Text('strategy_id', NULL, '', 'Strategy_id：', '存储策略 ID（可选）');
        $form->addInput($strategy_id);

        echo '<script>window.onload = function(){document.getElementsByName("desc")[0].type = "hidden";}</script>';
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 注入粘贴上传脚本
     */
    public static function injectScript()
    {
        echo '<script src="/usr/plugins/LskyProUpload/assets/paste-upload.js"></script>' . "\n";
    }

    /**
     * 公开的粘贴上传处理方法（用于 AJAX 请求）
     */
    public static function pasteUploadHandle()
    {
        // 修复：增加登录鉴权，防止未授权用户滥用上传接口
        $user = \Widget\User::alloc();
        if (!$user->hasLogin()) {
            self::jsonResponse(false, '未登录，无权上传');
        }

        if (empty($_FILES['file'])) {
            self::jsonResponse(false, '未接收到文件');
        }

        $file = $_FILES['file'];
        $name = $file['name'];
        $ext  = self::_getSafeName($name);

        if (!self::_isImage($ext)) {
            self::jsonResponse(false, '仅支持上传图片格式');
        }

        // 使用用户自定义的文件名
        $customName = !empty($_POST['name']) ? trim($_POST['name']) : null;
        if ($customName) {
            if (strpos($customName, '.') !== false) {
                $customName = pathinfo($customName, PATHINFO_FILENAME);
            }
            $file['name'] = $customName . '.' . $ext;
        }

        $result = self::_uploadImg($file, $ext);

        if (!$result) {
            self::jsonResponse(false, '图片上传失败');
        }

        $imageName = $customName ? $customName : $result['name'];
        if (strpos($imageName, '.') !== false) {
            $imageName = pathinfo($imageName, PATHINFO_FILENAME);
        }

        $markdown = '![' . $imageName . '](' . $result['path'] . ')';

        self::jsonResponse(true, '上传成功', [
            'markdown' => $markdown,
            'url'      => $result['path'],
            'name'     => $imageName
        ]);
    }

    /**
     * JSON 响应辅助方法
     */
    private static function jsonResponse(bool $status, string $message, array $data = [])
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => $status,
            'message' => $message,
            'data'    => $data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function uploadHandle($file)
    {
        if (empty($file['name'])) {
            return false;
        }

        $ext = self::_getSafeName($file['name']);

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

        return unlink($content['attachment']->path);
    }

    public static function modifyHandle($content, $file)
    {
        if (empty($file['name'])) {
            return false;
        }

        $ext = self::_getSafeName($file['name']);

        if ($content['attachment']->type != $ext || Common::isAppEngine()) {
            return false;
        }

        if (!self::_getUploadFile($file)) {
            return false;
        }

        if (self::_isImage($ext)) {
            self::_deleteImg($content);
            return self::_uploadImg($file, $ext);
        }

        return self::_uploadOtherFile($file, $ext);
    }

    public static function attachmentHandle(array $content): string
    {
        // 修复：使用 pathinfo 获取扩展名，避免 substr 截断 webp/tiff/jpeg 等4位扩展名
        $arr = unserialize($content['text']);
        $ext = strtolower(pathinfo($arr['path'] ?? '', PATHINFO_EXTENSION));

        if (self::_isImage($ext)) {
            return $content['attachment']->path ?? '';
        }

        $ret = explode(self::UPLOAD_DIR, $arr['path']);
        return Common::url(self::UPLOAD_DIR . ($ret[1] ?? ''), Options::alloc()->siteUrl);
    }

    private static function _getUploadDir(string $ext = ''): string
    {
        if (self::_isImage($ext)) {
            $url = parse_url(Options::alloc()->siteUrl);
            $DIR = str_replace('.', '_', $url['host']);
            return '/' . $DIR . self::UPLOAD_DIR;
        } elseif (defined('__TYPECHO_UPLOAD_DIR__')) {
            return __TYPECHO_UPLOAD_DIR__;
        } else {
            return Common::url(self::UPLOAD_DIR, __TYPECHO_ROOT_DIR__);
        }
    }

    private static function _getUploadFile(array $file): string
    {
        return $file['tmp_name'] ?? ($file['bytes'] ?? ($file['bits'] ?? ''));
    }

    private static function _getSafeName(string &$name): string
    {
        $name = str_replace(['"', '<', '>'], '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);

        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    /**
     * 修复：简化目录创建，使用 mkdir 递归参数替代复杂递归逻辑
     */
    private static function _makeUploadDir(string $path): bool
    {
        return is_dir($path) || mkdir($path, 0755, true);
    }

    private static function _isImage(string $ext): bool
    {
        return in_array($ext, self::IMAGE_EXTENSIONS, true);
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
            'mime' => Common::mimeContentType($path)
        ];
    }

    private static function _uploadImg(array $file, string $ext)
    {
        $options    = Options::alloc()->plugin(self::PLUGIN_NAME);
        $api        = $options->api . '/api/v1/upload';
        $token      = 'Bearer ' . $options->token;
        $strategyId = $options->strategy_id;

        $tmp = self::_getUploadFile($file);
        if (empty($tmp)) {
            return false;
        }

        // 修复：使用系统临时目录生成安全的临时文件路径，避免在网站根目录创建文件
        $img = tempnam(sys_get_temp_dir(), 'lsky_') . '.' . $ext;

        if (!rename($tmp, $img)) {
            return false;
        }

        $params = ['file' => new CURLFile($img, mime_content_type($img), $file['name'])];
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

        if (empty($json) || $json['status'] === false) {
            // 修复：日志写入插件目录（非 Web 直接可访问路径，或改为 error_log）
            error_log('[LskyProUpload] 上传失败: ' . json_encode($json, JSON_UNESCAPED_UNICODE));
            return false;
        }

        $data = $json['data'];
        return [
            'img_key'     => $data['key'],
            'img_id'      => $data['md5'],
            'name'        => $data['origin_name'],
            'path'        => $data['links']['url'],
            'size'        => $data['size'] * 1024,
            'type'        => $data['extension'],
            'mime'        => $data['mimetype'],
            'description' => $data['mimetype'],
        ];
    }

    private static function _deleteImg(array $content): bool
    {
        $options = Options::alloc()->plugin(self::PLUGIN_NAME);
        $api     = $options->api . '/api/v1/images';
        $token   = 'Bearer ' . $options->token;
        $id      = $content['attachment']->img_key ?? '';

        if (empty($id)) {
            return false;
        }

        $res  = self::_curlRequest('DELETE', $api . '/' . $id, ['key' => $id], $token);
        $json = json_decode($res, true);

        // 修复：检查 API 返回的实际状态，而不是仅判断响应是否为数组
        return is_array($json) && ($json['status'] === true);
    }

    /**
     * 修复：合并 _curlPost 和 _curlDelete 为统一的 cURL 请求方法，消除重复代码，并增加超时设置
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
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_CONNECTTIMEOUT => 10,   // 新增：连接超时 10 秒
            CURLOPT_TIMEOUT        => 60,   // 新增：总超时 60 秒
            CURLOPT_USERAGENT      => 'LskyProUpload/' . '1.1.0',
        ]);

        $res = curl_exec($ch);

        if (curl_errno($ch)) {
            error_log('[LskyProUpload] cURL 错误: ' . curl_error($ch));
        }

        curl_close($ch);
        return $res;
    }
}

/**
 * AJAX 粘贴上传处理入口
 */
if (isset($_GET['action']) && $_GET['action'] === 'lsky_paste_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    LskyProUpload_Plugin::pasteUploadHandle();
}
