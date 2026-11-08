<?php
/**
 * Growth Hub Portal - Role-Aware Sidebar Template
 * Renders Student Navigation (Build 5), Tutor Navigation (Build 1-3), or Ops and BD Manager Navigation (Build 6)
 *
 * Author: Adeleye Shina
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user = wp_get_current_user();
$user_id = $user->ID;
$is_admin = current_user_can( 'administrator' );
$is_tutor = GH_Portal_Roles::is_tutor( $user_id );

// 1. Resolve Canonical Dashboard URL (always anchored to /dashboard/ portal route)
$dash_url = function_exists( 'gh_portal_dashboard_url' ) ? gh_portal_dashboard_url() : home_url( '/dashboard/' );

// Helper to build reliable internal tab URLs that stay on the Growth Hub dashboard
if ( ! function_exists( 'gh_portal_nav_link' ) ) {
    function gh_portal_nav_link( $tab, $base_url = '', $extra = array() ) {
        if ( function_exists( 'gh_portal_dashboard_url' ) ) {
            return gh_portal_dashboard_url( $tab, $extra );
        }
        $clean_base = strtok( $base_url ? $base_url : home_url( '/dashboard/' ), '?' );
        $clean_base = trailingslashit( $clean_base );
        if ( 'index' === $tab || 'overview' === $tab || empty( $tab ) ) {
            return empty( $extra ) ? $clean_base : add_query_arg( $extra, $clean_base );
        }
        $args = array_merge( array( 'tab' => $tab ), $extra );
        return add_query_arg( $args, $clean_base );
    }
}

$current_endpoint = 'index';
if ( isset( $_GET['tab'] ) && ! empty( $_GET['tab'] ) ) {
    $current_endpoint = sanitize_text_field( $_GET['tab'] );
} elseif ( function_exists( 'tutor_utils' ) ) {
    $ep = tutor_utils()->get_dashboard_endpoint();
    if ( ! empty( $ep ) ) {
        $current_endpoint = $ep;
    }
}

// Role / View Detection (Manager, Student, or Mentor)
$role_view = isset( $_GET['view'] ) ? sanitize_text_field( $_GET['view'] ) : ( isset( $_GET['role_view'] ) ? sanitize_text_field( $_GET['role_view'] ) : '' );
$roles     = ( $user_id && isset( $user->roles ) ) ? (array) $user->roles : array();

$is_manager_view = false;
if ( 'manager' === $role_view || 'ops' === $role_view || 'ops_manager' === $role_view || in_array( 'ops_bd_manager', $roles, true ) || in_array( 'manager', $roles, true ) || ( $is_admin && 'manager' === $role_view ) || 'cohort-delivery' === $current_endpoint ) {
    $is_manager_view = true;
}

$is_student_view = false;
if ( ! $is_manager_view ) {
    if ( 'student' === $role_view ) {
        $is_student_view = true;
    } elseif ( 'mentor' === $role_view || 'tutor' === $role_view ) {
        $is_student_view = false;
    } elseif ( ! $is_admin && ! $is_tutor ) {
        $is_student_view = true;
    } elseif ( in_array( $current_endpoint, array( 'weekly-check-in', 'weekly-checkin', 'student-dashboard', 'assignments', 'talent-profile' ), true ) ) {
        $is_student_view = true;
    }
}

// User Profile Details
if ( $is_manager_view ) {
    $display_name = $user_id ? ( $user->display_name ? $user->display_name : $user->user_login ) : __( 'Ops & BD Manager', 'growth-hub' );
    $role_title   = __( 'Learner experience owner', 'growth-hub' );
} elseif ( $is_student_view ) {
    $display_name = $user_id ? ( $user->display_name ? $user->display_name : $user->user_login ) : __( 'Student', 'growth-hub' );
    $role_title   = GH_Portal_Roles::get_role_badge_title( $user_id );
} else {
    $display_name = $user_id ? ( $user->display_name ? $user->display_name : $user->user_login ) : __( 'Instructor', 'growth-hub' );
    $role_title   = $is_admin ? __( 'Administrator &middot; The Growth Hub', 'growth-hub' ) : __( 'Volunteer Mentor &middot; Tech Academy', 'growth-hub' );
}
?>

<aside class="gh-sidebar" id="gh-sidebar">
    <button type="button" class="gh-sidebar-close" id="gh-close-menu">&times;</button>
    
    <!-- Brand Header -->
    <a href="<?php echo esc_url( home_url() ); ?>" class="gh-brand">
        THE GROWTH HUB
    </a>

    <!-- User Profile Badge -->
    <div class="gh-user-badge">
        <div class="gh-user-name"><?php echo esc_html( strtoupper( $display_name ) ); ?></div>
        <div class="gh-user-role"><?php echo esc_html( $role_title ); ?></div>
    </div>

    <!-- Navigation Menu -->
    <ul class="gh-nav-menu">
        <?php if ( $is_manager_view ) : ?>
            <!-- ==================================================== -->
            <!-- OPS AND BD MANAGER NAVIGATION (Figma Build 6)        -->
            <!-- ==================================================== -->
            <li class="gh-nav-item <?php echo ( 'overview' === $current_endpoint ) ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'overview', $dash_url, array( 'view' => 'manager' ) ) ); ?>">
                    <?php esc_html_e( 'Overview', 'growth-hub' ); ?>
                </a>
            </li>

            <li class="gh-nav-item <?php echo ( 'cohort-delivery' === $current_endpoint || 'cohort_delivery' === $current_endpoint || empty( $current_endpoint ) || 'index' === $current_endpoint ) ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'cohort-delivery', $dash_url, array( 'view' => 'manager' ) ) ); ?>">
                    <?php esc_html_e( 'Cohort Delivery', 'growth-hub' ); ?>
                </a>
            </li>

            <li class="gh-nav-item <?php echo 'tutors' === $current_endpoint ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'tutors', $dash_url, array( 'view' => 'manager' ) ) ); ?>">
                    <?php esc_html_e( 'Tutors', 'growth-hub' ); ?>
                </a>
            </li>

            <li class="gh-nav-item <?php echo 'learners' === $current_endpoint ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'learners', $dash_url, array( 'view' => 'manager' ) ) ); ?>">
                    <?php esc_html_e( 'Learners', 'growth-hub' ); ?>
                </a>
            </li>

            <li class="gh-nav-item <?php echo 'weekly-reports' === $current_endpoint ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'weekly-reports', $dash_url, array( 'view' => 'manager' ) ) ); ?>">
                    <?php esc_html_e( 'Weekly Reports', 'growth-hub' ); ?>
                </a>
            </li>

            <li class="gh-nav-item <?php echo 'support-requests' === $current_endpoint ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'support-requests', $dash_url, array( 'view' => 'manager' ) ) ); ?>">
                    <?php esc_html_e( 'Support Requests', 'growth-hub' ); ?>
                </a>
            </li>

            <li class="gh-nav-item <?php echo 'sponsors' === $current_endpoint ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'sponsors', $dash_url, array( 'view' => 'manager' ) ) ); ?>">
                    <?php esc_html_e( 'Sponsors', 'growth-hub' ); ?>
                </a>
            </li>

            <li class="gh-nav-item <?php echo 'payments' === $current_endpoint ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'payments', $dash_url, array( 'view' => 'manager' ) ) ); ?>">
                    <?php esc_html_e( 'Payments', 'growth-hub' ); ?>
                </a>
            </li>

        <?php elseif ( $is_student_view ) : ?>
            <!-- ============================================== -->
            <!-- STUDENT NAVIGATION (Figma Build 5)            -->
            <!-- ============================================== -->
            <li class="gh-nav-item <?php echo ( 'index' === $current_endpoint || 'dashboard' === $current_endpoint || 'student-dashboard' === $current_endpoint ) ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'index', $dash_url, array( 'view' => 'student' ) ) ); ?>">
                    <?php esc_html_e( 'Dashboard', 'growth-hub' ); ?>
                </a>
            </li>

            <li class="gh-nav-item <?php echo ( 'my-courses' === $current_endpoint || 'enrolled-courses' === $current_endpoint ) ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'my-courses', $dash_url, array( 'view' => 'student' ) ) ); ?>">
                    <?php esc_html_e( 'My Courses', 'growth-hub' ); ?>
                </a>
            </li>

            <li class="gh-nav-item <?php echo ( 'weekly-check-in' === $current_endpoint || 'weekly-checkin' === $current_endpoint ) ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'weekly-check-in', $dash_url, array( 'view' => 'student' ) ) ); ?>">
                    <?php esc_html_e( 'Weekly Check-in', 'growth-hub' ); ?>
                </a>
            </li>

            <li class="gh-nav-item <?php echo 'assignments' === $current_endpoint ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'assignments', $dash_url, array( 'view' => 'student' ) ) ); ?>">
                    <?php esc_html_e( 'Assignments', 'growth-hub' ); ?>
                </a>
            </li>

            <li class="gh-nav-item <?php echo 'my-certificates' === $current_endpoint ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'my-certificates', $dash_url, array( 'view' => 'student' ) ) ); ?>">
                    <?php esc_html_e( 'Certificates', 'growth-hub' ); ?>
                </a>
            </li>

            <li class="gh-nav-item <?php echo 'talent-profile' === $current_endpoint ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'talent-profile', $dash_url, array( 'view' => 'student' ) ) ); ?>">
                    <?php esc_html_e( 'Talent Profile', 'growth-hub' ); ?>
                </a>
            </li>

            <li class="gh-nav-item <?php echo ( 'payments' === $current_endpoint || 'purchase_history' === $current_endpoint ) ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'purchase_history', $dash_url, array( 'view' => 'student' ) ) ); ?>">
                    <?php esc_html_e( 'Payments', 'growth-hub' ); ?>
                </a>
            </li>

        <?php else : ?>
            <!-- ============================================== -->
            <!-- TUTOR NAVIGATION (Figma Build 1-3)            -->
            <!-- ============================================== -->
            <li class="gh-nav-item <?php echo ( 'index' === $current_endpoint || 'overview' === $current_endpoint || empty( $current_endpoint ) || 'dashboard' === $current_endpoint ) ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'index', $dash_url ) ); ?>">
                    <?php esc_html_e( 'Overview', 'growth-hub' ); ?>
                </a>
            </li>
            <li class="gh-nav-item <?php echo ( 'my-courses' === $current_endpoint || 'courses' === $current_endpoint ) ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'my-courses', $dash_url ) ); ?>">
                    <?php esc_html_e( 'My Courses', 'growth-hub' ); ?>
                </a>
            </li>
            <li class="gh-nav-item <?php echo ( 'mentees' === $current_endpoint || 'students' === $current_endpoint ) ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'mentees', $dash_url ) ); ?>">
                    <?php esc_html_e( 'Mentees & Students', 'growth-hub' ); ?>
                </a>
            </li>
            <li class="gh-nav-item <?php echo ( 'live-classes' === $current_endpoint || 'live_classes' === $current_endpoint ) ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'live-classes', $dash_url ) ); ?>">
                    <?php esc_html_e( 'Live Classes', 'growth-hub' ); ?>
                </a>
            </li>
            <li class="gh-nav-item <?php echo 'class-log' === $current_endpoint ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'class-log', $dash_url ) ); ?>">
                    <?php esc_html_e( 'Class Log', 'growth-hub' ); ?>
                </a>
            </li>
            <li class="gh-nav-item <?php echo 'announcements' === $current_endpoint ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'announcements', $dash_url ) ); ?>">
                    <?php esc_html_e( 'Announcements', 'growth-hub' ); ?>
                </a>
            </li>
            <li class="gh-nav-item <?php echo ( 'question-answer' === $current_endpoint || 'discussions' === $current_endpoint ) ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'question-answer', $dash_url ) ); ?>">
                    <?php esc_html_e( 'Discussions (Q&A)', 'growth-hub' ); ?>
                </a>
            </li>
            <li class="gh-nav-item <?php echo ( 'grading' === $current_endpoint || 'grades' === $current_endpoint || 'assignments' === $current_endpoint ) ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'grading', $dash_url ) ); ?>">
                    <?php esc_html_e( 'Grading', 'growth-hub' ); ?>
                </a>
            </li>
            <li class="gh-nav-item <?php echo ( 'my-certificates' === $current_endpoint || 'certificates' === $current_endpoint ) ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'my-certificates', $dash_url ) ); ?>">
                    <?php esc_html_e( 'Certificates', 'growth-hub' ); ?>
                </a>
            </li>
            <li class="gh-nav-item <?php echo 'analytics' === $current_endpoint ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'analytics', $dash_url ) ); ?>">
                    <?php esc_html_e( 'Analytics', 'growth-hub' ); ?>
                </a>
            </li>
            <li class="gh-nav-item <?php echo ( 'weekly-report' === $current_endpoint || 'weekly_report' === $current_endpoint || 'concern-report' === $current_endpoint ) ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'weekly-report', $dash_url ) ); ?>">
                    <?php esc_html_e( 'Weekly Report', 'growth-hub' ); ?>
                </a>
            </li>
            <li class="gh-nav-item <?php echo 'earning' === $current_endpoint ? 'active' : ''; ?>">
                <a href="<?php echo esc_url( gh_portal_nav_link( 'earning', $dash_url ) ); ?>">
                    <?php esc_html_e( 'Earnings', 'growth-hub' ); ?>
                </a>
            </li>
        <?php endif; ?>
    </ul>
</aside>
