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

        $all_users = get_users( [
            'role__in' => [ 'subscriber', 'editor' ],
            'orderby'  => 'login',
            'order'    => 'ASC',
        ] );

        // Group users by role for easier access
        $users_by_role = [
            'subscriber' => [],
            'editor' => []
        ];
        foreach ( $all_users as $user ) {
            $user_role = ! empty( $user->roles ) ? $user->roles[0] : 'subscriber';
            $users_by_role[ $user_role ][] = $user;
        }

        $action_url = admin_url( 'admin-post.php' );
        ?>
<style>
.umc-container {
    max-width: 1200px;
    margin: 20px 0;
    background: #fff;
    padding: 30px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.umc-header {
    margin-bottom: 30px;
}

.umc-header h1 {
    color: #1e293b;
    font-size: 28px;
    margin-bottom: 10px;
}

.umc-header p {
    color: #64748b;
    font-size: 14px;
    line-height: 1.6;
}

.umc-selectors {
    margin-bottom: 30px;
    padding: 20px;
    background: #f8fafc;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
}

.umc-selector-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 15px;
}

.umc-selector-row:last-child {
    margin-bottom: 0;
}

.umc-selector-group {
    display: flex;
    flex-direction: column;
}

.umc-selector-group label {
    display: block;
    font-weight: 600;
    color: #334155;
    margin-bottom: 10px;
    font-size: 15px;
}

.umc-selector-group select {
    width: 100%;
    padding: 10px 15px;
    font-size: 15px;
    border: 2px solid #cbd5e1;
    border-radius: 6px;
    background: #fff;
    color: #1e293b;
    cursor: pointer;
    transition: all 0.2s;
}

.umc-selector-group select:focus {
    outline: none;
    border-color: #0073aa;
    box-shadow: 0 0 0 3px rgba(0, 115, 170, 0.1);
}

.umc-selector-group select:disabled {
    background: #f1f5f9;
    cursor: not-allowed;
    opacity: 0.6;
}

.umc-user-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    transition: all 0.2s;
    display: none;
}

.umc-user-card.active {
    display: block;
}

.umc-user-card:hover {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    border-color: #cbd5e1;
}

.umc-user-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f1f5f9;
}

.umc-user-info h3 {
    margin: 0 0 5px 0;
    color: #1e293b;
    font-size: 18px;
}

.umc-user-info .email {
    color: #64748b;
    font-size: 13px;
}

.umc-user-role {
    background: #0073aa;
    color: #fff;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 600;
}

.umc-menus-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.umc-menu-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 15px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    transition: all 0.2s;
}

.umc-menu-item:hover {
    background: #f1f5f9;
}

.umc-menu-label {
    font-size: 14px;
    color: #334155;
    font-weight: 500;
    flex: 1;
}

/* Toggle Switch */
.umc-toggle {
    position: relative;
    display: inline-block;
    width: 48px;
    height: 24px;
}

.umc-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}

.umc-toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #cbd5e1;
    transition: .3s;
    border-radius: 24px;
}

.umc-toggle-slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}

.umc-toggle input:checked+.umc-toggle-slider {
    background-color: #00a32a;
}

.umc-toggle input:checked+.umc-toggle-slider:before {
    transform: translateX(24px);
}

.umc-redirect-section {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 2px solid #f1f5f9;
}

.umc-redirect-section label {
    display: block;
    font-weight: 600;
    color: #334155;
    margin-bottom: 8px;
    font-size: 14px;
}

.umc-redirect-section input {
    width: 100%;
    padding: 10px 15px;
    border: 2px solid #e2e8f0;
    border-radius: 6px;
    font-size: 14px;
    transition: all 0.2s;
}

.umc-redirect-section input:focus {
    outline: none;
    border-color: #0073aa;
    box-shadow: 0 0 0 3px rgba(0, 115, 170, 0.1);
}

.umc-redirect-section small {
    display: block;
    margin-top: 6px;
    color: #64748b;
    font-size: 12px;
}

.umc-save-btn {
    background: #0073aa !important;
    border-color: #0073aa !important;
    color: #fff !important;
    padding: 12px 30px !important;
    font-size: 15px !important;
    font-weight: 600 !important;
    border-radius: 6px !important;
    cursor: pointer !important;
    transition: all 0.2s !important;
    box-shadow: 0 2px 4px rgba(0, 115, 170, 0.2) !important;
}

.umc-save-btn:hover {
    background: #005a87 !important;
    border-color: #005a87 !important;
    box-shadow: 0 4px 8px rgba(0, 115, 170, 0.3) !important;
}

.umc-no-selection {
    text-align: center;
    padding: 40px;
    color: #64748b;
    font-size: 15px;
    background: #f8fafc;
    border-radius: 6px;
    border: 1px dashed #cbd5e1;
}
</style>

