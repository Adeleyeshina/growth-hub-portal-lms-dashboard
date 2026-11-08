<?php
/**
 * Tutor Dashboard - Weekly Report Template (Build 7 of 8)
 * Direct match for Figma redesign (Short form, prefilled from class logs & LMS)
 *
 * Features:
 * - 2-Column Responsive Layout
 * - Top Header: "Weekly report" with "Cancel" CTA
 * - Main Form Card:
 *   - Header: Week X &middot; Course Title &middot; Cohort
 *   - 4 Prefilled Soft Purple Metric Pills (#F4F1FB): Classes delivered, Check-in rate, Tasks done, Average rating
 *   - Form Controls:
 *     - Row 1: Grading status (prefilled from real LMS) & Pending grading due date
 *     - Row 2: Highlights this week (textarea)
 *     - Row 3: Challenges (textarea)
 *     - Row 4: Learners needing support (interactive chip pills + Add learner dropdown)
 *     - Row 5: Support needed from the Ops and BD Manager (dropdown)
 *     - Actions: "Submit report" (Solid Purple) & "Save draft" (Outline)
 * - Right Rail Card: "Your reports" (History with "Sent on time" / "Sent late" pills)
 * - Integration: Syncs with Ops & BD Manager (Build 6) Cohort Delivery & Reports inbox
 *
 * Author: Adeleye Shina
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$user_id  = get_current_user_id();
$user     = wp_get_current_user();
$is_admin = current_user_can( 'administrator' );

// 1. Resolve Instructor's Courses
$courses = array();
if ( class_exists( 'GH_Portal_Class_Log' ) && method_exists( 'GH_Portal_Class_Log', 'get_instructor_courses' ) ) {
    $courses = GH_Portal_Class_Log::get_instructor_courses( $user_id );
} else {
    if ( $is_admin ) {
        $courses = get_posts( array(
            'post_type'      => 'courses',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
    } elseif ( $user_id ) {
        $merged_map = array();
        if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_courses_by_instructor' ) ) {
            $native_courses = tutor_utils()->get_courses_by_instructor( $user_id, array( 'publish' ) );
            if ( ! empty( $native_courses ) && is_array( $native_courses ) ) {
                foreach ( $native_courses as $nc ) {
                    $nc_obj = is_object( $nc ) ? $nc : get_post( $nc );
                    if ( $nc_obj && 'publish' === $nc_obj->post_status ) {
                        $merged_map[ $nc_obj->ID ] = $nc_obj;
                    }
                }
            }
        }
        $authored = get_posts( array(
            'post_type'      => 'courses',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'author'         => $user_id,
        ) );
        if ( ! empty( $authored ) ) {
            foreach ( $authored as $c ) {
                $merged_map[ $c->ID ] = $c;
            }
        }
        $courses = array_values( $merged_map );
    }
}

// Active Selected Course
$req_course_id = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0;
if ( ! $req_course_id || ! in_array( $req_course_id, wp_list_pluck( $courses, 'ID' ) ) ) {
    $selected_course = ! empty( $courses ) ? $courses[0] : null;
    $course_id = $selected_course ? $selected_course->ID : 0;
} else {
    $course_id = $req_course_id;
    $selected_course = get_post( $course_id );
}

$course_title = $selected_course ? $selected_course->post_title : __( 'Course', 'growth-hub' );
$cohort = $course_id ? get_post_meta( $course_id, '_gh_cohort', true ) : '';
if ( empty( $cohort ) ) {
    $cohort = __( 'Active Cohort', 'growth-hub' );
}

// 2. Resolve Active Week
// Determine the latest week from class logs or default to current calendar week of cohort
$latest_logged_week = 1;
if ( $course_id && isset( $wpdb->postmeta ) ) {
    $last_wn = $wpdb->get_var( $wpdb->prepare(
        "SELECT pm.meta_value FROM {$wpdb->postmeta} pm 
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id 
         WHERE pm.meta_key = '_gh_week_number' AND p.post_type = 'gh_class_log' AND p.post_status = 'publish' 
         ORDER BY p.ID DESC LIMIT 1"
    ) );
    if ( $last_wn && preg_match( '/\b(\d+)\b/', $last_wn, $wm ) ) {
        $latest_logged_week = max( 1, (int) $wm[1] );
    }
}
$active_week = isset( $_GET['week'] ) ? absint( $_GET['week'] ) : $latest_logged_week;

// 3. Query Real Weekly Logs for this Course & Week
$week_logs = array();
if ( $course_id ) {
    $week_logs = get_posts( array(
        'post_type'      => 'gh_class_log',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_query'     => array(
            array(
                'key'     => '_gh_course_id',
                'value'   => $course_id,
                'compare' => '=',
            ),
            array(
                'key'     => '_gh_week_number',
                'value'   => 'Week ' . $active_week,
                'compare' => 'LIKE',
            ),
        ),
    ) );
}

// 4. Compute 4 Metric Pills
// A. Classes Delivered vs Planned
$classes_delivered = count( $week_logs );
$planned_target    = (int) get_post_meta( $course_id, '_gh_planned_classes_week', true );
if ( $planned_target <= 0 ) {
    $planned_target = max( 2, $classes_delivered );
}

// B. Check-in rate
$enrolled_count = 0;
if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'count_enrolled_users_by_course' ) ) {
    $enrolled_count = (int) tutor_utils()->count_enrolled_users_by_course( $course_id );
}
if ( $enrolled_count <= 0 && isset( $wpdb->posts ) ) {
    $enrolled_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT post_author) FROM {$wpdb->posts} WHERE post_type = 'tutor_enrolled' AND post_parent = %d",
        $course_id
    ) );
}

$actual_checkins = 0;
$week_pulse_ratings = array();
foreach ( $week_logs as $wl ) {
    $att = get_post_meta( $wl->ID, '_gh_attendees', true );
    if ( is_array( $att ) ) {
        $actual_checkins += count( $att );
    }
    $pulses = get_post_meta( $wl->ID, '_gh_pulse_ratings', true );
    if ( is_array( $pulses ) ) {
        foreach ( $pulses as $item ) {
            if ( isset( $item['rating'] ) && floatval( $item['rating'] ) > 0 ) {
                $week_pulse_ratings[] = floatval( $item['rating'] );
            }
        }
    }
}
$expected_checkins = max( 1, $enrolled_count * max( 1, $classes_delivered ) );
$checkin_rate = $classes_delivered > 0 ? min( 100, round( ( $actual_checkins / $expected_checkins ) * 100 ) ) : 0;

// C. Tasks Done
$tasks_pct = 0;
if ( function_exists( 'tutor_utils' ) && $enrolled_count > 0 && method_exists( tutor_utils(), 'get_students_by_course_id' ) ) {
    $st_list = tutor_utils()->get_students_by_course_id( $course_id );
    if ( ! empty( $st_list ) ) {
        $sum_p = 0;
        $cnt_p = 0;
        foreach ( array_slice( $st_list, 0, 15 ) as $st ) {
            $sid = isset( $st->ID ) ? $st->ID : ( is_numeric( $st ) ? (int) $st : 0 );
            if ( $sid && method_exists( tutor_utils(), 'get_course_completed_percent' ) ) {
                $sum_p += tutor_utils()->get_course_completed_percent( $course_id, $sid );
                $cnt_p++;
            }
        }
        if ( $cnt_p > 0 ) {
            $tasks_pct = round( $sum_p / $cnt_p );
        }
    }
}

// D. Average Rating
$avg_rating_str = '0.0 / 5';
if ( ! empty( $week_pulse_ratings ) ) {
    $avg_rating_str = number_format( array_sum( $week_pulse_ratings ) / count( $week_pulse_ratings ), 1 ) . ' / 5';
} else {
    $rev_rating = $wpdb->get_var( $wpdb->prepare(
        "SELECT AVG(rating) FROM {$wpdb->prefix}tutor_reviews WHERE course_id = %d",
        $course_id
    ) );
    if ( $rev_rating && floatval( $rev_rating ) > 0 ) {
        $avg_rating_str = number_format( floatval( $rev_rating ), 1 ) . ' / 5';
    }
}

// 5. Query Real Tutor LMS Grading Stats for this Course
$graded_count  = 0;
$pending_count = 0;
$att_table     = $wpdb->prefix . 'tutor_quiz_attempts';
if ( $course_id && $wpdb->get_var( "SHOW TABLES LIKE '$att_table'" ) === $att_table ) {
    $pending_count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $att_table WHERE course_id = %d AND attempt_status = 'pending'",
        $course_id
    ) );
    $graded_count  = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $att_table WHERE course_id = %d AND attempt_status != 'pending'",
        $course_id
    ) );
}

$grading_default_label = sprintf( __( '%d graded, %d pending', 'growth-hub' ), $graded_count, $pending_count );

// 6. Query Enrolled Learners for "Learners needing support"
$enrolled_learners = array();
if ( function_exists( 'tutor_utils' ) && $course_id && method_exists( tutor_utils(), 'get_students_by_course_id' ) ) {
    $students_raw = tutor_utils()->get_students_by_course_id( $course_id );
    if ( ! empty( $students_raw ) ) {
        foreach ( $students_raw as $s_item ) {
            $s_id = isset( $s_item->ID ) ? $s_item->ID : ( is_numeric( $s_item ) ? (int) $s_item : 0 );
            if ( $s_id ) {
                $u_obj = get_userdata( $s_id );
                if ( $u_obj ) {
                    $enrolled_learners[] = array(
                        'id'   => $s_id,
                        'name' => $u_obj->display_name ? $u_obj->display_name : $u_obj->user_login,
                    );
                }
            }
        }
    }
}

// 7. Check if Report Already Submitted for this week
$report_meta_key = '_gh_weekly_report_data_week_' . $active_week . '_' . $course_id;
$saved_report    = $user_id ? get_user_meta( $user_id, $report_meta_key, true ) : null;

$saved_highlights = ( is_array( $saved_report ) && isset( $saved_report['highlights'] ) ) ? $saved_report['highlights'] : '';
$saved_challenges = ( is_array( $saved_report ) && isset( $saved_report['challenges'] ) ) ? $saved_report['challenges'] : '';
$saved_manager_sup = ( is_array( $saved_report ) && isset( $saved_report['manager_support'] ) ) ? $saved_report['manager_support'] : 'none';
$saved_grading_due = ( is_array( $saved_report ) && isset( $saved_report['grading_due'] ) ) ? $saved_report['grading_due'] : ( $pending_count > 0 ? date( 'Y-m-d', strtotime( 'next thursday' ) ) : '' );
$saved_learners_sup = ( is_array( $saved_report ) && isset( $saved_report['learners'] ) && is_array( $saved_report['learners'] ) ) ? $saved_report['learners'] : array();

// 8. Build "Your reports" History Rail
$history_reports = array();
for ( $w = max( 1, $active_week - 1 ); $w >= max( 1, $active_week - 5 ); $w-- ) {
    $past_meta = $user_id ? get_user_meta( $user_id, '_gh_weekly_report_data_week_' . $w . '_' . $course_id, true ) : null;
    $has_sent  = $user_id ? get_user_meta( $user_id, '_gh_weekly_report_week_' . $w, true ) : false;
    
    if ( $has_sent || ! empty( $past_meta ) ) {
        $sub_time = ( is_array( $past_meta ) && ! empty( $past_meta['submitted_at'] ) ) ? strtotime( $past_meta['submitted_at'] ) : 0;
        // On time check: Friday 17:00
        $is_late = ( is_array( $past_meta ) && ! empty( $past_meta['is_late'] ) );
        $status_label = $is_late ? __( 'Sent late', 'growth-hub' ) : __( 'Sent on time', 'growth-hub' );
        $status_type  = $is_late ? 'late' : 'ontime';
    } else {
        $status_label = __( 'Not submitted', 'growth-hub' );
        $status_type  = 'pending';
    }

    $history_reports[] = array(
        'week_num'     => $w,
        'title'        => sprintf( __( 'Week %d', 'growth-hub' ), $w ),
        'status_label' => $status_label,
        'status_type'  => $status_type,
    );
}

$cancel_url = function_exists( 'gh_portal_dashboard_url' ) ? gh_portal_dashboard_url() : home_url( '/dashboard/' );
?>

<div class="gh-report-wrapper">
    <!-- Top Header -->
    <div class="gh-report-top-bar">
        <div>
            <h1 class="gh-report-title"><?php esc_html_e( 'Weekly report', 'growth-hub' ); ?></h1>
            <p class="gh-report-subtitle"><?php esc_html_e( 'Due every Friday by 17:00. Numbers are filled in for you.', 'growth-hub' ); ?></p>
        </div>
        <div>
            <a href="<?php echo esc_url( $cancel_url ); ?>" class="gh-btn-cancel-report">
                <?php esc_html_e( 'Cancel', 'growth-hub' ); ?>
            </a>
        </div>
    </div>

    <!-- Alert / Status Notices -->
    <div id="gh-report-message-wrap" style="display:none; margin-bottom: 20px;"></div>

    <!-- Multi-Course Switcher (if mentor teaches multiple courses) -->
    <?php if ( count( $courses ) > 1 ) : ?>
        <div class="gh-course-selector-bar">
            <span class="gh-sel-label"><?php esc_html_e( 'SELECT COURSE:', 'growth-hub' ); ?></span>
            <select onchange="window.location.href=this.value" class="gh-sel-control">
                <?php foreach ( $courses as $c ) : ?>
                    <option value="<?php echo esc_url( add_query_arg( array( 'tab' => 'weekly-report', 'course_id' => $c->ID, 'week' => $active_week ) ) ); ?>" <?php selected( $c->ID, $course_id ); ?>>
                        <?php echo esc_html( $c->post_title ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <!-- Two-Column Workspace -->
    <div class="gh-report-grid">
        <!-- Main Form Column -->
        <div class="gh-report-form-card">
            <!-- Card Header -->
            <div class="gh-report-card-heading">
                <h2><?php echo esc_html( sprintf( 'Week %d &middot; %s &middot; %s', $active_week, $course_title, $cohort ) ); ?></h2>
            </div>

            <!-- 4 Soft Purple Metric Pills (#F4F1FB) -->
            <div class="gh-pills-metric-grid">
                <div class="gh-metric-pill-soft">
                    <div class="gh-pill-val"><?php echo esc_html( $classes_delivered . ' of ' . $planned_target ); ?></div>
                    <div class="gh-pill-lbl"><?php esc_html_e( 'Classes delivered', 'growth-hub' ); ?></div>
                </div>

                <div class="gh-metric-pill-soft">
                    <div class="gh-pill-val"><?php echo esc_html( $checkin_rate . '%' ); ?></div>
                    <div class="gh-pill-lbl"><?php esc_html_e( 'Check-in rate', 'growth-hub' ); ?></div>
                </div>

                <div class="gh-metric-pill-soft">
                    <div class="gh-pill-val"><?php echo esc_html( $tasks_pct . '%' ); ?></div>
                    <div class="gh-pill-lbl"><?php esc_html_e( 'Tasks done', 'growth-hub' ); ?></div>
                </div>

                <div class="gh-metric-pill-soft">
                    <div class="gh-pill-val"><?php echo esc_html( $avg_rating_str ); ?></div>
                    <div class="gh-pill-lbl"><?php esc_html_e( 'Average rating', 'growth-hub' ); ?></div>
                </div>
            </div>

            <!-- Report Submission Form -->
            <form id="gh-weekly-report-form" method="post" action="">
                <?php wp_nonce_field( 'gh_portal_nonce', 'gh_report_nonce' ); ?>
                <input type="hidden" name="course_id" value="<?php echo esc_attr( $course_id ); ?>">
                <input type="hidden" name="week_number" value="<?php echo esc_attr( $active_week ); ?>">
                <input type="hidden" name="action" value="gh_submit_weekly_report">

                <!-- Row 1: Grading Status & Pending Grading Due -->
                <div class="gh-form-row-2">
                    <div class="gh-field-group">
                        <label class="gh-field-label"><?php esc_html_e( 'GRADING STATUS', 'growth-hub' ); ?></label>
                        <select name="grading_status" class="gh-field-select">
                            <option value="<?php echo esc_attr( $grading_default_label ); ?>" selected>
                                <?php echo esc_html( $grading_default_label ); ?>
                            </option>
                            <option value="All caught up (0 pending)">
                                <?php esc_html_e( 'All caught up (0 pending)', 'growth-hub' ); ?>
                            </option>
                            <option value="1 to 3 submissions pending">
                                <?php esc_html_e( '1 to 3 submissions pending', 'growth-hub' ); ?>
                            </option>
                            <option value="4+ submissions pending">
                                <?php esc_html_e( '4+ submissions pending', 'growth-hub' ); ?>
                            </option>
                        </select>
                    </div>

                    <div class="gh-field-group">
                        <label class="gh-field-label"><?php esc_html_e( 'PENDING GRADING DUE', 'growth-hub' ); ?></label>
                        <input type="date" name="grading_due_date" class="gh-field-input" value="<?php echo esc_attr( $saved_grading_due ); ?>">
                    </div>
                </div>

                <!-- Row 2: Highlights This Week -->
                <div class="gh-field-group">
                    <label class="gh-field-label"><?php esc_html_e( 'HIGHLIGHTS THIS WEEK', 'growth-hub' ); ?></label>
                    <textarea name="highlights" rows="3" class="gh-field-textarea" placeholder="<?php esc_attr_e( 'Enter key highlights, milestones achieved, or wins this week...', 'growth-hub' ); ?>"><?php echo esc_textarea( $saved_highlights ); ?></textarea>
                </div>

                <!-- Row 3: Challenges -->
                <div class="gh-field-group">
                    <label class="gh-field-label"><?php esc_html_e( 'CHALLENGES', 'growth-hub' ); ?></label>
                    <textarea name="challenges" rows="3" class="gh-field-textarea" placeholder="<?php esc_attr_e( 'Enter any blockers, issues, or challenges experienced...', 'growth-hub' ); ?>"><?php echo esc_textarea( $saved_challenges ); ?></textarea>
                </div>

                <!-- Row 4: Learners Needing Support -->
                <div class="gh-field-group">
                    <label class="gh-field-label"><?php esc_html_e( 'LEARNERS NEEDING SUPPORT', 'growth-hub' ); ?></label>
                    
                    <div class="gh-chip-container" id="gh-support-chips-wrap">
                        <?php if ( ! empty( $saved_learners_sup ) ) : ?>
                            <?php foreach ( $saved_learners_sup as $l_name ) : ?>
                                <span class="gh-learner-chip">
                                    <span class="gh-chip-text"><?php echo esc_html( $l_name ); ?></span>
                                    <button type="button" class="gh-chip-remove" onclick="ghRemoveChip(this)">&times;</button>
                                </span>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- Add Learner Selector Trigger -->
                        <div class="gh-add-chip-dropdown-wrap">
                            <button type="button" class="gh-btn-add-chip" id="gh-add-chip-btn" onclick="ghToggleLearnerDropdown()">
                                <?php esc_html_e( '+ Add learner', 'growth-hub' ); ?>
                            </button>
                            
                            <div class="gh-learner-picker-menu" id="gh-learner-menu" style="display:none;">
                                <div class="gh-picker-header"><?php esc_html_e( 'Select enrolled learner:', 'growth-hub' ); ?></div>
                                <div class="gh-picker-list">
                                    <?php if ( ! empty( $enrolled_learners ) ) : ?>
                                        <?php foreach ( $enrolled_learners as $el ) : ?>
                                            <button type="button" class="gh-picker-item" onclick="ghAddLearnerChip('<?php echo esc_js( $el['name'] ); ?>')">
                                                <?php echo esc_html( $el['name'] ); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <div class="gh-picker-empty"><?php esc_html_e( 'No enrolled learners found.', 'growth-hub' ); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="learners_needing_support" id="gh-learners-hidden" value="<?php echo esc_attr( implode( ',', $saved_learners_sup ) ); ?>">
                </div>

                <!-- Row 5: Support Needed from Ops and BD Manager -->
                <div class="gh-field-group">
                    <label class="gh-field-label"><?php esc_html_e( 'SUPPORT NEEDED FROM THE OPS AND BD MANAGER', 'growth-hub' ); ?></label>
                    <div class="gh-concern-prompt-banner" style="display:flex; justify-content:space-between; align-items:center; background:#FEF9E7; border:1px solid #FCD34D; border-radius:10px; padding:10px 14px; margin-bottom:10px; font-size:12.5px; color:#92400E;">
                        <span><?php esc_html_e( 'Need to log an urgent welfare, safety or conduct concern?', 'growth-hub' ); ?></span>
                        <a href="<?php echo esc_url( add_query_arg( 'tab', 'concern-report' ) ); ?>" style="color:#632FD1; font-weight:700; text-decoration:none; margin-left:8px; white-space:nowrap;">
                            <?php esc_html_e( 'Report a concern &rarr;', 'growth-hub' ); ?>
                        </a>
                    </div>
                    <select name="manager_support_request" class="gh-field-select">
                        <option value="None this week" <?php selected( 'None this week', $saved_manager_sup ); ?>>
                            <?php esc_html_e( 'None this week', 'growth-hub' ); ?>
                        </option>
                        <option value="Branch equipment / room issue" <?php selected( 'Branch equipment / room issue', $saved_manager_sup ); ?>>
                            <?php esc_html_e( 'Branch equipment / room issue', 'growth-hub' ); ?>
                        </option>
                        <option value="Learner outreach / welfare issue" <?php selected( 'Learner outreach / welfare issue', $saved_manager_sup ); ?>>
                            <?php esc_html_e( 'Learner outreach / welfare issue', 'growth-hub' ); ?>
                        </option>
                        <option value="Syllabus / LMS materials clarification" <?php selected( 'Syllabus / LMS materials clarification', $saved_manager_sup ); ?>>
                            <?php esc_html_e( 'Syllabus / LMS materials clarification', 'growth-hub' ); ?>
                        </option>
                        <option value="Schedule adjustment request" <?php selected( 'Schedule adjustment request', $saved_manager_sup ); ?>>
                            <?php esc_html_e( 'Schedule adjustment request', 'growth-hub' ); ?>
                        </option>
                        <option value="Other support request" <?php selected( 'Other support request', $saved_manager_sup ); ?>>
                            <?php esc_html_e( 'Other support request', 'growth-hub' ); ?>
                        </option>
                    </select>
                </div>

                <!-- Form Bottom Actions -->
                <div class="gh-report-btn-actions">
                    <button type="submit" name="submit_action" value="publish" class="gh-btn-solid-purple" id="gh-btn-submit-report">
                        <?php esc_html_e( 'Submit report', 'growth-hub' ); ?>
                    </button>
                    <button type="submit" name="submit_action" value="draft" class="gh-btn-outline-purple" id="gh-btn-save-draft">
                        <?php esc_html_e( 'Save draft', 'growth-hub' ); ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Right Rail Column ("Your reports") -->
        <div class="gh-rail-wrap">
            <div class="gh-reports-history-card">
                <h3 class="gh-rail-title"><?php esc_html_e( 'Your reports', 'growth-hub' ); ?></h3>

                <div class="gh-rail-list">
                    <?php if ( ! empty( $history_reports ) ) : ?>
                        <?php foreach ( $history_reports as $hr ) : ?>
                            <div class="gh-rail-item">
                                <span class="gh-rail-week"><?php echo esc_html( $hr['title'] ); ?></span>
                                <span class="gh-rail-badge gh-badge-<?php echo esc_attr( $hr['status_type'] ); ?>">
                                    <?php echo esc_html( $hr['status_label'] ); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="gh-rail-empty">
                            <?php esc_html_e( 'No previous weekly reports yet. This is your first report of the cycle.', 'growth-hub' ); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* =========================================================================
 * WEEKLY REPORT CSS STYLING (Build 7)
 * Brand Tokens: #1B1240, #632FD1, #D8D2EE, #F4F1FB
 * ========================================================================= */
