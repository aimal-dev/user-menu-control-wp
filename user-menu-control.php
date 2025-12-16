<?php
/**
 * Plugin Name: User Menu Control
 * Description: Admin selects which admin menus each subscriber/editor can see. If nothing allowed, user only sees Dashboard + Profile. Works with any plugin dynamically.
 * Version: 1.0.0
 * Author: Your Name
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class User_Menu_Control {

    const META_ALLOWED_MENUS = '_umc_allowed_menus';
    const META_LOGIN_REDIRECT = '_umc_login_redirect';

    public function __construct() {
        // Settings page
        add_action( 'admin_menu', [ $this, 'add_admin_page' ] );

        // Save handler
        add_action( 'admin_post_umc_save', [ $this, 'handle_form_submit' ] );

        // Apply menu & bar limits
        add_action( 'admin_menu', [ $this, 'limit_admin_menu' ], 999 );
        add_action( 'wp_before_admin_bar_render', [ $this, 'limit_admin_bar' ], 999 );

        // Optional: per-user login redirect
        add_filter( 'login_redirect', [ $this, 'maybe_redirect_after_login' ], 10, 3 );
        
         // Allowed menus ke liye required capability automatically de do
        add_filter( 'user_has_cap', [ $this, 'grant_caps_for_allowed_menus' ], 10, 4 );
    }

    /** ---------- Helpers ---------- */

    protected function get_limited_user() {
        if ( ! function_exists( 'wp_get_current_user' ) ) {
            require_once ABSPATH . 'wp-includes/pluggable.php';
        }

        $user = wp_get_current_user();
        if ( ! $user || ! $user->ID ) {
            return false;
        }

        // CRITICAL FIX: Do NOT use user_can( $user, 'manage_options' ) here.
        // It triggers 'user_has_cap' filter, which calls this function again -> Infinite Loop.
        // Instead, check roles directly.
        if ( in_array( 'administrator', (array) $user->roles, true ) ) {
            return false;
        }

        // Only subscriber/editor
        if ( ! array_intersect( [ 'subscriber', 'editor' ], (array) $user->roles ) ) {
            return false;
        }

        return $user;
    }

    protected function get_allowed_menus_for_user( $user_id ) {
        $val = get_user_meta( $user_id, self::META_ALLOWED_MENUS, true );
        return is_array( $val ) ? $val : [];
    }

    /** ---------- Admin page ---------- */

    public function add_admin_page() {
        add_menu_page(
            'User Menu Control',
            'User Menu Control',
            'manage_options',
            'user-menu-control',
            [ $this, 'render_admin_page' ],
            'dashicons-lock',
            80
        );
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to access this page.' );
        }

        // Collect all current top-level menus (except Dashboard, Profile, separator)
        global $menu;
        // Ensure $menu is populated
        if ( ! is_array( $menu ) ) {
            $menu = [];
        }

        $available_menus = [];
        foreach ( $menu as $item ) {
            $slug = isset( $item[2] ) ? $item[2] : '';
            $title = isset( $item[0] ) ? wp_strip_all_tags( $item[0] ) : '';
            if ( ! $slug || in_array( $slug, [ 'index.php', 'profile.php' ], true ) ) {
                continue;
            }
            if ( 0 === strpos( $slug, 'separator' ) ) {
                continue;
            }
            $available_menus[ $slug ] = $title ?: $slug;
        }
        ksort( $available_menus );

        $users = get_users( [
            'role__in' => [ 'subscriber', 'editor' ],
            'orderby'  => 'login',
            'order'    => 'ASC',
        ] );

        $action_url = admin_url( 'admin-post.php' );
        ?>
