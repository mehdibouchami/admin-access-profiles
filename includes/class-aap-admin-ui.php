<?php
/**
 * Owner screens: Profiles (list / create / edit / delete), Activity, Settings, About.
 */

defined( 'ABSPATH' ) || exit;

class AAP_Admin_UI {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_aap_owner_create', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_aap_owner_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_aap_owner_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_aap_owner_settings', array( __CLASS__, 'handle_settings' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( AAP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function register_page() {
		if ( ! AAP_Settings::is_owner() ) {
			return;
		}
		add_menu_page(
			__( 'Access Profiles', 'admin-access-profiles' ),
			__( 'Access Profiles', 'admin-access-profiles' ),
			'manage_options',
			AAP_Plugin::SETTINGS_PAGE,
			array( __CLASS__, 'render' ),
			'dashicons-shield',
			71
		);
	}

	public static function enqueue( $hook ) {
		if ( false === strpos( $hook, AAP_Plugin::SETTINGS_PAGE ) && false === strpos( $hook, AAP_Plugin::SCRIPTS_PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'aap-admin', AAP_URL . 'assets/admin.css', array(), AAP_VERSION );
		wp_enqueue_script( 'aap-admin', AAP_URL . 'assets/admin.js', array(), AAP_VERSION, true );
		wp_localize_script(
			'aap-admin',
			'aapAdmin',
			array( 'confirmDelete' => __( 'Delete this profile? Its users will become Subscribers.', 'admin-access-profiles' ) )
		);
	}

	public static function row_meta( $links, $file ) {
		if ( plugin_basename( AAP_FILE ) === $file ) {
			$links[] = '<a href="' . esc_url( AAP_Plugin::GITHUB_URL ) . '" target="_blank" rel="noopener">GitHub</a>';
			$links[] = '<a href="' . esc_url( AAP_Plugin::SUPPORT_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Support the developer', 'admin-access-profiles' ) . '</a>';
		}
		return $links;
	}

	public static function action_links( $links ) {
		if ( AAP_Settings::is_owner() ) {
			array_unshift( $links, '<a href="' . esc_url( self::url( 'profiles' ) ) . '">' . esc_html__( 'Profiles', 'admin-access-profiles' ) . '</a>' );
		}
		return $links;
	}

	private static function url( $tab, array $extra = array() ) {
		return add_query_arg( array_merge( array( 'page' => AAP_Plugin::SETTINGS_PAGE, 'tab' => $tab ), $extra ), admin_url( 'admin.php' ) );
	}

	private static function check_owner( $nonce ) {
		if ( ! AAP_Settings::is_owner() ) {
			wp_die( esc_html__( 'Not allowed.', 'admin-access-profiles' ), 403 );
		}
		check_admin_referer( $nonce );
	}

	private static function lines( $key ) {
		$raw = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
		$out = array();
		foreach ( preg_split( '/[\r\n,]+/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function durations() {
		return array(
			'keep'      => __( '— no change —', 'admin-access-profiles' ),
			'off'       => __( 'Off', 'admin-access-profiles' ),
			'3600'      => __( 'On for 1 hour', 'admin-access-profiles' ),
			'86400'     => __( 'On for 24 hours', 'admin-access-profiles' ),
			'604800'    => __( 'On for 7 days', 'admin-access-profiles' ),
			'2592000'   => __( 'On for 30 days', 'admin-access-profiles' ),
			'permanent' => __( 'On — unlimited', 'admin-access-profiles' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Handlers
	 * ---------------------------------------------------------------- */

	public static function handle_create() {
		self::check_owner( 'aap_owner_create' );
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			wp_safe_redirect( self::url( 'profiles', array( 'error' => 'name' ) ) );
			exit;
		}
		$presets = AAP_Profiles::presets();
		$preset  = isset( $_POST['preset'] ) ? sanitize_key( $_POST['preset'] ) : 'blank';
		$preset  = isset( $presets[ $preset ] ) ? $presets[ $preset ]['profile'] : array();

		$profile            = array_replace_recursive( AAP_Profiles::defaults(), $preset );
		$profile['id']      = AAP_Profiles::unique_id( $name );
		$profile['name']    = $name;
		$profile['created'] = time();
		AAP_Profiles::save( $profile );
		AAP_Activity_Log::log( 'profile_created', $profile['id'], array( 'name' => $name ), null, '' );

		wp_safe_redirect( self::url( 'edit', array( 'profile' => $profile['id'], 'created' => 1 ) ) );
		exit;
	}

	public static function handle_save() {
		self::check_owner( 'aap_owner_save' );
		$id      = isset( $_POST['profile'] ) ? sanitize_key( $_POST['profile'] ) : '';
		$profile = AAP_Profiles::get( $id );
		if ( ! $profile ) {
			wp_die( esc_html__( 'Unknown profile.', 'admin-access-profiles' ) );
		}
		$before = $profile;

		$name                   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$profile['name']        = '' === $name ? $profile['name'] : $name;
		$profile['description'] = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$base                   = isset( $_POST['base_role'] ) ? sanitize_key( $_POST['base_role'] ) : '';
		$profile['base_role']   = ( '' !== $base && get_role( $base ) && 0 !== strpos( $base, AAP_Profiles::ROLE_PREFIX ) ) ? $base : '';
		$profile['scripts']     = empty( $_POST['scripts'] ) ? 0 : 1;
		$profile['extra_caps']  = array_map( 'sanitize_key', self::lines( 'extra_caps' ) );
		$profile['menu_mode']   = ( isset( $_POST['menu_mode'] ) && 'all' === $_POST['menu_mode'] ) ? 'all' : 'allowlist';

		$packs = isset( $_POST['packs'] ) && is_array( $_POST['packs'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['packs'] ) ) : array();
		foreach ( array_keys( AAP_Packs::all() ) as $pack ) {
			$profile['packs'][ $pack ] = in_array( $pack, $packs, true ) ? 1 : 0;
		}

		$toggles = isset( $_POST['toggles'] ) && is_array( $_POST['toggles'] ) ? wp_unslash( $_POST['toggles'] ) : array();
		foreach ( array_keys( AAP_Profiles::toggle_caps() ) as $key ) {
			$choice = isset( $toggles[ $key ] ) && is_string( $toggles[ $key ] ) ? sanitize_key( $toggles[ $key ] ) : 'keep';
			if ( 'off' === $choice ) {
				$profile['toggles'][ $key ] = array( 'on' => 0, 'expires' => 0 );
			} elseif ( 'permanent' === $choice ) {
				$profile['toggles'][ $key ] = array( 'on' => 1, 'expires' => 0 );
			} elseif ( ctype_digit( $choice ) && (int) $choice > 0 ) {
				$profile['toggles'][ $key ] = array( 'on' => 1, 'expires' => time() + (int) $choice );
			}
		}

		$registry = AAP_Menu::registry();
		$allowed  = isset( $_POST['allowed'] ) && is_array( $_POST['allowed'] ) ? wp_unslash( $_POST['allowed'] ) : array();
		$profile['allowed_menus'] = array_values(
			array_filter(
				array_map( 'strval', $allowed ),
				function ( $key ) use ( $registry ) {
					return isset( $registry[ $key ] );
				}
			)
		);
		$profile['extra_pages'] = array_map( 'sanitize_text_field', self::lines( 'extra_pages' ) );

		$profile['custom'] = array(
			'pages'      => array_map( 'sanitize_text_field', self::lines( 'custom_pages' ) ),
			'post_types' => array_map( 'sanitize_text_field', self::lines( 'custom_post_types' ) ),
			'rest'       => array_map( 'sanitize_text_field', self::lines( 'custom_rest' ) ),
			'plugins'    => array_map( 'sanitize_text_field', self::lines( 'custom_plugins' ) ),
		);

		AAP_Profiles::save( $profile );
		$changes = array();
		foreach ( $profile as $key => $value ) {
			if ( $before[ $key ] !== $value ) {
				$changes[ $key ] = $value;
			}
		}
		AAP_Activity_Log::log( 'profile_changed', $id, $changes, null, '' );

		wp_safe_redirect( self::url( 'edit', array( 'profile' => $id, 'updated' => 1 ) ) );
		exit;
	}

	public static function handle_delete() {
		self::check_owner( 'aap_owner_delete' );
		$id = isset( $_POST['profile'] ) ? sanitize_key( $_POST['profile'] ) : '';
		if ( AAP_Profiles::get( $id ) ) {
			AAP_Profiles::delete( $id );
			AAP_Activity_Log::log( 'profile_deleted', $id, array(), null, '' );
		}
		wp_safe_redirect( self::url( 'profiles', array( 'deleted' => 1 ) ) );
		exit;
	}

	public static function handle_settings() {
		self::check_owner( 'aap_owner_settings' );
		$ids = array_filter( array_map( 'absint', self::lines( 'owner_ids' ) ) );
		if ( $ids && ! in_array( get_current_user_id(), $ids, true ) ) {
			$ids[] = get_current_user_id(); // Never lock yourself out.
		}
		AAP_Settings::update(
			array(
				'owner_ids'           => array_values( array_unique( $ids ) ),
				'scripts_skip_admins' => empty( $_POST['scripts_skip_admins'] ) ? 0 : 1,
				'log_retention'       => max( 7, isset( $_POST['log_retention'] ) ? absint( $_POST['log_retention'] ) : 90 ),
				'log_views'           => empty( $_POST['log_views'] ) ? 0 : 1,
			)
		);
		AAP_Activity_Log::log( 'settings_changed', 'settings', AAP_Settings::get(), null, '' );
		wp_safe_redirect( self::url( 'settings', array( 'updated' => 1 ) ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Screen
	 * ---------------------------------------------------------------- */

	public static function render() {
		if ( ! AAP_Settings::is_owner() ) {
			return;
		}
		$tabs = array(
			'profiles' => __( 'Profiles', 'admin-access-profiles' ),
			'activity' => __( 'Activity', 'admin-access-profiles' ),
			'settings' => __( 'Settings', 'admin-access-profiles' ),
			'about'    => __( 'About & support', 'admin-access-profiles' ),
		);
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'profiles';
		if ( 'edit' !== $tab && ! isset( $tabs[ $tab ] ) ) {
			$tab = 'profiles';
		}
		?>
		<div class="wrap aap-wrap">
			<h1><?php esc_html_e( 'Access Profiles', 'admin-access-profiles' ); ?></h1>
			<?php self::notices(); ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="nav-tab <?php echo ( $key === $tab || ( 'edit' === $tab && 'profiles' === $key ) ) ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			switch ( $tab ) {
				case 'edit':
					$id      = isset( $_GET['profile'] ) && is_string( $_GET['profile'] ) ? sanitize_key( $_GET['profile'] ) : '';
					$profile = AAP_Profiles::get( $id );
					$profile ? self::render_edit( $profile ) : self::render_profiles();
					break;
				case 'activity':
					self::render_activity();
					break;
				case 'settings':
					self::render_settings();
					break;
				case 'about':
					self::render_about();
					break;
				default:
					self::render_profiles();
			}
			?>
		</div>
		<?php
	}

	private static function notices() {
		$messages = array(
			'updated' => __( 'Saved.', 'admin-access-profiles' ),
			'created' => __( 'Profile created. Review it below, then add users with this role.', 'admin-access-profiles' ),
			'deleted' => __( 'Profile deleted.', 'admin-access-profiles' ),
		);
		foreach ( $messages as $flag => $message ) {
			if ( isset( $_GET[ $flag ] ) ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
			}
		}
		if ( isset( $_GET['error'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Please give the profile a name.', 'admin-access-profiles' ) . '</p></div>';
		}
	}

	private static function form_open( $action ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
	}

	/* --- Profiles list ------------------------------------------------ */

	private static function render_profiles() {
		$profiles = AAP_Profiles::all();
		$packs    = AAP_Packs::labels();
		$labels   = AAP_Profiles::toggle_labels();
		?>
		<p class="description"><?php esc_html_e( 'Each profile is a user role with its own limits. Give each person their own account with the profile\'s role.', 'admin-access-profiles' ); ?></p>

		<?php if ( $profiles ) : ?>
			<table class="widefat striped aap-profiles">
				<thead><tr>
					<th><?php esc_html_e( 'Profile', 'admin-access-profiles' ); ?></th>
					<th><?php esc_html_e( 'Users', 'admin-access-profiles' ); ?></th>
					<th><?php esc_html_e( 'Locked packs', 'admin-access-profiles' ); ?></th>
					<th><?php esc_html_e( 'Switches on', 'admin-access-profiles' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $profiles as $profile ) : ?>
					<?php
					$count  = count( get_users( array( 'role' => AAP_Profiles::role_name( $profile['id'] ), 'fields' => 'ID' ) ) );
					$locked = array();
					foreach ( $packs as $pack_id => $pack ) {
						if ( ! empty( $profile['packs'][ $pack_id ] ) ) {
							$locked[] = $pack['label'];
						}
					}
					$on = array();
					foreach ( $labels as $key => $info ) {
						if ( AAP_Profiles::toggle_on( $profile, $key ) ) {
							$on[] = $info['label'];
						}
					}
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( self::url( 'edit', array( 'profile' => $profile['id'] ) ) ); ?>"><?php echo esc_html( $profile['name'] ); ?></a></strong>
							<br><code><?php echo esc_html( AAP_Profiles::role_name( $profile['id'] ) ); ?></code>
							<?php if ( $profile['description'] ) : ?>
								<p class="description"><?php echo esc_html( $profile['description'] ); ?></p>
							<?php endif; ?>
						</td>
						<td><?php echo (int) $count; ?></td>
						<td><?php echo esc_html( $locked ? implode( ', ', $locked ) : '—' ); ?></td>
						<td><?php echo esc_html( $on ? implode( ', ', $on ) : '—' ); ?></td>
						<td class="aap-actions">
							<a class="button" href="<?php echo esc_url( self::url( 'edit', array( 'profile' => $profile['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'admin-access-profiles' ); ?></a>
							<?php self::form_open( 'aap_owner_delete' ); ?>
								<input type="hidden" name="profile" value="<?php echo esc_attr( $profile['id'] ); ?>">
								<button type="submit" class="button-link aap-delete"><?php esc_html_e( 'Delete', 'admin-access-profiles' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'New profile', 'admin-access-profiles' ); ?></h2>
		<?php self::form_open( 'aap_owner_create' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aap-new-name"><?php esc_html_e( 'Name', 'admin-access-profiles' ); ?></label></th>
					<td><input id="aap-new-name" type="text" name="name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Marketing agency', 'admin-access-profiles' ); ?>" required></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Start from', 'admin-access-profiles' ); ?></th>
					<td>
						<?php foreach ( AAP_Profiles::presets() as $key => $preset ) : ?>
							<label class="aap-preset"><input type="radio" name="preset" value="<?php echo esc_attr( $key ); ?>" <?php checked( 'blank', $key ); ?>> <?php echo esc_html( $preset['label'] ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Every preset locks all protection packs. You can change everything on the next screen.', 'admin-access-profiles' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Create profile', 'admin-access-profiles' ) ); ?>
		</form>
		<?php
	}

	/* --- Profile editor ------------------------------------------------ */

	private static function render_edit( array $profile ) {
		$role_name = AAP_Profiles::role_name( $profile['id'] );
		$users     = get_users( array( 'role' => $role_name ) );
		$sections  = array(
			'general' => __( 'General', 'admin-access-profiles' ),
			'packs'   => __( 'Protection packs', 'admin-access-profiles' ),
			'toggles' => __( 'Switches', 'admin-access-profiles' ),
			'menus'   => __( 'Menu access', 'admin-access-profiles' ),
			'custom'  => __( 'Custom rules', 'admin-access-profiles' ),
			'users'   => __( 'Users', 'admin-access-profiles' ),
		);
		?>
		<h2 class="aap-profile-title">
			<?php echo esc_html( $profile['name'] ); ?>
			<code><?php echo esc_html( $role_name ); ?></code>
		</h2>
		<p class="aap-jump">
			<?php foreach ( $sections as $anchor => $label ) : ?>
				<a href="#aap-<?php echo esc_attr( $anchor ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</p>

		<?php self::form_open( 'aap_owner_save' ); ?>
		<input type="hidden" name="profile" value="<?php echo esc_attr( $profile['id'] ); ?>">

		<div class="aap-card" id="aap-general">
			<h2><?php echo esc_html( $sections['general'] ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aap-name"><?php esc_html_e( 'Name', 'admin-access-profiles' ); ?></label></th>
					<td><input id="aap-name" type="text" name="name" class="regular-text" value="<?php echo esc_attr( $profile['name'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aap-description"><?php esc_html_e( 'Notes', 'admin-access-profiles' ); ?></label></th>
					<td><textarea id="aap-description" name="description" rows="2" class="large-text"><?php echo esc_textarea( $profile['description'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="aap-base"><?php esc_html_e( 'Based on role', 'admin-access-profiles' ); ?></label></th>
					<td>
						<select id="aap-base" name="base_role">
							<option value=""><?php esc_html_e( '— none (start from zero) —', 'admin-access-profiles' ); ?></option>
							<?php foreach ( wp_roles()->get_names() as $role => $label ) : ?>
								<?php if ( 0 === strpos( $role, AAP_Profiles::ROLE_PREFIX ) ) { continue; } ?>
								<option value="<?php echo esc_attr( $role ); ?>" <?php selected( $profile['base_role'], $role ); ?>><?php echo esc_html( translate_user_role( $label ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'The profile gets that role\'s capabilities, then locked packs and the built-in safety rules remove what is forbidden. The Switches below still decide their own areas: a switch that is Off removes that access even if the role has it. Users, file editors, core updates and privacy tools are never allowed.', 'admin-access-profiles' ); ?></p>
						<?php if ( 'administrator' === $profile['base_role'] ) : ?>
							<p class="aap-warning"><?php esc_html_e( 'Based on Administrator: this profile can install plugins and change most settings. Locked packs still apply, but a plugin they install could get around them.', 'admin-access-profiles' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Tracking Scripts', 'admin-access-profiles' ); ?></th>
					<td>
						<label><input type="checkbox" name="scripts" value="1" <?php checked( $profile['scripts'] ); ?>> <?php esc_html_e( 'May edit the Tracking Scripts page (head / body / footer code for pixels and tag managers)', 'admin-access-profiles' ); ?></label>
						<p class="description"><?php esc_html_e( 'A safe alternative to giving access to a snippet plugin: HTML/JavaScript only, never PHP. Every change is logged and can be restored.', 'admin-access-profiles' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aap-extra-caps"><?php esc_html_e( 'Extra capabilities', 'admin-access-profiles' ); ?></label></th>
					<td>
						<textarea id="aap-extra-caps" name="extra_caps" rows="2" class="large-text code" placeholder="wpseo_manage_options"><?php echo esc_textarea( implode( "\n", $profile['extra_caps'] ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Advanced: one capability per line, for plugins that use their own (for example an SEO plugin). Locked packs and safety rules still win.', 'admin-access-profiles' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="aap-card" id="aap-packs">
			<h2><?php echo esc_html( $sections['packs'] ); ?></h2>
			<p class="description"><?php esc_html_e( 'A locked pack blocks the whole plugin for this profile: its admin screens, content, REST API, AJAX actions and settings. Its plugin can not be deactivated or deleted by the profile.', 'admin-access-profiles' ); ?></p>
			<?php foreach ( AAP_Packs::labels() as $pack_id => $pack ) : ?>
				<label class="aap-pack">
					<input type="checkbox" name="packs[]" value="<?php echo esc_attr( $pack_id ); ?>" <?php checked( ! empty( $profile['packs'][ $pack_id ] ) ); ?>>
					<strong><?php echo esc_html( sprintf( /* translators: %s: plugin name */ __( 'Lock %s', 'admin-access-profiles' ), $pack['label'] ) ); ?></strong>
					<?php if ( AAP_Packs::is_detected( $pack_id ) ) : ?>
						<span class="aap-badge aap-on"><?php esc_html_e( 'installed', 'admin-access-profiles' ); ?></span>
					<?php else : ?>
						<span class="aap-badge"><?php esc_html_e( 'not active on this site', 'admin-access-profiles' ); ?></span>
					<?php endif; ?>
					<span class="description"><?php echo esc_html( $pack['description'] ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>

		<div class="aap-card" id="aap-toggles">
			<h2><?php echo esc_html( $sections['toggles'] ); ?></h2>
			<p class="description"><?php esc_html_e( 'Each switch has the final say over its area, even when the profile is based on a role. Access opened for a limited time is removed automatically when the time is up.', 'admin-access-profiles' ); ?></p>
			<table class="widefat striped aap-toggles">
				<tbody>
				<?php foreach ( AAP_Profiles::available_toggles() as $key => $info ) : ?>
					<?php
					$on      = AAP_Profiles::toggle_on( $profile, $key );
					$expires = (int) $profile['toggles'][ $key ]['expires'];
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $info['label'] ); ?></strong>
							<p class="description"><?php echo esc_html( $info['description'] ); ?></p>
							<?php if ( ! empty( $info['warning'] ) ) : ?>
								<p class="aap-warning"><?php echo esc_html( $info['warning'] ); ?></p>
							<?php endif; ?>
						</td>
						<td class="aap-status">
							<?php if ( $on ) : ?>
								<span class="aap-badge aap-on"><?php esc_html_e( 'On', 'admin-access-profiles' ); ?></span>
								<br><small><?php echo esc_html( $expires ? sprintf( /* translators: %s: date */ __( 'until %s', 'admin-access-profiles' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expires ) ) : __( 'unlimited', 'admin-access-profiles' ) ); ?></small>
							<?php else : ?>
								<span class="aap-badge aap-off"><?php esc_html_e( 'Off', 'admin-access-profiles' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<select name="toggles[<?php echo esc_attr( $key ); ?>]">
								<?php foreach ( self::durations() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<div class="aap-card" id="aap-menus">
			<h2><?php echo esc_html( $sections['menus'] ); ?></h2>
			<fieldset class="aap-mode">
				<label><input type="radio" name="menu_mode" value="allowlist" <?php checked( 'allowlist', $profile['menu_mode'] ); ?>> <strong><?php esc_html_e( 'Only ticked menus', 'admin-access-profiles' ); ?></strong> — <?php esc_html_e( 'new menus (from newly installed plugins) stay hidden until you tick them. Safest.', 'admin-access-profiles' ); ?></label><br>
				<label><input type="radio" name="menu_mode" value="all" <?php checked( 'all', $profile['menu_mode'] ); ?>> <strong><?php esc_html_e( 'Every menu the role can use', 'admin-access-profiles' ); ?></strong> — <?php esc_html_e( 'except locked areas. Good with "Based on role".', 'admin-access-profiles' ); ?></label>
			</fieldset>
			<p class="description"><?php esc_html_e( 'The list below is recorded while you (an owner) browse wp-admin. After a new plugin is installed, open any admin page once and it appears here marked NEW. WordPress also hides any menu the profile lacks the capability for, whatever you tick: those menus are marked "Hidden: needs…" with the switch to turn on.', 'admin-access-profiles' ); ?></p>
			<p><button type="button" class="button aap-tick-all"><?php esc_html_e( 'Tick all available', 'admin-access-profiles' ); ?></button> <button type="button" class="button aap-untick-all"><?php esc_html_e( 'Untick all', 'admin-access-profiles' ); ?></button></p>
			<?php self::render_menu_tree( $profile ); ?>

			<h3><?php esc_html_e( 'Extra hidden pages', 'admin-access-profiles' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Some plugins have pages that are in no menu (setup wizards, connection screens). If the Activity log shows "page not allowed: something" for a page this profile needs, add that page name here, one per line.', 'admin-access-profiles' ); ?></p>
			<textarea name="extra_pages" rows="3" class="large-text code"><?php echo esc_textarea( implode( "\n", $profile['extra_pages'] ) ); ?></textarea>
		</div>

		<div class="aap-card" id="aap-custom">
			<h2><?php echo esc_html( $sections['custom'] ); ?></h2>
			<p class="description"><?php esc_html_e( 'Advanced: lock other plugins that have no pack. One pattern per line, * is a wildcard.', 'admin-access-profiles' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				$custom_fields = array(
					'custom_pages'      => array( 'pages', __( 'Admin page slugs (?page=)', 'admin-access-profiles' ), 'my-plugin*' ),
					'custom_post_types' => array( 'post_types', __( 'Post types', 'admin-access-profiles' ), 'my_cpt*' ),
					'custom_rest'       => array( 'rest', __( 'REST route prefixes', 'admin-access-profiles' ), '/my-plugin/v1' ),
					'custom_plugins'    => array( 'plugins', __( 'Plugin folders that cannot be (de)activated', 'admin-access-profiles' ), 'my-plugin' ),
				);
				foreach ( $custom_fields as $field => $info ) :
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $info[1] ); ?></th>
						<td><textarea name="<?php echo esc_attr( $field ); ?>" rows="2" class="large-text code" placeholder="<?php echo esc_attr( $info[2] ); ?>"><?php echo esc_textarea( implode( "\n", $profile['custom'][ $info[0] ] ) ); ?></textarea></td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>

		<?php submit_button( __( 'Save profile', 'admin-access-profiles' ), 'primary large aap-save' ); ?>
		</form>

		<div class="aap-card" id="aap-users">
			<h2><?php echo esc_html( $sections['users'] ); ?></h2>
			<?php if ( $users ) : ?>
				<ul class="aap-users">
					<?php foreach ( $users as $user ) : ?>
						<li><strong><?php echo esc_html( $user->display_name ); ?></strong> — <?php echo esc_html( $user->user_email ); ?>
							(<a href="<?php echo esc_url( get_edit_user_link( $user->ID ) ); ?>"><?php esc_html_e( 'edit', 'admin-access-profiles' ); ?></a>,
							<a href="<?php echo esc_url( self::url( 'activity', array( 'user' => $user->ID ) ) ); ?>"><?php esc_html_e( 'activity', 'admin-access-profiles' ); ?></a>)</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><?php esc_html_e( 'No user has this profile yet.', 'admin-access-profiles' ); ?></p>
			<?php endif; ?>
			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>"><?php esc_html_e( 'Add a user', 'admin-access-profiles' ); ?></a>
				<span class="description">
					<?php
					/* translators: %s: role name */
					printf( esc_html__( 'Choose the role "%s". To move an existing user, change their role on the Users screen.', 'admin-access-profiles' ), esc_html( $profile['name'] ) );
					?>
				</span>
			</p>
		</div>
		<?php
	}

	private static function render_menu_tree( array $profile ) {
		$tops = array();
		$subs = array();
		foreach ( AAP_Menu::registry() as $key => $entry ) {
			if ( '' === $entry['parent'] ) {
				$tops[ $key ] = $entry;
			} else {
				$subs[ $entry['parent'] ][ $key ] = $entry;
			}
		}
		echo '<ul class="aap-tree">';
		foreach ( $tops as $key => $entry ) {
			echo '<li class="aap-top">';
			self::render_menu_row( $profile, $key, $entry );
			if ( ! empty( $subs[ $key ] ) ) {
				echo '<ul>';
				foreach ( $subs[ $key ] as $sub_key => $sub ) {
					echo '<li>';
					self::render_menu_row( $profile, $sub_key, $sub );
					echo '</li>';
				}
				echo '</ul>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	private static function render_menu_row( array $profile, $key, array $entry ) {
		$state = AAP_Menu::entry_state( $profile, $key, $entry['slug'], $entry['parent'] );
		$notes = array(
			'always'  => __( 'Always visible', 'admin-access-profiles' ),
			'reports' => __( 'Opened by "Sales reports"', 'admin-access-profiles' ),
			'locked'  => __( 'Locked', 'admin-access-profiles' ),
			'cap'     => __( 'Follows the plugin / theme switches', 'admin-access-profiles' ),
			'normal'  => '',
		);
		$checked = 'normal' === $state
			? in_array( $key, $profile['allowed_menus'], true )
			: AAP_Menu::is_allowed( $profile, $key, $entry['slug'], $entry['parent'] );
		$is_new  = ! empty( $entry['first_seen'] ) && $entry['first_seen'] > time() - WEEK_IN_SECONDS;
		?>
		<label class="aap-row aap-<?php echo esc_attr( $state ); ?>">
			<input type="checkbox" name="allowed[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $checked ); ?> <?php disabled( 'normal' !== $state ); ?>>
			<span class="aap-title"><?php echo esc_html( $entry['title'] ); ?></span>
			<code><?php echo esc_html( $entry['slug'] ); ?></code>
			<?php if ( $is_new && 'locked' !== $state ) : ?>
				<span class="aap-badge aap-new"><?php esc_html_e( 'NEW', 'admin-access-profiles' ); ?></span>
			<?php endif; ?>
			<?php if ( $notes[ $state ] ) : ?>
				<span class="aap-note"><?php echo esc_html( $notes[ $state ] ); ?></span>
			<?php endif; ?>
			<?php if ( 'normal' === $state && ! empty( $entry['cap'] ) ) : ?>
				<?php $hint = self::missing_cap_hint( $profile, AAP_Menu::missing_caps( $profile, $entry['cap'] ) ); ?>
				<?php if ( $hint ) : ?>
					<span class="aap-badge aap-needs"><?php echo esc_html( $hint ); ?></span>
				<?php endif; ?>
			<?php endif; ?>
		</label>
		<?php
	}

	/** "Hidden: needs the "Plugin settings pages" switch" style hint, or ''. */
	private static function missing_cap_hint( array $profile, array $missing ) {
		if ( ! $missing ) {
			return '';
		}
		$labels       = AAP_Profiles::toggle_labels();
		$switches     = array();
		$other        = array();
		$plugin_gated = false;
		foreach ( $missing as $cap ) {
			if ( 'do_not_allow' === $cap ) {
				$plugin_gated = true;
				continue;
			}
			$found = false;
			foreach ( AAP_Profiles::toggle_caps() as $toggle => $caps ) {
				if ( in_array( $cap, $caps, true ) && isset( $labels[ $toggle ] ) ) {
					$switches[ $labels[ $toggle ]['label'] ] = true;
					$found = true;
				}
			}
			if ( ! $found ) {
				$other[] = $cap;
			}
		}
		// Most plugins only hand out their own capabilities to users who have manage_options.
		if ( $other && ! AAP_Profiles::allows_cap( $profile, 'manage_options' ) ) {
			$switches[ $labels['settings']['label'] ] = true;
			$other = array();
		}

		$parts = array();
		foreach ( array_keys( $switches ) as $label ) {
			/* translators: %s: switch name */
			$parts[] = sprintf( __( 'the "%s" switch', 'admin-access-profiles' ), $label );
		}
		if ( $other ) {
			/* translators: %s: capability names */
			$parts[] = sprintf( __( 'capability %s (add it under Extra capabilities)', 'admin-access-profiles' ), implode( ', ', $other ) );
		}
		if ( ! $parts ) {
			return $plugin_gated
				? __( 'Hidden by the plugin itself until its setup or sign-in is done', 'admin-access-profiles' )
				: '';
		}
		/* translators: %s: what is needed */
		return sprintf( __( 'Hidden: needs %s', 'admin-access-profiles' ), implode( ' + ', $parts ) );
	}

	/* --- Activity ------------------------------------------------------ */

	private static function render_activity() {
		$filters = array(
			'event'   => isset( $_GET['event'] ) && is_string( $_GET['event'] ) ? sanitize_key( $_GET['event'] ) : '',
			'profile' => isset( $_GET['profile'] ) && is_string( $_GET['profile'] ) ? sanitize_key( $_GET['profile'] ) : '',
			'user'    => isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0,
		);
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$result   = AAP_Activity_Log::query( $paged, 50, $filters );
		$pages    = max( 1, (int) ceil( $result['total'] / 50 ) );
		$profiles = AAP_Profiles::all();
		?>
		<form method="get" class="aap-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( AAP_Plugin::SETTINGS_PAGE ); ?>">
			<input type="hidden" name="tab" value="activity">
			<select name="profile">
				<option value=""><?php esc_html_e( 'All profiles', 'admin-access-profiles' ); ?></option>
				<?php foreach ( $profiles as $profile ) : ?>
					<option value="<?php echo esc_attr( $profile['id'] ); ?>" <?php selected( $filters['profile'], $profile['id'] ); ?>><?php echo esc_html( $profile['name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="event">
				<option value=""><?php esc_html_e( 'All events', 'admin-access-profiles' ); ?></option>
				<?php foreach ( AAP_Activity_Log::events() as $name ) : ?>
					<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $filters['event'], $name ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( $filters['user'] ) : ?>
				<input type="hidden" name="user" value="<?php echo (int) $filters['user']; ?>">
				<a href="<?php echo esc_url( self::url( 'activity' ) ); ?>"><?php esc_html_e( 'clear user filter', 'admin-access-profiles' ); ?></a>
			<?php endif; ?>
			<?php submit_button( __( 'Filter', 'admin-access-profiles' ), 'secondary', '', false ); ?>
			<span class="description">
				<?php
				/* translators: %d: number of entries */
				printf( esc_html__( '%d entries', 'admin-access-profiles' ), (int) $result['total'] );
				?>
			</span>
		</form>

		<table class="widefat striped aap-log">
			<thead><tr>
				<th><?php esc_html_e( 'When', 'admin-access-profiles' ); ?></th>
				<th><?php esc_html_e( 'User', 'admin-access-profiles' ); ?></th>
				<th><?php esc_html_e( 'Event', 'admin-access-profiles' ); ?></th>
				<th><?php esc_html_e( 'What', 'admin-access-profiles' ); ?></th>
				<th><?php esc_html_e( 'Details', 'admin-access-profiles' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $result['rows'] ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'Nothing logged yet.', 'admin-access-profiles' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $result['rows'] as $row ) : ?>
				<?php
				$user    = $row->user_id ? get_userdata( (int) $row->user_id ) : null;
				$details = $row->details ? json_decode( $row->details, true ) : null;
				$pname   = ( $row->profile && isset( $profiles[ $row->profile ] ) ) ? $profiles[ $row->profile ]['name'] : $row->profile;
				?>
				<tr class="aap-event-<?php echo esc_attr( $row->event ); ?>">
					<td><?php echo esc_html( get_date_from_gmt( $row->created_at, get_option( 'date_format' ) . ' H:i:s' ) ); ?></td>
					<td>
						<?php echo esc_html( $user ? $user->display_name : ( $row->user_id ? '#' . $row->user_id : __( 'system', 'admin-access-profiles' ) ) ); ?>
						<?php if ( $pname ) : ?><br><small><?php echo esc_html( $pname ); ?></small><?php endif; ?>
						<br><small><?php echo esc_html( $row->ip ); ?></small>
					</td>
					<td><span class="aap-badge"><?php echo esc_html( $row->event ); ?></span></td>
					<td><?php echo esc_html( $row->object ); ?></td>
					<td>
						<?php if ( $details ) : ?>
							<details><summary><?php esc_html_e( 'show', 'admin-access-profiles' ); ?></summary>
								<pre><?php echo esc_html( wp_json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
							</details>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<p class="aap-pager">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%', self::url( 'activity', array_filter( $filters ) ) ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						)
					)
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/* --- Settings ------------------------------------------------------ */

	private static function render_settings() {
		$settings = AAP_Settings::get();
		self::form_open( 'aap_owner_settings' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="aap-owner-ids"><?php esc_html_e( 'Owners', 'admin-access-profiles' ); ?></label></th>
				<td>
					<input id="aap-owner-ids" type="text" class="regular-text" name="owner_ids" value="<?php echo esc_attr( implode( ', ', $settings['owner_ids'] ) ); ?>">
					<p class="description">
						<?php esc_html_e( 'User IDs allowed to manage Access Profiles, separated by commas. Leave empty for every administrator. Your own ID is always kept.', 'admin-access-profiles' ); ?>
						<?php
						/* translators: %d: user ID */
						printf( esc_html__( 'Your ID is %d.', 'admin-access-profiles' ), (int) get_current_user_id() );
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Tracking Scripts', 'admin-access-profiles' ); ?></th>
				<td><label><input type="checkbox" name="scripts_skip_admins" value="1" <?php checked( $settings['scripts_skip_admins'] ); ?>> <?php esc_html_e( 'Do not load tracking scripts for logged-in administrators (recommended: code written by others never runs in your browser session).', 'admin-access-profiles' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="aap-retention"><?php esc_html_e( 'Keep activity for', 'admin-access-profiles' ); ?></label></th>
				<td><input id="aap-retention" type="number" min="7" name="log_retention" value="<?php echo (int) $settings['log_retention']; ?>" class="small-text"> <?php esc_html_e( 'days', 'admin-access-profiles' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Page views', 'admin-access-profiles' ); ?></th>
				<td><label><input type="checkbox" name="log_views" value="1" <?php checked( $settings['log_views'] ); ?>> <?php esc_html_e( 'Log every admin screen profile users open (not only changes).', 'admin-access-profiles' ); ?></label></td>
			</tr>
		</table>
		<?php submit_button(); ?>
		</form>
		<?php
	}

	/* --- About --------------------------------------------------------- */

	private static function render_about() {
		?>
		<div class="aap-card aap-about">
			<h2><?php echo esc_html( 'Admin Access Profiles ' . AAP_VERSION ); ?></h2>
			<p><?php esc_html_e( 'Give agencies, freelancers and staff their own wp-admin access without making them administrators.', 'admin-access-profiles' ); ?></p>
			<p>
				<?php
				printf(
					/* translators: %s: author name */
					esc_html__( 'Developed by %s.', 'admin-access-profiles' ),
					'<a href="' . esc_url( AAP_Plugin::GITHUB_URL ) . '" target="_blank" rel="noopener">' . esc_html( AAP_Plugin::AUTHOR ) . '</a>'
				);
				?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( AAP_Plugin::REPO_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Source code, issues & updates on GitHub', 'admin-access-profiles' ); ?></a>
				<a class="button button-primary" href="<?php echo esc_url( AAP_Plugin::SUPPORT_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Support the developer', 'admin-access-profiles' ); ?></a>
			</p>
			<p class="description"><?php esc_html_e( 'Found a plugin that should have its own protection pack? Open an issue on GitHub.', 'admin-access-profiles' ); ?></p>
		</div>
		<?php
	}
}