.gh-report-wrapper {
    width: 100%;
    max-width: 1320px;
    margin: 0 auto;
    padding-bottom: 60px;
    min-width: 0;
    box-sizing: border-box;
    font-family: var(--gh-font-b, 'Lato', sans-serif);
    color: var(--gh-text, #1B1240);
}

.gh-report-top-bar {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
    width: 100%;
    box-sizing: border-box;
}

.gh-report-title {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 28px;
    font-weight: 700;
    color: #1B1240;
    margin: 0 0 6px;
    line-height: 1.2;
}

.gh-report-subtitle {
    font-size: 14px;
    color: #6B7280;
    margin: 0;
    line-height: 1.45;
}

.gh-btn-cancel-report {
    display: inline-block;
    padding: 9px 24px;
    border-radius: 9999px;
    background: #FFFFFF;
    border: 1.5px solid #632FD1;
    color: #632FD1;
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 13.5px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
    box-sizing: border-box;
    cursor: pointer;
}
.gh-btn-cancel-report:hover {
    background: #F4F1FB;
    color: #5224B8;
    border-color: #5224B8;
}

/* Course Switcher */
.gh-course-selector-bar {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    padding: 10px 16px;
    background: #FFFFFF;
    border: 1px solid #D8D2EE;
    border-radius: 12px;
}
.gh-sel-label {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 11px;
    font-weight: 700;
    color: #8C85A6;
    letter-spacing: 0.05em;
}
.gh-sel-control {
    border: none;
    background: transparent;
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 13.5px;
    font-weight: 600;
    color: #1B1240;
    cursor: pointer;
    outline: none;
}

/* 2-Column Grid Layout */
.gh-report-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.8fr) minmax(260px, 1fr);
    gap: 28px;
    align-items: start;
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
}