<div class="wrap">
    <h1>User Menu Control</h1>
    <p>
        For each subscriber/editor user:<br>
        - If <strong>no menus are allowed</strong> → only <strong>Dashboard + Profile</strong> will be visible.<br>
        - If <strong>1 or more menus are allowed</strong> → Dashboard + Profile along with <strong>only those
            menus</strong> that are checked here (Posts, WPForms, etc.) will be visible.<br>
        When a new plugin is installed, its menu will automatically appear in the list without any code changes.
    </p>

    <form method="post" action="<?php echo esc_url( $action_url ); ?>">
        <?php wp_nonce_field( 'umc_save_settings', 'umc_nonce' ); ?>
        <input type="hidden" name="action" value="umc_save" />

        <?php if ( empty( $users ) ) : ?>
        <p>No subscribers or editors found.</p>
        <?php else : ?>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Role(s)</th>
                    <th>Allowed Menus (in addition to Dashboard & Profile)</th>
                    <th>Login Redirect URL (optional)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $users as $user ) : ?>
                <?php
                            $allowed  = $this->get_allowed_menus_for_user( $user->ID );
                            $redirect = get_user_meta( $user->ID, self::META_LOGIN_REDIRECT, true );
                            ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( $user->user_login ); ?></strong><br />
                        <small><?php echo esc_html( $user->user_email ); ?></small>
                    </td>
                    <td><?php echo esc_html( implode( ', ', $user->roles ) ); ?></td>
                    <td>
                        <div style="max-height:220px;overflow:auto;border:1px solid #ddd;padding:8px;">
                            <?php if ( empty( $available_menus ) ) : ?>
                            <em>No menus found.</em>
                            <?php else : ?>
                            <?php foreach ( $available_menus as $slug => $title ) : ?>
                            <?php $checked = in_array( $slug, $allowed, true ) ? 'checked' : ''; ?>
                            <label style="display:block;margin-bottom:4px;">
                                <input type="checkbox" name="umc_allowed[<?php echo esc_attr( $user->ID ); ?>][]"
                                    value="<?php echo esc_attr( $slug ); ?>" <?php echo $checked; ?> />
                                <?php echo esc_html( $title . " ($slug)" ); ?>
                            </label>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <input type="text" name="umc_redirect[<?php echo esc_attr( $user->ID ); ?>]"
                            value="<?php echo esc_attr( $redirect ); ?>" style="width:100%;"
                            placeholder="/wp-admin/admin.php?page=your-page" />
                        <small>Start with <code>/</code> for internal links (e.g. <code>/wp-admin/...</code>). Domain
                            will be auto-added.</small>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="submit">
            <button type="submit" class="button button-primary">Save Changes</button>
        </p>
        <?php endif; ?>
    </form>
