<?php
/**
 * Plugin Name: The Growth Hub Portal & Dashboard
 * Plugin URI: https://thegrowthhub.africa
 * Description: Custom responsive role-based dashboard for The Growth Hub (Students, Offline Learners, Mentors, and Admins) integrated with Tutor LMS.
 * Version: 1.1.3
 * Author: Adeleye Shina
 * Text Domain: growth-hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GH_PORTAL_VERSION', '1.1.3' );
define( 'GH_PORTAL_PATH', plugin_dir_path( __FILE__ ) );
define( 'GH_PORTAL_URL', plugin_dir_url( __FILE__ ) );

/**
 * Canonical Growth Hub Dashboard URL Resolver
 * Guarantees all portal navigation stays on the dedicated /dashboard/ page
 * and smoothly switches tabs via ?tab=<tab_slug> without triggering Tutor LMS redirects or 404s.
 *
 * @param string $tab   Optional tab/endpoint slug (e.g. 'my-courses', 'mentees', 'grading').
 * @param array  $extra Optional query parameters (e.g. array('view' => 'manager')).
 * @return string Full URL to the requested portal tab.
 */
if ( ! function_exists( 'gh_portal_dashboard_url' ) ) {
    function gh_portal_dashboard_url( $tab = '', $extra = array() ) {
        static $dash_base = null;
        if ( is_null( $dash_base ) ) {
            $dash_page = get_page_by_path( 'dashboard' );
            if ( $dash_page ) {
                $dash_base = get_permalink( $dash_page->ID );
            } elseif ( is_page() ) {
                $current_post = get_post();
                if ( $current_post && has_shortcode( $current_post->post_content, 'growth_hub_dashboard' ) ) {
                    $dash_base = get_permalink( $current_post->ID );
                }
            }
            if ( empty( $dash_base ) ) {
                $dash_base = home_url( '/dashboard/' );
            }
            $dash_base = strtok( $dash_base, '?' );
            $dash_base = trailingslashit( $dash_base );
        }

        if ( empty( $tab ) || 'index' === $tab || 'overview' === $tab || 'dashboard' === $tab ) {
            return empty( $extra ) ? $dash_base : add_query_arg( $extra, $dash_base );
        }

        $args = array_merge( array( 'tab' => $tab ), $extra );
        return add_query_arg( $args, $dash_base );
    }
}

// Load includes
require_once GH_PORTAL_PATH . 'includes/class-roles.php';
require_once GH_PORTAL_PATH . 'includes/class-class-log.php';
require_once GH_PORTAL_PATH . 'includes/class-checkin.php';

final class Growth_Hub_Portal {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Enqueue Assets
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // Auth redirects: Login & Protected pages
        add_action( 'template_redirect', array( $this, 'handle_auth_redirects' ) );

        // Force all login redirects (WordPress, Tutor LMS, WooCommerce) directly to Growth Hub Dashboard
        add_filter( 'login_redirect', array( $this, 'custom_login_redirect' ), 999, 3 );
        add_filter( 'tutor_after_login_redirect_url', array( $this, 'custom_tutor_login_redirect' ), 999, 2 );
        add_filter( 'woocommerce_login_redirect', array( $this, 'custom_tutor_login_redirect' ), 999, 2 );
        add_filter( 'tutor_dashboard_url', array( $this, 'filter_tutor_dashboard_url' ), 999 );
        add_filter( 'show_admin_bar', array( $this, 'control_admin_bar' ), 999 );

        // Register custom endpoints in Tutor LMS
        add_filter( 'tutor_dashboard/nav_items', array( $this, 'register_tutor_nav_items' ), 50 );
        add_filter( 'tutor_dashboard/nav_item_template', array( $this, 'route_custom_nav_templates' ), 50, 2 );

        // Intercept Tutor LMS dashboard template loading to serve Growth Hub shell
        add_filter( 'tutor_load_template', array( $this, 'intercept_tutor_dashboard_template' ), 99, 2 );

        // Automatically map [tutor_dashboard] to Growth Hub Portal at priority 99
        add_action( 'init', array( $this, 'register_dashboard_shortcodes' ), 99 );

