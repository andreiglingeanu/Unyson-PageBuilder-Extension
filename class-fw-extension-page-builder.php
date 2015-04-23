<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

class FW_Extension_Page_Builder extends FW_Extension {
	private $builder_option_key = 'page-builder';
	private $supports_feature_name = 'fw-page-builder';

	/**
	 * @var _FW_Ext_Page_Builder_Shortcode_Atts_Coder $shortcode_atts_coder
	 */
	private $shortcode_atts_coder;

	public function get_supports_feature_name() {
		return $this->supports_feature_name;
	}

	/**
	 * @internal
	 */
	protected function _init() {
		add_action( 'import_post_meta', array( $this, '_action_import_post_meta' ), 10, 3 );

		if ( is_admin() ) {
			$this->add_admin_filters();
			$this->add_admin_actions();
		} else {
			$this->add_theme_filters();
		}
	}

	private function add_admin_filters() {
		add_filter( 'fw_post_options', array( $this, '_admin_filter_fw_post_options' ), 10, 2 );
	}

	private function add_admin_actions() {
		add_action( 'fw_extensions_init', array( $this, '_admin_action_fw_extensions_init' ) );

		if (version_compare(fw()->manifest->get_version(), '2.2.8', '>=')) {
			// this action was added in Unyson 2.2.8
			add_action( 'fw_post_options_update', array( $this, '_action_fw_post_options_update' ), 11, 3 );
		} else {
			// @deprecated
			add_action( 'fw_save_post_options', array( $this, '_admin_action_fw_save_post_options' ), 10, 2 );
		}
	}

	private function add_theme_filters() {
		add_filter( 'fw_shortcode_atts', array( $this, '_theme_filter_fw_shortcode_atts' ) );
		add_action( 'the_content', array( $this, '_theme_filter_prevent_autop' ), 1 );
	}

	/*
	 * when a builder modal window draws or saves
	 * options the shortcodes must be loaded
	 * because they may load their own custom option types
	 *
	 * NOTE: this checking is done at the `fw_extensions_init`
	 * at the moment when all the extensions are loaded the shortcode
	 * extension can begin collecting their shortcodes.
	 * We need this because the shortcodes can load their own option types
	 */
	public function _admin_action_fw_extensions_init() {
		if (
			defined( 'DOING_AJAX' ) &&
			DOING_AJAX === true &&
			(
				FW_Request::POST( 'action', '' ) === 'fw_backend_options_render' ||
				FW_Request::POST( 'action', '' ) === 'fw_backend_options_get_values'
			)
		) {
			$this->get_parent()->load_shortcodes();
		}
	}

	/*
	 * Adds the page builder metabox if the $post_type is supported
	 * @internal
	 */
	public function _admin_filter_fw_post_options( $post_options, $post_type ) {
		if ( post_type_supports( $post_type, $this->supports_feature_name ) ) {
			$this->get_parent()->load_shortcodes();
			$page_builder_options = array(
				'page-builder-box' => array(
					'title'    => false,
					'type'     => 'box',
					'priority' => 'high',
					'options'  => array(
						$this->builder_option_key => array(
							'label'              => false,
							'desc'               => false,
							'type'               => 'page-builder',
							'editor_integration' => true,
							'fullscreen'         => true,
							'template_saving'    => true,
							'history'            => true,
						)
					)
				)
			);
			$post_options         = array_merge( $page_builder_options, $post_options );
		}

		return $post_options;
	}

	/**
	 * @internal
	 * @deprecated The new approach is the _action_fw_post_options_update() method
	 * @param int $post_id
	 * @param WP_Post $post
	 */
	public function _admin_action_fw_save_post_options( $post_id, $post ) {
		if ( wp_is_post_autosave( $post_id ) ) {
			$original_id   = wp_is_post_autosave( $post_id );
			$original_post = get_post( $original_id );
		} else if ( wp_is_post_revision( $post_id ) ) {
			$original_id   = wp_is_post_revision( $post_id );
			$original_post = get_post( $original_id );
		} else {
			$original_id   = $post_id;
			$original_post = $post;
		}
		if ( post_type_supports( $original_post->post_type, $this->supports_feature_name ) ) {
			$builder_shortcodes = fw_get_db_post_option( $original_id, $this->builder_option_key );
			if ( ! $builder_shortcodes['builder_active'] ) {
				return;
			}
			// remove then add again to avoid infinite loop
			remove_action( 'fw_save_post_options', array( $this, '_admin_action_fw_save_post_options' ) );
			wp_update_post( array(
				'ID'           => $post_id,
				'post_content' => str_replace( '\\', '\\\\\\\\\\', $builder_shortcodes['shortcode_notation'] )
			) );
			add_action( 'fw_save_post_options', array( $this, '_admin_action_fw_save_post_options' ), 10, 2 );
		}
	}

