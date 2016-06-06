<?php
/*
Plugin Name: CIS 云端图片存储
Plugin URI:
Description: 支持使用云存储作为图片的存储空间，目前支持BOS百度云存储。当你设置好插件并启用后：(1)从媒体库上传图片时图片会被自动上传至云平台，而本地的图片则会被删除；(2)编辑文章并从外部网站(云平台以外的网站)引用图片(复制html代码中的img)，最后点击发布或更新时，图片会被自动上传至云平台，引用的图片的外部链接(src地址)会被替换为对应的云平台上的地址；(3)在媒体库删除图片时会将云平台上的图片删除。
Version:     1.0.4
Author:      Jacky Yang
Author URI:
License:     GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Domain Path: /languages
Text Domain: my-toolset
*/
include 'BaiduBce.phar';

use BaiduBce\BceClientConfigOptions;
use BaiduBce\Util\Time;
use BaiduBce\Util\MimeTypes;
use BaiduBce\Http\HttpHeaders;
use BaiduBce\Services\Bos\BosClient;
use BaiduBce\Services\Bos\BosOptions;
use BaiduBCE\Auth\SignOptions;


define('BOS_BASEFOLDER', plugin_basename(dirname(__FILE__)));

// BOS设置页面，将BOS存入数据库
function bos_setting_page() {
    $options = [];
    $settings_updated = false;

    if (isset($_POST['bucket'])) {
        $options['bucket'] = trim(stripslashes($_POST['bucket']));
    }
    if (isset($_POST['ak'])) {
        $options['ak'] = trim(stripslashes($_POST['ak']));
    }
    if (isset($_POST['sk'])) {
        $options['sk'] = trim(stripslashes($_POST['sk']));
    }
    if (isset($_POST['host'])) {
        $options['host'] = trim(stripslashes($_POST['host']));
    }
    if (isset($_POST['path'])) {
        $options['path'] = trim(stripslashes($_POST['path']));
    }
    if (isset($_POST['domain'])) {
        $options['domain'] = trim(stripslashes($_POST['domain']));
    }

    if ($options !== []) {
        // 写入数据库
        update_option('bos_options', $options);
        $settings_updated = true;
    }

    // 从数据库中取出
    $bos_options   = get_option('bos_options', true);

    $bos_bucket    = esc_attr($bos_options['bucket']);
    $bos_ak        = esc_attr($bos_options['ak']);
    $bos_sk        = esc_attr($bos_options['sk']);
    $bos_host      = esc_attr($bos_options['host']);
    $upload_path   = esc_attr($bos_options['path']);
    $bucket_domain = esc_attr($bos_options['domain']);

    ?>

    <div class="wrap">
        <div id="icon-options-general" class="icon32"><br></div>
        <h2>百度云BOS存储设置</h2>
        <?php if ($settings_updated): ?>
            <div id="setting-error-settings_updated" class="updated settings-error">
                <p><strong>设置已保存。</strong></p></div>
        <?php endif; ?>
        <form name="form1" method="post"
              action="<?php echo wp_nonce_url('./options-general.php?page=' . plugin_basename(dirname(__FILE__)) . '/wp-bos.php'); ?>">
            <table class="form-table">
                <tbody>
                <tr valign="top">
                    <th scope="row"><label for="bucket">Bucket设置</label></th>
                    <td>
                        <input name="bucket" type="text" id="bucket" value="<?php echo $bos_bucket; ?>"
                               class="regular-text" placeholder="请输入云存储使用的 Bucket">

                        <p class="description">访问 <a href="http://console.bce.baidu.com/bos/" target="_blank">百度开放云对象存储BOS</a> 创建
                            Bucket ，设置权限为“公有读”，填写以上内容。</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="ak">Access Key / API key(AK)</label></th>
                    <td><input name="ak" type="text" id="ak"
                               value="<?php echo $bos_ak; ?>" class="regular-text">

                        <p class="description">访问“安全认证”->“ <a href="http://console.bce.baidu.com/iam/#/iam/accesslist"
                                                     target="_blank">AccessKey</a>”，获取 AK和SK。</p></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="sk">Secret Key (SK)</label></th>
                    <td><input name="sk" type="text" id="sk"
                               value="<?php echo $bos_sk; ?>" class="regular-text">
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="sk">HOST设置</label></th>
                    <td><input name="host" type="text" id="host"
                               value="<?php echo $bos_host; ?>" class="regular-text">

                        <p class="description">根据地域设置HOST，例如“华北 - 北京”为bj.bcebos.com（请勿带http前缀）</p></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="sk">BOS Bucket 域名设置</label></th>
                    <td><input name="domain" type="text" id="domain"
                               value="<?php echo $bucket_domain; ?>" class="regular-text">

                        <p class="description">请填写BOS Bucket绑定的自定义域名（请勿带http前缀）</p></td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="path">上传文件夹设置</label></th>
                    <td><input name="path" type="text" id="path"
                               value="<?php echo $upload_path; ?>" class="regular-text">

                        <p class="description">填写需要上传到bucket下文件夹的名称</p></td>
                </tr>
                </tbody>
            </table>
            <p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="保存更改">
            </p>
        </form>
    </div>
<?php }

