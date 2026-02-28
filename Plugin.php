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
 * @version 1.0.0
 * @link https://www.yeyhome.com
 */
class LskyProUpload_Plugin implements Typecho_Plugin_Interface
{
    const UPLOAD_DIR  = '/usr/uploads';
    const PLUGIN_NAME = 'LskyProUpload';
    const IMAGE_EXTENSIONS = array('gif','jpg','jpeg','png','tiff','bmp','ico','psd','webp','JPG','BMP','GIF','PNG','JPEG','ICO','PSD','TIFF','WEBP');

    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Upload')->uploadHandle     = array('LskyProUpload_Plugin', 'uploadHandle');
        Typecho_Plugin::factory('Widget_Upload')->modifyHandle     = array('LskyProUpload_Plugin', 'modifyHandle');
        Typecho_Plugin::factory('Widget_Upload')->deleteHandle     = array('LskyProUpload_Plugin', 'deleteHandle');
        Typecho_Plugin::factory('Widget_Upload')->attachmentHandle = array('LskyProUpload_Plugin', 'attachmentHandle');

        Typecho_Plugin::factory('admin/write-post.php')->bottom = array('LskyProUpload_Plugin', 'injectScript');
        Typecho_Plugin::factory('admin/write-page.php')->bottom = array('LskyProUpload_Plugin', 'injectScript');
    }

    public static function deactivate()
    {
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $desc = new Typecho_Widget_Helper_Form_Element_Text('desc', NULL, '', '插件介绍：', '<p>本插件是在isYangs插件基础上修改而来</p>');
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
        if (empty($_FILES['file'])) {
            self::jsonResponse(false, '未接收到文件');
        }

        $file = $_FILES['file'];
        $name = $file['name'];
        $ext = self::_getSafeName($name);

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
            'url' => $result['path'],
            'name' => $imageName
        ]);
    }

    /**
     * JSON 响应
     */
    private static function jsonResponse($status, $message, $data = [])
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => $status,
            'message' => $message,
            'data' => $data
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
        $arr = unserialize($content['text']);
        $text = strstr($content['text'],'.');
        $ext = substr($text,1,3);
        if (self::_isImage($ext)) {
            return $content['attachment']->path ?? '';
        }

        $ret = explode(self::UPLOAD_DIR, $arr['path']);
        return Common::url(self::UPLOAD_DIR . @$ret[1], Options::alloc()->siteUrl);
    }

    private static function _getUploadDir($ext = ''): string
    {
        if (self::_isImage($ext)) {
            $url = parse_url(Options::alloc()->siteUrl);
            $DIR = str_replace('.', '_', $url['host']);
            return '/' . $DIR . self::UPLOAD_DIR;
        } elseif (defined('__TYPECHO_UPLOAD_DIR__')) {
            return __TYPECHO_UPLOAD_DIR__;
        } else {
            $path = Common::url(self::UPLOAD_DIR, __TYPECHO_ROOT_DIR__);
            return $path;
        }
    }

    private static function _getUploadFile($file): string
    {
        return $file['tmp_name'] ?? ($file['bytes'] ?? ($file['bits'] ?? ''));
    }

    private static function _getSafeName(&$name): string
    {
        $name = str_replace(array('\"', '<', '>'), '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);

        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    private static function _makeUploadDir($path): bool
    {
        $path    = preg_replace("/\\\\+/", '/', $path);
        $current = rtrim($path, '/');
        $last    = $current;

        while (!is_dir($current) && false !== strpos($path, '/')) {
            $last    = $current;
            $current = dirname($current);
        }

        if ($last == $current) {
            return true;
        }

        if (!@mkdir($last)) {
            return false;
        }

        $stat  = @stat($last);
        $perms = $stat['mode'] & 0007777;
        @chmod($last, $perms);

        return self::_makeUploadDir($path);
    }

    private static function _isImage($ext): bool
    {
        return in_array($ext, self::IMAGE_EXTENSIONS);
    }

    private static function _uploadOtherFile($file, $ext)
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
            'mime' => @Common::mimeContentType($path)
        ];
    }

    private static function _uploadImg($file, $ext)
    {
        $options = Options::alloc()->plugin(self::PLUGIN_NAME);
        $api     = $options->api . '/api/v1/upload';
        $token   = 'Bearer '.$options->token;
        $strategyId = $options->strategy_id;

        $tmp     = self::_getUploadFile($file);
        if (empty($tmp)) {
            return false;
        }

        $img = $file['name'];
        if (!rename($tmp, $img)) {
            return false;
        }
        $params = ['file' => new CURLFile($img)];
        if ($strategyId) {
            $params['strategy_id'] = $strategyId;
        }

        $res = self::_curlPost($api, $params, $token);
        unlink($img);

        if (!$res) {
            return false;
        }

        $json = json_decode($res, true);

        if ($json['status'] === false) {
            file_put_contents('./usr/plugins/'.self::PLUGIN_NAME.'/msg.log', json_encode($json, 256) . PHP_EOL, FILE_APPEND);
            return false;
        }

        $data = $json['data'];
        return [
            'img_key' => $data['key'],
            'img_id' => $data['md5'],
            'name'   => $data['origin_name'],
            'path'   => $data['links']['url'],
            'size'   => $data['size']*1024,
            'type'   => $data['extension'],
            'mime'   => $data['mimetype'],
            'description'  => $data['mimetype'],
        ];
    }

    private static function _deleteImg(array $content): bool
    {
        $options = Options::alloc()->plugin(self::PLUGIN_NAME);
        $api     = $options->api . '/api/v1/images';
        $token   = 'Bearer '.$options->token;

        $id = $content['attachment']->img_key;

        if (empty($id)) {
            return false;
        }

        $res  = self::_curlDelete($api . '/' . $id, ['key' => $id], $token);
        $json = json_decode($res, true);

        if (!is_array($json)) {
            return false;
        }

        return true;
    }

    private static function _curlDelete($api, $post, $token)
    {
        $headers = array(
            "Content-Type: multipart/form-data",
            "Accept: application/json",
            "Authorization: ".$token,
        );
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/85.0.4183.102 Safari/537.36');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        $res = curl_exec($ch);
        curl_close($ch);

        return $res;
    }

    private static function _curlPost($api, $post, $token)
    {
        $headers = array(
            "Content-Type: multipart/form-data",
            "Accept: application/json",
            "Authorization: ".$token,
        );
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/85.0.4183.102 Safari/537.36');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        $res = curl_exec($ch);
        curl_close($ch);

        return $res;
    }
}

/**
 * AJAX 粘贴上传处理
 */
if (isset($_GET['action']) && $_GET['action'] === 'lsky_paste_upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    LskyProUpload_Plugin::pasteUploadHandle();
}