	/**
	 * Update post content with the generated builder shortcodes
	 * @internal
	 * @param int $post_id
	 * @param string $option_id
	 * @param array $sub_keys
	 */
	public function _action_fw_post_options_update( $post_id, $option_id, $sub_keys ) {
		if (
			empty($option_id) // all options were updated
			||
			$option_id === $this->builder_option_key // our option was updated
		) {
			//
		} else {
			return;
		}

		if ($original_post_id = wp_is_post_autosave($post_id)) {
			//
		} elseif ($original_post_id = wp_is_post_revision($post_id)) {
			//
		} else {
			$original_post_id = $post_id;
		}

		if ( post_type_supports( get_post_type($original_post_id), $this->supports_feature_name ) ) {
			$builder_shortcodes = fw_get_db_post_option( $post_id, $this->builder_option_key );

			if ( ! $builder_shortcodes['builder_active'] ) {
				return;
			}

			global $wpdb;

			/**
			 * Update directly in db instead of wp_update_post() to prevent revisions flood
			 */
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE $wpdb->posts SET post_content = %s WHERE ID = %d",
					$builder_shortcodes['shortcode_notation'],
					$post_id
				)
			);
		}
	}

	/**
	 * @internal
	 *
	 * @param $atts
	 *
	 * @return mixed
	 */
	public function _theme_filter_fw_shortcode_atts( $atts ) {
		return $this->get_shortcode_atts_coder()->decode_atts( $atts );
	}

	/**
	 * @internal
	 *
	 * @param string $content
	 *
	 * @return string
	 */
	public function _theme_filter_prevent_autop( $content ) {
		if ( $this->is_builder_post() ) {
			$wrapper_class = apply_filters( 'fw_ext_page_builder_content_wrapper_class', 'fw-page-builder-content' );

			/**
			 * Wrap the content in a div to prevent wpautop change/break the html generated by shortcodes
			 */

			return
				'<div ' . ( empty( $wrapper_class ) ? '' : 'class="' . esc_attr( $wrapper_class ) . '"' ) . '>' .
				$content .
				'</div>';
		}

		return $content;
	}

	/**
	 * Checks if a post was built with builder
	 *
	 * @param int $post_id
	 *
	 * @return bool
	 */
	public function is_builder_post( $post_id = null ) {
		if ( empty( $post_id ) ) {
			global $post;
		} else {
			$post = get_post( $post_id );
		}

		if ( ! $post ) {
			return false;
		}

		if ( post_type_supports( $post->post_type, $this->supports_feature_name ) ) {
			return (bool) fw_get_db_post_option( $post->ID, $this->builder_option_key . '/builder_active' );
		} else {
			return false;
		}
	}

	/**
	 * Solve the problem with striped backslashes by wordpress when doing add_post_meta
	 *
	 * @internal
	 *
	 * @param int $post_id
	 * @param string $key
	 * @param mixed $value
	 **/
	public function _action_import_post_meta( $post_id, $key, $value ) {
		if ( $key != FW_Option_Type::get_default_name_prefix() || ! isset( $value[ $this->builder_option_key ] ) ) {
			return;
		}

		fw_set_db_post_option( $post_id, $this->builder_option_key, $value[ $this->builder_option_key ] );
	}

	public function get_shortcode_atts_coder() {
		if ( ! $this->shortcode_atts_coder ) {
			// lazy init
			$this->shortcode_atts_coder = new _FW_Ext_Page_Builder_Shortcode_Atts_Coder();
		}

		return $this->shortcode_atts_coder;
	}
}