// 钩子函数: 添加BOS设置菜单
function add_setting_menu() {
    add_options_page('百度云BOS存储设置', '百度BOS设置', 'manage_options', __FILE__,
                     'bos_setting_page');
}

// 获取BosClient对象
function get_bos_client() {
    $bos_options = get_option('bos_options', true);
    $config      = [
                       'credentials' => [
                           'ak' => $bos_options['ak'],
                           'sk' => $bos_options['sk'],
                   ],
                   'endpoint' => 'http://' . $bos_options['host'],
        ];
    $bos_client  = new BosClient($config);

    return $bos_client;
}

/**
 * 将图片上传到BOS并删除本地文件
 *
 * @param array $data _wp_attachment_metadata
 * @param int $post_id
 *
 * @return string $ori_object
 */
function upload_attachement_to_bos($data, $post_id) {
    // 原图上传和删除
    $wp_upload_dir = str_replace('\\', '/', wp_upload_dir());
    $file_name     = basename($data['file']);
    $file_path     = $wp_upload_dir['basedir'] . $wp_upload_dir['subdir'] . '/'
                   . $file_name;

    if (!file_exists($file_path)) {
        return new WP_Error('exception', sprintf('File %s does not exist',
                                                 $file_path));
    }

    $bos_options = get_option('bos_options', true);
    $ori_object  = $bos_options['path'] . $wp_upload_dir['subdir'] . '/'
                 . $file_name;
    $bos_client  = get_bos_client();

    if (file_exists($file_path)) {
        try {
            $bos_client->putObjectFromFile($bos_options['bucket'], $ori_object,
                                           $file_path);
        } catch (Exception $e) {
            $error_msg = sprintf('Error uploading %s to BOS: %s',$file_path,
                                 $e->getMessage());
            error_log($error_msg);
        }
    }

    if (file_exists($file_path)) {
        try {
            unlink($file_path);
        } catch (Exception $e) {
            $error_msg = sprintf('Error removing local file %s: %s', $file_path,
                                 $e->getMessage());
            error_log($error_msg);
        }

    }

    // 缩略图上传和删除
    if (isset($data['sizes']) && count($data['sizes']) > 0) {
        foreach ($data['sizes'] as $key => $data) {
            $thumb_path   = $wp_upload_dir['basedir'] . $wp_upload_dir['subdir']
                          . '/' . $data['file'];
            $thumb_object = $bos_options['path'] . $wp_upload_dir['subdir'] . '/'
                          . $data['file'];

            if (file_exists($thumb_path)) {
                try {
                    $bos_client->putObjectFromFile($bos_options['bucket'],
                                                   $thumb_object, $thumb_path);
                } catch (Exception $e) {
                    $error_msg = sprintf('Error uploading %s to BOS: %s',$thumb_path,
                                         $e->getMessage());
                    error_log($error_msg);
                }
            }

            if (file_exists($thumb_path)) {
                try {
                    unlink($thumb_path);
                } catch (Exception $e) {
                    $error_msg = sprintf('Error removing local file %s: %s', $thumb_path,
                                         $e->getMessage());
                    error_log($error_msg);
                }
            }
        }
    }

    return $ori_object;
}

// 钩子函数: 调用上传函数并将上传的原图在bucket下的路径信息保存到数据库
function update_attachment_metadata($data, $post_id) {
    $ori_object_key = upload_attachement_to_bos($data, $post_id);
    // 将原始图片在BOS bucket下的路径信息(object信息)添加到数据库，这个数据别处好像没有用到
    add_post_meta($post_id, 'bos_info', $ori_object_key);

    return $data;
}

/**
 * 钩子函数: 获取附件的url
 *
 * @param string $url 本地图片url
 *
 * @return string $url BOS图片url
 */