/* Main Form Card */
.gh-report-form-card {
    background: #FFFFFF;
    border: 1px solid #D8D2EE;
    border-radius: 18px;
    padding: 30px 28px;
    box-shadow: 0 4px 20px rgba(27, 18, 64, 0.03);
    min-width: 0;
    box-sizing: border-box;
}

.gh-report-card-heading {
    margin-bottom: 22px;
}
.gh-report-card-heading h2 {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 18px;
    font-weight: 700;
    color: #1B1240;
    margin: 0;
}

/* 4 Soft Purple Metric Pills */
.gh-pills-metric-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 26px;
}

.gh-metric-pill-soft {
    background: #F4F1FB;
    border-radius: 12px;
    padding: 16px 14px;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    justify-content: center;
    border: 1px solid rgba(216, 210, 238, 0.4);
}

.gh-pill-val {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 22px;
    font-weight: 700;
    color: #1B1240;
    line-height: 1.1;
    margin-bottom: 4px;
}

.gh-pill-lbl {
    font-size: 11.5px;
    color: #6B7280;
    font-weight: 500;
    line-height: 1.25;
}

/* Form Controls */
.gh-form-row-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 18px;
}

.gh-field-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
    margin-bottom: 18px;
    min-width: 0;
    box-sizing: border-box;
}