<div class="wrap">
    <div class="umc-container">
        <div class="umc-header">
            <h1>Menu Visibility Control</h1>
            <p>Control which admin menus are visible to subscribers and editors. Dashboard and Profile are always
                visible.</p>
        </div>

        <form method="post" action="<?php echo esc_url( $action_url ); ?>" id="umc-form">
            <?php wp_nonce_field( 'umc_save_settings', 'umc_nonce' ); ?>
            <input type="hidden" name="action" value="umc_save" />

            <div class="umc-selectors">
                <div class="umc-selector-row">
                    <div class="umc-selector-group">
                        <label for="umc-role-filter">Select User Role</label>
                        <select id="umc-role-filter">
                            <option value="">-- Select Role --</option>
                            <option value="subscriber">Subscriber (<?php echo count($users_by_role['subscriber']); ?>)
                            </option>
                            <option value="editor">Editor (<?php echo count($users_by_role['editor']); ?>)</option>
                        </select>
                    </div>
                    <div class="umc-selector-group">
                        <label for="umc-user-filter">Select User</label>
                        <select id="umc-user-filter" disabled>
                            <option value="">-- Select a role first --</option>
                        </select>
                    </div>
                </div>
            </div>

            <div id="umc-users-container">
                <?php if ( empty( $all_users ) ) : ?>
                <div class="umc-no-selection">No subscribers or editors found.</div>
                <?php else : ?>
                <div class="umc-no-selection" id="umc-no-selection">
                    👆 Please select a role and user from the dropdowns above
                </div>
                <?php foreach ( $all_users as $user ) : ?>
                <?php
                                $allowed  = $this->get_allowed_menus_for_user( $user->ID );
                                $redirect = get_user_meta( $user->ID, self::META_LOGIN_REDIRECT, true );
                                $user_role = ! empty( $user->roles ) ? $user->roles[0] : 'subscriber';
                                ?>
                <div class="umc-user-card" data-role="<?php echo esc_attr( $user_role ); ?>"
                    data-user-id="<?php echo esc_attr( $user->ID ); ?>">
                    <div class="umc-user-header">
                        <div class="umc-user-info">
                            <h3><?php echo esc_html( $user->display_name ); ?></h3>
                            <span class="email"><?php echo esc_html( $user->user_email ); ?></span>
                        </div>
                        <span class="umc-user-role"><?php echo esc_html( ucfirst( $user_role ) ); ?></span>
                    </div>

                    <?php if ( ! empty( $available_menus ) ) : ?>
                    <div class="umc-menus-grid">
                        <?php foreach ( $available_menus as $slug => $title ) : ?>
                        <?php $checked = in_array( $slug, $allowed, true ) ? 'checked' : ''; ?>
                        <div class="umc-menu-item">
                            <span class="umc-menu-label"><?php echo esc_html( $title ); ?></span>
                            <label class="umc-toggle">
                                <input type="checkbox" name="umc_allowed[<?php echo esc_attr( $user->ID ); ?>][]"
                                    value="<?php echo esc_attr( $slug ); ?>" <?php echo $checked; ?> />
                                <span class="umc-toggle-slider"></span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="umc-redirect-section">
                        <label>Login Redirect URL (Optional)</label>
                        <input type="text" name="umc_redirect[<?php echo esc_attr( $user->ID ); ?>]"
                            value="<?php echo esc_attr( $redirect ); ?>"
                            placeholder="/wp-admin/admin.php?page=your-page" />
                        <small>Start with <code>/</code> for internal links. Domain will be auto-added.</small>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="umc-save-container" style="display: none;">
                <p class="submit">
                    <button type="submit" class="button button-primary umc-save-btn">Save Changes</button>
                </p>
            </div>
        </form>
    </div>
</div>

<script>
(function($) {
    $(document).ready(function() {
        // User data organized by role
        var usersByRole = <?php echo json_encode( array_map( function( $users ) {
                    return array_map( function( $user ) {
                        return [
                            'id' => $user->ID,
                            'name' => $user->display_name,
                            'email' => $user->user_email
                        ];
                    }, $users );
                }, $users_by_role ) ); ?>;

        // When role is selected, populate user dropdown
        $('#umc-role-filter').on('change', function() {
            var selectedRole = $(this).val();
            var $userSelect = $('#umc-user-filter');

            // Reset user dropdown
            $userSelect.html('<option value="">-- Select User --</option>');

            if (selectedRole && usersByRole[selectedRole]) {
                // Populate users for this role
                $.each(usersByRole[selectedRole], function(index, user) {
                    $userSelect.append(
                        $('<option></option>')
                        .val(user.id)
                        .text(user.name + ' (' + user.email + ')')
                    );
                });
                $userSelect.prop('disabled', false);
            } else {
                $userSelect.html('<option value="">-- Select a role first --</option>');
                $userSelect.prop('disabled', true);
            }

            // Hide all cards and show no selection message
            $('.umc-user-card').removeClass('active');
            $('#umc-no-selection').show();
            $('#umc-save-container').hide();
        });

        // When user is selected, show their card
        $('#umc-user-filter').on('change', function() {
            var selectedUserId = $(this).val();

            if (selectedUserId) {
                // Hide all cards
                $('.umc-user-card').removeClass('active');
                $('#umc-no-selection').hide();

                // Show selected user's card
                $('.umc-user-card[data-user-id="' + selectedUserId + '"]').addClass('active');
                $('#umc-save-container').show();
            } else {
                $('.umc-user-card').removeClass('active');
                $('#umc-no-selection').show();
                $('#umc-save-container').hide();
            }
        });
    });
})(jQuery);
</script>
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