function get_attachment_url($url, $post_id) {
    $bos_options   = get_option('bos_options', true);
    $arr           = parse_url($url);
    $file_name     = basename($url);
    $file_path     = $_SERVER['DOCUMENT_ROOT'] . $arr['path'];

    if (!file_exists($file_path)) {
        $arr2   = explode('/', $arr['path']);
        $n      = count($arr2);
        $object = $bos_options['path'] . '/'. $arr2[$n-3] . '/' .$arr2[$n-2]
                . '/' . $file_name;

        $url = get_object_url($object);
    }

    return $url;
}

/**
 * 钩子函数: 对responsive images srcset重新设置，wp原来的函数是从本地获取的url
 *
 * @param array $sources
 *
 * @return $sources
 */
function calculate_image_srcset($sources) {
    $bos_options   = get_option('bos_options', true);

    foreach ($sources as $key => &$value) {
        $file_name = basename($value['url']);
        $arr       = parse_url($value['url']);
        $file_path = $_SERVER['DOCUMENT_ROOT'] . $arr['path'];

        if (!file_exists($file_path)) {
            $arr2   = explode('/', $arr['path']);
            $n      = count($arr2);
            $object = $bos_options['path'] . '/' . $arr2[$n-3] . '/'
                    . '/' . $arr2[$n-2] . '/' . $file_name;

            $value['url'] = get_object_url($object);
        }
    }

    return $sources;
}

/**
 * 钩子函数: 删除BOS上的附件
 *
 * @param string $file 附件的本地路径
 *
 * @return string $file
 */
function del_attachments_from_bos($file) {
    $bos_options = get_option('bos_options', true);
    $object      = $bos_options['path'] . $file;
    $bos_client  = get_bos_client();
    $url         = get_object_url($object);

    // 检查远程文件是否存在
    $ch = curl_init();
    $timeout = 30;
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);

    $contents = curl_exec($ch);

    if (strpos($contents, '404') !== false) {
        // error_log(sprintf('Exception: file %s does not exist', $object));
        return new WP_Error('exception', sprintf('File %s does not exist',
                            $object));
    }

    // 删除时会将原图和缩略图都删除
    try {
        $bos_client->deleteObject($bos_options['bucket'], $object);
    } catch (Exception $e) {
        $error_msg = sprintf('Error removing files %s from BOS: %s', $object, $e->getMessage());
        error_log($error_msg);
    }

    return $file;
}

/**
 * 钩子函数: 增加设置链接
 *
 * @param array $links
 * @param string $file
 *
 * @return array $links
 */
function plugin_action_links($links, $file) {
    if ($file == plugin_basename(dirname(__FILE__) . '/wp-bos.php')) {
        $links[] = '<a href="options-general.php?page=' . BOS_BASEFOLDER
                 . '/wp-bos.php">' . __('Settings') . '</a>';
    }
    return $links;
}

/**
 * 获取BOS上object的url
 * @param string $object
 *
 * @retrun string $url
 */
function get_object_url($object) {
    $bos_options = get_option('bos_options', true);
    $bos_client  = get_bos_client();
    $signOptions = [
        SignOptions::TIMESTAMP=>new \DateTime(),
        SignOptions::EXPIRATION_IN_SECONDS=>300,
    ];

    $url = $bos_client->generatePreSignedUrl($bos_options['bucket'], $object,
        [BosOptions::SIGN_OPTIONS => $signOptions]);
    $arr = explode('?', $url);

    return $arr[0];
}

/**
 * 钩子函数：将post_content中BOS外的img上传至BOS并替换url
 *
 * @param int $post_id
 * @param object $post
 *
 */