        // Shortcode fallback for standalone dashboard page: [growth_hub_dashboard]
        add_shortcode( 'growth_hub_dashboard', array( $this, 'render_dashboard_shortcode' ) );
        add_shortcode( 'growth_hub_cohort_delivery', array( $this, 'render_cohort_delivery_shortcode' ) );
        add_shortcode( 'growth_hub_student_checkin', array( $this, 'render_student_checkin_shortcode' ) );
        add_shortcode( 'growth_hub_weekly_report', array( $this, 'render_weekly_report_shortcode' ) );
        add_shortcode( 'growth_hub_concern_report', array( $this, 'render_concern_report_shortcode' ) );
        add_shortcode( 'growth_hub_live_classes', array( $this, 'render_live_classes_shortcode' ) );
        add_shortcode( 'growth_hub_announcements', array( $this, 'render_announcements_shortcode' ) );
        add_shortcode( 'growth_hub_discussions', array( $this, 'render_discussions_shortcode' ) );
        add_shortcode( 'growth_hub_grading', array( $this, 'render_grading_shortcode' ) );
        add_shortcode( 'growth_hub_mentees', array( $this, 'render_mentees_shortcode' ) );
        add_shortcode( 'growth_hub_my_courses', array( $this, 'render_my_courses_shortcode' ) );
        add_shortcode( 'growth_hub_certificates', array( $this, 'render_certificates_shortcode' ) );
        add_shortcode( 'growth_hub_my_certificates', array( $this, 'render_certificates_shortcode' ) );
        add_shortcode( 'growth_hub_earnings', array( $this, 'render_earnings_shortcode' ) );
        add_shortcode( 'growth_hub_earning', array( $this, 'render_earnings_shortcode' ) );

        // Live Classes AJAX handlers
        add_action( 'wp_ajax_gh_save_live_class', array( $this, 'handle_save_live_class_ajax' ) );
        add_action( 'wp_ajax_gh_delete_live_class', array( $this, 'handle_delete_live_class_ajax' ) );

        // Announcements AJAX handlers
        add_action( 'wp_ajax_gh_save_announcement', array( $this, 'handle_save_announcement_ajax' ) );
        add_action( 'wp_ajax_gh_delete_announcement', array( $this, 'handle_delete_announcement_ajax' ) );

        // Discussions & Q&A AJAX handlers
        add_action( 'wp_ajax_gh_submit_qa_reply', array( $this, 'handle_submit_qa_reply_ajax' ) );
        add_action( 'wp_ajax_gh_submit_grade', array( $this, 'handle_submit_grade_ajax' ) );