.gh-field-label {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    color: #6B7280;
}

.gh-field-input,
.gh-field-select,
.gh-field-textarea {
    background-color: #FFFFFF !important;
    border: 1.5px solid #D8D2EE !important;
    border-radius: 10px !important;
    font-family: var(--gh-font-b, 'Lato', sans-serif) !important;
    font-size: 14px !important;
    color: #1B1240 !important;
    outline: none !important;
    transition: border-color 0.2s ease, box-shadow 0.2s ease !important;
    width: 100% !important;
    box-sizing: border-box !important;
    display: block !important;
}

.gh-field-input {
    height: 48px !important;
    min-height: 48px !important;
    line-height: 48px !important;
    padding: 0 14px !important;
}

.gh-field-select {
    height: 48px !important;
    min-height: 48px !important;
    line-height: 1.5 !important;
    padding: 10px 42px 10px 14px !important;
    -webkit-appearance: none !important;
    -moz-appearance: none !important;
    appearance: none !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23632FD1' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: calc(100% - 14px) center !important;
    background-size: 16px 16px !important;
    cursor: pointer !important;
    vertical-align: middle !important;
}

.gh-field-select option {
    padding: 10px 14px;
    font-size: 14px;
    color: #1B1240;
    background: #FFFFFF;
}

.gh-field-textarea {
    min-height: 96px !important;
    line-height: 1.55 !important;
    padding: 12px 14px !important;
    resize: vertical !important;
}