function bos_save_post($post_id, $post) {
    // wordpress 全局变量 wpdb类
    global $wpdb;

    // 只有在点击发布/更新时才执行以下动作
    if($post->post_status == 'publish') {
        // 匹配<img>、src，存入$matches数组,
        $p   = '/<img.*[\s]src=[\"|\'](.*)[\"|\'].*>/iU';
        $num = preg_match_all($p, $post->post_content, $matches);

        if ($num) {
            // BOS参数(数组)，用来构造url
            $bos_options   = get_option('bos_options', true);
            // 本地上传路径信息(数组)，用来构造url
            $wp_upload_dir = str_replace('\\', '/', wp_upload_dir());

            // 脚本执行不限制时间
            set_time_limit(0);

            // 构造curl，配置参数
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            // 抓取时如果发生301，302跳转，则进行跳转抓取
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            // 最多跳转20次
            curl_setopt($ch, CURLOPT_MAXREDIRS,20);
            // 发起连接前最长等待时间
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);

            foreach ($matches[1] as $src) {
                if (isset($src) && strpos($src, $bos_options['host']) === false
                   && strpos($src, $bos_options['domain']) == false) {

                    // 检查src中的url有无扩展名，没有则重新给定文件名
                    $file_info = wp_check_filetype(basename($src), null);
                    if ($file_info['ext'] == false) {
                        date_default_timezone_set('PRC');
                        $file_name = date('YmdHis-').dechex(mt_rand(100000, 999999)).'.tmp';
                    } else {
                        $file_name = basename($src);
                    }

                    // 抓取图片, 将图片写入本地文件
                    curl_setopt($ch, CURLOPT_URL, $src);
                    $file_path = $wp_upload_dir['path'] . '/' . $file_name;
                    $img       = fopen($file_path, 'wb');
                    // curl写入$img
                    curl_setopt($ch, CURLOPT_FILE, $img);
                    $img_data  = curl_exec($ch);

                    fclose($img);

                    // 判断是否为需要更改文件名的文件，如果是则修改本地文件名
                    if (file_exists($file_path) && filesize($file_path) > 0
                        && pathinfo($file_path, PATHINFO_EXTENSION) == 'tmp') {
                        $t   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                        $arr = explode('/', $t);

                        $file_name = str_replace('tmp', $arr[1], $file_name);

                        if (rename($file_path, str_replace('tmp', $arr[1], $file_path))) {
                            $file_path = $wp_upload_dir['path'] . '/' . $file_name;
                        }

                        // 将webp格式的图片转换为jpeg格式
                        if ('webp' == $arr[1]) {
                            // 加载 WebP 文件
                            $im = imagecreatefromwebp($file_path);
                            // 以 100% 的质量转换成 jpeg 格式并将原webp格式文件删除
                            if (imagejpeg($im, str_replace('webp', 'jpeg', $file_path), 100)) {
                                try {
                                    unlink($file_path);
                                } catch (Exception $e) {
                                    $error_msg = sprintf('Error removing local file %s: %s', $file_path,
                                                         $e->getMessage());
                                    error_log($error_msg);
                                }

                                $file_path = str_replace('webp', 'jpeg', $file_path);
                                $file_name = basename($file_path);
                            }
                            imagedestroy($im);
                        }
                    }

                    // BOS上图片的url地址
                    $url = 'http://' . $bos_options['bucket'] . '.' . $bos_options['host']
                         . '/' . $bos_options['path'] . $wp_upload_dir['subdir']. '/' . $file_name;

                    // 替换文章内容中的src
                    $post->post_content  = str_replace($src, $url, $post->post_content);

                    // 构造附件post参数并插入媒体库(作为一个post插入到数据库)
                    $file_info   = wp_check_filetype($file_name, null);
                    $attachment = [
                        'guid'           => $wp_upload_dir['url'] . '/' . $file_name,
                        'post_type'      => 'attachement',
                        'post_mime_type' => $file_info['type'],
                        'post_title'     => preg_replace('/\.[^.]+$/', '', $file_name),
                        'post_content'   => '',
                        'post_status'    => 'inherit'
                    ];

                    // 生成并更新图片的metadata信息
                    $attach_id   = wp_insert_attachment($attachment, $wp_upload_dir['subdir']
                                                      . '/' . $file_name, 0);
                    $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
                    // 将metadata信息写入数据库，会调用上传BOS的函数
                    wp_update_attachment_metadata($attach_id, $attach_data);
                }
            }
            curl_close($ch);

            // 更新posts数据表的post_content字段
            $wpdb->update( $wpdb->posts, array('post_content' => $post->post_content), array('ID' => $post->ID));
        }
    }
}

// 在设置下面添加BOS设置菜单
add_action('admin_menu', 'add_setting_menu');
// 插件列表中的 启用/编辑/设置 链接设置
add_filter('plugin_action_links', 'plugin_action_links', 10, 2);
// 更新数据库中的meta时，将BOS上的object信息存到数据库
add_filter('wp_update_attachment_metadata', 'update_attachment_metadata', 10, 2);
// 获取BOS上的图片url
add_filter('wp_get_attachment_url', 'get_attachment_url', 99, 2);
// 增加设置responsive images srcset的钩子，解决bos上的图片在文章页无法显示的问题
add_filter('wp_calculate_image_srcset', 'calculate_image_srcset', 99, 2);
// 增加删除BOS文件的钩子
add_filter('wp_delete_file', 'del_attachments_from_bos', 110, 2);
// 钩子, 发布/草稿/预览时触发
add_action('save_post', 'bos_save_post', 10, 2);
