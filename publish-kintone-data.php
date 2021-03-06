<?php
/**
 * Plugin Name: Publish kintone data
 * Plugin URI:
 * Description: The data of kintone can be reflected on WordPress.
 * Version:     1.9.4
 * Author:      Takashi Hosoya
 * Author URI:  http://ht79.info/
 * License:     GPLv2
 * Text Domain: kintone-to-wp
 * Domain Path: /languages
 */

/**
 * Copyright (c) 2017 Takashi Hosoya ( http://ht79.info/ )
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2 or, at
 * your discretion, any later version, as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */


define( 'KINTONE_TO_WP_URL', plugins_url( '', __FILE__ ) );
define( 'KINTONE_TO_WP_PATH', dirname( __FILE__ ) );


$kintone_to_wp = new KintoneToWP();
$kintone_to_wp->register();


class KintoneToWP {

	private $version = '';
	private $langs   = '';
	private $nonce   = 'kintone_to_wp_';


	function __construct() {
		$data          = get_file_data(
			__FILE__,
			array( 'ver' => 'Version', 'langs' => 'Domain Path' )
		);
		$this->version = $data['ver'];
		$this->langs   = $data['langs'];

	}

	public function register() {
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ), 1 );
	}

	public function plugins_loaded() {
		load_plugin_textdomain(
			'kintone-to-wp',
			false,
			dirname( plugin_basename( __FILE__ ) ) . $this->langs
		);

		// Create Admin Menu.
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );

		// Webhook.
		add_action( 'wp_ajax_kintone_to_wp_start', array( $this, 'kintone_to_wp_start' ) );
		add_action( 'wp_ajax_nopriv_kintone_to_wp_start', array( $this, 'kintone_to_wp_start' ) );

		add_action( 'save_post', array( $this, 'update_post_kintone_data' ), 10, 3 );
	}

	public function admin_menu() {
		add_submenu_page( 'options-general.php', 'Publish kintone data', 'Publish kintone data', 'manage_options', 'publish-kintone-data-setting', array(
			$this,
			'kintone_to_wp_setting'
		) );
	}


	/**************************************
	 *
	 *  Admin
	 *
	 ***************************************/
	public function kintone_to_wp_setting() {

		if ( ! empty( $_POST ) && check_admin_referer( $this->nonce ) ) {

			if ( isset( $_POST['get_kintone_fields'] ) ) {

				$kintone_basci_information = array();

				$kintone_basci_information['domain']    = sanitize_text_field( trim( $_POST['kintone_to_wp_kintone_url'] ) );
				$kintone_basci_information['app_id']    = sanitize_text_field( trim( $_POST['kintone_to_wp_target_appid'] ) );
				$kintone_basci_information['url']       = 'https://' . $kintone_basci_information['domain'] . '/k/v1/form.json?app=' . $kintone_basci_information['app_id'];
				$kintone_basci_information['token']     = sanitize_text_field( trim( $_POST['kintone_to_wp_kintone_api_token'] ) );
				$kintone_basci_information['post_type'] = sanitize_text_field( trim( $_POST['kintone_to_wp_reflect_post_type'] ) );

				$error_flg = false;
				if ( ! $kintone_basci_information['domain'] ) {
					echo '<div class="error notice is-dismissible"><p><strong>Domain is required</strong></p></div>';
					$error_flg = true;
				} else if ( ! $kintone_basci_information['post_type'] ) {
					echo '<div class="error notice is-dismissible"><p><strong>Post type is required</strong></p></div>';
					$error_flg = true;
				}

				if ( ! $error_flg ) {
					$kintone_form_data = $this->kintone_api( $kintone_basci_information['url'], $kintone_basci_information['token'] );
					if ( ! is_wp_error( $kintone_form_data ) ) {
						$this->update_kintone_basci_information( $kintone_basci_information, $kintone_form_data );
					} else {
						echo '<div class="error fade"><p><strong>setting information is incorrect</strong></p></div>';
					}
				}
			} elseif ( isset( $_POST['save'] ) ) {

				$kintone_app_fields_code_for_wp = array();
				if ( isset( $_POST['kintone_to_wp_kintone_field_code_for_post_title'] ) ) {
					$kintone_app_fields_code_for_wp['kintone_to_wp_kintone_field_code_for_post_title'] = sanitize_text_field( $_POST['kintone_to_wp_kintone_field_code_for_post_title'] );
				}
				if ( isset( $_POST['kintone_to_wp_kintone_field_code_for_post_contents'] ) ) {
					$kintone_app_fields_code_for_wp['kintone_to_wp_kintone_field_code_for_post_contents'] = sanitize_text_field( $_POST['kintone_to_wp_kintone_field_code_for_post_contents'] );
				}
				if ( isset( $_POST['kintone_to_wp_kintone_field_code_for_terms'] ) && is_array( $_POST['kintone_to_wp_kintone_field_code_for_terms'] ) ) {
					$kintone_app_fields_code_for_wp['kintone_to_wp_kintone_field_code_for_terms'] = $_POST['kintone_to_wp_kintone_field_code_for_terms'];
				}
				if ( isset( $_POST['kintone_to_wp_kintone_field_code_for_featured_image'] ) ) {
					$kintone_app_fields_code_for_wp['kintone_to_wp_kintone_field_code_for_featured_image'] = sanitize_text_field( $_POST['kintone_to_wp_kintone_field_code_for_featured_image'] );
				}

				if ( isset( $_POST['kintone_to_wp_setting_custom_fields'] ) && is_array( $_POST['kintone_to_wp_setting_custom_fields'] ) ) {
					$kintone_app_fields_code_for_wp['kintone_to_wp_setting_custom_fields'] = $_POST['kintone_to_wp_setting_custom_fields'];
				}


				$this->update_kintone_app_fields_code_for_wp( $kintone_app_fields_code_for_wp );

			} elseif ( isset( $_POST['bulk_update'] ) ) {

				$this->bulk_update();

			}

		}

		$wp_n = wp_nonce_field( $this->nonce );


		$kintone_url           = get_option( 'kintone_to_wp_kintone_url' );
		$api_token             = get_option( 'kintone_to_wp_kintone_api_token' );
		$target_appid          = get_option( 'kintone_to_wp_target_appid' );
		$reflect_post_type     = get_option( 'kintone_to_wp_reflect_post_type' );
		$kintone_app_form_data = get_option( 'kintone_to_wp_kintone_app_form_data' );

		echo '<div class="wrap">';

		echo '<h2>Setting Publish kintone data</h2>';
		echo '<form method="post" action="">';
		echo $wp_n;

		echo '<table class="form-table">';
		echo '	<tr valign="top">';
		echo '		<th scope="row"><label for="add_text">kintone domain</label></th>';
		echo '		<td><input name="kintone_to_wp_kintone_url" type="text" id="kintone_to_wp_kintone_url" value="' . ( $kintone_url == "" ? "" : esc_textarea( $kintone_url ) ) . '" class="regular-text" /></td>';
		echo '	</tr>';
		echo '	<tr valign="top">';
		echo '		<th scope="row"><label for="add_text">API Token</label><br><span style="font-size:10px;">Permission: show record</span></th>';
		echo '		<td><input name="kintone_to_wp_kintone_api_token" type="text" id="kintone_to_wp_kintone_api_token" value="' . ( $api_token == "" ? "" : esc_textarea( $api_token ) ) . '" class="regular-text" /></td>';
		echo '	</tr>';
		echo '	<tr valign="top">';
		echo '		<th scope="row"><label for="add_text">Reflect kintone to post_type</label></th>';
		echo '		<td>';
		echo '			kintone APP ID:<input name="kintone_to_wp_target_appid" type="text" id="kintone_to_wp_target_appid" value="' . ( $target_appid == "" ? "" : esc_textarea( $target_appid ) ) . '" class="small-text" /> ->';
		echo '			WordPress Post Type:<select name="kintone_to_wp_reflect_post_type">';
		echo '				<option value=""></option>';
		echo '				<option ' . selected( $reflect_post_type, "post", false ) . ' value="post">post</option>';
		echo $this->get_html_post_type_form_slect_option( $reflect_post_type );
		echo '			</select>';
		echo '		</td>';
		echo '	</tr>';
		echo '	</table>';

		echo '<p class="submit"><input type="submit" name="get_kintone_fields" class="button-primary" value="Get kintone fields" /></p>';

		echo '</form>';

		$disp_data        = '';
		$old_kintone_data = get_option( 'kintone_to_wp_kintone_app_form_data' );
		if ( isset( $kintone_form_data ) ) {

			if ( is_wp_error( $kintone_form_data ) ) {
				// Error
				if ( ! empty( $old_kintone_data ) ) {
					$disp_data = $old_kintone_data;
				}
			} else {
				// Success
				$disp_data = $kintone_form_data;
			}
		} else {
			// Nothing
			if ( ! empty( $old_kintone_data ) ) {
				$disp_data = $old_kintone_data;
			}
		}

		if ( ! empty( $disp_data ) ) {


			echo '<form method="post" action="">';

			echo $wp_n;

			echo 'Please set this URL to kintone\'s WEBHOOK-><strong>' . site_url( '/wp-admin/admin-ajax.php?action=kintone_to_wp_start' ) . '</strong><br><span style="font-size:10px;">Permission: post record, update record, delete record</span>';
			echo '<br/>';
			echo '<br/>';
			echo '	<table>';
			echo '	<tr valign="top">';
			echo '		<th scope="row"><label for="add_text">Select Post title</label></th>';
			echo '		<td>';
			echo '			<select name="kintone_to_wp_kintone_field_code_for_post_title">';
			echo $this->get_html_post_title_form_select_option( $disp_data );
			echo '			</select>';
			echo '		</td>';
			echo '	</tr>';
			echo '	<tr valign="top">';
			echo '		<th scope="row"><label for="add_text">Select Post contents</label></th>';
			echo '		<td>';
			echo '			<select name="kintone_to_wp_kintone_field_code_for_post_contents">';
			echo $this->get_html_post_contents_form_select_option( $disp_data );
			echo '			</select>';
			echo '		</td>';
			echo '	</tr>';
			echo '	<tr valign="top">';
			echo '		<th scope="row"><label for="add_text">Select Term</label></th>';
			echo '		<td>';
			echo $this->get_html_taxonomy_form_select( $disp_data, $reflect_post_type );
			echo '		</td>';
			echo '	</tr>';
			echo '	<tr valign="top">';
			echo '		<th scope="row"><label for="add_text">Select Featured image</label></th>';
			echo '		<td>';
			echo '			<select name="kintone_to_wp_kintone_field_code_for_featured_image">';
			echo $this->get_html_featured_image_form_select_option( $disp_data );
			echo '			</select>';
			echo '		</td>';
			echo '	</tr>';
			echo '	<tr valign="top">';
			echo '		<th scope="row"><label for="add_text">Setting Custom Field</label></th>';
			echo '		<td>';
			echo $this->get_html_custom_field_form_input( $disp_data );
			echo '		</td>';
			echo '	</tr>';
			echo '</table>';

			echo '<p class="submit"><input type="submit" name="save" class="button-primary" value="save" /></p>';
			echo '<p class="submit"><input type="submit" name="bulk_update" class="button-primary" value="Bulk Update" /></p>';
			echo '</form>';

		}

		echo '</div>';

	}

	/**
	 * 管理画面から記事を保存した時に実行する.
	 *
	 * @param int     $post_id .
	 * @param WP_Post $post .
	 * @param boolean $update .
	 */
	public function update_post_kintone_data( $post_id, $post, $update ) {

		// Autosave, do nothing.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		// AJAX? Not used here.
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( 'trash' === get_post_status( $post_id ) ) {
			return;
		}

		if ( ! $update ) {
			return;
		}

		remove_action( 'save_post', array( $this, 'update_post_kintone_data' ), 10 );

		$reflect_post_type = get_option( 'kintone_to_wp_reflect_post_type' );
		if ( get_post_type( $post_id ) !== $reflect_post_type ) {
			return;
		}

		$kintone_id = $this->get_kintone_id_without_appcode( get_post_meta( $post_id, 'kintone_record_id', true ) );

		if ( empty( $kintone_id ) ) {
			return;
		}

		// WordPress側で更新処理をされた場合、再度 kintoneからデータを取得して反映する（kintoneの情報が常に正しい）.
		$url                                = 'https://' . get_option( 'kintone_to_wp_kintone_url' ) . '/k/v1/record.json?app=' . get_option( 'kintone_to_wp_target_appid' ) . '&id=' . $kintone_id;
		$retun_data                         = $this->kintone_api( $url, get_option( 'kintone_to_wp_kintone_api_token' ) );
		$retun_data['kintone_to_wp_status'] = 'normal';
		$this->sync( $retun_data );

//		add_action( 'save_post', array( $this, 'update_post_kintone_data' ), 10, 3 );

	}

	/**********************************************************
	 * 一括アップデート処理
	 * kintoneから該当データを全件取得してWPへ反映する
	 *
	 * @param
	 *
	 * @return
	 **********************************************************/
	private function bulk_update() {

		$kintone_data['records'] = array();

		$offset             = 0;
		$reacquisition_flag = true;

		while ( $reacquisition_flag ) {

			$url        = 'https://' . get_option( 'kintone_to_wp_kintone_url' ) . '/k/v1/records.json?app=' . get_option( 'kintone_to_wp_target_appid' ) . '&query=order by $id asc limit 500 offset ' . $offset;
			$retun_data = $this->kintone_api( $url, get_option( 'kintone_to_wp_kintone_api_token' ) );

			$kintone_data['records'] = array_merge( $kintone_data['records'], $retun_data['records'] );

			if ( count( $kintone_data['records'] ) < 500 ) {
				$reacquisition_flag = false;
			} else {
				$offset = $offset + 500;
			}
		}

		// @todo 一括更新は kintone でデータを削除したものは、WordPress側では削除されない。(Webhookを最初から使用しているとWebhookで削除はされるけど)
		foreach ( $kintone_data['records'] as $key => $value ) {

			$data                         = array();
			$data['record']               = $value;
			$data['kintone_to_wp_status'] = 'normal';
			$data                         = apply_filters( 'kintone_to_wp_kintone_data', $data );
			$this->sync( $data );
		}

		echo '<div class="updated fade"><p><strong>Updated</strong></p></div>';

	}

	private function update_kintone_app_fields_code_for_wp( $kintone_app_fields_code_for_wp ) {

		if ( empty( $kintone_app_fields_code_for_wp['kintone_to_wp_kintone_field_code_for_post_title'] ) ) {
			delete_option( 'kintone_to_wp_kintone_field_code_for_post_title' );
		} else {
			update_option( 'kintone_to_wp_kintone_field_code_for_post_title', $kintone_app_fields_code_for_wp['kintone_to_wp_kintone_field_code_for_post_title'] );
		}

		if ( empty( $kintone_app_fields_code_for_wp['kintone_to_wp_kintone_field_code_for_post_contents'] ) ) {
			delete_option( 'kintone_to_wp_kintone_field_code_for_post_contents' );
		} else {
			update_option( 'kintone_to_wp_kintone_field_code_for_post_contents', $kintone_app_fields_code_for_wp['kintone_to_wp_kintone_field_code_for_post_contents'] );
		}

		if ( empty( $kintone_app_fields_code_for_wp['kintone_to_wp_kintone_field_code_for_terms'] ) && empty( $kintone_app_fields_code_for_wp['kintone_to_wp_kintone_field_code_for_terms'] ) ) {
			delete_option( 'kintone_to_wp_kintone_field_code_for_terms' );
		} else {
			update_option( 'kintone_to_wp_kintone_field_code_for_terms', $kintone_app_fields_code_for_wp['kintone_to_wp_kintone_field_code_for_terms'] );
		}

		if ( empty( $kintone_app_fields_code_for_wp['kintone_to_wp_setting_custom_fields'] ) ) {
			delete_option( 'kintone_to_wp_setting_custom_fields' );
		} else {
			update_option( 'kintone_to_wp_setting_custom_fields', $kintone_app_fields_code_for_wp['kintone_to_wp_setting_custom_fields'] );
		}

		if ( empty( $kintone_app_fields_code_for_wp['kintone_to_wp_kintone_field_code_for_featured_image'] ) ) {
			delete_option( 'kintone_to_wp_kintone_field_code_for_featured_image' );
		} else {
			update_option( 'kintone_to_wp_kintone_field_code_for_featured_image', $kintone_app_fields_code_for_wp['kintone_to_wp_kintone_field_code_for_featured_image'] );
		}

	}


	private function update_kintone_basci_information( $kintone_basci_information, $kintone_form_data ) {


		if ( empty( $kintone_basci_information['url'] ) ) {
			delete_option( 'kintone_to_wp_kintone_url' );
		} else {
			update_option( 'kintone_to_wp_kintone_url', $kintone_basci_information['domain'] );
		}

		if ( empty( $kintone_basci_information['token'] ) ) {
			delete_option( 'kintone_to_wp_kintone_api_token' );
		} else {
			update_option( 'kintone_to_wp_kintone_api_token', $kintone_basci_information['token'] );
		}

		if ( empty( $kintone_basci_information['app_id'] ) ) {
			delete_option( 'kintone_to_wp_target_appid' );
		} else {
			update_option( 'kintone_to_wp_target_appid', $kintone_basci_information['app_id'] );
		}

		if ( empty( $kintone_basci_information['post_type'] ) ) {
			delete_option( 'kintone_to_wp_reflect_post_type' );
		} else {
			update_option( 'kintone_to_wp_reflect_post_type', $kintone_basci_information['post_type'] );
		}


		if ( ! is_wp_error( $kintone_form_data ) ) {

			if ( empty( $kintone_form_data ) ) {
				delete_option( 'kintone_to_wp_kintone_app_form_data' );
			} else {
				update_option( 'kintone_to_wp_kintone_app_form_data', $kintone_form_data );
			}

			echo '<div class="updated notice is-dismissible"><p><strong>Success</strong></p></div>';
		}

	}

	private function get_html_custom_field_form_input( $kintone_app_form_data ) {

		$html_setting_custom_fields = "";
		$html_setting_custom_fields .= '<table>';
		$setting_custom_fields      = get_option( 'kintone_to_wp_setting_custom_fields' );

		foreach ( $kintone_app_form_data['properties'] as $kintone_form_value ) {
			$input_val = '';

			if ( is_array( $setting_custom_fields ) ) {
				foreach ( $setting_custom_fields as $key => $custom_form_value ) {
					if ( array_key_exists( 'code', $kintone_form_value ) ) {
						if ( $kintone_form_value['code'] == $key ) {
							$input_val = $custom_form_value;
						}
					}
				}
			}

			if ( array_key_exists( 'code', $kintone_form_value ) ) {
				$html_setting_custom_fields .= '<tr>';
				if ( $kintone_form_value['type'] == 'RECORD_NUMBER' ) {

					$html_setting_custom_fields .= '<th>' . esc_html( $kintone_form_value['label'] ) . '(' . esc_html( $kintone_form_value['code'] ) . ')' . '</th><td><input readonly="readonly" type="text" name="kintone_to_wp_setting_custom_fields[' . esc_attr( $kintone_form_value['code'] ) . ']" value="kintone_record_id" class="regular-text" /></td>';

				} else {

					$label = '';
					if ( isset( $kintone_form_value['label'] ) ) {
						$label = $kintone_form_value['label'];
					}

					$html_setting_custom_fields .= '<th>' . esc_html( $label ) . '(' . esc_html( $kintone_form_value['code'] ) . ')' . '</th><td><input type="text" name="kintone_to_wp_setting_custom_fields[' . esc_attr( $kintone_form_value['code'] ) . ']" value="' . esc_attr( $input_val ) . '" class="regular-text" /></td>';

				}
				$html_setting_custom_fields .= '</tr>';
			}

		}
		$html_setting_custom_fields .= '</table>';

		return $html_setting_custom_fields;

	}

	private function get_html_post_type_form_slect_option( $reflect_post_type ) {

		$args       = array(
			'public'   => true,
			'_builtin' => false
		);
		$post_types = get_post_types( $args, 'names' );

		$html_option = '';
		foreach ( $post_types as $value ) {
			$html_option .= '<option ' . selected( $reflect_post_type, $value, false ) . ' value="' . esc_attr( $value ) . '">' . esc_html( $value ) . '</option>';
		}

		return $html_option;

	}

	private function get_html_featured_image_form_select_option( $kintone_app_form_data ) {

		$html_select_featured_image            = '';
		$kintone_field_code_for_featured_image = get_option( 'kintone_to_wp_kintone_field_code_for_featured_image' );

		$html_select_featured_image .= '<option ' . selected( '', $kintone_field_code_for_featured_image, false ) . ' value=""></option>';

		foreach ( $kintone_app_form_data['properties'] as $kintone_form_value ) {

			if ( array_key_exists( 'code', $kintone_form_value ) ) {

				$label = '';
				if ( isset( $kintone_form_value['label'] ) ) {
					$label = $kintone_form_value['label'];
				}

				$html_select_featured_image .= '<option ' . selected( $kintone_form_value['code'], $kintone_field_code_for_featured_image, false ) . ' value="' . esc_attr( $kintone_form_value['code'] ) . '">' . esc_html( $label ) . '(' . esc_html( $kintone_form_value['code'] ) . ')' . '</option>';
			}

		}

		return $html_select_featured_image;
	}

	private function get_html_post_title_form_select_option( $kintone_app_form_data ) {

		$html_select_post_title            = '';
		$kintone_field_code_for_post_title = get_option( 'kintone_to_wp_kintone_field_code_for_post_title' );

		$html_select_post_title .= '<option ' . selected( '', $kintone_field_code_for_post_title, false ) . ' value=""></option>';

		foreach ( $kintone_app_form_data['properties'] as $kintone_form_value ) {

			if ( array_key_exists( 'code', $kintone_form_value ) ) {

				$label = '';
				if ( isset( $kintone_form_value['label'] ) ) {
					$label = $kintone_form_value['label'];
				}

				$html_select_post_title .= '<option ' . selected( $kintone_form_value['code'], $kintone_field_code_for_post_title, false ) . ' value="' . esc_attr( $kintone_form_value['code'] ) . '">' . esc_html( $label ) . '(' . esc_html( $kintone_form_value['code'] ) . ')' . '</option>';
			}

		}

		return $html_select_post_title;

	}

	private function get_html_post_contents_form_select_option( $kintone_app_form_data ) {
		$html_select_post_contents            = '';
		$kintone_field_code_for_post_contents = get_option( 'kintone_to_wp_kintone_field_code_for_post_contents' );

		$html_select_post_contents .= '<option ' . selected( '', $kintone_field_code_for_post_contents, false ) . ' value=""></option>';

		foreach ( $kintone_app_form_data['properties'] as $kintone_form_value ) {

			if ( array_key_exists( 'code', $kintone_form_value ) ) {

				$label = '';
				if ( isset( $kintone_form_value['label'] ) ) {
					$label = $kintone_form_value['label'];
				}

				$html_select_post_contents .= '<option ' . selected( $kintone_form_value['code'], $kintone_field_code_for_post_contents, false ) . ' value="' . esc_attr( $kintone_form_value['code'] ) . '">' . esc_html( $label ) . '(' . esc_html( $kintone_form_value['code'] ) . ')' . '</option>';
			}

		}

		return $html_select_post_contents;
	}


	private function get_html_taxonomy_form_select( $kintone_app_form_data, $reflect_post_type ) {

		// Category
		$terms = get_taxonomies(
			array(),
			'objects'
		);

		$html_select_term = '';

		foreach ( $terms as $key => $term ) {

			if ( in_array( $reflect_post_type, $term->object_type ) ) {

				$html_select_term             .= $term->label . '-><select name="kintone_to_wp_kintone_field_code_for_terms[' . $term->name . ']">';
				$kintone_field_code_for_terms = get_option( 'kintone_to_wp_kintone_field_code_for_terms' );

				$html_select_term .= '<option value=""></option>';

				foreach ( $kintone_app_form_data['properties'] as $kintone_form_value ) {

					$input_val = '';

					if ( is_array( $kintone_field_code_for_terms ) ) {
						foreach ( $kintone_field_code_for_terms as $key => $kintone_field_code_for_term ) {
							if ( $term->name == $key ) {
								$input_val = $kintone_field_code_for_term;
							}
						}
					}

					if ( array_key_exists( 'code', $kintone_form_value ) ) {
						$html_select_term .= '<option ' . selected( $kintone_form_value['code'], $input_val, false ) . ' value="' . esc_attr( $kintone_form_value['code'] ) . '">' . esc_html( $kintone_form_value['label'] ) . '(' . esc_html( $kintone_form_value['code'] ) . ')' . '</option>';
					}
				}

				$html_select_term .= '</select><br/>';
			}
		}

		return $html_select_term;

	}

	/**************************************
	 *
	 * Web Hookからの処理
	 *
	 *
	 ***************************************/
	public function kintone_to_wp_start() {

		$update_kintone_data_json = file_get_contents( "php://input" );
		$update_kintone_data      = json_decode( $update_kintone_data_json, true );

		if ( $update_kintone_data['type'] == 'DELETE_RECORD' ) {

			$kintone_id = $update_kintone_data['recordId'];

		} else {

			$kintone_id = $update_kintone_data['record']['$id']['value'];

		}

		$kintone_data = $this->get_update_kintone_data_by_id( $kintone_id );

		if ( is_wp_error( $kintone_data ) ) {

			if ( $update_kintone_data['type'] == 'DELETE_RECORD' && $kintone_data->get_error_code() == 'GAIA_RE01' ) {
				$this->delete( $update_kintone_data['recordId'], $update_kintone_data );
			}
		} else {

			$kintone_data['kintone_to_wp_status'] = 'normal';
			$kintone_data                         = apply_filters( 'kintone_to_wp_kintone_data', $kintone_data );
			remove_action( 'save_post', array( $this, 'update_post_kintone_data' ), 10 );
			$this->sync( $kintone_data );
//			add_action( 'save_post', array( $this, 'update_post_kintone_data' ),10 );
		}

	}

	private function delete( $record_id, $update_kintone_data ) {

		$args      = array(
			'post_type'   => get_option( 'kintone_to_wp_reflect_post_type' ),
			'meta_key'    => 'kintone_record_id',
			'meta_value'  => $record_id,
			'post_status' => array( 'publish', 'pending', 'draft', 'future', 'private' )
		);
		$the_query = new WP_Query( $args );
		if ( $the_query->have_posts() ) {
			wp_delete_post( $the_query->post->ID );
		} else {
			// 削除できなかったらアプリコード付きで削除する
			$url              = 'https://' . get_option( 'kintone_to_wp_kintone_url' ) . '/k/v1/app.json?id=' . $update_kintone_data['app']['id'];
			$kintone_app_data = $this->kintone_api( $url, get_option( 'kintone_to_wp_kintone_api_token' ) );

			if ( $kintone_app_data['code'] ) {
				$args      = array(
					'post_type'   => get_option( 'kintone_to_wp_reflect_post_type' ),
					'meta_key'    => 'kintone_record_id',
					'meta_value'  => $kintone_app_data['code'] . '-' . $record_id,
					'post_status' => array( 'publish', 'pending', 'draft', 'future', 'private' )
				);
				$the_query = new WP_Query( $args );
				if ( $the_query->have_posts() ) {
					wp_delete_post( $the_query->post->ID );
				}
			}
		}
	}

	private function get_kintone_id_without_appcode( $id ) {

		if ( empty( $id ) ) {
			return $id;
		}

		$id = explode( '-', $id );
		if ( 1 !== count( $id ) ) {
			$id = $id[1];
		} else {
			$id = $id[0];
		}

		return $id;

	}

	private function get_kintone_record_number( $kintoen_data ) {

		$kintone_record_number = '';
		foreach ( $kintoen_data['record'] as $kintone_field_code => $kintone_field_info ) {
			if ( 'RECORD_NUMBER' === $kintone_field_info['type'] ) {
				$kintone_record_number = $kintone_field_info['value'];
			}
		}

		return $kintone_record_number;

	}

	private function sync( $kintoen_data ) {

		remove_filter( 'content_save_pre', 'wp_filter_post_kses' );

		$status  = '';
		$post_id = '';

		if ( ! empty( $kintoen_data ) ) {
			$kintone_record_number = $this->get_kintone_record_number( $kintoen_data );

			$args      = array(
				'post_type'   => get_option( 'kintone_to_wp_reflect_post_type' ),
				'meta_key'    => 'kintone_record_id',
				'meta_value'  => $kintone_record_number,
				'post_status' => array( 'publish', 'pending', 'draft', 'future', 'private' ),
			);
			$the_query = new WP_Query( $args );
			if ( $the_query->have_posts() ) {

				// WordPressにデータが存在するので、UPDATE or DELETE の処理をする.
				if ( $kintoen_data['kintone_to_wp_status'] == 'normal' ) {
					$this->update_kintone_data_to_wp_post( $the_query->post->ID, $kintoen_data );
					$this->update_kintone_data_to_wp_post_meta( $the_query->post->ID, $kintoen_data );
					$this->update_kintone_data_to_wp_terms( $the_query->post->ID, $kintoen_data );
					$this->update_kintone_data_to_wp_post_featured_image( $the_query->post->ID, $kintoen_data );

					$status  = 'update';
					$post_id = $the_query->post->ID;

				} elseif ( $kintoen_data['kintone_to_wp_status'] == 'delete' ) {
					wp_delete_post( $the_query->post->ID );

					$status  = 'delete';
					$post_id = $the_query->post->ID;

				}
			} else {

				// WordPressにデータが存在しないので、INSERT 処理をする.
				if ( $kintoen_data['kintone_to_wp_status'] == 'normal' ) {
					$post_id = $this->insert_kintone_data_to_wp_post( $kintoen_data );
					$this->update_kintone_data_to_wp_post_meta( $post_id, $kintoen_data );
					$this->update_kintone_data_to_wp_terms( $post_id, $kintoen_data );
					$this->update_kintone_data_to_wp_post_featured_image( $post_id, $kintoen_data );

					$status = 'insert';
				}
			}

			do_action( 'after_insert_or_update_to_post', $post_id, $kintoen_data, $status );
		}

		add_filter( 'content_save_pre', 'wp_filter_post_kses' );

	}

	private function update_kintone_data_to_wp_post_featured_image( $post_id, $kintone_data ) {

		$kintone_to_wp_kintone_field_code_for_featured_image = get_option( 'kintone_to_wp_kintone_field_code_for_featured_image' );
		if ( $kintone_to_wp_kintone_field_code_for_featured_image ) {
			if ( array_key_exists( $kintone_to_wp_kintone_field_code_for_featured_image, $kintone_data['record'] ) ) {
				$setting_kintone_field_code_for_featured_image = get_option( 'kintone_to_wp_kintone_field_code_for_featured_image' );

				if ( 'FILE' === $kintone_data['record'][ $setting_kintone_field_code_for_featured_image ]['type'] ) {
					if ( ! empty( $kintone_data['record'][ $setting_kintone_field_code_for_featured_image ]['value'] ) ) {
						$this->update_kintone_temp_file_to_meta( $post_id, $kintone_data['record'][ $setting_kintone_field_code_for_featured_image ]['value'][0], '', true );
					} else {
						$this->delete_kintone_temp_file( $post_id, '', true );
					}
				}
			}
		}
	}

	private function insert_kintone_data_to_wp_post( $kintoen_data ) {

		$field_code_for_post_title    = get_option( 'kintone_to_wp_kintone_field_code_for_post_title' );
		$field_code_for_post_contents = get_option( 'kintone_to_wp_kintone_field_code_for_post_contents' );

		$post_status = 'draft';
		$post_status = apply_filters( 'import_kintone_insert_post_status', $post_status );

		$post_title = '';
		if ( isset( $kintoen_data['record'][ $field_code_for_post_title ] ) && $kintoen_data['record'][ $field_code_for_post_title ]['value'] ) {
			$post_title = $kintoen_data['record'][ $field_code_for_post_title ]['value'];
		}

		$post_content = '';
		if ( isset( $kintoen_data['record'][ $field_code_for_post_contents ] ) && $kintoen_data['record'][ $field_code_for_post_contents ]['value'] ) {
			$post_content = $kintoen_data['record'][ $field_code_for_post_contents ]['value'];
		}

		$post_author = '';
		$post_author = apply_filters( 'import_kintone_insert_post_author', $post_author );

		$post_id = wp_insert_post(
			array(
				'post_type'    => get_option( 'kintone_to_wp_reflect_post_type' ),
				'post_title'   => $post_title,
				'post_content' => $post_content,
				'post_status'  => $post_status,
				'post_author'  => $post_author,
			)
		);

		return $post_id;

	}

	private function update_kintone_data_to_wp_post( $post_id, $kintoen_data ) {

		$post_title = '';
		if ( ! empty( get_option( 'kintone_to_wp_kintone_field_code_for_post_title' ) ) && array_key_exists( get_option( 'kintone_to_wp_kintone_field_code_for_post_title' ), $kintoen_data['record'] ) ) {
			$post_title = $kintoen_data['record'][ get_option( 'kintone_to_wp_kintone_field_code_for_post_title' ) ]['value'];
		}
		$post_contents = '';
		if ( ! empty( get_option( 'kintone_to_wp_kintone_field_code_for_post_contents' ) ) && array_key_exists( get_option( 'kintone_to_wp_kintone_field_code_for_post_contents' ), $kintoen_data['record'] ) ) {
			$post_contents = $kintoen_data['record'][ get_option( 'kintone_to_wp_kintone_field_code_for_post_contents' ) ]['value'];
		}

		$my_post = array(
			'ID'           => $post_id,
			'post_type'    => get_option( 'kintone_to_wp_reflect_post_type' ),
			'post_title'   => $post_title,
			'post_content' => $post_contents,
		);

		$post_id = wp_update_post( $my_post );
		if ( is_wp_error( $post_id ) ) {
			$errors = $post_id->get_error_messages();
			foreach ( $errors as $error ) {
				error_log( var_export( $error, true ) );
			}
		}

	}

	private function update_kintone_data_to_wp_post_meta( $post_id, $kintone_data ) {

		$setting_custom_fields = get_option( 'kintone_to_wp_setting_custom_fields' );

		// update kintone_id
		update_post_meta( $post_id, 'kintone_record_id', $kintone_data['record']['$id']['value'] );

		foreach ( $setting_custom_fields as $key => $kintone_fieldcode ) {

			if ( $kintone_fieldcode ) {


				if ( $kintone_data['record'][ $key ]['type'] == 'USER_SELECT' ) {

					update_post_meta( $post_id, $kintone_fieldcode, $kintone_data['record'][ $key ]['value'] );

				} elseif ( $kintone_data['record'][ $key ]['type'] == 'FILE' ) {

					if ( ! empty( $kintone_data['record'][ $key ]['value'] ) ) {
						$this->update_kintone_temp_file_to_meta( $post_id, $kintone_data['record'][ $key ]['value'][0], $kintone_fieldcode );
					} else {
						$this->delete_kintone_temp_file( $post_id, $kintone_fieldcode );
					}

				} elseif ( $kintone_data['record'][ $key ]['type'] == 'CREATOR' || $kintone_data['record'][ $key ]['type'] == 'MODIFIER' ) {

					update_post_meta( $post_id, $kintone_fieldcode . '_code', $kintone_data['record'][ $key ]['value']['code'] );
					update_post_meta( $post_id, $kintone_fieldcode . '_name', $kintone_data['record'][ $key ]['value']['name'] );

				} elseif ( $kintone_data['record'][ $key ]['type'] == 'SUBTABLE' ) {

					update_post_meta( $post_id, $kintone_fieldcode, $kintone_data['record'][ $key ]['value'] );

				} else {
					$record_data = $this->make_kintone_array_to_string( $kintone_data['record'][ $key ]['value'] );

					if ( function_exists( 'CFS' ) ) {

						$field_data = array(
							$kintone_fieldcode => $record_data,
						);

						$post_data = array(
							'ID' => $post_id,
						);

						CFS()->save( $field_data, $post_data );
					} else {
						update_post_meta( $post_id, $kintone_fieldcode, $record_data );
					}
				}
			}
		}
	}


	private function update_kintone_data_to_wp_terms( $post_id, $kintone_data ) {

		$kintone_field_code_for_terms = get_option( 'kintone_to_wp_kintone_field_code_for_terms' );

		if ( ! empty( $kintone_field_code_for_terms ) ) {
			foreach ( $kintone_field_code_for_terms as $key => $kintone_field_code_for_term ) {

				if ( isset( $kintone_data['record'][ $kintone_field_code_for_term ] ) ) {
					$terms = $kintone_data['record'][ $kintone_field_code_for_term ]['value'];

					if ( ! is_array( $terms ) ) {
						$terms = array( $terms );
					}

					wp_set_object_terms( $post_id, $terms, $key );
				}
			}
		}
	}


	private function get_update_kintone_data_by_id( $kintone_record_id ) {

		$url = 'https://' . get_option( 'kintone_to_wp_kintone_url' ) . '/k/v1/record.json?app=' . get_option( 'kintone_to_wp_target_appid' ) . '&id=' . $kintone_record_id;

		return $this->kintone_api( $url, get_option( 'kintone_to_wp_kintone_api_token' ) );

	}

	private function kintone_api( $request_url, $kintone_token, $file = false ) {

		if ( $request_url ) {

			$headers = array( 'X-Cybozu-API-Token' => $kintone_token );

			$res = wp_remote_get(
				$request_url,
				array(
					'headers' => $headers
				)
			);

			if ( is_wp_error( $res ) ) {

				return $res;

			} else {

				if ( $file ) {
					$return_value = $res['body'];
				} else {
					$return_value = json_decode( $res['body'], true );
				}

				if ( isset( $return_value['message'] ) && isset( $return_value['code'] ) ) {

					echo '<div class="error fade"><p><strong>' . $return_value['message'] . '</strong></p></div>';

					return new WP_Error( $return_value['code'], $return_value['message'] );
				}

				return $return_value;
			}
		} else {
			echo '<div class="error fade"><p><strong>URL is required</strong></p></div>';

			return new WP_Error( 'Error', 'URL is required' );
		}

	}

	private function make_kintone_array_to_string( $kintone_data ) {

		$record_data = $kintone_data;

		if ( is_array( $record_data ) ) {

			$record_data = implode( ",", $record_data );
		}

		return $record_data;
	}

	private function get_kintone_temp_file( $filekey ) {

		$url = 'https://' . get_option( 'kintone_to_wp_kintone_url' ) . '/k/v1/file.json?fileKey=' . $filekey;

		return $this->kintone_api( $url, get_option( 'kintone_to_wp_kintone_api_token' ), true );

	}

	private function delete_kintone_temp_file( $post_id, $kintone_fieldcode, $featured_image_flag = false ) {

		if ( $featured_image_flag ) {
			$attachment_id = get_post_thumbnail_id( $post_id );
			if ( $attachment_id ) {
				wp_delete_attachment( $attachment_id );
				delete_post_thumbnail( $post_id );
			}
		} else {
			if ( $kintone_fieldcode ) {
				$attachment_id = get_post_meta( $post_id, $kintone_fieldcode, true );
				if ( ! empty( $attachment_id ) ) {

					wp_delete_attachment( $attachment_id );
					delete_post_meta( $post_id, $kintone_fieldcode, $attachment_id );

				}
			}
		}
	}

	private function update_kintone_temp_file_to_meta( $post_id, $temp_base_data, $post_meta_name, $featured_image_flag = false ) {


		$file_data = $this->get_kintone_temp_file( $temp_base_data['fileKey'] );

		$upload_dir = wp_upload_dir();
		$tmp_dir    = $upload_dir['basedir'] . '/import_kintone';

		$tmp_filename = $post_id . '_' . $temp_base_data['name'];
		$tmp_type     = $temp_base_data['contentType'];
		$tmp_size     = $temp_base_data['size'];

		if ( ! file_exists( $tmp_dir ) ) {
			if ( mkdir( $tmp_dir, 0777 ) ) {
				chmod( $tmp_dir, 0777 );
			}
		}

		$fp = fopen( $tmp_dir . '/' . $tmp_filename, 'w' );
		fwrite( $fp, $file_data );
		fclose( $fp );
		chmod( $tmp_dir . '/' . $tmp_filename, 0777 );

		$upload['tmp_name'] = $tmp_dir . '/' . $tmp_filename;
		$upload['name']     = $tmp_filename;
		$upload['type']     = $tmp_type;
		$upload['error']    = UPLOAD_ERR_OK;
		$upload['size']     = $tmp_size;

		include_once ABSPATH . 'wp-admin/includes/file.php';
		include_once ABSPATH . 'wp-admin/includes/media.php';
		include_once ABSPATH . 'wp-admin/includes/image.php';

		$overrides = array(
			'test_form' => false,
			'action'    => ''
		);
		$file      = wp_handle_upload( $upload, $overrides );

		$post_author = '';
		$post_author = apply_filters( 'import_kintone_insert_post_author', $post_author );

		$attachment = array(
			'post_title'     => $temp_base_data['name'],
			'post_mime_type' => $file['type'],
			'post_parent'    => $post_id,
			'post_author'    => $post_author,
		);

		$aid         = wp_insert_attachment( $attachment, $file['file'], $post_id );
		$attach_data = wp_generate_attachment_metadata( $aid, $file['file'] );

		if ( $featured_image_flag ) {
			if ( has_post_thumbnail( $post_id ) ) {
				$thumbnail_id = get_post_thumbnail_id( $post_id );
				delete_post_thumbnail( $post_id );
				wp_delete_attachment( $thumbnail_id );  /*Delete previous image画像が増えていかないように*/
			}
			set_post_thumbnail( $post_id, $aid );

		} else {
			if ( $post_meta_name ) {
				$attachment_id = get_post_meta( $post_id, $post_meta_name, true );
				if ( ! empty( $attachment_id ) ) {
					wp_delete_attachment( $attachment_id );  /*Delete previous image画像が増えていかないように*/
				}
				update_post_meta( $post_id, $post_meta_name, $aid );
			}
		}

		if ( ! is_wp_error( $aid ) ) {
			wp_update_attachment_metadata( $aid, $attach_data );  /*If there is no error, update the metadata of the newly uploaded image*/
		}

		// ディレクトリー削除.
		@unlink( $tmp_dir . '/' . $tmp_filename );

	}
}