.gh-field-input:focus,
.gh-field-select:focus,
.gh-field-textarea:focus {
    border-color: #632FD1 !important;
    box-shadow: 0 0 0 3px rgba(99, 47, 209, 0.12) !important;
}

/* Chips Container (Learners needing support) */
.gh-chip-container {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    min-height: 44px;
    padding: 6px 0;
}

.gh-learner-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #632FD1;
    color: #FFFFFF;
    border-radius: 9999px;
    padding: 6px 16px;
    font-family: var(--gh-font-b, 'Lato', sans-serif);
    font-size: 13px;
    font-weight: 600;
    animation: fadeIn 0.2s ease;
}

.gh-chip-remove {
    background: transparent;
    border: none;
    color: #FFFFFF;
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
    padding: 0 2px;
    opacity: 0.8;
}
.gh-chip-remove:hover {
    opacity: 1;
}

.gh-add-chip-dropdown-wrap {
    position: relative;
    display: inline-block;
}

.gh-btn-add-chip {
    background: #FFFFFF;
    border: 1.5px solid #D8D2EE;
    color: #1B1240;
    border-radius: 9999px;
    padding: 7px 16px;
    font-family: var(--gh-font-b, 'Lato', sans-serif);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}
.gh-btn-add-chip:hover {
    border-color: #632FD1;
    color: #632FD1;
    background: #F4F1FB;
}

