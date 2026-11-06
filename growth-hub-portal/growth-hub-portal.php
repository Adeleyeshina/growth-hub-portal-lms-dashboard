<?php
/**
 * Plugin Name: The Growth Hub Portal & Dashboard
 * Plugin URI: https://thegrowthhub.africa
 * Description: Custom responsive role-based dashboard for The Growth Hub (Students, Offline Learners, Mentors, and Admins) integrated with Tutor LMS.
 * Version: 1.0.0
 * Author: Adeleye Shina
 * Text Domain: growth-hub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GH_PORTAL_VERSION', '1.0.1' );
define( 'GH_PORTAL_PATH', plugin_dir_path( __FILE__ ) );
define( 'GH_PORTAL_URL', plugin_dir_url( __FILE__ ) );

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

        // Register custom endpoints in Tutor LMS
        add_filter( 'tutor_dashboard/nav_items', array( $this, 'register_tutor_nav_items' ), 50 );
        add_filter( 'tutor_dashboard/nav_item_template', array( $this, 'route_custom_nav_templates' ), 50, 2 );

        // Shortcode fallback for standalone dashboard page: [growth_hub_dashboard]
        add_shortcode( 'growth_hub_dashboard', array( $this, 'render_dashboard_shortcode' ) );
        add_shortcode( 'growth_hub_cohort_delivery', array( $this, 'render_cohort_delivery_shortcode' ) );
        add_shortcode( 'growth_hub_student_checkin', array( $this, 'render_student_checkin_shortcode' ) );
        add_shortcode( 'growth_hub_weekly_report', array( $this, 'render_weekly_report_shortcode' ) );
        add_shortcode( 'growth_hub_concern_report', array( $this, 'render_concern_report_shortcode' ) );

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
        if ( 'analytics' === $endpoint ) {
            return GH_PORTAL_PATH . 'templates/tutor/analytics.php';
        }
        if ( 'check-in' === $endpoint || 'checkin' === $endpoint ) {
            return GH_PORTAL_PATH . 'templates/learner/checkin.php';
        }
        return $template;
    }

    /**
     * Standalone shortcode [growth_hub_dashboard]
     */
    public function render_dashboard_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<div class="gh-login-notice"><p>Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">log in</a> to access your dashboard.</p></div>';
        }

        ob_start();
        include GH_PORTAL_PATH . 'templates/dashboard-shell.php';
        return ob_get_clean();
    }

    /**
     * Standalone shortcode [growth_hub_cohort_delivery]
     */
    public function render_cohort_delivery_shortcode( $atts ) {
        ob_start();
        include GH_PORTAL_PATH . 'templates/manager/cohort-delivery.php';
        return ob_get_clean();
    }

    /**
     * Standalone shortcode [growth_hub_student_checkin]
     */
    public function render_student_checkin_shortcode( $atts ) {
        ob_start();
        include GH_PORTAL_PATH . 'templates/student/weekly-checkin.php';
        return ob_get_clean();
    }

    /**
     * Standalone shortcode [growth_hub_weekly_report]
     */
    public function render_weekly_report_shortcode( $atts ) {
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
        ob_start();
        include GH_PORTAL_PATH . 'templates/tutor/concern-report.php';
        return ob_get_clean();
    }

    /**
     * AJAX handler for Concern Report (Build 8)
     */
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
}

function gh_portal() {
    return Growth_Hub_Portal::instance();
}
add_action( 'plugins_loaded', 'gh_portal' );
