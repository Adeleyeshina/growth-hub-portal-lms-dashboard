<?php
/**
 * Master Dashboard Shell Template
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( function_exists( 'show_admin_bar' ) ) {
    show_admin_bar( false );
}
?>

<div class="gh-portal-wrapper">
    <!-- Top Bar for Mobile & Tablet -->
    <header class="gh-mobile-bar">
        <a href="<?php echo esc_url( home_url() ); ?>" class="gh-mobile-brand">THE GROWTH HUB</a>
        <button type="button" class="gh-hamburger-btn" id="gh-open-menu" aria-label="Open Navigation">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
        </button>
    </header>

    <div class="gh-drawer-overlay" id="gh-overlay"></div>

    <div class="gh-dash-grid">
        <!-- Independent Column 1: Sidebar -->
        <?php include GH_PORTAL_PATH . 'templates/sidebar.php'; ?>

        <!-- Independent Column 2: Main Workspace -->
        <main class="gh-main-area">
            <?php
            $endpoint = 'index';
            if ( isset( $_GET['tab'] ) && ! empty( $_GET['tab'] ) ) {
                $endpoint = sanitize_text_field( $_GET['tab'] );
            } elseif ( function_exists( 'tutor_utils' ) ) {
                $ep = tutor_utils()->get_dashboard_endpoint();
                if ( ! empty( $ep ) ) {
                    $endpoint = $ep;
                }
            }

            if ( 'cohort-delivery' === $endpoint || 'cohort_delivery' === $endpoint ) {
                include GH_PORTAL_PATH . 'templates/manager/cohort-delivery.php';
            } elseif ( 'weekly-check-in' === $endpoint || 'weekly-checkin' === $endpoint ) {
                include GH_PORTAL_PATH . 'templates/student/weekly-checkin.php';
            } elseif ( 'weekly-report' === $endpoint || 'weekly_report' === $endpoint ) {
                include GH_PORTAL_PATH . 'templates/tutor/weekly-report.php';
            } elseif ( 'concern-report' === $endpoint || 'report-concern' === $endpoint ) {
                include GH_PORTAL_PATH . 'templates/tutor/concern-report.php';
            } elseif ( 'class-log' === $endpoint ) {
                include GH_PORTAL_PATH . 'templates/tutor/class-log.php';
            } elseif ( 'live-classes' === $endpoint || 'live_classes' === $endpoint ) {
                include GH_PORTAL_PATH . 'templates/tutor/live-classes.php';
            } elseif ( 'announcements' === $endpoint ) {
                include GH_PORTAL_PATH . 'templates/tutor/announcements.php';
            } elseif ( 'question-answer' === $endpoint || 'discussions' === $endpoint ) {
                include GH_PORTAL_PATH . 'templates/tutor/discussions.php';
            } elseif ( 'grading' === $endpoint || 'grades' === $endpoint || 'assignments' === $endpoint ) {
                include GH_PORTAL_PATH . 'templates/tutor/grading.php';
            } elseif ( 'mentees' === $endpoint || 'students' === $endpoint ) {
                include GH_PORTAL_PATH . 'templates/tutor/mentees.php';
            } elseif ( 'my-courses' === $endpoint || 'my_courses' === $endpoint || 'courses' === $endpoint ) {
                include GH_PORTAL_PATH . 'templates/tutor/my-courses.php';
            } elseif ( 'my-certificates' === $endpoint || 'certificates' === $endpoint ) {
                include GH_PORTAL_PATH . 'templates/tutor/my-certificates.php';
            } elseif ( 'analytics' === $endpoint ) {
                include GH_PORTAL_PATH . 'templates/tutor/analytics.php';
            } elseif ( 'earning' === $endpoint || 'earnings' === $endpoint ) {
                include GH_PORTAL_PATH . 'templates/tutor/earnings.php';
            } elseif ( 'check-in' === $endpoint || 'checkin' === $endpoint ) {
                include GH_PORTAL_PATH . 'templates/learner/checkin.php';
            } elseif ( 'index' === $endpoint || empty( $endpoint ) || 'overview' === $endpoint || 'dashboard' === $endpoint ) {
                if ( isset( $_GET['view'] ) && ( 'manager' === $_GET['view'] || 'ops' === $_GET['view'] ) ) {
                    include GH_PORTAL_PATH . 'templates/manager/cohort-delivery.php';
                } elseif ( isset( $_GET['view'] ) && 'student' === $_GET['view'] ) {
                    include GH_PORTAL_PATH . 'templates/student/weekly-checkin.php';
                } else {
                    include GH_PORTAL_PATH . 'templates/tutor/overview.php';
                }
            } else {
                echo '<div class="gh-section-card" style="padding: 30px;">';
                echo '<h2>' . esc_html( ucfirst( str_replace( '-', ' ', $endpoint ) ) ) . '</h2>';
                echo '<p>Content for ' . esc_html( $endpoint ) . ' will load here.</p>';
                echo '</div>';
            }
            ?>
        </main>
    </div>
</div>