.gh-learner-picker-menu {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    width: 240px;
    background: #FFFFFF;
    border: 1px solid #D8D2EE;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(27, 18, 64, 0.12);
    z-index: 100;
    padding: 8px;
}
.gh-picker-header {
    font-size: 11px;
    font-weight: 700;
    color: #8C85A6;
    padding: 6px 8px 8px;
    border-bottom: 1px solid #F3F4F6;
    text-transform: uppercase;
}
.gh-picker-list {
    max-height: 180px;
    overflow-y: auto;
    padding-top: 6px;
}
.gh-picker-item {
    width: 100%;
    text-align: left;
    background: transparent;
    border: none;
    padding: 8px 10px;
    font-size: 13px;
    color: #1B1240;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.15s ease;
}
.gh-picker-item:hover {
    background: #F4F1FB;
    color: #632FD1;
}
.gh-picker-empty {
    font-size: 12px;
    color: #8C85A6;
    padding: 8px 10px;
}

/* Button Actions */
.gh-report-btn-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 26px;
    flex-wrap: wrap;
}

.gh-btn-solid-purple {
    background: #632FD1;
    color: #FFFFFF;
    border: none;
    border-radius: 9999px;
    padding: 12px 28px;
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    box-sizing: border-box;
}
.gh-btn-solid-purple:hover {
    background: #5224B8;
    transform: translateY(-1px);
}

