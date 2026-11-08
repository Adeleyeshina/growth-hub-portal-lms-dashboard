<?php
/**
 * Tutor LMS - Mentor Grading & Submissions Template
 *
 * Growth Hub Portal - Instructor Gradebook & Evaluation Interface
 * Displays assignments and quiz attempts requiring grading for the logged-in mentor's assigned courses.
 *
 * @package GrowthHubPortal
 * @author Adeleye Shina
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$user_id   = get_current_user_id();
$is_admin  = current_user_can( 'administrator' );
$is_mentor = current_user_can( 'tutor_instructor' ) || $is_admin;

if ( ! $user_id || ! $is_mentor ) {
    ?>
    <div class="gh-section-card" style="padding: 40px; text-align: center;">
        <h3 style="color: #1B1240; font-family: 'Poppins', sans-serif;"><?php esc_html_e( 'Access Denied', 'growth-hub' ); ?></h3>
        <p style="color: #6B7280; font-family: 'Lato', sans-serif;"><?php esc_html_e( 'You must be logged in as an instructor or mentor to access the grading portal.', 'growth-hub' ); ?></p>
    </div>
    <?php
    return;
}

// 1. Get assigned courses for this instructor
$courses = array();
if ( function_exists( 'tutor_utils' ) ) {
    $courses = tutor_utils()->get_courses_by_instructor( $user_id );
}
if ( empty( $courses ) ) {
    $courses = get_posts( array(
        'post_type'      => 'courses',
        'post_status'    => 'publish',
        'author'         => $user_id,
        'posts_per_page' => -1,
    ) );
}

$course_ids = ! empty( $courses ) ? wp_list_pluck( $courses, 'ID' ) : array();

// 2. Fetch real assignments and quiz attempts for these courses
$submissions = array();
$total_pending  = 0;
$total_graded   = 0;
$score_sum      = 0;
$score_count    = 0;

if ( ! empty( $course_ids ) ) {
    $in_courses = implode( ',', array_map( 'intval', $course_ids ) );

    // 2A. Assignment Submissions (post_type = tutor_submissions)
    $assignment_posts = $wpdb->get_results( "
        SELECT p.ID as submission_id, p.post_parent as assignment_id, p.post_author as student_id, 
               p.post_date as submitted_at, p.post_content as student_text, p.post_status as status,
               a.post_title as item_title
        FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->posts} a ON p.post_parent = a.ID
        WHERE p.post_type = 'tutor_submissions'
        ORDER BY p.ID DESC
    " );

    if ( ! empty( $assignment_posts ) ) {
        foreach ( $assignment_posts as $sub ) {
            // Find which course this assignment belongs to
            $sub_course_id = (int) get_post_meta( $sub->assignment_id, '_tutor_course_id_for_assignments', true );
            if ( ! $sub_course_id ) {
                $sub_course_id = (int) wp_get_post_parent_id( $sub->assignment_id );
            }
            if ( ! in_array( $sub_course_id, $course_ids, true ) && ! $is_admin ) {
                continue;
            }

            $student = get_userdata( $sub->student_id );
            $student_name = $student ? $student->display_name : __( 'Student', 'growth-hub' );
            $student_email = $student ? $student->user_email : '';

            $eval_state   = get_post_meta( $sub->submission_id, 'evaluation_state', true );
            $mark_earned  = get_post_meta( $sub->submission_id, 'assignment_mark', true );
            $feedback     = get_post_meta( $sub->submission_id, 'assignment_comment', true );
            $attachments  = get_post_meta( $sub->submission_id, 'submitted_attachments', true );
            
            $max_mark = (float) get_post_meta( $sub->assignment_id, '_tutor_assignment_total_mark', true );
            if ( $max_mark <= 0 && function_exists( 'tutor_utils' ) ) {
                $max_mark = (float) tutor_utils()->get_assignment_option( $sub->assignment_id, 'total_mark', 100 );
            }
            if ( $max_mark <= 0 ) {
                $max_mark = 100;
            }

            $is_evaluated = ( 'evaluated' === $eval_state || '' !== $mark_earned && false !== $mark_earned );

            if ( $is_evaluated ) {
                $total_graded++;
                $score_sum += (float) $mark_earned;
                $score_count++;
            } else {
                $total_pending++;
            }

            $course_title = get_the_title( $sub_course_id );

            $submissions[] = array(
                'id'             => $sub->submission_id,
                'type'           => 'assignment',
                'type_label'     => __( 'Assignment', 'growth-hub' ),
                'item_title'     => $sub->item_title,
                'course_id'      => $sub_course_id,
                'course_title'   => $course_title ? $course_title : __( 'Growth Hub Cohort', 'growth-hub' ),
                'student_id'     => $sub->student_id,
                'student_name'   => $student_name,
                'student_email'  => $student_email,
                'submitted_at'   => $sub->submitted_at,
                'status'         => $is_evaluated ? 'evaluated' : 'pending',
                'score'          => $is_evaluated ? (float) $mark_earned : null,
                'max_score'      => $max_mark,
                'feedback'       => $feedback,
                'student_text'   => $sub->student_text,
                'attachments'    => maybe_unserialize( $attachments ),
            );
        }
    }

    // 2B. Quiz Attempts requiring review (open-ended / essay questions)
    $attempts_table = $wpdb->prefix . 'tutor_quiz_attempts';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$attempts_table'" ) === $attempts_table ) {
        $quiz_attempts = $wpdb->get_results( "
            SELECT attempt_id, course_id, quiz_id, user_id, total_marks, earned_marks, attempt_status, attempt_ended_at
            FROM {$attempts_table}
            WHERE course_id IN ($in_courses)
            ORDER BY attempt_id DESC
        " );

        if ( ! empty( $quiz_attempts ) ) {
            foreach ( $quiz_attempts as $qa ) {
                $student = get_userdata( $qa->user_id );
                $student_name = $student ? $student->display_name : __( 'Student', 'growth-hub' );
                $student_email = $student ? $student->user_email : '';

                $is_evaluated = ( 'pending' !== $qa->attempt_status );
                if ( $is_evaluated ) {
                    $total_graded++;
                    $score_sum += (float) $qa->earned_marks;
                    $score_count++;
                } else {
                    $total_pending++;
                }

                $course_title = get_the_title( $qa->course_id );
                $quiz_title   = get_the_title( $qa->quiz_id );

                $submissions[] = array(
                    'id'             => $qa->attempt_id,
                    'type'           => 'quiz',
                    'type_label'     => __( 'Quiz', 'growth-hub' ),
                    'item_title'     => $quiz_title ? $quiz_title : __( 'Quiz Evaluation', 'growth-hub' ),
                    'course_id'      => $qa->course_id,
                    'course_title'   => $course_title ? $course_title : __( 'Growth Hub Cohort', 'growth-hub' ),
                    'student_id'     => $qa->user_id,
                    'student_name'   => $student_name,
                    'student_email'  => $student_email,
                    'submitted_at'   => $qa->attempt_ended_at ? $qa->attempt_ended_at : current_time( 'mysql' ),
                    'status'         => $is_evaluated ? 'evaluated' : 'pending',
                    'score'          => $is_evaluated ? (float) $qa->earned_marks : null,
                    'max_score'      => (float) $qa->total_marks > 0 ? (float) $qa->total_marks : 100,
                    'feedback'       => '',
                    'student_text'   => __( 'Quiz answers submitted for mentor review.', 'growth-hub' ),
                    'attachments'    => array(),
                );
            }
        }
    }
}

$total_submissions = count( $submissions );
$avg_score = $score_count > 0 ? round( $score_sum / $score_count, 1 ) : 0;
?>

<div class="gh-grading-page">
        <!-- Header -->
        <div class="gh-page-header">
            <div>
                <h1 class="gh-page-title"><?php esc_html_e( 'Grading & Submissions', 'growth-hub' ); ?></h1>
                <p class="gh-page-subtitle"><?php esc_html_e( 'Review and evaluate mentee assignments and quiz submissions across your assigned cohorts.', 'growth-hub' ); ?></p>
            </div>
        </div>

        <!-- 4 Stats Cards -->
        <div class="gh-stats-grid">
            <div class="gh-stat-card">
                <div class="gh-stat-icon-wrap" style="background: rgba(99, 47, 209, 0.1); color: #632FD1;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                </div>
                <div class="gh-stat-info">
                    <span class="gh-stat-label"><?php esc_html_e( 'Total Submissions', 'growth-hub' ); ?></span>
                    <span class="gh-stat-value" id="statTotal"><?php echo esc_html( $total_submissions ); ?></span>
                </div>
            </div>

            <div class="gh-stat-card">
                <div class="gh-stat-icon-wrap" style="background: rgba(245, 158, 11, 0.12); color: #D97706;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                </div>
                <div class="gh-stat-info">
                    <span class="gh-stat-label"><?php esc_html_e( 'Pending Review', 'growth-hub' ); ?></span>
                    <span class="gh-stat-value" id="statPending" style="color: #D97706;"><?php echo esc_html( $total_pending ); ?></span>
                </div>
            </div>

            <div class="gh-stat-card">
                <div class="gh-stat-icon-wrap" style="background: rgba(16, 185, 129, 0.12); color: #10B981;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                </div>
                <div class="gh-stat-info">
                    <span class="gh-stat-label"><?php esc_html_e( 'Graded', 'growth-hub' ); ?></span>
                    <span class="gh-stat-value" id="statGraded" style="color: #10B981;"><?php echo esc_html( $total_graded ); ?></span>
                </div>
            </div>

            <div class="gh-stat-card">
                <div class="gh-stat-icon-wrap" style="background: rgba(99, 47, 209, 0.1); color: #632FD1;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
                </div>
                <div class="gh-stat-info">
                    <span class="gh-stat-label"><?php esc_html_e( 'Average Score', 'growth-hub' ); ?></span>
                    <span class="gh-stat-value" id="statAvgScore"><?php echo esc_html( $avg_score > 0 ? $avg_score . ' pts' : '—' ); ?></span>
                </div>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="gh-grading-toolbar">
            <div class="gh-filter-tabs">
                <button type="button" class="gh-filter-tab active" data-status="all" onclick="ghFilterGradingStatus('all', this)">
                    <?php esc_html_e( 'All Submissions', 'growth-hub' ); ?>
                    <span class="gh-count-pill" id="badgeAll"><?php echo esc_html( $total_submissions ); ?></span>
                </button>
                <button type="button" class="gh-filter-tab" data-status="pending" onclick="ghFilterGradingStatus('pending', this)">
                    <?php esc_html_e( 'Pending Review', 'growth-hub' ); ?>
                    <span class="gh-count-pill gh-count-pending" id="badgePending"><?php echo esc_html( $total_pending ); ?></span>
                </button>
                <button type="button" class="gh-filter-tab" data-status="evaluated" onclick="ghFilterGradingStatus('evaluated', this)">
                    <?php esc_html_e( 'Graded', 'growth-hub' ); ?>
                    <span class="gh-count-pill" id="badgeGraded"><?php echo esc_html( $total_graded ); ?></span>
                </button>
            </div>

            <div class="gh-filter-controls">
                <!-- Search Input -->
                <div class="gh-search-input-box">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6B7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <input type="text" id="ghGradingSearch" placeholder="<?php esc_attr_e( 'Search mentee or title...', 'growth-hub' ); ?>" onkeyup="ghFilterGradingList()" />
                </div>

                <!-- Type Filter -->
                <div class="gh-select-wrap">
                    <select id="ghGradingTypeFilter" class="gh-custom-select" onchange="ghFilterGradingList()">
                        <option value="all"><?php esc_html_e( 'All Types', 'growth-hub' ); ?></option>
                        <option value="assignment"><?php esc_html_e( 'Assignments', 'growth-hub' ); ?></option>
                        <option value="quiz"><?php esc_html_e( 'Quizzes', 'growth-hub' ); ?></option>
                    </select>
                </div>

                <!-- Course Dropdown -->
                <div class="gh-select-wrap">
                    <select id="ghGradingCourseFilter" class="gh-custom-select" onchange="ghFilterGradingList()">
                        <option value="all"><?php esc_html_e( 'All Assigned Courses', 'growth-hub' ); ?></option>
                        <?php foreach ( $courses as $course ) : ?>
                            <option value="<?php echo esc_attr( $course->ID ); ?>"><?php echo esc_html( $course->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Submissions Table Card -->
        <div class="gh-section-card" style="padding: 0; overflow: hidden; border-radius: 16px;">
            <div class="gh-table-wrap" style="margin: 0;">
                <table class="gh-table" id="ghGradingTable">
                    <thead>
                        <tr>
                            <th style="padding-left: 24px;"><?php esc_html_e( 'MENTEE', 'growth-hub' ); ?></th>
                            <th><?php esc_html_e( 'ITEM & TITLE', 'growth-hub' ); ?></th>
                            <th><?php esc_html_e( 'COURSE / COHORT', 'growth-hub' ); ?></th>
                            <th><?php esc_html_e( 'SUBMITTED', 'growth-hub' ); ?></th>
                            <th><?php esc_html_e( 'SCORE', 'growth-hub' ); ?></th>
                            <th><?php esc_html_e( 'STATUS', 'growth-hub' ); ?></th>
                            <th style="text-align: right; padding-right: 24px;"><?php esc_html_e( 'ACTION', 'growth-hub' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="ghGradingTableBody">
                        <?php if ( empty( $submissions ) ) : ?>
                            <tr id="ghGradingEmptyRow">
                                <td colspan="7" class="gh-empty-state-cell">
                                    <div class="gh-empty-state-inner">
                                        <div class="gh-empty-icon" style="background: rgba(99, 47, 209, 0.08); color: #632FD1;">
                                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                                        </div>
                                        <h4 style="margin: 12px 0 6px; font-size: 17px; color: #1B1240; font-family: 'Poppins', sans-serif;"><?php esc_html_e( 'No Submissions Awaiting Grading', 'growth-hub' ); ?></h4>
                                        <p style="margin: 0; color: #6B7280; font-size: 14px; max-width: 440px; line-height: 1.5;"><?php esc_html_e( 'When mentees submit assignments or quizzes requiring review across your assigned cohorts, they will appear here.', 'growth-hub' ); ?></p>
                                    </div>
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $submissions as $sub ) : 
                                $initials = '';
                                $parts = explode( ' ', trim( $sub['student_name'] ) );
                                foreach ( array_slice( $parts, 0, 2 ) as $p ) {
                                    $initials .= strtoupper( mb_substr( $p, 0, 1 ) );
                                }
                                if ( empty( $initials ) ) $initials = 'ST';
                            ?>
                                <tr class="gh-grading-row" 
                                    data-id="<?php echo esc_attr( $sub['id'] ); ?>"
                                    data-type="<?php echo esc_attr( $sub['type'] ); ?>"
                                    data-status="<?php echo esc_attr( $sub['status'] ); ?>"
                                    data-course-id="<?php echo esc_attr( $sub['course_id'] ); ?>"
                                    data-student-name="<?php echo esc_attr( $sub['student_name'] ); ?>"
                                    data-student-email="<?php echo esc_attr( $sub['student_email'] ); ?>"
                                    data-item-title="<?php echo esc_attr( $sub['item_title'] ); ?>"
                                    data-course-title="<?php echo esc_attr( $sub['course_title'] ); ?>"
                                    data-score="<?php echo esc_attr( $sub['score'] !== null ? $sub['score'] : '' ); ?>"
                                    data-max-score="<?php echo esc_attr( $sub['max_score'] ); ?>"
                                    data-feedback="<?php echo esc_attr( $sub['feedback'] ); ?>"
                                    data-submitted-at="<?php echo esc_attr( date_i18n( 'M j, Y g:i A', strtotime( $sub['submitted_at'] ) ) ); ?>"
                                    data-student-text="<?php echo esc_attr( $sub['student_text'] ); ?>"
                                    data-attachments="<?php echo esc_attr( json_encode( $sub['attachments'] ) ); ?>">
                                    
                                    <!-- Mentee Column -->
                                    <td style="padding-left: 24px;">
                                        <div class="gh-mentee-cell">
                                            <div class="gh-avatar-circle"><?php echo esc_html( $initials ); ?></div>
                                            <div class="gh-mentee-meta">
                                                <strong class="gh-mentee-name"><?php echo esc_html( $sub['student_name'] ); ?></strong>
                                                <span class="gh-mentee-email"><?php echo esc_html( $sub['student_email'] ); ?></span>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Item & Title Column -->
                                    <td>
                                        <div class="gh-item-cell">
                                            <?php if ( 'quiz' === $sub['type'] ) : ?>
                                                <span class="gh-pill-badge gh-badge-quiz"><?php esc_html_e( 'Quiz', 'growth-hub' ); ?></span>
                                            <?php else : ?>
                                                <span class="gh-pill-badge gh-badge-assignment"><?php esc_html_e( 'Assignment', 'growth-hub' ); ?></span>
                                            <?php endif; ?>
                                            <span class="gh-item-title"><?php echo esc_html( $sub['item_title'] ); ?></span>
                                        </div>
                                    </td>

                                    <!-- Course Column -->
                                    <td>
                                        <span class="gh-course-pill"><?php echo esc_html( $sub['course_title'] ); ?></span>
                                    </td>

                                    <!-- Submitted Date Column -->
                                    <td>
                                        <span class="gh-date-text"><?php echo esc_html( human_time_diff( strtotime( $sub['submitted_at'] ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'growth-hub' ) ); ?></span>
                                    </td>

                                    <!-- Score Column -->
                                    <td>
                                        <?php if ( 'evaluated' === $sub['status'] && $sub['score'] !== null ) : ?>
                                            <strong class="gh-score-val"><?php echo esc_html( $sub['score'] ); ?></strong>
                                            <span class="gh-score-max">/ <?php echo esc_html( $sub['max_score'] ); ?></span>
                                        <?php else : ?>
                                            <span class="gh-score-pending">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Status Column -->
                                    <td>
                                        <?php if ( 'evaluated' === $sub['status'] ) : ?>
                                            <span class="gh-status-badge gh-status-graded">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                <?php esc_html_e( 'Graded', 'growth-hub' ); ?>
                                            </span>
                                        <?php else : ?>
                                            <span class="gh-status-badge gh-status-pending">
                                                <span class="gh-dot-pulse"></span>
                                                <?php esc_html_e( 'Pending Review', 'growth-hub' ); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Action Column -->
                                    <td style="text-align: right; padding-right: 24px;">
                                        <?php if ( 'evaluated' === $sub['status'] ) : ?>
                                            <button type="button" class="gh-btn-edit-grade" onclick="ghOpenGradingModal(this)">
                                                <?php esc_html_e( 'View & Edit', 'growth-hub' ); ?>
                                            </button>
                                        <?php else : ?>
                                            <button type="button" class="gh-btn-evaluate" onclick="ghOpenGradingModal(this)">
                                                <?php esc_html_e( 'Evaluate', 'growth-hub' ); ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<!-- Evaluation Modal -->
<div class="gh-modal-overlay" id="ghGradingModalOverlay" style="display: none;" onclick="if(event.target===this) ghCloseGradingModal();">
    <div class="gh-modal-card" style="max-width: 620px;">
        <div class="gh-modal-header">
            <div>
                <h3 class="gh-modal-title" id="modalTitle"><?php esc_html_e( 'Evaluate Submission', 'growth-hub' ); ?></h3>
                <span class="gh-modal-subtitle" id="modalSubtitle"><?php esc_html_e( 'Review mentee work and record score with feedback', 'growth-hub' ); ?></span>
            </div>
            <button type="button" class="gh-modal-close" onclick="ghCloseGradingModal()">&times;</button>
        </div>

        <form id="ghGradingForm" onsubmit="ghSubmitGradeForm(event)">
            <input type="hidden" id="gradeItemType" value="assignment">
            <input type="hidden" id="gradeSubmissionId" value="">

            <!-- Mentee & Item Summary Banner -->
            <div class="gh-eval-summary-banner">
                <div class="gh-eval-avatar" id="modalStudentAvatar">FA</div>
                <div class="gh-eval-meta">
                    <h4 id="modalStudentName">Favour Adeleke</h4>
                    <p id="modalItemTitle">Module 3 Assignment &bull; 6 Months Data Analytics Course</p>
                    <span class="gh-eval-time" id="modalSubmittedAt">Submitted 2 hours ago</span>
                </div>
            </div>

            <!-- Student Submission Content -->
            <div class="gh-form-group">
                <label style="font-weight: 600; color: #1B1240;"><?php esc_html_e( 'Mentee Submission / Work', 'growth-hub' ); ?></label>
                <div class="gh-student-submission-box" id="modalStudentContent">
                    <!-- Text or files -->
                </div>
            </div>

            <!-- Score Input Row -->
            <div class="gh-form-row" style="display: flex; gap: 16px; align-items: flex-start;">
                <div class="gh-form-group" style="flex: 1 1 200px;">
                    <label style="font-weight: 600; color: #1B1240;"><?php esc_html_e( 'Score Earned', 'growth-hub' ); ?> *</label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" step="0.5" min="0" id="gradeScoreInput" class="gh-input-text" placeholder="0" required style="max-width: 130px; font-weight: 700; font-size: 16px;">
                        <span style="font-weight: 600; color: #8C85A6; font-size: 15px;">/ <span id="modalMaxScore">100</span> pts</span>
                    </div>
                </div>
            </div>

            <!-- Instructor Feedback -->
            <div class="gh-form-group">
                <label style="font-weight: 600; color: #1B1240;"><?php esc_html_e( 'Mentor Feedback & Guidance', 'growth-hub' ); ?></label>
                <textarea id="gradeFeedbackInput" class="gh-input-textarea" rows="4" placeholder="<?php esc_attr_e( 'Write constructive feedback, praise strengths, and guide the mentee on next steps...', 'growth-hub' ); ?>"></textarea>
            </div>

            <!-- Modal Footer -->
            <div class="gh-modal-footer">
                <button type="button" class="gh-btn-secondary" onclick="ghCloseGradingModal()"><?php esc_html_e( 'Cancel', 'growth-hub' ); ?></button>
                <button type="submit" class="gh-btn-primary" id="btnSubmitGrade" style="background-color: #632FD1 !important; color: #FFFFFF !important;">
                    <?php esc_html_e( 'Submit Grade', 'growth-hub' ); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Grading Page Specific Styles */
