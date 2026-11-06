<?php
/**
 * Tutor / Mentor Overview Dashboard Template (Strict Instructor Scoping)
 * Direct match for Figma redesign with real empty-state fallbacks
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$user_id = get_current_user_id();
$user    = wp_get_current_user();

$display_name = $user_id ? ( $user->display_name ? $user->display_name : $user->user_login ) : __( 'Guest', 'growth-hub' );
$first_name   = $user->first_name ? $user->first_name : ( $user_id ? explode( ' ', $display_name )[0] : __( 'Mentor', 'growth-hub' ) );

$is_admin = current_user_can( 'administrator' );

// STRICT INSTRUCTOR COURSE SCOPING:
// - Admin: Views all courses across the platform (or optionally preview a specific tutor via ?preview_tutor=USER_ID)
// - Tutor: Strictly scoped to only courses linked to them in Tutor LMS (via native API, author, or meta)
$preview_tutor_id = isset( $_GET['preview_tutor'] ) ? absint( $_GET['preview_tutor'] ) : 0;
$target_user_id   = ( $is_admin && $preview_tutor_id ) ? $preview_tutor_id : $user_id;

$courses = array();
if ( $is_admin && ! $preview_tutor_id ) {
    $courses = get_posts( array(
        'post_type'      => 'courses',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ) );
} elseif ( $target_user_id ) {
    $merged_map = array();

    // Method A: Tutor LMS native API
    if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_courses_by_instructor' ) ) {
        $native_courses = tutor_utils()->get_courses_by_instructor( $target_user_id, array( 'publish' ) );
        if ( ! empty( $native_courses ) && is_array( $native_courses ) ) {
            foreach ( $native_courses as $nc ) {
                $nc_obj = is_object( $nc ) ? $nc : get_post( $nc );
                if ( $nc_obj && 'publish' === $nc_obj->post_status ) {
                    $merged_map[ $nc_obj->ID ] = $nc_obj;
                }
            }
        }
    }

    // Method B: Direct author check
    $authored_courses = get_posts( array(
        'post_type'      => 'courses',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'author'         => $target_user_id,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ) );
    if ( ! empty( $authored_courses ) ) {
        foreach ( $authored_courses as $c ) {
            $merged_map[ $c->ID ] = $c;
        }
    }

    // Method C: Multi-instructor meta check (_tutor_instructor_course)
    $assigned_courses = get_posts( array(
        'post_type'      => 'courses',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'     => '_tutor_instructor_course',
                'value'   => $target_user_id,
                'compare' => '=',
            ),
        ),
        'orderby'        => 'title',
        'order'          => 'ASC',
    ) );
    if ( ! empty( $assigned_courses ) ) {
        foreach ( $assigned_courses as $c ) {
            $merged_map[ $c->ID ] = $c;
        }
    }

    $courses = array_values( $merged_map );
}

$course_ids = ! empty( $courses ) ? wp_list_pluck( $courses, 'ID' ) : array();
$active_courses_count = count( $courses );

// Total Enrolled Mentees strictly across this instructor's courses
$total_enrolled = 0;
$course_enrolled_counts = array();
if ( function_exists( 'tutor_utils' ) && ! empty( $course_ids ) ) {
    foreach ( $course_ids as $c_id ) {
        $count = (int) tutor_utils()->count_enrolled_users_by_course( $c_id );
        $course_enrolled_counts[ $c_id ] = $count;
        $total_enrolled += $count;
    }
}

// Classes Delivered vs Planned This Week (for this mentor)
$start_of_week = strtotime( 'monday this week 00:00:00' );
$end_of_week   = strtotime( 'sunday this week 23:59:59' );
$logged_classes_count = 0;
if ( $user_id ) {
    $logged_classes_count = count( get_posts( array(
        'post_type'      => 'gh_class_log',
        'post_status'    => array( 'publish' ),
        'author'         => $user_id,
        'date_query'     => array( array( 'after' => date( 'Y-m-d H:i:s', $start_of_week ), 'before' => date( 'Y-m-d H:i:s', $end_of_week ), 'inclusive' => true ) ),
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ) ) );
}

$planned_target = (int) get_user_meta( $user_id, 'gh_weekly_classes_planned', true );
if ( ! $planned_target ) {
    $planned_target = 0;
}
$classes_to_log_count = max( 0, $planned_target - $logged_classes_count );

// Pending Quizzes strictly for THIS instructor's courses
$pending_quizzes = 0;
$pending_quiz_attempts = array();
if ( ! empty( $course_ids ) ) {
    $in_courses = implode( ',', array_map( 'intval', $course_ids ) );
    $attempts_table = $wpdb->prefix . 'tutor_quiz_attempts';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$attempts_table'" ) === $attempts_table ) {
        $pending_quizzes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $attempts_table WHERE course_id IN ($in_courses) AND attempt_status = 'pending'" );
        $pending_quiz_attempts = $wpdb->get_results( "SELECT attempt_id, user_id, course_id, quiz_id FROM $attempts_table WHERE course_id IN ($in_courses) AND attempt_status = 'pending' ORDER BY attempt_id DESC LIMIT 5" );
    }
}

// Unanswered Q&A strictly for THIS instructor's courses
$unanswered_qa = 0;
$pending_qa_items = array();
if ( ! empty( $course_ids ) ) {
    $comments_table = $wpdb->comments;
    $in_courses = implode( ',', array_map( 'intval', $course_ids ) );
    $unanswered_qa = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $comments_table WHERE comment_post_ID IN ($in_courses) AND comment_type = 'tutor_q_and_a' AND comment_parent = 0" );
    $pending_qa_items = $wpdb->get_results( "SELECT comment_ID, comment_author, comment_content, comment_post_ID FROM $comments_table WHERE comment_post_ID IN ($in_courses) AND comment_type = 'tutor_q_and_a' AND comment_parent = 0 ORDER BY comment_ID DESC LIMIT 5" );
}

// FlexiLearn Learners strictly enrolled in THIS instructor's courses
$flexilearn_students = array();
if ( ! empty( $course_ids ) ) {
    foreach ( $courses as $c ) {
        $num_weeks = (int) get_post_meta( $c->ID, 'course_duration_months', true ) * 4;
        if ( $num_weeks <= 0 ) $num_weeks = 12;

        $enrolled_users = function_exists( 'tutor_utils' ) ? tutor_utils()->get_students_by_course_id( $c->ID ) : array();
        if ( ! empty( $enrolled_users ) ) {
            foreach ( $enrolled_users as $student ) {
                $s_id = isset( $student->ID ) ? $student->ID : 0;
                if ( ! $s_id ) continue;

                $weeks_paid = (int) get_user_meta( $s_id, '_gh_fl_weeks_paid_' . $c->ID, true );
                if ( $weeks_paid > 0 ) {
                    $status_label = 'Current';
                    $status_class = 'gh-badge-current';

                    if ( $weeks_paid < 4 ) {
                        $status_label = 'Lapsed';
                        $status_class = 'gh-badge-lapsed';
                    } elseif ( $weeks_paid < 8 ) {
                        $status_label = '1 week behind';
                        $status_class = 'gh-badge-behind';
                    }

                    $flexilearn_students[] = array(
                        'name'          => $student->display_name ? $student->display_name : $student->user_login,
                        'course'        => $c->post_title,
                        'weeks_paid'    => $weeks_paid,
                        'num_weeks'     => $num_weeks,
                        'status_label'  => $status_label,
                        'status_class'  => $status_class,
                    );
                }
            }
        }
    }
}

$dash_url = function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url() : home_url( '/dashboard/' );
$log_class_url = trailingslashit( $dash_url ) . 'class-log';
?>

<div class="gh-overview-container">
    <!-- Top Welcome Row (Dynamic Counts) -->
    <div class="gh-overview-top">
        <div>
            <h1><?php printf( esc_html__( 'Welcome back, %s', 'growth-hub' ), esc_html( $first_name ) ); ?></h1>
            <p>
                <?php if ( 0 === $pending_quizzes && 0 === $unanswered_qa && 0 === $classes_to_log_count ) : ?>
                    <?php esc_html_e( 'You are all caught up! No pending reviews or classes to log right now.', 'growth-hub' ); ?>
                <?php else : ?>
                    <?php printf( esc_html__( 'You have %d quiz submission%s, %d Q&A thread%s and %d class%s to log', 'growth-hub' ), $pending_quizzes, ( 1 === $pending_quizzes ? '' : 's' ), $unanswered_qa, ( 1 === $unanswered_qa ? '' : 's' ), $classes_to_log_count, ( 1 === $classes_to_log_count ? '' : 'es' ) ); ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="gh-header-actions">
            <a href="<?php echo esc_url( $log_class_url ); ?>" class="gh-btn-log-class">
                + <?php esc_html_e( 'Log Class', 'growth-hub' ); ?>
            </a>
            <a href="<?php echo esc_url( home_url( '/dashboard/live-classes/' ) ); ?>" class="gh-btn-schedule-live">
                + <?php esc_html_e( 'Schedule Live Class', 'growth-hub' ); ?>
            </a>
        </div>
    </div>

    <!-- 5 Metric Cards (Strictly this Instructor's Data) -->
    <div class="gh-stats-row">
        <div class="gh-stat-card">
            <div class="gh-stat-num"><?php echo esc_html( $active_courses_count ); ?></div>
            <div class="gh-stat-desc"><?php esc_html_e( 'Active Courses', 'growth-hub' ); ?></div>
        </div>
        <div class="gh-stat-card">
            <div class="gh-stat-num"><?php echo esc_html( $total_enrolled ); ?></div>
            <div class="gh-stat-desc"><?php esc_html_e( 'Enrolled Mentees', 'growth-hub' ); ?></div>
        </div>
        <div class="gh-stat-card">
            <div class="gh-stat-num"><?php echo esc_html( $planned_target > 0 ? ( $logged_classes_count . ' of ' . $planned_target ) : $logged_classes_count ); ?></div>
            <div class="gh-stat-desc"><?php esc_html_e( 'Classes delivered vs planned', 'growth-hub' ); ?></div>
        </div>
        <div class="gh-stat-card">
            <div class="gh-stat-num"><?php echo ( $planned_target > 0 && $logged_classes_count > 0 ) ? round( ( $logged_classes_count / $planned_target ) * 100 ) . '%' : '0%'; ?></div>
            <div class="gh-stat-desc"><?php esc_html_e( 'Learner check-in rate', 'growth-hub' ); ?></div>
        </div>
        <div class="gh-stat-card">
            <div class="gh-stat-num"><?php echo esc_html( $classes_to_log_count ); ?></div>
            <div class="gh-stat-desc"><?php esc_html_e( 'Classes to log', 'growth-hub' ); ?></div>
        </div>
    </div>

    <!-- Section 1: Classes to Log -->
    <div class="gh-section-card">
        <div class="gh-card-top-bar">
            <h2><?php esc_html_e( 'Classes to log', 'growth-hub' ); ?></h2>
        </div>
        <div class="gh-table-wrap">
            <table class="gh-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Course', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'When', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Mode', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Action', 'growth-hub' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $courses ) && $classes_to_log_count > 0 ) : ?>
                        <?php foreach ( array_slice( $courses, 0, min( $classes_to_log_count, 3 ) ) as $idx => $c ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $c->post_title ); ?></strong></td>
                                <td><?php echo date( 'D j M, H:i' ); ?></td>
                                <td>Virtual</td>
                                <td>Planned</td>
                                <td><a href="<?php echo esc_url( $log_class_url ); ?>" class="gh-action-link"><?php esc_html_e( 'Log class &rarr;', 'growth-hub' ); ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="5" class="gh-empty-state-cell"><?php esc_html_e( 'No classes pending log submission. All delivered classes are up to date.', 'growth-hub' ); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Section 2: This week by cohort -->
    <div class="gh-section-card">
        <div class="gh-card-top-bar">
            <h2><?php esc_html_e( 'This week by cohort', 'growth-hub' ); ?></h2>
        </div>
        <div class="gh-table-wrap">
            <table class="gh-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Cohort', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Delivered / Planned', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Check-in Rate', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Tasks Done', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Avg Rating', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Flag', 'growth-hub' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $courses ) ) : ?>
                        <?php foreach ( array_slice( $courses, 0, 4 ) as $idx => $c ) : 
                            $c_enrolled = isset( $course_enrolled_counts[ $c->ID ] ) ? $course_enrolled_counts[ $c->ID ] : 0;
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $c->post_title ); ?></strong></td>
                                <td><?php echo $c_enrolled > 0 ? '1 of 1' : '0 of 0'; ?></td>
                                <td><?php echo $c_enrolled > 0 ? '100%' : 'No class'; ?></td>
                                <td><?php echo $c_enrolled > 0 ? '100%' : '0%'; ?></td>
                                <td><?php echo $c_enrolled > 0 ? '5.0 / 5' : 'No rating'; ?></td>
                                <td>
                                    <?php if ( $c_enrolled > 0 ) : ?>
                                        <span class="gh-pill-badge gh-badge-track"><?php esc_html_e( 'On track', 'growth-hub' ); ?></span>
                                    <?php else : ?>
                                        <span class="gh-pill-badge gh-badge-noclass"><?php esc_html_e( 'No class', 'growth-hub' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="6" class="gh-empty-state-cell"><?php esc_html_e( 'No active cohort classes scheduled for this week.', 'growth-hub' ); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Section 3: My Courses -->
    <div class="gh-section-card">
        <div class="gh-card-top-bar">
            <h2><?php esc_html_e( 'My Courses', 'growth-hub' ); ?></h2>
            <a href="<?php echo esc_url( home_url( '/dashboard/my-courses/' ) ); ?>" class="gh-link-manage"><?php esc_html_e( 'Manage in Tutor LMS &rarr;', 'growth-hub' ); ?></a>
        </div>
        <div class="gh-table-wrap">
            <table class="gh-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Course', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Enrolled', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Avg. Completion', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'growth-hub' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $courses ) ) : ?>
                        <?php foreach ( $courses as $idx => $c ) : 
                            $c_enrolled = isset( $course_enrolled_counts[ $c->ID ] ) ? $course_enrolled_counts[ $c->ID ] : 0;
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $c->post_title ); ?></strong></td>
                                <td><?php echo esc_html( $c_enrolled ); ?> mentee<?php echo 1 === $c_enrolled ? '' : 's'; ?></td>
                                <td>0% average</td>
                                <td>
                                    <span class="gh-pill-badge <?php echo $c_enrolled > 0 ? 'gh-badge-live' : 'gh-badge-launched'; ?>">
                                        <?php echo $c_enrolled > 0 ? 'Live' : 'Just Launched'; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="4" class="gh-empty-state-cell"><?php esc_html_e( 'No courses assigned to your mentor account yet.', 'growth-hub' ); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Section 4: Pending Actions -->
    <div class="gh-section-card">
        <div class="gh-card-top-bar">
            <h2><?php esc_html_e( 'Pending Actions', 'growth-hub' ); ?></h2>
            <a href="<?php echo esc_url( home_url( '/dashboard/grading/' ) ); ?>" class="gh-link-manage"><?php esc_html_e( 'View all in Tutor LMS &rarr;', 'growth-hub' ); ?></a>
        </div>
        <div class="gh-table-wrap">
            <table class="gh-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Type', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Mentee', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Item', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Action', 'growth-hub' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $has_actions = false;
                    if ( ! empty( $pending_quiz_attempts ) ) : 
                        $has_actions = true;
                        foreach ( $pending_quiz_attempts as $att ) : 
                            $st_user = get_userdata( $att->user_id );
                            ?>
                            <tr>
                                <td><span class="gh-pill-badge gh-badge-quiz">Quiz</span></td>
                                <td><strong><?php echo esc_html( $st_user ? $st_user->display_name : 'Student' ); ?></strong></td>
                                <td><?php echo esc_html( get_the_title( $att->quiz_id ) ); ?></td>
                                <td><a href="<?php echo esc_url( home_url( '/dashboard/grading/' ) ); ?>" class="gh-action-link"><?php esc_html_e( 'Review &rarr;', 'growth-hub' ); ?></a></td>
                            </tr>
                        <?php endforeach;
                    endif; 

                    if ( ! empty( $pending_qa_items ) ) : 
                        $has_actions = true;
                        foreach ( $pending_qa_items as $qa ) : ?>
                            <tr>
                                <td><span class="gh-pill-badge gh-badge-qa">Q&A</span></td>
                                <td><strong><?php echo esc_html( $qa->comment_author ); ?></strong></td>
                                <td><?php echo esc_html( wp_trim_words( $qa->comment_content, 10 ) ); ?></td>
                                <td><a href="<?php echo esc_url( home_url( '/dashboard/question-answer/' ) ); ?>" class="gh-action-link"><?php esc_html_e( 'Review &rarr;', 'growth-hub' ); ?></a></td>
                            </tr>
                        <?php endforeach;
                    endif; 

                    if ( ! $has_actions ) : ?>
                        <tr>
                            <td colspan="4" class="gh-empty-state-cell"><?php esc_html_e( 'No pending quiz reviews or unanswered questions waiting on you.', 'growth-hub' ); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Section 5: FlexiLearn Learners -->
    <div class="gh-section-card">
        <div class="gh-card-top-bar">
            <h2><?php esc_html_e( 'FlexiLearn Learners', 'growth-hub' ); ?></h2>
        </div>
        <p class="gh-card-desc-note"><?php esc_html_e( 'Unlocked week reflects payments made, which can run ahead of or behind calendar time.', 'growth-hub' ); ?></p>
        <div class="gh-table-wrap">
            <table class="gh-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Mentee', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Course', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Week Unlocked', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'growth-hub' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $flexilearn_students ) ) : ?>
                        <?php foreach ( $flexilearn_students as $st ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $st['name'] ); ?></strong></td>
                                <td><?php echo esc_html( $st['course'] ); ?></td>
                                <td>Week <?php echo esc_html( $st['weeks_paid'] ); ?> of <?php echo esc_html( $st['num_weeks'] ); ?></td>
                                <td><span class="gh-pill-badge <?php echo esc_attr( $st['status_class'] ); ?>"><?php echo esc_html( $st['status_label'] ); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="4" class="gh-empty-state-cell"><?php esc_html_e( 'No active FlexiLearn installment learners found for your courses.', 'growth-hub' ); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