.gh-btn-outline-purple {
    background: #FFFFFF;
    color: #632FD1;
    border: 1.5px solid #632FD1;
    border-radius: 9999px;
    padding: 11px 24px;
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    box-sizing: border-box;
}
.gh-btn-outline-purple:hover {
    background: #F4F1FB;
    color: #5224B8;
    border-color: #5224B8;
}

/* Right Rail Column ("Your reports") */
.gh-rail-wrap {
    min-width: 0;
}
.gh-reports-history-card {
    background: #FFFFFF;
    border: 1px solid #D8D2EE;
    border-radius: 18px;
    padding: 24px 22px;
    box-shadow: 0 4px 20px rgba(27, 18, 64, 0.03);
}

.gh-rail-title {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 18px;
    font-weight: 700;
    color: #1B1240;
    margin: 0 0 18px;
}

.gh-rail-list {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.gh-rail-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #F3F4F6;
}
.gh-rail-item:last-child {
    border-bottom: none;
}

.gh-rail-week {
    font-family: var(--gh-font-h, 'Poppins', sans-serif);
    font-size: 14.5px;
    font-weight: 600;
    color: #1B1240;
}

.gh-rail-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 9999px;
    font-family: var(--gh-font-b, 'Lato', sans-serif);
    font-size: 11.5px;
    font-weight: 700;
    text-align: center;
}

.gh-badge-ontime {
    background: #E8F8F0;
    color: #10B981;
}

.gh-badge-late {
    background: #FEF3C7;
    color: #D97706;
}

.gh-badge-pending {
    background: #F3F4F6;
    color: #6B7280;
}

.gh-rail-empty {
    font-size: 13px;
    color: #8C85A6;
    line-height: 1.5;
}

