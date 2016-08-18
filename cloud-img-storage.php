<?php
/*
Plugin Name: CIS 云端图片存储
Plugin URI: https://github.com/yangtoude/cloud-img-storage
Description: 支持使用云存储作为图片的存储空间，目前支持BOS百度云存储。
Version:     1.0.6
Author:      yangtoudde
Author URI: https://github.com/yangtoude
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

define('CIS_DIR', plugin_basename(dirname(__FILE__)));

// CIS设置页面，将CIS设置参数存入数据库
function cis_opts_page() {
    $opts = [];
    $settings_updated = false;

    if (isset($_POST['bucket'])) {
        $opts['bucket'] = trim(stripslashes($_POST['bucket']));
    }
    if (isset($_POST['ak'])) {
        $opts['ak'] = trim(stripslashes($_POST['ak']));
    }
    if (isset($_POST['sk'])) {
        $opts['sk'] = trim(stripslashes($_POST['sk']));
    }
    if (isset($_POST['host'])) {
        $opts['host'] = trim(stripslashes($_POST['host']));
    }
    if (isset($_POST['path'])) {
        $opts['path'] = trim(stripslashes($_POST['path']));
    }
    if (isset($_POST['domain'])) {
        $opts['domain'] = trim(stripslashes($_POST['domain']));
    }

    if ($opts !== []) {
        // 写入数据库
        update_option('cis_opts', $opts);
        $settings_updated = true;
    }

    // 从数据库中取出
    $cis_opts = get_option('cis_opts', true);
    $bucket   = esc_attr($cis_opts['bucket']);
    $ak       = esc_attr($cis_opts['ak']);
    $sk       = esc_attr($cis_opts['sk']);
    $host     = esc_attr($cis_opts['host']);
    $path     = esc_attr($cis_opts['path']);
    $domain   = esc_attr($cis_opts['domain']);

	require 'options-page.php';
    ?>
<?php }

// 钩子函数: 添加CIS设置菜单
function cis_admin_menu() {
    add_options_page('CIS 云端图片存储设置', 'CIS设置', 'manage_options', __FILE__, 'cis_opts_page');
}

/**
 * 获取BosClient对象
 *
 * @return Object $bos_cli
 */
function get_bos_client() {
    $cis_opts = get_option('cis_opts', true);
    $conf     = [
        'credentials' => [
            'ak' => $cis_opts['ak'],
            'sk' => $cis_opts['sk'],
        ],
        'endpoint' => $cis_opts['host'],
    ];
    $bos_cli  = new BosClient($conf);

    return $bos_cli;
}

/**
 * 将图片上传到BOS并删除本地文件
 *
 * @param   Array   $data _wp_attachment_metadata
 * @param   Int     $post_id
 * @return  String  $ori_obj
 */
function upload_to_bos($data, $post_id) {
    // 原图上传和删除
    $wud = wp_upload_dir();
    $fp  = $wud['basedir'] . '/' . $data['file'];

    if (!file_exists($fp)) {
        return new WP_Error('exception', sprintf('File %s does not exist',
            $fp));
    }

    $cis_opts = get_option('cis_opts', true);
    $ori_obj  = $cis_opts['path'] . '/' . $data['file'];
    $bos_cli  = get_bos_client();

    if (file_exists($fp)) {
        try {
            $bos_cli->putObjectFromFile($cis_opts['bucket'], $ori_obj,
                $fp);
        } catch (Exception $e) {
            $err_msg = sprintf('Error uploading %s to BOS: %s',$fp,
                $e->getMessage());
            error_log($err_msg);
        }
    }

    if (file_exists($fp)) {
        try {
            unlink($fp);
        } catch (Exception $e) {
            $error_msg = sprintf('Error removing local file %s: %s', $fp,
                $e->getMessage());
            error_log($err_msg);
        }

    }

    // 缩略图上传和删除
    if (isset($data['sizes']) && count($data['sizes']) > 0) {
        foreach ($data['sizes'] as $key => $thd) {
            $thp = $wud['basedir'] . '/' . substr($data['file'], 0, 8)
                . $thd['file'];
            $th_obj = $cis_opts['path'] . '/' . substr($data['file'], 0, 8)
                . $thd['file'];

            if (file_exists($thp)) {
                try {
                    $bos_cli->putObjectFromFile($cis_opts['bucket'],
                        $th_obj, $thp);
                } catch (Exception $e) {
                    $err_msg = sprintf('Error uploading %s to BOS: %s',$thp,
                        $e->getMessage());
                    error_log($err_msg);
                }
            }

            if (file_exists($thp)) {
                try {
                    unlink($thp);
                } catch (Exception $e) {
                    $err_msg = sprintf('Error removing local file %s: %s',
                        $thp, $e->getMessage());
                    error_log($err_msg);
                }
            }
        }
    }

    return $ori_obj;
}