        // Weekly Report & Concern Report AJAX handlers
        add_action( 'wp_ajax_gh_submit_weekly_report', array( $this, 'handle_weekly_report_ajax' ) );
        add_action( 'wp_ajax_gh_submit_concern_report', array( $this, 'handle_concern_report_ajax' ) );
    }

    public function enqueue_assets() {
        // Only load on dashboard pages or shortcode page
        if ( is_page() || ( function_exists( 'tutor_utils' ) && tutor_utils()->is_tutor_dashboard() ) ) {
            // Google Fonts: Poppins (headings) and Lato (body)
            wp_enqueue_style(
                'gh-google-fonts',
                'https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,300;0,400;0,700;0,900;1,400&family=Poppins:wght@500;600;700;800&display=swap',
                array(),
                null
            );

            // Dashboard Master CSS
            wp_enqueue_style(
                'gh-portal-dashboard',
                GH_PORTAL_URL . 'assets/css/dashboard.css',
                array( 'gh-google-fonts' ),
                GH_PORTAL_VERSION
            );

            // Class Log Specific CSS
            wp_enqueue_style(
                'gh-portal-class-log',
                GH_PORTAL_URL . 'assets/css/class-log.css',
                array( 'gh-portal-dashboard' ),
                GH_PORTAL_VERSION
            );

            // Dashboard Master JS
            wp_enqueue_script(
                'gh-portal-dashboard-js',
                GH_PORTAL_URL . 'assets/js/dashboard.js',
                array( 'jquery' ),
                GH_PORTAL_VERSION,
                true
            );

            // Class Log JS
            wp_enqueue_script(
                'gh-portal-class-log-js',
                GH_PORTAL_URL . 'assets/js/class-log.js',
                array( 'jquery', 'gh-portal-dashboard-js' ),
                GH_PORTAL_VERSION,
                true
            );

            wp_localize_script( 'gh-portal-dashboard-js', 'ghPortalData', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'gh_portal_nonce' ),
            ) );
        }
    }

    /**
     * Intercept and customize Tutor LMS dashboard nav items
     */
    public function register_tutor_nav_items( $items ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return $items;
        }

        $user_roles = GH_Portal_Roles::get_user_roles( $user_id );
        $is_instructor = in_array( 'tutor_instructor', $user_roles, true ) || current_user_can( 'administrator' );

        // If viewing as Student (or regular student user)
        if ( ! $is_instructor || ( isset( $_GET['view'] ) && 'student' === $_GET['view'] ) ) {
            return array(
                'index' => array(
                    'title' => __( 'Dashboard', 'growth-hub' ),
                    'icon'  => 'overview',
                ),
                'my-courses' => array(
                    'title' => __( 'My Courses', 'growth-hub' ),
                    'icon'  => 'book',
                ),
                'weekly-check-in' => array(
                    'title' => __( 'Weekly Check-in', 'growth-hub' ),
                    'icon'  => 'check-circle',
                ),
                'assignments' => array(
                    'title' => __( 'Assignments', 'growth-hub' ),
                    'icon'  => 'edit',
                ),
                'my-certificates' => array(
                    'title' => __( 'Certificates', 'growth-hub' ),
                    'icon'  => 'award',
                ),
                'talent-profile' => array(
                    'title' => __( 'Talent Profile', 'growth-hub' ),
                    'icon'  => 'users',
                ),
                'purchase_history' => array(
                    'title' => __( 'Payments', 'growth-hub' ),
                    'icon'  => 'wallet',
                ),
            );
        }

        if ( $is_instructor ) {
            // Build instructor nav matching Figma
            $custom_items = array(
                'index' => array(
                    'title' => __( 'Overview', 'growth-hub' ),
                    'icon'  => 'overview',
                ),
                'my-courses' => array(
                    'title' => __( 'My Courses', 'growth-hub' ),
                    'icon'  => 'book',
                ),
                'mentees' => array(
                    'title' => __( 'Mentees & Students', 'growth-hub' ),
                    'icon'  => 'users',
                ),
                'live-classes' => array(
                    'title' => __( 'Live Classes', 'growth-hub' ),
                    'icon'  => 'video',
                ),
                'class-log' => array(
                    'title' => __( 'Class Log', 'growth-hub' ),
                    'icon'  => 'edit',
                ),
                'announcements' => array(
                    'title' => __( 'Announcements', 'growth-hub' ),
                    'icon'  => 'bullhorn',
                ),
                'question-answer' => array(
                    'title' => __( 'Discussions (Q&A)', 'growth-hub' ),
                    'icon'  => 'comments',
                ),
                'grading' => array(
                    'title' => __( 'Grading', 'growth-hub' ),
                    'icon'  => 'check-circle',
                ),
                'my-certificates' => array(
                    'title' => __( 'Certificates', 'growth-hub' ),
                    'icon'  => 'award',
                ),
                'analytics' => array(
                    'title' => __( 'Analytics', 'growth-hub' ),
                    'icon'  => 'chart',
                ),
                'weekly-report' => array(
                    'title' => __( 'Weekly Report', 'growth-hub' ),
                    'icon'  => 'file-text',
                ),
                'earning' => array(
                    'title' => __( 'Earnings', 'growth-hub' ),
                    'icon'  => 'wallet',
                ),
            );

            return $custom_items;
        }

        return $items;
    }

    /**
     * Route custom template endpoints
     */
    public function route_custom_nav_templates( $template, $endpoint ) {
        if ( 'index' === $endpoint || empty( $endpoint ) || 'dashboard' === $endpoint || 'overview' === $endpoint ) {
            return GH_PORTAL_PATH . 'templates/tutor/overview.php';
        }
        if ( 'cohort-delivery' === $endpoint || 'cohort_delivery' === $endpoint ) {
            return GH_PORTAL_PATH . 'templates/manager/cohort-delivery.php';
        }
        if ( 'weekly-report' === $endpoint || 'weekly_report' === $endpoint ) {
            return GH_PORTAL_PATH . 'templates/tutor/weekly-report.php';
        }
        if ( 'concern-report' === $endpoint || 'report-concern' === $endpoint ) {
            return GH_PORTAL_PATH . 'templates/tutor/concern-report.php';
        }
        if ( 'weekly-check-in' === $endpoint || 'weekly-checkin' === $endpoint ) {
            return GH_PORTAL_PATH . 'templates/student/weekly-checkin.php';
        }
        if ( 'class-log' === $endpoint ) {
            return GH_PORTAL_PATH . 'templates/tutor/class-log.php';
        }
        if ( 'live-classes' === $endpoint || 'live_classes' === $endpoint ) {
            return GH_PORTAL_PATH . 'templates/tutor/live-classes.php';
        }
        if ( 'announcements' === $endpoint ) {
            return GH_PORTAL_PATH . 'templates/tutor/announcements.php';
        }
        if ( 'question-answer' === $endpoint || 'discussions' === $endpoint ) {
            return GH_PORTAL_PATH . 'templates/tutor/discussions.php';
        }
        if ( 'grading' === $endpoint || 'grades' === $endpoint ) {
            return GH_PORTAL_PATH . 'templates/tutor/grading.php';
        }
        if ( 'mentees' === $endpoint || 'students' === $endpoint ) {
            return GH_PORTAL_PATH . 'templates/tutor/mentees.php';
        }
        if ( 'my-courses' === $endpoint || 'my_courses' === $endpoint || 'courses' === $endpoint ) {
            return GH_PORTAL_PATH . 'templates/tutor/my-courses.php';
        }
        if ( 'my-certificates' === $endpoint || 'certificates' === $endpoint ) {
            return GH_PORTAL_PATH . 'templates/tutor/my-certificates.php';
        }
        if ( 'analytics' === $endpoint ) {
            return GH_PORTAL_PATH . 'templates/tutor/analytics.php';
        }
        if ( 'earning' === $endpoint || 'earnings' === $endpoint ) {
            return GH_PORTAL_PATH . 'templates/tutor/earnings.php';
        }
        if ( 'check-in' === $endpoint || 'checkin' === $endpoint ) {
            return GH_PORTAL_PATH . 'templates/learner/checkin.php';
        }
        return $template;
    }

    /**
     * Intercept Tutor LMS dashboard template loading to serve the Growth Hub shell
     */
    public function intercept_tutor_dashboard_template( $template_location, $template_name ) {
        if ( 'dashboard' === $template_name ) {
            return GH_PORTAL_PATH . 'templates/dashboard-shell.php';
        }
        return $template_location;
    }

    public function control_admin_bar( $show ) {
        if ( is_admin() ) {
            return true;
        }
        if ( $this->is_dashboard_request() ) {
            return false;
        }
        return $show;
    }

    public function is_dashboard_request() {
        if ( is_admin() ) {
            return false;
        }
        if ( is_page( array( 'dashboard', 'my-courses', 'growth-hub-dashboard' ) ) ) {
            return true;
        }
        if ( function_exists( 'tutor_utils' ) && tutor_utils()->is_tutor_dashboard() ) {
            return true;
        }
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        if ( false !== strpos( $uri, '/dashboard' ) || false !== strpos( $uri, '/my-courses' ) ) {
            return true;
        }
        global $post;
        if ( is_a( $post, 'WP_Post' ) && ( has_shortcode( $post->post_content, 'growth_hub_dashboard' ) || has_shortcode( $post->post_content, 'tutor_dashboard' ) ) ) {
            return true;
        }
        return false;
    }

    /**
     * Ensure Tutor LMS always recognizes the Growth Hub dashboard page
     */
    public function filter_tutor_dashboard_url( $url ) {
        return gh_portal_dashboard_url();
    }

    /**
     * Redirect users to the Growth Hub Dashboard upon login (WordPress Core)
     */
    public function custom_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
        if ( is_a( $user, 'WP_User' ) && in_array( 'administrator', (array) $user->roles, true ) ) {
            if ( ! empty( $requested_redirect_to ) && false !== strpos( $requested_redirect_to, 'wp-admin' ) ) {
                return $requested_redirect_to;
            }
        }
        if ( ! empty( $requested_redirect_to ) && false === strpos( $requested_redirect_to, 'wp-login' ) && false === strpos( $requested_redirect_to, 'my-courses' ) && false === strpos( $requested_redirect_to, 'login' ) ) {
            return $requested_redirect_to;
        }
        return gh_portal_dashboard_url();
    }

    /**
     * Tutor LMS & WooCommerce login redirect filter
     */
    public function custom_tutor_login_redirect( $redirect_to, $user = null ) {
        return gh_portal_dashboard_url();
    }

    /**
     * Handle redirection for login and protected portal pages
     *
     * Rules:
     * - Logged-in users visiting /login/ are safely redirected to /dashboard/
     * - Logged-in users visiting /my-courses/ (or any default Tutor LMS route) redirect immediately to /dashboard/
     * - Logged-out users visiting protected pages (dashboard, my-courses, or tutor dashboard) are safely redirected to /login/
     * - Preserves Elementor editing / preview modes without interference
     */
    public function handle_auth_redirects() {
        // Don't interfere with editing pages in Elementor
        if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['preview_id'] ) ) {
            return;
        }
        if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->editor ) && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
            return;
        }

        // 1. If user is logged in:
        if ( is_user_logged_in() ) {
            if ( is_page( 'login' ) ) {
                $dashboard_url = gh_portal_dashboard_url();
                wp_safe_redirect( $dashboard_url );
                exit;
            }

            // If logged-in user visits my-courses (Tutor LMS default) or any subpath, redirect seamlessly to the Growth Hub dashboard
            $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
            if ( is_page( 'my-courses' ) || false !== strpos( $request_uri, '/my-courses' ) ) {
                $tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : '';
                $target_url = gh_portal_dashboard_url( $tab, $_GET );
                wp_safe_redirect( $target_url );
                exit;
            }
            return;
        }

        // 2. If user is logged out, protect dashboard and portal pages
        $protected_pages = array( 'my-courses', 'dashboard', 'growth-hub-dashboard' );
        $is_protected    = is_page( $protected_pages );

        // Also protect if Tutor LMS identifies the current page as the Tutor dashboard
        if ( ! $is_protected && function_exists( 'tutor_utils' ) && tutor_utils()->is_tutor_dashboard() ) {
            if ( ! is_page( 'login' ) ) {
                $is_protected = true;
            }
        }

        if ( $is_protected ) {
            wp_safe_redirect( home_url( '/login/' ) );
            exit;
        }
    }

    /**
     * Ensure user is logged in for shortcode renderings.
     * Redirects immediately if headers not yet sent, or injects a seamless client-side redirect.
     */
    private function ensure_user_logged_in() {
        if ( ! is_user_logged_in() ) {
            if ( ! headers_sent() ) {
                wp_safe_redirect( home_url( '/login/' ) );
                exit;
            }
            return '<script>window.location.href = "' . esc_url( home_url( '/login/' ) ) . '";</script><div class="gh-login-notice" style="text-align:center; padding:50px 20px; font-family:\'Poppins\',sans-serif;"><p style="font-size:15px; color:#1B1240;">Redirecting to <a href="' . esc_url( home_url( '/login/' ) ) . '" style="color:#632FD1; font-weight:600;">login</a>...</p></div>';
        }
        return true;
    }

    /**
     * Map [tutor_dashboard] and [growth_hub_dashboard]
     */
    public function register_dashboard_shortcodes() {
        add_shortcode( 'tutor_dashboard', array( $this, 'render_dashboard_shortcode' ) );
        add_shortcode( 'growth_hub_dashboard', array( $this, 'render_dashboard_shortcode' ) );
    }

    /**
     * Standalone shortcode [growth_hub_dashboard]
     */
    public function render_dashboard_shortcode( $atts ) {
        $auth_check = $this->ensure_user_logged_in();
        if ( true !== $auth_check ) {
            return $auth_check;
        }

        ob_start();
        include GH_PORTAL_PATH . 'templates/dashboard-shell.php';
        return ob_get_clean();
    }

    /**
     * Standalone shortcode [growth_hub_cohort_delivery]
     */
    public function render_cohort_delivery_shortcode( $atts ) {
        $auth_check = $this->ensure_user_logged_in();
        if ( true !== $auth_check ) {
            return $auth_check;
        }

        ob_start();
        include GH_PORTAL_PATH . 'templates/manager/cohort-delivery.php';
        return ob_get_clean();
    }

    /**
     * Standalone shortcode [growth_hub_student_checkin]
     */
    public function render_student_checkin_shortcode( $atts ) {
        $auth_check = $this->ensure_user_logged_in();
        if ( true !== $auth_check ) {
            return $auth_check;
        }

        ob_start();
        include GH_PORTAL_PATH . 'templates/student/weekly-checkin.php';
        return ob_get_clean();
    }

    /**
     * Standalone shortcode [growth_hub_weekly_report]
     */
    public function render_weekly_report_shortcode( $atts ) {
        $auth_check = $this->ensure_user_logged_in();
        if ( true !== $auth_check ) {
            return $auth_check;
        }

        ob_start();
        include GH_PORTAL_PATH . 'templates/tutor/weekly-report.php';
        return ob_get_clean();
    }

    /**
     * AJAX handler for Tutor Weekly Report submission
     */
    public function handle_weekly_report_ajax() {
        check_ajax_referer( 'gh_portal_nonce', 'gh_report_nonce' );

        $user_id   = get_current_user_id();
        $course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;
        $week_num  = isset( $_POST['week_number'] ) ? absint( $_POST['week_number'] ) : 1;
        $action    = isset( $_POST['submit_action'] ) ? sanitize_text_field( $_POST['submit_action'] ) : 'publish';

        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in to submit a report.', 'growth-hub' ) ) );
        }

        $highlights      = isset( $_POST['highlights'] ) ? sanitize_textarea_field( wp_unslash( $_POST['highlights'] ) ) : '';
        $challenges      = isset( $_POST['challenges'] ) ? sanitize_textarea_field( wp_unslash( $_POST['challenges'] ) ) : '';
        $grading_status  = isset( $_POST['grading_status'] ) ? sanitize_text_field( wp_unslash( $_POST['grading_status'] ) ) : '';
        $grading_due     = isset( $_POST['grading_due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['grading_due_date'] ) ) : '';
        $manager_support = isset( $_POST['manager_support_request'] ) ? sanitize_text_field( wp_unslash( $_POST['manager_support_request'] ) ) : 'None this week';

        $learners_raw    = isset( $_POST['learners_needing_support'] ) ? sanitize_text_field( wp_unslash( $_POST['learners_needing_support'] ) ) : '';
        $learners_arr    = array_filter( array_map( 'trim', explode( ',', $learners_raw ) ) );

        $current_ts         = current_time( 'timestamp' );
        $friday_deadline_ts = strtotime( 'this week friday 17:00:00', $current_ts );
        $is_late            = ( $current_ts > $friday_deadline_ts );

        $report_data = array(
            'user_id'         => $user_id,
            'course_id'       => $course_id,
            'week_number'     => $week_num,
            'highlights'      => $highlights,
            'challenges'      => $challenges,
            'grading_status'  => $grading_status,
            'grading_due'     => $grading_due,
            'manager_support' => $manager_support,
            'learners'        => $learners_arr,
            'status'          => $action,
            'submitted_at'    => current_time( 'mysql' ),
            'is_late'         => $is_late,
        );

        $meta_key = '_gh_weekly_report_data_week_' . $week_num . '_' . $course_id;
        update_user_meta( $user_id, $meta_key, $report_data );
        update_user_meta( $user_id, '_gh_weekly_report_week_' . $week_num, ( 'draft' === $action ? 'draft' : '1' ) );

        $all_reports = get_option( '_gh_all_tutor_weekly_reports', array() );
        if ( ! is_array( $all_reports ) ) {
            $all_reports = array();
        }
        $report_id = $user_id . '_' . $course_id . '_' . $week_num;
        $all_reports[ $report_id ] = $report_data;
        update_option( '_gh_all_tutor_weekly_reports', $all_reports );

        $msg = ( 'draft' === $action )
            ? __( 'Weekly report draft saved successfully!', 'growth-hub' )
            : __( 'Weekly report submitted successfully! Ops and BD Manager has been notified.', 'growth-hub' );

        wp_send_json_success( array(
            'message' => $msg,
            'report'  => $report_data,
        ) );
    }

    /**
     * Standalone shortcode [growth_hub_concern_report]
     */
    public function render_concern_report_shortcode( $atts ) {
        $auth_check = $this->ensure_user_logged_in();
        if ( true !== $auth_check ) {
            return $auth_check;
        }

        ob_start();
        include GH_PORTAL_PATH . 'templates/tutor/concern-report.php';
        return ob_get_clean();
    }

    /**
     * AJAX handler for Concern Report (Build 8)
     */
    public function render_live_classes_shortcode( $atts ) {
        $auth_check = $this->ensure_user_logged_in();
        if ( true !== $auth_check ) {
            return $auth_check;
        }

        ob_start();
        include GH_PORTAL_PATH . 'templates/tutor/live-classes.php';
        return ob_get_clean();
    }

    public function handle_save_live_class_ajax() {
        $user_id = get_current_user_id();
        if ( ! $user_id || empty( $_POST['class_data'] ) ) {
            wp_send_json_error( 'Invalid data' );
        }
        $class_data = json_decode( stripslashes( $_POST['class_data'] ), true );
        if ( ! is_array( $class_data ) ) {
            wp_send_json_error( 'Malformed class payload' );
        }
        $meta_key = '_gh_tutor_live_classes_' . $user_id;
        $saved = get_user_meta( $user_id, $meta_key, true );
        if ( ! is_array( $saved ) ) $saved = array();
        array_unshift( $saved, $class_data );
        update_user_meta( $user_id, $meta_key, $saved );
        wp_send_json_success( array( 'message' => 'Class saved successfully' ) );
    }

    public function handle_delete_live_class_ajax() {
        $user_id = get_current_user_id();
        $class_id = isset( $_POST['class_id'] ) ? sanitize_text_field( $_POST['class_id'] ) : '';
        if ( ! $user_id || ! $class_id ) {
            wp_send_json_error( 'Invalid request' );
        }
        $meta_key = '_gh_tutor_live_classes_' . $user_id;
        $saved = get_user_meta( $user_id, $meta_key, true );
        if ( is_array( $saved ) ) {
            $updated = array();
            foreach ( $saved as $item ) {
                if ( isset( $item['id'] ) && $item['id'] === $class_id ) continue;
                $updated[] = $item;
            }
            update_user_meta( $user_id, $meta_key, $updated );
        }
        wp_send_json_success( array( 'message' => 'Class deleted' ) );
    }

    public function render_announcements_shortcode( $atts ) {
        $auth_check = $this->ensure_user_logged_in();
        if ( true !== $auth_check ) {
            return $auth_check;
        }

        ob_start();
        include GH_PORTAL_PATH . 'templates/tutor/announcements.php';
        return ob_get_clean();
    }

    public function handle_save_announcement_ajax() {
        $user_id = get_current_user_id();
        if ( ! $user_id || empty( $_POST['ann_data'] ) ) {
            wp_send_json_error( 'Invalid announcement payload' );
        }
        $ann_data = json_decode( stripslashes( $_POST['ann_data'] ), true );
        if ( ! is_array( $ann_data ) ) {
            wp_send_json_error( 'Malformed payload' );
        }

        $course_id = isset( $ann_data['course_id'] ) ? intval( $ann_data['course_id'] ) : 0;
        $title     = isset( $ann_data['title'] ) ? sanitize_text_field( $ann_data['title'] ) : '';
        $content   = isset( $ann_data['content'] ) ? wp_kses_post( $ann_data['content'] ) : '';

        // If native post type exists, also insert into tutor_announcements
        $post_id = 0;
        if ( post_type_exists( 'tutor_announcements' ) || function_exists( 'tutor' ) ) {
            $post_id = wp_insert_post( array(
                'post_type'    => 'tutor_announcements',
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => 'publish',
                'post_parent'  => $course_id,
                'post_author'  => $user_id,
            ) );
            if ( ! is_wp_error( $post_id ) ) {
                $ann_data['wp_id'] = $post_id;
            }
        }

        // Also save to user meta for reliable instant display
        $meta_key = '_gh_tutor_announcements_' . $user_id;
        $saved = get_user_meta( $user_id, $meta_key, true );
        if ( ! is_array( $saved ) ) $saved = array();
        array_unshift( $saved, $ann_data );
        update_user_meta( $user_id, $meta_key, $saved );

        wp_send_json_success( array( 'message' => 'Announcement published successfully', 'id' => $post_id ) );
    }

    public function handle_delete_announcement_ajax() {
        $user_id = get_current_user_id();
        $ann_id = isset( $_POST['ann_id'] ) ? sanitize_text_field( $_POST['ann_id'] ) : '';
        if ( ! $user_id || ! $ann_id ) {
            wp_send_json_error( 'Invalid request' );
        }

        // If it starts with post_, delete WP post
        if ( strpos( $ann_id, 'post_' ) === 0 ) {
            $real_id = intval( str_replace( 'post_', '', $ann_id ) );
            if ( $real_id > 0 ) {
                $p = get_post( $real_id );
                if ( $p && ( intval( $p->post_author ) === $user_id || current_user_can( 'administrator' ) ) ) {
                    wp_delete_post( $real_id, true );
                }
            }
        }

        // Also clean from user meta
        $meta_key = '_gh_tutor_announcements_' . $user_id;
        $saved = get_user_meta( $user_id, $meta_key, true );
        if ( is_array( $saved ) ) {
            $updated = array();
            foreach ( $saved as $item ) {
                if ( isset( $item['id'] ) && $item['id'] === $ann_id ) continue;
                $updated[] = $item;
            }
            update_user_meta( $user_id, $meta_key, $updated );
        }
        wp_send_json_success( array( 'message' => 'Announcement deleted' ) );
    }

    public function render_discussions_shortcode( $atts ) {
        $auth_check = $this->ensure_user_logged_in();
        if ( true !== $auth_check ) {
            return $auth_check;
        }

        ob_start();
        include GH_PORTAL_PATH . 'templates/tutor/discussions.php';
        return ob_get_clean();
    }

    public function handle_submit_qa_reply_ajax() {
        $user_id = get_current_user_id();
        $user    = wp_get_current_user();
        $question_id = isset( $_POST['question_id'] ) ? intval( $_POST['question_id'] ) : 0;
        $course_id   = isset( $_POST['course_id'] ) ? intval( $_POST['course_id'] ) : 0;
        $reply       = isset( $_POST['reply_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reply_content'] ) ) : '';

        if ( ! $user_id || ! $question_id || empty( $reply ) ) {
            wp_send_json_error( 'Invalid reply parameters' );
        }

        // Insert native comment reply into wp_comments
        $comment_id = wp_insert_comment( array(
            'comment_post_ID'      => $course_id,
            'comment_author'       => $user->display_name ? $user->display_name : 'Instructor',
            'comment_author_email' => $user->user_email,
            'comment_content'      => $reply,
            'comment_type'         => 'tutor_q_and_a',
            'comment_parent'       => $question_id,
            'user_id'              => $user_id,
            'comment_approved'     => 1,
            'comment_date'         => current_time( 'mysql' ),
        ) );

        wp_send_json_success( array( 'message' => 'Reply posted successfully', 'comment_id' => $comment_id ) );
    }

    public function handle_concern_report_ajax() {
        check_ajax_referer( 'gh_portal_nonce', 'gh_concern_nonce' );

        $user_id   = get_current_user_id();
        $user      = wp_get_current_user();
        $course_id = isset( $_POST['course_id'] ) ? absint( $_POST['course_id'] ) : 0;

        if ( ! $user_id ) {
            wp_send_json_error( array( 'message' => __( 'You must be logged in to submit a concern report.', 'growth-hub' ) ) );
        }

        $concern_type  = isset( $_POST['concern_type'] ) ? sanitize_text_field( wp_unslash( $_POST['concern_type'] ) ) : 'Welfare';
        $learner_name  = isset( $_POST['learner_name'] ) ? sanitize_text_field( wp_unslash( $_POST['learner_name'] ) ) : '';
        $incident_date = isset( $_POST['incident_date'] ) ? sanitize_text_field( wp_unslash( $_POST['incident_date'] ) ) : current_time( 'Y-m-d' );
        $location      = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '';
        $urgency       = isset( $_POST['urgency'] ) ? sanitize_text_field( wp_unslash( $_POST['urgency'] ) ) : 'urgent';
        $details       = isset( $_POST['details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['details'] ) ) : '';
        $under_18      = ! empty( $_POST['under_18'] );

        $course_obj    = $course_id ? get_post( $course_id ) : null;
        $cohort_name   = $course_id ? get_post_meta( $course_id, '_gh_cohort', true ) : '';
        if ( ! $cohort_name && $course_obj ) {
            $cohort_name = $course_obj->post_title;
        }

        $concern_data = array(
            'id'            => uniqid( 'cr_' ),
            'user_id'       => $user_id,
            'reporter_name' => $user->display_name ? $user->display_name : $user->user_login,
            'course_id'     => $course_id,
            'cohort'        => $cohort_name,
            'type'          => $concern_type,
            'learner_name'  => $learner_name,
            'incident_date' => $incident_date,
            'location'      => $location,
            'urgency'       => $urgency,
            'details'       => $details,
            'under_18'      => $under_18,
            'status'        => 'open',
            'submitted_at'  => current_time( 'mysql' ),
        );

        $all_concerns = get_option( '_gh_all_concern_reports', array() );
        if ( ! is_array( $all_concerns ) ) {
            $all_concerns = array();
        }
        $all_concerns[] = $concern_data;
        update_option( '_gh_all_concern_reports', $all_concerns );

        wp_send_json_success( array(
            'message' => __( 'Concern report submitted securely. Hub manager and Ops & BD Manager have been alerted.', 'growth-hub' ),
            'report'  => $concern_data,
        ) );
    }

    public function render_grading_shortcode( $atts ) {
        $auth_check = $this->ensure_user_logged_in();
        if ( true !== $auth_check ) {
            return $auth_check;
        }

        ob_start();
        include GH_PORTAL_PATH . 'templates/tutor/grading.php';
        return ob_get_clean();
    }

    public function handle_submit_grade_ajax() {
        check_ajax_referer( 'gh_portal_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id || ( ! current_user_can( 'tutor_instructor' ) && ! current_user_can( 'administrator' ) ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized access.', 'growth-hub' ) ) );
        }

        $item_type     = isset( $_POST['item_type'] ) ? sanitize_text_field( wp_unslash( $_POST['item_type'] ) ) : 'assignment';
        $submission_id = isset( $_POST['submission_id'] ) ? intval( $_POST['submission_id'] ) : 0;
        $score         = isset( $_POST['score'] ) ? floatval( $_POST['score'] ) : 0;
        $feedback      = isset( $_POST['feedback'] ) ? sanitize_textarea_field( wp_unslash( $_POST['feedback'] ) ) : '';

        if ( ! $submission_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid submission ID.', 'growth-hub' ) ) );
        }

        if ( 'quiz' === $item_type ) {
            global $wpdb;
            $table = $wpdb->prefix . 'tutor_quiz_attempts';
            $attempt = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE attempt_id = %d", $submission_id ) );
            if ( $attempt ) {
                $total_marks = (float) $attempt->total_marks;
                $status = ( $score >= ( $total_marks * 0.5 ) ) ? 'pass' : 'fail';
                $wpdb->update(
                    $table,
                    array(
                        'earned_marks'   => $score,
                        'attempt_status' => $status,
                    ),
                    array( 'attempt_id' => $submission_id ),
                    array( '%f', '%s' ),
                    array( '%d' )
                );
            }
        } else {
            update_post_meta( $submission_id, 'assignment_mark', $score );
            update_post_meta( $submission_id, 'assignment_comment', $feedback );
            update_post_meta( $submission_id, 'evaluation_state', 'evaluated' );
            update_post_meta( $submission_id, '_evaluated_by', $user_id );
            update_post_meta( $submission_id, '_evaluated_at', current_time( 'mysql' ) );

            wp_update_post( array(
                'ID'          => $submission_id,
                'post_status' => 'evaluated',
            ) );

            if ( function_exists( 'do_action' ) ) {
                do_action( 'tutor_after_assignment_evaluated', $submission_id );
            }
        }

        wp_send_json_success( array(
            'message' => __( 'Grade and feedback submitted successfully!', 'growth-hub' ),
            'score'   => $score,
            'status'  => 'evaluated'
        ) );
    }


    public function render_mentees_shortcode( $atts ) {
        $auth_check = $this->ensure_user_logged_in();
        if ( true !== $auth_check ) {
            return $auth_check;
        }

        ob_start();
        include GH_PORTAL_PATH . 'templates/tutor/mentees.php';
        return ob_get_clean();
    }

    public function render_my_courses_shortcode( $atts ) {
        $auth_check = $this->ensure_user_logged_in();
        if ( true !== $auth_check ) {
            return $auth_check;
        }

        ob_start();
        include GH_PORTAL_PATH . 'templates/tutor/my-courses.php';
        return ob_get_clean();
    }

    public function render_certificates_shortcode( $atts ) {
        $auth_check = $this->ensure_user_logged_in();
        if ( true !== $auth_check ) {
            return $auth_check;
        }

        ob_start();
        include GH_PORTAL_PATH . 'templates/tutor/my-certificates.php';
        return ob_get_clean();
    }

    public function render_earnings_shortcode( $atts ) {
        $auth_check = $this->ensure_user_logged_in();
        if ( true !== $auth_check ) {
            return $auth_check;
        }

        ob_start();
        include GH_PORTAL_PATH . 'templates/tutor/earnings.php';
        return ob_get_clean();
    }

} // End Class Growth_Hub_Portal

function gh_portal() {
    return Growth_Hub_Portal::instance();
}
gh_portal();