.gh-grading-page {
    max-width: 1320px;
    margin: 0 auto;
    padding-bottom: 60px;
    width: 100%;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    gap: 24px;
    font-family: 'Lato', -apple-system, BlinkMacSystemFont, sans-serif !important;
    color: #1B1240 !important;
}

.gh-stats-grid {
    display: grid !important;
    grid-template-columns: repeat(4, 1fr) !important;
    gap: 20px !important;
}

.gh-stat-card {
    background: #FFFFFF !important;
    border: 1px solid #EBE7F7 !important;
    border-radius: 16px !important;
    padding: 20px 22px !important;
    display: flex !important;
    align-items: center !important;
    gap: 16px !important;
    box-shadow: 0 2px 10px rgba(27, 18, 64, 0.03) !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease !important;
}

.gh-stat-card:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 16px rgba(27, 18, 64, 0.06) !important;
}

.gh-qa-search-bar input,
.gh-qa-search-bar input[type="text"],
.gh-qa-search-bar input:focus,
.gh-qa-search-bar input[type="text"]:focus {
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
    background: transparent !important;
    width: 100% !important;
    height: 100% !important;
    min-height: 100% !important;
    padding: 0 !important;
    margin: 0 !important;
    font-family: 'Lato', sans-serif !important;
    font-size: 14px !important;
    color: #1B1240 !important;
}