// 钩子函数: 调用上传函数并将上传的原图在bucket下的路径信息保存到数据库
function cis_update_metada($data, $post_id) {
    $ori_obj = upload_to_bos($data, $post_id);
    // 将原始图片在BOS bucket下的路径信息(object信息)添加到数据库，可以供其它插件使用
    add_post_meta($post_id, 'cis_info', $ori_obj);

    return $data;
}

/**
 * 钩子函数: 获取附件的url
 *
 * @param  String $url 本地图片url
 * @return String $url BOS图片url
 */
function cis_get_url($url, $post_id) {
    $cis_opts = get_option('cis_opts', true);
    $arr      = parse_url($url);
    $fn       = basename($url);
    $fp       = $_SERVER['DOCUMENT_ROOT'] . $arr['path'];

    if (!file_exists($fp)) {
        $arr2 = explode('/', $arr['path']);
        $n    = count($arr2);
        $obj  = $cis_opts['path'] . '/'. $arr2[$n-3] . '/' .$arr2[$n-2]
            . '/' . $fn;
        $url  = get_object_url($obj);
    }

    return $url;
}

/**
 * 钩子函数: 对responsive images srcset重新设置，wp原来的函数是从本地获取的url
 *
 * @param  Array $sources
 * @return Array $sources
 */
function cis_cal_srcset($sources) {
    $cis_opts   = get_option('cis_opts', true);

    foreach ($sources as $key => &$value) {
        $fn  = basename($value['url']);
        $arr = parse_url($value['url']);
        $fp  = $_SERVER['DOCUMENT_ROOT'] . $arr['path'];

        if (!file_exists($fp)) {
            $arr2 = explode('/', $arr['path']);
            $n    = count($arr2);
            $obj  = $cis_opts['path'] . '/' . $arr2[$n-3] . '/'
                . '/' . $arr2[$n-2] . '/' . $fn;

            $value['url'] = get_object_url($obj);
        }
    }

    return $sources;
}

/**
 * 钩子函数: 删除BOS上的附件
 *
 * @param  String $file 附件的本地路径
 * @return String $file 附件的本地路径
 */
function cis_del_from_bos($file) {
    $arr = explode('/', $file);
    $n = count($arr);
    $cis_opts = get_option('cis_opts', true);
    $obj      = $cis_opts['path'] . '/' . $arr[$n-3] . '/' . $arr[$n-2] . '/'
        . $arr[$n-1];
    $bos_cli  = get_bos_client();
    $url      = get_object_url($obj);

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
        // error_log(sprintf('Exception: file %s does not exist', $obj));
        return new WP_Error('exception', sprintf('File %s does not exist',
            $obj));
    }

    // 删除时会将原图和缩略图都删除
    try {
        $bos_cli->deleteObject($cis_opts['bucket'], $obj);
    } catch (Exception $e) {
        $err_msg = sprintf('Error removing files %s from BOS: %s', $obj,
            $e->getMessage());
        error_log($err_msg);
    }

    return $file;
}

/**
 * 钩子函数: 增加设置链接
 *
 * @param  Array  $links
 * @param  String $file
 * @return Array  $links
 */