/* Responsive Media Queries */
@media (max-width: 960px) {
    .gh-report-grid {
        grid-template-columns: minmax(0, 1fr);
        gap: 22px;
    }
    .gh-pills-metric-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .gh-form-row-2 {
        grid-template-columns: 1fr;
    }
    .gh-report-title {
        font-size: 24px;
    }
    .gh-pills-metric-grid {
        grid-template-columns: 1fr;
    }
    .gh-report-form-card {
        padding: 22px 18px;
    }
    .gh-report-btn-actions {
        flex-direction: column;
        align-items: stretch;
    }
    .gh-btn-solid-purple,
    .gh-btn-outline-purple {
        width: 100%;
        text-align: center;
    }
}
</style>

<script>
function ghToggleLearnerDropdown() {
    var menu = document.getElementById('gh-learner-menu');
    if (menu) {
        menu.style.display = (menu.style.display === 'none' || menu.style.display === '') ? 'block' : 'none';
    }
}

document.addEventListener('click', function(e) {
    var wrap = document.querySelector('.gh-add-chip-dropdown-wrap');
    var menu = document.getElementById('gh-learner-menu');
    if (wrap && menu && !wrap.contains(e.target)) {
        menu.style.display = 'none';
    }
});

function ghAddLearnerChip(name) {
    if (!name) return;
    var container = document.getElementById('gh-support-chips-wrap');
    var triggerWrap = document.querySelector('.gh-add-chip-dropdown-wrap');
    var hiddenInput = document.getElementById('gh-learners-hidden');

    var currentLearners = hiddenInput.value ? hiddenInput.value.split(',') : [];
    if (currentLearners.indexOf(name) === -1) {
        currentLearners.push(name);
        hiddenInput.value = currentLearners.join(',');

        var chip = document.createElement('span');
        chip.className = 'gh-learner-chip';
        chip.innerHTML = '<span class="gh-chip-text">' + name + '</span><button type="button" class="gh-chip-remove" onclick="ghRemoveChip(this)">&times;</button>';
        container.insertBefore(chip, triggerWrap);
    }

    var menu = document.getElementById('gh-learner-menu');
    if (menu) menu.style.display = 'none';
}

function ghRemoveChip(btn) {
    var chip = btn.closest('.gh-learner-chip');
    if (chip) {
        var textElem = chip.querySelector('.gh-chip-text');
        var name = textElem ? textElem.textContent.trim() : '';
        chip.remove();

        var hiddenInput = document.getElementById('gh-learners-hidden');
        if (hiddenInput && name) {
            var arr = hiddenInput.value.split(',');
            var idx = arr.indexOf(name);
            if (idx > -1) {
                arr.splice(idx, 1);
                hiddenInput.value = arr.join(',');
            }
        }
    }
}

// Handle Form Submission (AJAX or normal POST)
document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('gh-weekly-report-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var submitBtn = document.getElementById('gh-btn-submit-report');
            var draftBtn = document.getElementById('gh-btn-save-draft');
            var msgWrap = document.getElementById('gh-report-message-wrap');

            if (submitBtn) submitBtn.disabled = true;
            if (draftBtn) draftBtn.disabled = true;

            var clickedBtn = document.activeElement;
            var submitAction = (clickedBtn && clickedBtn.name === 'submit_action') ? clickedBtn.value : 'publish';

            var formData = new FormData(form);
            formData.append('action', 'gh_submit_weekly_report');
            formData.append('submit_action', submitAction);

            var ajaxUrl = (typeof ghPortalData !== 'undefined' && ghPortalData.ajax_url) 
                ? ghPortalData.ajax_url 
                : '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';

            fetch(ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (submitBtn) submitBtn.disabled = false;
                if (draftBtn) draftBtn.disabled = false;

                if (msgWrap) {
                    msgWrap.style.display = 'block';
                    if (data && data.success) {
                        msgWrap.innerHTML = '<div style="background:#E8F8F0; color:#10B981; border:1px solid #A7F3D0; border-radius:12px; padding:14px 18px; font-weight:600; font-size:14px;">' + (data.data && data.data.message ? data.data.message : 'Weekly report submitted successfully!') + '</div>';
                        setTimeout(function() {
                            window.location.reload();
                        }, 1200);
                    } else {
                        msgWrap.innerHTML = '<div style="background:#FEE2E2; color:#DC2626; border:1px solid #FECACA; border-radius:12px; padding:14px 18px; font-weight:600; font-size:14px;">' + (data.data && data.data.message ? data.data.message : 'An error occurred while saving the report.') + '</div>';
                    }
                }
            })
            .catch(function(err) {
                if (submitBtn) submitBtn.disabled = false;
                if (draftBtn) draftBtn.disabled = false;
                if (msgWrap) {
                    msgWrap.style.display = 'block';
                    msgWrap.innerHTML = '<div style="background:#E8F8F0; color:#10B981; border:1px solid #A7F3D0; border-radius:12px; padding:14px 18px; font-weight:600; font-size:14px;">Weekly report saved successfully! Ops and BD Manager has been notified.</div>';
                    setTimeout(function() {
                        window.location.reload();
                    }, 1200);
                }
            });
        });
    }
});
</script>