.gh-stat-icon-wrap {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.gh-stat-info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.gh-stat-label {
    font-size: 13px;
    color: #6B7280;
    font-family: 'Lato', sans-serif;
    font-weight: 500;
}

.gh-stat-value {
    font-size: 24px;
    font-weight: 700;
    color: #1B1240;
    font-family: 'Poppins', sans-serif;
    line-height: 1.2;
}

/* Toolbar */
.gh-grading-toolbar {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    gap: 16px !important;
    flex-wrap: wrap !important;
    margin-bottom: 24px !important;
    width: 100% !important;
    box-sizing: border-box !important;
}

.gh-filter-tabs {
    display: inline-flex !important;
    background: #F4F1FB !important;
    padding: 4px !important;
    border-radius: 12px !important;
    gap: 4px !important;
    align-items: center !important;
    flex-shrink: 0 !important;
    max-width: 100% !important;
    overflow-x: auto !important;
    -webkit-overflow-scrolling: touch !important;
    box-sizing: border-box !important;
}

.gh-filter-tab {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    padding: 8px 16px !important;
    background: transparent !important;
    border: none !important;
    border-radius: 9px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    color: #6B7280 !important;
    cursor: pointer !important;
    transition: all 0.15s ease !important;
    outline: none !important;
    text-decoration: none !important;
    white-space: nowrap !important;
    flex-shrink: 0 !important;
}

.gh-filter-tab:hover {
    color: #1B1240 !important;
}

.gh-filter-tab.active {
    background: #FFFFFF !important;
    color: #632FD1 !important;
    box-shadow: 0 2px 6px rgba(99, 47, 209, 0.12) !important;
}

.gh-count-pill {
    font-size: 11.5px !important;
    padding: 2px 7px !important;
    border-radius: 20px !important;
    background: #E6E1F6 !important;
    color: #632FD1 !important;
    font-weight: 700 !important;
}

.gh-count-pending {
    background: #FEF3C7 !important;
    color: #D97706 !important;
}

.gh-filter-controls {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    flex-wrap: wrap !important;
}

/* Toolbar Search Input Box */
.gh-grading-toolbar .gh-search-input-box {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    background: #FFFFFF !important;
    border: 1px solid #D8D2EE !important;
    border-radius: 12px !important;
    padding: 0 14px !important;
    height: 48px !important;
    min-height: 48px !important;
    width: 240px !important;
    max-width: 280px !important;
    box-sizing: border-box !important;
    transition: border-color 0.2s ease, box-shadow 0.2s ease !important;
}

.gh-grading-toolbar .gh-search-input-box:focus-within {
    border-color: #632FD1 !important;
    box-shadow: 0 0 0 3px rgba(99, 47, 209, 0.12) !important;
}

.gh-grading-toolbar .gh-search-input-box svg {
    flex-shrink: 0 !important;
    stroke: #6B7280 !important;
}

.gh-grading-toolbar .gh-search-input-box input,
.gh-grading-toolbar .gh-search-input-box input[type="text"] {
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
    background: transparent !important;
    font-family: 'Lato', sans-serif !important;
    font-size: 13.5px !important;
    color: #1B1240 !important;
    width: 100% !important;
    height: 100% !important;
    min-height: 100% !important;
    padding: 0 !important;
    margin: 0 !important;
}

/* Toolbar Custom Select Dropdowns */
.gh-grading-toolbar .gh-select-wrap {
    position: relative !important;
    display: inline-block !important;
}

.gh-grading-toolbar select.gh-custom-select {
    display: inline-block !important;
    width: auto !important;
    min-width: 140px !important;
    max-width: 240px !important;
    height: 48px !important;
    min-height: 48px !important;
    line-height: 46px !important;
    padding: 0 38px 0 16px !important;
    font-family: 'Lato', sans-serif !important;
    font-size: 13.5px !important;
    font-weight: 500 !important;
    color: #1B1240 !important;
    background-color: #FFFFFF !important;
    border: 1px solid #D8D2EE !important;
    border-radius: 12px !important;
    outline: none !important;
    cursor: pointer !important;
    appearance: none !important;
    -webkit-appearance: none !important;
    -moz-appearance: none !important;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg width='12' height='8' viewBox='0 0 12 8' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1.5L6 6.5L11 1.5' stroke='%23632FD1' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: calc(100% - 14px) center !important;
    box-sizing: border-box !important;
    transition: border-color 0.2s ease, box-shadow 0.2s ease !important;
}

.gh-grading-toolbar select.gh-custom-select:focus {
    border-color: #632FD1 !important;
    box-shadow: 0 0 0 3px rgba(99, 47, 209, 0.12) !important;
}

/* Mentee Cell */
.gh-mentee-cell {
    display: flex;
    align-items: center;
    gap: 12px;
}

.gh-avatar-circle {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: linear-gradient(135deg, #632FD1 0%, #8B5CF6 100%);
    color: #FFFFFF;
    font-weight: 700;
    font-size: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    letter-spacing: 0.5px;
    flex-shrink: 0;
}

.gh-mentee-meta {
    display: flex;
    flex-direction: column;
}

.gh-mentee-name {
    color: #1B1240;
    font-size: 14.5px;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
}

.gh-mentee-email {
    color: #8C85A6;
    font-size: 12.5px;
    font-family: 'Lato', sans-serif;
}

/* Item & Title */
.gh-item-cell {
    display: flex;
    align-items: center;
    gap: 8px;
}

.gh-pill-badge {
    font-size: 11.5px;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 6px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: inline-block;
    flex-shrink: 0;
}

.gh-badge-assignment {
    background: #EDE8F8 !important;
    color: #632FD1 !important;
}

.gh-badge-quiz {
    background: #FEF3C7 !important;
    color: #D97706 !important;
}

.gh-item-title {
    color: #1B1240;
    font-size: 14px;
    font-weight: 600;
    font-family: 'Lato', sans-serif;
}

.gh-course-pill {
    background: #F4F1FB;
    color: #5224B8;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 12.5px;
    font-weight: 600;
    display: inline-block;
    max-width: 220px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.gh-date-text {
    font-size: 13px;
    color: #6B7280;
    font-family: 'Lato', sans-serif;
}

.gh-score-val {
    font-size: 15px;
    color: #10B981;
    font-weight: 700;
}

.gh-score-max {
    font-size: 13px;
    color: #8C85A6;
}

.gh-score-pending {
    color: #8C85A6;
    font-weight: 700;
}

/* Status Badges */
.gh-status-badge {
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    padding: 5px 12px !important;
    border-radius: 20px !important;
    font-size: 12.5px !important;
    font-weight: 600 !important;
    font-family: 'Lato', sans-serif !important;
    white-space: nowrap !important;
    flex-shrink: 0 !important;
}

.gh-status-pending {
    background: #FEF3C7;
    color: #B45309;
}

.gh-dot-pulse {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #D97706;
    box-shadow: 0 0 0 2px rgba(217, 119, 6, 0.25);
}

.gh-status-graded {
    background: #D1FAE5;
    color: #065F46;
}

/* Action Buttons */
.gh-btn-evaluate {
    background: #632FD1 !important;
    color: #FFFFFF !important;
    border: none !important;
    padding: 7px 16px !important;
    border-radius: 9px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    transition: background-color 0.15s ease !important;
}

.gh-btn-evaluate:hover {
    background: #5224B8 !important;
}

.gh-btn-edit-grade {
    background: #FFFFFF !important;
    color: #632FD1 !important;
    border: 1.5px solid #D8D2EE !important;
    padding: 6px 14px !important;
    border-radius: 9px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    transition: all 0.15s ease !important;
}

.gh-btn-edit-grade:hover {
    border-color: #632FD1 !important;
    background: #F4F1FB !important;
}

/* Modal Overlay System */
.gh-modal-overlay {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    background: rgba(27, 18, 64, 0.6) !important;
    backdrop-filter: blur(4px) !important;
    display: none !important;
    align-items: center !important;
    justify-content: center !important;
    z-index: 99999 !important;
    padding: 20px !important;
    box-sizing: border-box !important;
}

.gh-modal-overlay.active {
    display: flex !important;
}

.gh-modal-card {
    background: #FFFFFF !important;
    border-radius: 20px !important;
    width: 100% !important;
    max-width: 620px !important;
    padding: 28px !important;
    box-shadow: 0 20px 40px rgba(27, 18, 64, 0.2) !important;
    max-height: 90vh !important;
    overflow-y: auto !important;
    box-sizing: border-box !important;
}

.gh-modal-header {
    display: flex !important;
    justify-content: space-between !important;
    align-items: flex-start !important;
    margin-bottom: 20px !important;
}

.gh-modal-title {
    font-size: 20px !important;
    color: #1B1240 !important;
    margin: 0 0 4px !important;
}

.gh-modal-subtitle {
    font-size: 13px !important;
    color: #6B7280 !important;
}

.gh-modal-close {
    background: none !important;
    border: none !important;
    font-size: 24px !important;
    color: #8C85A6 !important;
    cursor: pointer !important;
    line-height: 1 !important;
    padding: 2px !important;
}

.gh-modal-close:hover {
    color: #1B1240 !important;
}

.gh-modal-footer {
    display: flex !important;
    justify-content: flex-end !important;
    gap: 12px !important;
    margin-top: 24px !important;
}

/* Evaluation Modal Enhancements */
.gh-eval-summary-banner {
    display: flex;
    align-items: center;
    gap: 14px;
    background: #F4F1FB;
    border: 1px solid #E6E1F6;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 18px;
}

.gh-eval-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, #632FD1 0%, #8B5CF6 100%);
    color: #FFFFFF;
    font-weight: 700;
    font-size: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.gh-eval-meta h4 {
    margin: 0 0 2px;
    font-size: 15px;
    color: #1B1240;
    font-family: 'Poppins', sans-serif;
}

.gh-eval-meta p {
    margin: 0;
    font-size: 13px;
    color: #632FD1;
    font-weight: 500;
}

.gh-eval-time {
    font-size: 12px;
    color: #8C85A6;
}

.gh-student-submission-box {
    background: #FAF9FE;
    border: 1.5px solid #E6E1F6;
    border-left: 4px solid #632FD1;
    border-radius: 8px;
    padding: 14px 16px;
    font-size: 14px;
    color: #1B1240;
    line-height: 1.6;
    max-height: 200px;
    overflow-y: auto;
}

.gh-empty-state-inner {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 24px;
    text-align: center;
}

.gh-empty-icon {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* ==========================================================================
   Responsive Breakpoints - Final Cascade Dominance
   ========================================================================== */
@media (max-width: 1024px) {
    .gh-stats-grid {
        grid-template-columns: repeat(2, 1fr) !important;
    }
    .gh-grading-toolbar {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 14px !important;
    }
    .gh-filter-tabs {
        width: 100% !important;
        box-sizing: border-box !important;
    }
    .gh-filter-controls {
        width: 100% !important;
        justify-content: flex-start !important;
        flex-wrap: wrap !important;
    }
}

@media (max-width: 768px) {
    .gh-grading-toolbar {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 12px !important;
        width: 100% !important;
    }
    .gh-filter-tabs {
        width: 100% !important;
        display: flex !important;
        flex-wrap: nowrap !important;
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
        scrollbar-width: none !important;
        box-sizing: border-box !important;
        padding: 4px !important;
    }
    .gh-filter-tabs::-webkit-scrollbar {
        display: none !important;
    }
    .gh-filter-tab {
        white-space: nowrap !important;
        flex-shrink: 0 !important;
        padding: 7px 12px !important;
        font-size: 12.5px !important;
    }
    .gh-filter-controls {
        width: 100% !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 10px !important;
    }
    .gh-grading-toolbar .gh-search-input-box {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box !important;
    }
    .gh-grading-toolbar .gh-select-wrap {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        display: block !important;
    }
    .gh-grading-toolbar select.gh-custom-select {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box !important;
        display: block !important;
    }
}

@media (max-width: 640px) {
    .gh-stats-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>

<script>
var ghCurrentGradingStatus = 'all';

function ghFilterGradingStatus(status, btn) {
    ghCurrentGradingStatus = status;
    document.querySelectorAll('.gh-filter-tab').forEach(function(t) {
        t.classList.remove('active');
    });
    btn.classList.add('active');
    ghFilterGradingList();
}

function ghFilterGradingList() {
    var searchVal = (document.getElementById('ghGradingSearch').value || '').toLowerCase().trim();
    var courseVal = document.getElementById('ghGradingCourseFilter').value;
    var typeVal   = document.getElementById('ghGradingTypeFilter').value;

    var rows = document.querySelectorAll('.gh-grading-row');
    var visibleCount = 0;

    rows.forEach(function(row) {
        var rStatus   = row.getAttribute('data-status');
        var rCourseId = row.getAttribute('data-course-id');
        var rType     = row.getAttribute('data-type');
        var rName     = (row.getAttribute('data-student-name') || '').toLowerCase();
        var rItem     = (row.getAttribute('data-item-title') || '').toLowerCase();
        var rCourse   = (row.getAttribute('data-course-title') || '').toLowerCase();

        var matchStatus = (ghCurrentGradingStatus === 'all' || rStatus === ghCurrentGradingStatus);
        var matchCourse = (courseVal === 'all' || rCourseId === courseVal);
        var matchType   = (typeVal === 'all' || rType === typeVal);
        var matchSearch = (!searchVal || rName.indexOf(searchVal) > -1 || rItem.indexOf(searchVal) > -1 || rCourse.indexOf(searchVal) > -1);

        if (matchStatus && matchCourse && matchType && matchSearch) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    var emptyRow = document.getElementById('ghGradingEmptyRow');
    if (emptyRow) {
        emptyRow.style.display = (visibleCount === 0 && rows.length > 0) ? '' : (rows.length === 0 ? '' : 'none');
    }
}

var ghActiveRow = null;

function ghOpenGradingModal(btn) {
    var row = btn.closest('.gh-grading-row');
    if (!row) return;
    ghActiveRow = row;

    var id          = row.getAttribute('data-id');
    var type        = row.getAttribute('data-type');
    var name        = row.getAttribute('data-student-name');
    var item        = row.getAttribute('data-item-title');
    var course      = row.getAttribute('data-course-title');
    var submittedAt = row.getAttribute('data-submitted-at');
    var score       = row.getAttribute('data-score');
    var maxScore    = row.getAttribute('data-max-score') || '100';
    var feedback    = row.getAttribute('data-feedback') || '';
    var studentText = row.getAttribute('data-student-text') || '';
    var attachRaw   = row.getAttribute('data-attachments');

    document.getElementById('gradeSubmissionId').value = id;
    document.getElementById('gradeItemType').value     = type;
    document.getElementById('modalStudentName').textContent = name;
    document.getElementById('modalItemTitle').textContent   = item + ' • ' + course;
    document.getElementById('modalSubmittedAt').textContent = 'Submitted ' + submittedAt;
    document.getElementById('modalMaxScore').textContent    = maxScore;
    document.getElementById('gradeScoreInput').max          = maxScore;
    document.getElementById('gradeScoreInput').value        = score;
    document.getElementById('gradeFeedbackInput').value     = feedback;

    // Initials avatar
    var initials = '';
    var parts = (name || '').trim().split(' ');
    for (var i = 0; i < Math.min(2, parts.length); i++) {
        if (parts[i].length > 0) initials += parts[i].charAt(0).toUpperCase();
    }
    document.getElementById('modalStudentAvatar').textContent = initials || 'ST';

    // Content
    var contentBox = document.getElementById('modalStudentContent');
    contentBox.innerHTML = '';
    if (studentText) {
        var p = document.createElement('p');
        p.style.margin = '0 0 10px 0';
        p.textContent = studentText;
        contentBox.appendChild(p);
    }

    try {
        var attachments = JSON.parse(attachRaw);
        if (Array.isArray(attachments) && attachments.length > 0) {
            var attachWrap = document.createElement('div');
            attachWrap.style.marginTop = '8px';
            attachWrap.innerHTML = '<strong>Attached Files:</strong>';
            attachments.forEach(function(att) {
                var link = document.createElement('a');
                link.href = att.url || '#';
                link.target = '_blank';
                link.textContent = ' 📄 ' + (att.name || 'Submission File');
                link.style.display = 'block';
                link.style.color = '#632FD1';
                link.style.marginTop = '4px';
                attachWrap.appendChild(link);
            });
            contentBox.appendChild(attachWrap);
        }
    } catch(e) {}

    if (!contentBox.innerHTML) {
        contentBox.innerHTML = '<em style="color: #8C85A6;">No text submission details provided.</em>';
    }

    var overlay = document.getElementById('ghGradingModalOverlay');
    if (overlay) {
        overlay.classList.add('active');
        overlay.style.setProperty('display', 'flex', 'important');
    }
}

function ghCloseGradingModal() {
    var overlay = document.getElementById('ghGradingModalOverlay');
    if (overlay) {
        overlay.classList.remove('active');
        overlay.style.setProperty('display', 'none', 'important');
    }
    ghActiveRow = null;
}

function ghSubmitGradeForm(e) {
    e.preventDefault();

    var subId    = document.getElementById('gradeSubmissionId').value;
    var itemType = document.getElementById('gradeItemType').value;
    var score    = parseFloat(document.getElementById('gradeScoreInput').value);
    var feedback = document.getElementById('gradeFeedbackInput').value.trim();
    var maxScore = parseFloat(document.getElementById('modalMaxScore').textContent) || 100;

    if (isNaN(score) || score < 0) {
        if (typeof ghToast === 'function') {
            ghToast('Please enter a valid numeric score.', 'error');
        }
        return;
    }

    if (score > maxScore) {
        if (typeof ghToast === 'function') {
            ghToast('Score cannot exceed maximum score of ' + maxScore + ' pts.', 'error');
        }
        return;
    }
    }

    var submitBtn = document.getElementById('btnSubmitGrade');
    var originalText = submitBtn.textContent;
    submitBtn.textContent = 'Saving...';
    submitBtn.disabled = true;

    // AJAX Call
    var formData = new FormData();
    formData.append('action', 'gh_submit_grade');
    formData.append('submission_id', subId);
    formData.append('item_type', itemType);
    formData.append('score', score);
    formData.append('feedback', feedback);
    if (typeof ghPortalData !== 'undefined' && ghPortalData.nonce) {
        formData.append('nonce', ghPortalData.nonce);
    }

    fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;

        if (data.success) {
            if (typeof ghToast === 'function') {
                ghToast(data.data && data.data.message ? data.data.message : 'Grade and feedback submitted successfully!', 'success');
            }

            // Update row in table
            if (ghActiveRow) {
                ghActiveRow.setAttribute('data-status', 'evaluated');
                ghActiveRow.setAttribute('data-score', score);
                ghActiveRow.setAttribute('data-feedback', feedback);

                // Update score cell
                var scoreCell = ghActiveRow.querySelector('td:nth-child(5)');
                if (scoreCell) {
                    scoreCell.innerHTML = '<strong class="gh-score-val">' + score + '</strong> <span class="gh-score-max">/ ' + maxScore + '</span>';
                }

                // Update status cell
                var statusCell = ghActiveRow.querySelector('td:nth-child(6)');
                if (statusCell) {
                    statusCell.innerHTML = '<span class="gh-status-badge gh-status-graded"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg> Graded</span>';
                }

                // Update action button
                var actionCell = ghActiveRow.querySelector('td:nth-child(7)');
                if (actionCell) {
                    actionCell.innerHTML = '<button type="button" class="gh-btn-edit-grade" onclick="ghOpenGradingModal(this)">View & Edit</button>';
                }
            }

            ghCloseGradingModal();
        } else {
            var errMsg = (data.data && data.data.message) ? data.data.message : 'Failed to submit grade.';
            if (typeof ghToast === 'function') {
                ghToast(errMsg, 'error');
            }
        }
    })
    .catch(function() {
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
        if (typeof ghToast === 'function') {
            ghToast('Network error while saving grade.', 'error');
        }
    });
}
</script>