</div>
<?php
    }

    /** ---------- Save ---------- */

    public function handle_form_submit() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'You do not have permission to perform this action.' );
        }

        if ( ! isset( $_POST['umc_nonce'] ) || ! wp_verify_nonce( $_POST['umc_nonce'], 'umc_save_settings' ) ) {
            wp_die( 'Nonce check failed.' );
        }

        $data_allowed  = isset( $_POST['umc_allowed'] ) ? (array) $_POST['umc_allowed'] : [];
        $data_redirect = isset( $_POST['umc_redirect'] ) ? (array) $_POST['umc_redirect'] : [];

        $users = get_users( [
            'role__in' => [ 'subscriber', 'editor' ],
            'fields'   => [ 'ID' ],
        ] );

        foreach ( $users as $user ) {
            $uid = (int) $user->ID;

            // Menus
            if ( isset( $data_allowed[ $uid ] ) ) {
                $allowed = array_map( 'sanitize_text_field', (array) $data_allowed[ $uid ] );
                update_user_meta( $uid, self::META_ALLOWED_MENUS, $allowed );
            } else {
                update_user_meta( $uid, self::META_ALLOWED_MENUS, [] );
            }

            // Redirect
            $raw = isset( $data_redirect[ $uid ] ) ? trim( $data_redirect[ $uid ] ) : '';
            
            // Logic: Agar user ne relative path diya (start with /) to usme site_url() jod do
            if ( $raw && strpos( $raw, '/' ) === 0 ) {
                $raw = site_url( $raw );
            }
            
            $url = $raw ? esc_url_raw( $raw ) : '';
            if ( $url ) {
                update_user_meta( $uid, self::META_LOGIN_REDIRECT, $url );
            } else {
                delete_user_meta( $uid, self::META_LOGIN_REDIRECT );
            }
        }

        wp_redirect(
            add_query_arg(
                [ 'page' => 'user-menu-control', 'updated' => 'true' ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    /** ---------- Runtime: menu & redirect ---------- */

    public function limit_admin_menu() {
        if ( ! is_admin() ) {
            return;
        }

        $user = $this->get_limited_user();
        if ( ! $user ) {
            return;
        }

        $allowed_menus = $this->get_allowed_menus_for_user( $user->ID );

        // Always keep Dashboard + Profile
        $keep_slugs = [ 'index.php', 'profile.php' ];

        // Agar admin ne extra menus allow kiye hain, unko bhi list me add karo
        if ( ! empty( $allowed_menus ) ) {
            $keep_slugs = array_merge( $keep_slugs, $allowed_menus );
        }

        global $menu;

        foreach ( (array) $menu as $item ) {
            $slug = isset( $item[2] ) ? $item[2] : '';
            if ( ! $slug ) {
                continue;
            }
            if ( 0 === strpos( $slug, 'separator' ) ) {
                remove_menu_page( $slug );
                continue;
            }
            if ( ! in_array( $slug, $keep_slugs, true ) ) {
                remove_menu_page( $slug );
            }
        }
    }

    public function limit_admin_bar() {
        $user = $this->get_limited_user();
        if ( ! $user ) {
            return;
        }

        global $wp_admin_bar;
        if ( ! is_object( $wp_admin_bar ) ) {
            return;
        }

        // Simple clean: sirf account/ logout waghera chhoro
        $keep = [ 'top-secondary', 'my-account', 'user-info', 'logout' ];

        foreach ( (array) $wp_admin_bar->get_nodes() as $node ) {
            if ( ! in_array( $node->id, $keep, true ) ) {
                $wp_admin_bar->remove_node( $node->id );
            }
        }
    }

    public function maybe_redirect_after_login( $redirect_to, $request, $user ) {
        if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) ) {
            return $redirect_to;
        }

        if ( user_can( $user, 'manage_options' ) ) {
            return $redirect_to; // admin unaffected
        }

        if ( ! array_intersect( [ 'subscriber', 'editor' ], (array) $user->roles ) ) {
            return $redirect_to;
        }

        $url = get_user_meta( $user->ID, self::META_LOGIN_REDIRECT, true );
        return $url ? $url : $redirect_to;
    }
        /**
     * Jis menu ko admin ne allow kiya hai, uska required capability
     * subscriber/editor ko automatically de do, taa-ke Elementor, WPForms
     * jaisay plugins bhi visible ho jayein.
     */
    public function grant_caps_for_allowed_menus( $allcaps, $caps, $args, $user ) {
        // Sirf admin area me aur sirf limited users ke liye
        if ( ! is_admin() ) {
            return $allcaps;
        }

        $limited = $this->get_limited_user();
        if ( ! $limited || (int) $limited->ID !== (int) $user->ID ) {
            return $allcaps;
        }

        // Is user ke liye allowed menus (Dashboard/Profile ke ilawa)
        $allowed_menus = $this->get_allowed_menus_for_user( $user->ID );
        if ( empty( $allowed_menus ) ) {
            // Kuch extra menu allow nahi -> koi extra cap mat do
            return $allcaps;
        }

        // Har allowed menu ka required capability nikaal lo
        global $menu;
        $needed_caps = [];

        foreach ( (array) $menu as $item ) {
            $slug = isset( $item[2] ) ? $item[2] : '';
            $cap  = isset( $item[1] ) ? $item[1] : '';
            if ( $slug && $cap && in_array( $slug, $allowed_menus, true ) ) {
                $needed_caps[ $cap ] = true;
            }
        }

        // Jo caps WordPress is waqt check kar raha hai, unme se
        // agar koi hamare needed_caps me hai to usko force allow kar do.
        foreach ( $caps as $cap ) {
            if ( isset( $needed_caps[ $cap ] ) ) {
                $allcaps[ $cap ] = true;
            }
        }

        return $allcaps;
    }
}

new User_Menu_Control();