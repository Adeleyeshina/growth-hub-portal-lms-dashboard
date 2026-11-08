<?php
/**
 * Tutor LMS - Mentor Mentees & Students Template
 *
 * Growth Hub Portal - Instructor Mentee Roster & Progress Tracking
 * Displays all enrolled learners across the logged-in mentor's assigned courses.
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
        <p style="color: #6B7280; font-family: 'Lato', sans-serif;"><?php esc_html_e( 'You must be logged in as an instructor or mentor to view enrolled mentees.', 'growth-hub' ); ?></p>
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

// 2. Query real enrolled mentees across these courses
$mentees = array();
$total_on_track = 0;
$total_in_prog  = 0;
$total_attention = 0;
$total_completed = 0;
$sum_progress    = 0;

if ( ! empty( $course_ids ) ) {
    $in_courses = implode( ',', array_map( 'intval', $course_ids ) );

    $enrollment_rows = $wpdb->get_results( "
        SELECT p.ID as enrollment_id, p.post_author as student_id, p.post_parent as course_id, p.post_date as enrolled_at
        FROM {$wpdb->posts} p
        WHERE p.post_type = 'tutor_enrolled'
          AND p.post_status = 'completed'
          AND p.post_parent IN ($in_courses)
        ORDER BY p.ID DESC
    " );

    if ( ! empty( $enrollment_rows ) ) {
        foreach ( $enrollment_rows as $row ) {
            $student = get_userdata( $row->student_id );
            if ( ! $student ) continue;

            $c_id = (int) $row->course_id;
            $course_title = get_the_title( $c_id );

            // Calculate Progress
            $progress = 0;
            if ( function_exists( 'tutor_utils' ) ) {
                $progress = (int) tutor_utils()->get_course_completed_percent( $c_id, $row->student_id );
            }
            if ( $progress < 0 ) $progress = 0;
            if ( $progress > 100 ) $progress = 100;

            $sum_progress += $progress;

            // Status categorization
            if ( $progress >= 100 ) {
                $status_key   = 'completed';
                $status_label = __( 'Completed', 'growth-hub' );
                $total_completed++;
            } elseif ( $progress >= 50 ) {
                $status_key   = 'on_track';
                $status_label = __( 'On Track', 'growth-hub' );
                $total_on_track++;
            } elseif ( $progress >= 20 ) {
                $status_key   = 'in_progress';
                $status_label = __( 'In Progress', 'growth-hub' );
                $total_in_prog++;
            } else {
                $status_key   = 'needs_attention';
                $status_label = __( 'Needs Attention', 'growth-hub' );
                $total_attention++;
            }

            $mentees[] = array(
                'id'            => $row->student_id,
                'name'          => $student->display_name ? $student->display_name : $student->user_login,
                'email'         => $student->user_email,
                'course_id'     => $c_id,
                'course_title'  => $course_title ? $course_title : __( 'Growth Hub Cohort', 'growth-hub' ),
                'enrolled_at'   => $row->enrolled_at,
                'progress'      => $progress,
                'status_key'    => $status_key,
                'status_label'  => $status_label,
            );
        }
    }
}

$total_mentees = count( $mentees );
$avg_progress  = $total_mentees > 0 ? round( $sum_progress / $total_mentees ) : 0;
?>

<div class="gh-mentees-page">
        <!-- Page Header -->
        <div class="gh-page-header">
            <div>
                <h1 class="gh-page-title"><?php esc_html_e( 'Mentees & Students', 'growth-hub' ); ?></h1>
                <p class="gh-page-subtitle"><?php esc_html_e( 'Monitor enrolled learners, track course completion progress, and reach out directly to mentees.', 'growth-hub' ); ?></p>
            </div>
        </div>

        <!-- 4 Stats Cards -->
        <div class="gh-stats-grid">
            <div class="gh-stat-card">
                <div class="gh-stat-icon-wrap" style="background: rgba(99, 47, 209, 0.1); color: #632FD1;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                </div>
                <div class="gh-stat-info">
                    <span class="gh-stat-label"><?php esc_html_e( 'Total Mentees', 'growth-hub' ); ?></span>
                    <span class="gh-stat-value" id="statMenteesTotal"><?php echo esc_html( $total_mentees ); ?></span>
                </div>
            </div>

            <div class="gh-stat-card">
                <div class="gh-stat-icon-wrap" style="background: rgba(16, 185, 129, 0.12); color: #10B981;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                </div>
                <div class="gh-stat-info">
                    <span class="gh-stat-label"><?php esc_html_e( 'On Track', 'growth-hub' ); ?></span>
                    <span class="gh-stat-value" id="statMenteesOnTrack" style="color: #10B981;"><?php echo esc_html( $total_on_track ); ?></span>
                </div>
            </div>

            <div class="gh-stat-card">
                <div class="gh-stat-icon-wrap" style="background: rgba(245, 158, 11, 0.12); color: #D97706;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                </div>
                <div class="gh-stat-info">
                    <span class="gh-stat-label"><?php esc_html_e( 'Needs Attention', 'growth-hub' ); ?></span>
                    <span class="gh-stat-value" id="statMenteesAttention" style="color: #D97706;"><?php echo esc_html( $total_attention ); ?></span>
                </div>
            </div>

            <div class="gh-stat-card">
                <div class="gh-stat-icon-wrap" style="background: rgba(99, 47, 209, 0.1); color: #632FD1;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                </div>
                <div class="gh-stat-info">
                    <span class="gh-stat-label"><?php esc_html_e( 'Average Progress', 'growth-hub' ); ?></span>
                    <span class="gh-stat-value" id="statMenteesAvg"><?php echo esc_html( $avg_progress ); ?>%</span>
                </div>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="gh-mentees-toolbar">
            <div class="gh-filter-tabs">
                <button type="button" class="gh-filter-tab active" data-status="all" onclick="ghFilterMenteesStatus('all', this)">
                    <?php esc_html_e( 'All Mentees', 'growth-hub' ); ?>
                    <span class="gh-count-pill" id="badgeMenteesAll"><?php echo esc_html( $total_mentees ); ?></span>
                </button>
                <button type="button" class="gh-filter-tab" data-status="on_track" onclick="ghFilterMenteesStatus('on_track', this)">
                    <?php esc_html_e( 'On Track', 'growth-hub' ); ?>
                    <span class="gh-count-pill gh-count-on-track" id="badgeMenteesOnTrack"><?php echo esc_html( $total_on_track ); ?></span>
                </button>
                <button type="button" class="gh-filter-tab" data-status="in_progress" onclick="ghFilterMenteesStatus('in_progress', this)">
                    <?php esc_html_e( 'In Progress', 'growth-hub' ); ?>
                    <span class="gh-count-pill" id="badgeMenteesInProg"><?php echo esc_html( $total_in_prog ); ?></span>
                </button>
                <button type="button" class="gh-filter-tab" data-status="needs_attention" onclick="ghFilterMenteesStatus('needs_attention', this)">
                    <?php esc_html_e( 'Needs Attention', 'growth-hub' ); ?>
                    <span class="gh-count-pill gh-count-attention" id="badgeMenteesAttention"><?php echo esc_html( $total_attention ); ?></span>
                </button>
                <button type="button" class="gh-filter-tab" data-status="completed" onclick="ghFilterMenteesStatus('completed', this)">
                    <?php esc_html_e( 'Completed', 'growth-hub' ); ?>
                    <span class="gh-count-pill" id="badgeMenteesCompleted"><?php echo esc_html( $total_completed ); ?></span>
                </button>
            </div>

            <div class="gh-filter-controls">
                <!-- Search Input -->
                <div class="gh-search-input-box">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6B7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <input type="text" id="ghMenteesSearch" placeholder="<?php esc_attr_e( 'Search mentee or email...', 'growth-hub' ); ?>" onkeyup="ghFilterMenteesList()" />
                </div>

                <!-- Course Dropdown -->
                <div class="gh-select-wrap">
                    <select id="ghMenteesCourseFilter" class="gh-custom-select" onchange="ghFilterMenteesList()">
                        <option value="all"><?php esc_html_e( 'All Assigned Courses', 'growth-hub' ); ?></option>
                        <?php foreach ( $courses as $course ) : ?>
                            <option value="<?php echo esc_attr( $course->ID ); ?>"><?php echo esc_html( $course->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Mentees Table Card -->
        <div class="gh-section-card" style="padding: 0; overflow: hidden; border-radius: 16px;">
            <div class="gh-table-wrap" style="margin: 0;">
                <table class="gh-table" id="ghMenteesTable" style="min-width: 860px !important;">
                    <thead>
                        <tr>
                            <th style="padding-left: 24px;"><?php esc_html_e( 'MENTEE', 'growth-hub' ); ?></th>
                            <th><?php esc_html_e( 'COURSE / COHORT', 'growth-hub' ); ?></th>
                            <th><?php esc_html_e( 'ENROLLED', 'growth-hub' ); ?></th>
                            <th style="width: 220px;"><?php esc_html_e( 'CURRICULUM PROGRESS', 'growth-hub' ); ?></th>
                            <th><?php esc_html_e( 'STATUS', 'growth-hub' ); ?></th>
                            <th style="text-align: right; padding-right: 24px;"><?php esc_html_e( 'ACTIONS', 'growth-hub' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="ghMenteesTableBody">
                        <?php if ( empty( $mentees ) ) : ?>
                            <tr id="ghMenteesEmptyRow">
                                <td colspan="6" class="gh-empty-state-cell">
                                    <div class="gh-empty-state-inner">
                                        <div class="gh-empty-icon" style="background: rgba(99, 47, 209, 0.08); color: #632FD1;">
                                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                                        </div>
                                        <h4 style="margin: 12px 0 6px; font-size: 17px; color: #1B1240; font-family: 'Poppins', sans-serif;"><?php esc_html_e( 'No Mentees Enrolled Yet', 'growth-hub' ); ?></h4>
                                        <p style="margin: 0; color: #6B7280; font-size: 14px; max-width: 440px; line-height: 1.5;"><?php esc_html_e( 'Learners who enroll in your cohorts will appear here automatically.', 'growth-hub' ); ?></p>
                                    </div>
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $mentees as $m ) : 
                                $initials = '';
                                $parts = explode( ' ', trim( $m['name'] ) );
                                foreach ( array_slice( $parts, 0, 2 ) as $p ) {
                                    $initials .= strtoupper( mb_substr( $p, 0, 1 ) );
                                }
                                if ( empty( $initials ) ) $initials = 'ST';
                            ?>
                                <tr class="gh-mentee-row"
                                    data-id="<?php echo esc_attr( $m['id'] ); ?>"
                                    data-name="<?php echo esc_attr( $m['name'] ); ?>"
                                    data-email="<?php echo esc_attr( $m['email'] ); ?>"
                                    data-course-id="<?php echo esc_attr( $m['course_id'] ); ?>"
                                    data-course-title="<?php echo esc_attr( $m['course_title'] ); ?>"
                                    data-progress="<?php echo esc_attr( $m['progress'] ); ?>"
                                    data-status="<?php echo esc_attr( $m['status_key'] ); ?>"
                                    data-enrolled-at="<?php echo esc_attr( date_i18n( 'M j, Y', strtotime( $m['enrolled_at'] ) ) ); ?>">
                                    
                                    <!-- Mentee Column -->
                                    <td style="padding-left: 24px;">
                                        <div class="gh-mentee-cell">
                                            <div class="gh-avatar-circle"><?php echo esc_html( $initials ); ?></div>
                                            <div class="gh-mentee-meta">
                                                <strong class="gh-mentee-name"><?php echo esc_html( $m['name'] ); ?></strong>
                                                <span class="gh-mentee-email"><?php echo esc_html( $m['email'] ); ?></span>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Course Column -->
                                    <td>
                                        <span class="gh-course-pill"><?php echo esc_html( $m['course_title'] ); ?></span>
                                    </td>

                                    <!-- Enrolled Date Column -->
                                    <td>
                                        <span class="gh-date-text"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $m['enrolled_at'] ) ) ); ?></span>
                                    </td>

                                    <!-- Progress Bar Column -->
                                    <td>
                                        <div class="gh-progress-container">
                                            <div class="gh-progress-bar-track">
                                                <div class="gh-progress-bar-fill" style="width: <?php echo esc_attr( $m['progress'] ); ?>%;"></div>
                                            </div>
                                            <span class="gh-progress-val"><?php echo esc_html( $m['progress'] ); ?>%</span>
                                        </div>
                                    </td>

                                    <!-- Status Column -->
                                    <td>
                                        <?php if ( 'completed' === $m['status_key'] ) : ?>
                                            <span class="gh-status-badge gh-status-completed">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                                <?php esc_html_e( 'Completed', 'growth-hub' ); ?>
                                            </span>
                                        <?php elseif ( 'on_track' === $m['status_key'] ) : ?>
                                            <span class="gh-status-badge gh-status-on-track">
                                                <span class="gh-dot-pulse-green"></span>
                                                <?php esc_html_e( 'On Track', 'growth-hub' ); ?>
                                            </span>
                                        <?php elseif ( 'in_progress' === $m['status_key'] ) : ?>
                                            <span class="gh-status-badge gh-status-in-prog">
                                                <?php esc_html_e( 'In Progress', 'growth-hub' ); ?>
                                            </span>
                                        <?php else : ?>
                                            <span class="gh-status-badge gh-status-attention">
                                                <span class="gh-dot-pulse-amber"></span>
                                                <?php esc_html_e( 'Needs Attention', 'growth-hub' ); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Actions Column -->
                                    <td style="text-align: right; padding-right: 24px;">
                                        <div class="gh-mentee-actions">
                                            <a href="mailto:<?php echo esc_attr( $m['email'] ); ?>" class="gh-btn-action-icon" title="<?php esc_attr_e( 'Send Email', 'growth-hub' ); ?>">
                                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                                            </a>
                                            <button type="button" class="gh-btn-view-profile" onclick="ghOpenMenteeModal(this)">
                                                <?php esc_html_e( 'View Details', 'growth-hub' ); ?>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<!-- Mentee Detail Modal -->
<div class="gh-modal-overlay" id="ghMenteeModalOverlay" style="display: none;" onclick="if(event.target===this) ghCloseMenteeModal();">
    <div class="gh-modal-card" style="max-width: 580px;">
        <div class="gh-modal-header">
            <div>
                <h3 class="gh-modal-title"><?php esc_html_e( 'Mentee Profile & Progress', 'growth-hub' ); ?></h3>
                <span class="gh-modal-subtitle"><?php esc_html_e( 'Overview of enrolled learner performance and activity', 'growth-hub' ); ?></span>
            </div>
            <button type="button" class="gh-modal-close" onclick="ghCloseMenteeModal()">&times;</button>
        </div>

        <div class="gh-eval-summary-banner">
            <div class="gh-eval-avatar" id="modalMenteeAvatar">FA</div>
            <div class="gh-eval-meta">
                <h4 id="modalMenteeName">Favour Adeleke</h4>
                <p id="modalMenteeCourse">6 Months Data Analytics Course</p>
                <span class="gh-eval-time" id="modalMenteeEnrolled">Enrolled Oct 14, 2026</span>
            </div>
        </div>

        <div class="gh-mentee-modal-stats">
            <div class="gh-modal-stat-box">
                <span class="gh-stat-label"><?php esc_html_e( 'Curriculum Progress', 'growth-hub' ); ?></span>
                <strong id="modalMenteeProgressVal" style="color: #632FD1; font-size: 22px;">68%</strong>
            </div>
            <div class="gh-modal-stat-box">
                <span class="gh-stat-label"><?php esc_html_e( 'Cohort Status', 'growth-hub' ); ?></span>
                <strong id="modalMenteeStatusVal" style="color: #10B981; font-size: 16px;">On Track</strong>
            </div>
        </div>

        <div class="gh-form-group" style="margin-top: 18px;">
            <label><?php esc_html_e( 'Direct Contact', 'growth-hub' ); ?></label>
            <div class="gh-mentee-contact-card">
                <div class="gh-mentee-contact-info">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#632FD1" stroke-width="2" style="flex-shrink: 0;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    <span id="modalMenteeEmail" class="gh-mentee-email-text">email@growthhub.africa</span>
                </div>
                <a href="#" id="modalMenteeEmailLink" class="gh-btn-primary gh-btn-send-email">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                    <span><?php esc_html_e( 'Send Email', 'growth-hub' ); ?></span>
                </a>
            </div>
        </div>

        <div class="gh-modal-footer" style="margin-top: 24px;">
            <button type="button" class="gh-btn-secondary" onclick="ghCloseMenteeModal()"><?php esc_html_e( 'Close', 'growth-hub' ); ?></button>
        </div>
    </div>
</div>

<style>
/* Mentees Page Specific Styles */
.gh-mentees-page {
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

.gh-stat-icon-wrap {
    width: 48px !important;
    height: 48px !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
}

.gh-stat-info {
    display: flex !important;
    flex-direction: column !important;
}

.gh-stat-label {
    font-size: 13px !important;
    color: #6B7280 !important;
    font-weight: 500 !important;
    margin-bottom: 2px !important;
}

.gh-stat-value {
    font-family: 'Poppins', sans-serif !important;
    font-size: 24px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
    line-height: 1.1 !important;
}

.gh-mentees-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.gh-filter-tabs {
    display: flex !important;
    background: #F4F1FB !important;
    padding: 4px !important;
    border-radius: 12px !important;
    gap: 4px !important;
    flex-wrap: wrap !important;
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
    font-size: 13px !important;
    font-weight: 600 !important;
    color: #6B7280 !important;
    cursor: pointer !important;
    transition: all 0.15s ease !important;
    white-space: nowrap !important;
}

.gh-filter-tab:hover {
    color: #1B1240 !important;
    background: rgba(255, 255, 255, 0.5) !important;
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
    background: #EBE7F7 !important;
    color: #1B1240 !important;
    font-weight: 700 !important;
}

.gh-filter-tab.active .gh-count-pill {
    background: #632FD1 !important;
    color: #FFFFFF !important;
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

/* Toolbar Search Input Box */
.gh-mentees-toolbar .gh-search-input-box {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    background: #FFFFFF !important;
    border: 1px solid #D8D2EE !important;
    border-radius: 12px !important;
    padding: 0 14px !important;
    height: 48px !important;
    min-height: 48px !important;
    width: 250px !important;
    max-width: 280px !important;
    box-sizing: border-box !important;
    transition: border-color 0.2s ease, box-shadow 0.2s ease !important;
}

.gh-mentees-toolbar .gh-search-input-box:focus-within {
    border-color: #632FD1 !important;
    box-shadow: 0 0 0 3px rgba(99, 47, 209, 0.12) !important;
}

.gh-mentees-toolbar .gh-search-input-box svg {
    flex-shrink: 0 !important;
    stroke: #6B7280 !important;
}

.gh-mentees-toolbar .gh-search-input-box input,
.gh-mentees-toolbar .gh-search-input-box input[type="text"] {
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
.gh-mentees-toolbar .gh-select-wrap {
    position: relative !important;
    display: inline-block !important;
}

.gh-mentees-toolbar select.gh-custom-select {
    display: inline-block !important;
    width: auto !important;
    min-width: 180px !important;
    max-width: 260px !important;
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

.gh-mentees-toolbar select.gh-custom-select:focus {
    border-color: #632FD1 !important;
    box-shadow: 0 0 0 3px rgba(99, 47, 209, 0.12) !important;
}

@media (max-width: 1024px) {
    .gh-stats-grid {
        grid-template-columns: repeat(2, 1fr) !important;
    }
}

@media (max-width: 768px) {
    .gh-mentees-toolbar {
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
    .gh-mentees-toolbar .gh-search-input-box {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box !important;
    }
    .gh-mentees-toolbar .gh-select-wrap {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        display: block !important;
    }
    .gh-mentees-toolbar select.gh-custom-select {
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

.gh-count-on-track {
    background: #D1FAE5 !important;
    color: #065F46 !important;
}

.gh-count-attention {
    background: #FEF3C7 !important;
    color: #B45309 !important;
}

/* Progress Bar */
.gh-progress-container {
    display: flex;
    align-items: center;
    gap: 12px;
}

.gh-progress-bar-track {
    flex: 1;
    height: 8px;
    background: #E6E1F6;
    border-radius: 999px;
    overflow: hidden;
    position: relative;
}

.gh-progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #632FD1 0%, #8B5CF6 100%);
    border-radius: 999px;
    transition: width 0.3s ease;
}

.gh-progress-val {
    font-size: 13px;
    font-weight: 700;
    color: #1B1240;
    font-family: 'Poppins', sans-serif;
    min-width: 36px;
}

/* Status Pill Variations */
.gh-status-on-track {
    background: #D1FAE5 !important;
    color: #065F46 !important;
}

.gh-dot-pulse-green {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #10B981;
    box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.25);
    flex-shrink: 0;
}

.gh-status-in-prog {
    background: #EDE8F8 !important;
    color: #632FD1 !important;
}

.gh-status-attention {
    background: #FEF3C7 !important;
    color: #B45309 !important;
}

.gh-dot-pulse-amber {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #D97706;
    box-shadow: 0 0 0 2px rgba(217, 119, 6, 0.25);
    flex-shrink: 0;
}

.gh-status-completed {
    background: #E0E7FF !important;
    color: #3730A3 !important;
}

/* Mentee Actions */
.gh-mentee-actions {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    justify-content: flex-end;
}

.gh-btn-action-icon {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: #F4F1FB;
    border: 1px solid #D8D2EE;
    color: #632FD1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.15s ease;
}

.gh-btn-action-icon:hover {
    background: #632FD1;
    color: #FFFFFF;
    border-color: #632FD1;
}

.gh-btn-view-profile {
    background: #FFFFFF !important;
    color: #632FD1 !important;
    border: 1.5px solid #D8D2EE !important;
    padding: 6px 14px !important;
    border-radius: 8px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 12.5px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    transition: all 0.15s ease !important;
}

.gh-btn-view-profile:hover {
    border-color: #632FD1 !important;
    background: #F4F1FB !important;
}

/* Modal Inner Stats */
.gh-mentee-modal-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-top: 16px;
}

.gh-modal-stat-box {
    background: #FAF9FE;
    border: 1px solid #E6E1F6;
    border-radius: 10px;
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

/* Mentee Contact Card in Modal */
.gh-mentee-contact-card {
    background: #F4F1FB !important;
    border: 1.5px solid #E6E1F6 !important;
    border-radius: 12px !important;
    padding: 12px 16px !important;
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    gap: 12px !important;
}

.gh-mentee-contact-info {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    min-width: 0 !important;
    flex: 1 1 auto !important;
    overflow: hidden !important;
}

.gh-mentee-email-text {
    color: #1B1240 !important;
    font-weight: 600 !important;
    font-size: 13.5px !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    display: block !important;
}

.gh-btn-send-email {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 7px !important;
    padding: 9px 18px !important;
    border-radius: 10px !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    white-space: nowrap !important;
    flex-shrink: 0 !important;
    line-height: 1.2 !important;
    text-decoration: none !important;
    background-color: #632FD1 !important;
    color: #FFFFFF !important;
}

.gh-btn-send-email:hover {
    background-color: #5224B8 !important;
}

@media (max-width: 540px) {
    .gh-modal-card {
        padding: 22px 16px !important;
        border-radius: 16px !important;
    }

    .gh-mentee-contact-card {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 12px !important;
        padding: 14px !important;
    }

    .gh-mentee-contact-info {
        width: 100% !important;
    }

    .gh-btn-send-email {
        width: 100% !important;
        padding: 11px 16px !important;
    }
}
</style>

<script>
var ghCurrentMenteesStatus = 'all';

function ghFilterMenteesStatus(status, btn) {
    ghCurrentMenteesStatus = status;
    document.querySelectorAll('.gh-filter-tab').forEach(function(t) {
        t.classList.remove('active');
    });
    btn.classList.add('active');
    ghFilterMenteesList();
}

function ghFilterMenteesList() {
    var searchVal = (document.getElementById('ghMenteesSearch').value || '').toLowerCase().trim();
    var courseVal = document.getElementById('ghMenteesCourseFilter').value;

    var rows = document.querySelectorAll('.gh-mentee-row');
    var visibleCount = 0;

    rows.forEach(function(row) {
        var rStatus   = row.getAttribute('data-status');
        var rCourseId = row.getAttribute('data-course-id');
        var rName     = (row.getAttribute('data-name') || '').toLowerCase();
        var rEmail    = (row.getAttribute('data-email') || '').toLowerCase();
        var rCourse   = (row.getAttribute('data-course-title') || '').toLowerCase();

        var matchStatus = (ghCurrentMenteesStatus === 'all' || rStatus === ghCurrentMenteesStatus);
        var matchCourse = (courseVal === 'all' || rCourseId === courseVal);
        var matchSearch = (!searchVal || rName.indexOf(searchVal) > -1 || rEmail.indexOf(searchVal) > -1 || rCourse.indexOf(searchVal) > -1);

        if (matchStatus && matchCourse && matchSearch) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    var emptyRow = document.getElementById('ghMenteesEmptyRow');
    if (emptyRow) {
        emptyRow.style.display = (visibleCount === 0 && rows.length > 0) ? '' : (rows.length === 0 ? '' : 'none');
    }
}

function ghOpenMenteeModal(btn) {
    var row = btn.closest('.gh-mentee-row');
    if (!row) return;

    var name     = row.getAttribute('data-name');
    var email    = row.getAttribute('data-email');
    var course   = row.getAttribute('data-course-title');
    var progress = row.getAttribute('data-progress') || '0';
    var enrolled = row.getAttribute('data-enrolled-at');
    var status   = row.getAttribute('data-status');

    document.getElementById('modalMenteeName').textContent   = name;
    document.getElementById('modalMenteeCourse').textContent = course;
    document.getElementById('modalMenteeEnrolled').textContent = 'Enrolled ' + enrolled;
    document.getElementById('modalMenteeProgressVal').textContent = progress + '%';
    document.getElementById('modalMenteeEmail').textContent  = email;
    document.getElementById('modalMenteeEmailLink').href     = 'mailto:' + encodeURIComponent(email);

    var statusEl = document.getElementById('modalMenteeStatusVal');
    if (status === 'completed') {
        statusEl.textContent = 'Completed';
        statusEl.style.color = '#3730A3';
    } else if (status === 'on_track') {
        statusEl.textContent = 'On Track';
        statusEl.style.color = '#10B981';
    } else if (status === 'in_progress') {
        statusEl.textContent = 'In Progress';
        statusEl.style.color = '#632FD1';
    } else {
        statusEl.textContent = 'Needs Attention';
        statusEl.style.color = '#D97706';
    }

    var initials = '';
    var parts = (name || '').trim().split(' ');
    for (var i = 0; i < Math.min(2, parts.length); i++) {
        if (parts[i].length > 0) initials += parts[i].charAt(0).toUpperCase();
    }
    document.getElementById('modalMenteeAvatar').textContent = initials || 'ST';

    var overlay = document.getElementById('ghMenteeModalOverlay');
    if (overlay) {
        overlay.classList.add('active');
        overlay.style.setProperty('display', 'flex', 'important');
    }
}

function ghCloseMenteeModal() {
    var overlay = document.getElementById('ghMenteeModalOverlay');
    if (overlay) {
        overlay.classList.remove('active');
        overlay.style.setProperty('display', 'none', 'important');
    }
}
</script>