function cis_action_links($links, $file) {
    if ($file == plugin_basename(dirname(__FILE__) . '/cloud-img-storage.php')) {
        $links[] = '<a href="options-general.php?page=' . CIS_DIR
            . '/cloud-img-storage.php">' . __('Settings') . '</a>';
    }
    return $links;
}

/**
 * 获取BOS上object的url
 * @param string $object
 * @retrun string $url
 */
function get_object_url($object) {
    $cis_opts = get_option('cis_opts', true);
    $bos_cli  = get_bos_client();
    $signOptions = [
        SignOptions::TIMESTAMP=>new \DateTime(),
        SignOptions::EXPIRATION_IN_SECONDS=>300,
    ];

    $url = $bos_cli->generatePreSignedUrl($cis_opts['bucket'], $object,
        [BosOptions::SIGN_OPTIONS => $signOptions]);
    $arr = explode('?', $url);

    return $arr[0];
}

/**
 * 钩子函数：将post_content中BOS外的img上传至BOS并替换url
 *
 * @param Int     $post_id
 * @param Object  $post
 *
 */
function cis_save_post($post_id, $post) {
    // wordpress 全局变量 wpdb类
    global $wpdb;

    // 只有在点击发布/更新时才执行以下动作
    if($post->post_status == 'publish') {
        // 匹配<img>、src，存入$matches数组,
        $p   = '/<img.*[\s]src=[\"|\'](.*)[\"|\'].*>/iU';
        $num = preg_match_all($p, $post->post_content, $matches);

        if ($num) {
            // BOS参数(数组)，用来构造url
            $cis_opts = get_option('cis_opts', true);
            // 本地上传路径信息(数组)，用来构造url
            $wud = wp_upload_dir();

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
                if (isset($src) && strpos($src, $cis_opts['host']) === false
                   && strpos($src, $cis_opts['domain']) === false) {
					// 如果图片域名不是百度云

                    // 检查src中的url有无扩展名，没有则重新给定文件名
                    $fi = wp_check_filetype(basename($src), null);
                    if ($fi['ext'] == false) {
						// 注意：webp格式的图片也会被作为无扩展名文件处理
                        date_default_timezone_set('PRC');
                        $fn = date('YmdHis-').dechex(mt_rand(100000, 999999)).'.tmp';
                    } else {
						// 重新给文件名防止与本地文件名冲突
                        $fn = dechex(mt_rand(100000, 999999)) . '-' . basename($src);
                    }

                    // 抓取图片, 将图片写入本地文件
                    curl_setopt($ch, CURLOPT_URL, $src);
                    $fp  = $wud['path'] . '/' . $fn;
                    $img = fopen($fp, 'wb');
                    // curl写入$img
                    curl_setopt($ch, CURLOPT_FILE, $img);
                    $img_data  = curl_exec($ch);
                    fclose($img);

                    if (file_exists($fp) && filesize($fp) > 0) {
						// 将扩展名为tmp和webp的图片转换为jpeg文件并重命名
                        $t   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                        $arr = explode('/', $t);
						// 对url地址中没有扩展名或扩展名为webp的图片进行处理
						if (pathinfo($fp, PATHINFO_EXTENSION) == 'tmp') {
							$fp = handle_ext($fp, $arr[1], $wud['path'], $fn, 'tmp');
						} elseif (pathinfo($fp, PATHINFO_EXTENSION) == 'webp') {
							$fp = handle_ext($fp, $arr[1], $wud['path'], $fn, 'webp');
						}
                    }

					// BOS上图片的url地址(绑定的CDN加速域名地址)
					if (isset($cis_opts['domain']) && $cis_opts['domain'] != '') {
						$url = $cis_opts['domain'] . '/' . $cis_opts['path'] . $wud['subdir']
							. '/' . basename($fp);
					} else {
						$url = $cis_opts['host'] . '/' . $cis_opts['bucket'] . '/' . $cis_opts['path']
							. $wud['subdir']. '/' . basename($fp);
					}

                    // 替换文章内容中的src
                    $post->post_content = str_replace($src, $url, $post->post_content);
					// 构造附件post参数并插入媒体库(作为一个post插入到数据库)
					$attachment  = get_attachment_post(basename($fp), $wud['url'] . '/' . basename($fp));
                    // 生成并更新图片的metadata信息
                    $attach_id   = wp_insert_attachment($attachment, $wud['subdir'] . '/' . basename($fp), 0);
                    $attach_data = wp_generate_attachment_metadata($attach_id, $fp);
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

/**
 * 处理没有扩展名的图片:转换格式或更改扩展名
 *
 * @param string $file 图片本地绝对路径
 * @param string $type 图片mimetype
 * @param string $file_dir 图片在本地的文件夹
 * @param string $file_name 图片名称
 * @param string $ext 图片扩展名
 * @return string 处理后的本地图片绝对路径
 */
function handle_ext($file, $type, $file_dir, $file_name, $ext) {
	switch ($ext) {
		case 'tmp':
			if (rename($file, str_replace('tmp', $type, $file))) {
				if ('webp' == $type) {
					// 将webp格式的图片转换为jpeg格式
					return image_convert('webp', 'jpeg', $file_dir . '/' . str_replace('tmp', $type, $file_name));
				}
				return $file_dir . '/' . str_replace('tmp', $type, $file_name);
			}
		case 'webp':
			if ('webp' == $type) {
				// 将webp格式的图片转换为jpeg格式
				return image_convert('webp', 'jpeg', $file);
			} else {
				if (rename($file, str_replace('webp', $type, $file))) {
					return $file_dir . '/' . str_replace('webp', $type, $file_name);
				}
			}
		default:
			return $file;
	}
}

/**
 * 图片格式转换，暂只能从webp转换为jpeg
 *
 * @param string $from
 * @param string $to
 * @param string $image 图片本地绝对路径
 * @return string 转换后的图片绝对路径
 */
function image_convert($from='webp', $to='jpeg', $image) {
	// 加载 WebP 文件
	$im = imagecreatefromwebp($image);
	// 以 100% 的质量转换成 jpeg 格式并将原webp格式文件删除
	if (imagejpeg($im, str_replace('webp', 'jpeg', $image), 100)) {
		try {
			unlink($image);
		} catch (Exception $e) {
			$error_msg = sprintf('Error removing local file %s: %s', $image,
				$e->getMessage());
			error_log($error_msg);
		}
	}
	imagedestroy($im);

	return str_replace('webp', 'jpeg', $image);
}

/**
 * 构造图片post参数
 *
 * @param string $filename
 * @param string $url
 * @return array 图片post参数数组
 */
function get_attachment_post($filename, $url) {
	$file_info  = wp_check_filetype($filename, null);
	return [
		'guid'           => $url,
		'post_type'      => 'attachement',
		'post_mime_type' => $file_info['type'],
		'post_title'     => preg_replace('/\.[^.]+$/', '', $filename),
		'post_content'   => '',
		'post_status'    => 'inherit'
	];
}

// 在设置下面添加BOS设置菜单
add_action('admin_menu', 'cis_admin_menu');
// 插件列表中的 启用/编辑/设置 链接设置
add_filter('plugin_action_links', 'cis_action_links', 10, 2);
// 更新数据库中的meta时，将BOS上的object信息存到数据库
add_filter('wp_update_attachment_metadata', 'cis_update_metada', 10, 2);
// 获取BOS上的图片url
add_filter('wp_get_attachment_url', 'cis_get_url', 99, 2);
// 增加设置responsive images srcset的钩子，解决bos上的图片在文章页无法显示的问题
add_filter('wp_calculate_image_srcset', 'cis_cal_srcset', 99, 2);
// 增加删除BOS文件的钩子
add_filter('wp_delete_file', 'cis_del_from_bos', 110, 2);
// 钩子, 发布/草稿/预览时触发
add_action('save_post', 'cis_save_post', 10, 2);
