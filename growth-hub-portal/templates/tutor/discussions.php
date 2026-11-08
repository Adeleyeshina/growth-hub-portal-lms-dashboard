<?php
/**
 * Tutor Dashboard - Discussions & Q&A Template
 * Build: Growth Hub Custom Discussions & Q&A Hub
 * 
 * Features:
 * - Header with Discussions Overview
 * - Status Filters: All, Unanswered, Answered & Course Filter Dropdown
 * - Search bar for quick keyword/student lookup
 * - Discussion cards with Mentee avatar, Cohort tag, Unanswered/Answered status
 * - Existing replies thread with "Instructor" badge
 * - Inline Quick Reply composer with live feedback & Toast notifications
 * - Tutor LMS native comments (tutor_q_and_a) integration
 * 
 * Author: Adeleye Shina
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$user_id = get_current_user_id();
$user    = wp_get_current_user();

// Fetch Courses assigned to this instructor safely
if ( class_exists( 'GH_Portal_Class_Log' ) && method_exists( 'GH_Portal_Class_Log', 'get_instructor_courses' ) ) {
    $courses = GH_Portal_Class_Log::get_instructor_courses( $user_id );
} else {
    $courses = get_posts( array(
        'post_type'      => 'courses',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'author'         => $user_id,
    ) );
    if ( empty( $courses ) ) {
        $courses = get_posts( array(
            'post_type'      => 'courses',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ) );
    }
}

// Course ID to Title Map
$course_map = array();
$course_ids = array();
if ( ! empty( $courses ) ) {
    foreach ( $courses as $c ) {
        $course_map[ $c->ID ] = $c->post_title;
        $course_ids[]          = $c->ID;
    }
}

// Query Native Tutor LMS Q&A questions from wp_comments table
$questions = array();
$total_unanswered = 0;
$total_answered   = 0;

if ( ! empty( $course_ids ) ) {
    $comments_table = $wpdb->comments;
    $in_courses = implode( ',', array_map( 'intval', $course_ids ) );
    
    // Top-level questions have comment_parent = 0 and comment_type = 'tutor_q_and_a'
    $raw_questions = $wpdb->get_results( 
        "SELECT comment_ID, comment_post_ID, comment_author, comment_author_email, comment_date, comment_content, user_id 
         FROM $comments_table 
         WHERE comment_post_ID IN ($in_courses) 
           AND comment_type = 'tutor_q_and_a' 
           AND comment_parent = 0 
         ORDER BY comment_ID DESC" 
    );

    if ( ! empty( $raw_questions ) ) {
        foreach ( $raw_questions as $q ) {
            $q_id = $q->comment_ID;
            
            // Fetch replies for this question
            $raw_replies = $wpdb->get_results( $wpdb->prepare(
                "SELECT comment_ID, comment_author, comment_date, comment_content, user_id 
                 FROM $comments_table 
                 WHERE comment_parent = %d 
                   AND comment_type = 'tutor_q_and_a' 
                 ORDER BY comment_ID ASC",
                $q_id
            ) );

            $replies = array();
            $has_instructor_reply = false;

            if ( ! empty( $raw_replies ) ) {
                foreach ( $raw_replies as $r ) {
                    $is_instructor = ( intval( $r->user_id ) === $user_id || user_can( $r->user_id, 'manage_options' ) );
                    if ( $is_instructor ) {
                        $has_instructor_reply = true;
                    }
                    $replies[] = array(
                        'id'            => $r->comment_ID,
                        'author'        => $r->comment_author,
                        'content'       => $r->comment_content,
                        'date'          => $r->comment_date,
                        'is_instructor' => $is_instructor,
                    );
                }
            }

            $is_answered = ( ! empty( $replies ) && $has_instructor_reply );
            if ( $is_answered ) {
                $total_answered++;
            } else {
                $total_unanswered++;
            }

            $c_title = isset( $course_map[ $q->comment_post_ID ] ) ? $course_map[ $q->comment_post_ID ] : get_the_title( $q->comment_post_ID );

            $questions[] = array(
                'id'          => $q->comment_ID,
                'course_id'   => $q->comment_post_ID,
                'course_name' => $c_title ? $c_title : __( 'General Course', 'growth-hub' ),
                'author'      => $q->comment_author ? $q->comment_author : __( 'Student', 'growth-hub' ),
                'author_id'   => $q->user_id,
                'date'        => $q->comment_date,
                'content'     => $q->comment_content,
                'is_answered' => $is_answered,
                'replies'     => $replies,
            );
        }
    }
}

// Current Filter
$filter_status = isset( $_GET['status_filter'] ) ? sanitize_text_field( $_GET['status_filter'] ) : 'all';
$filter_course = isset( $_GET['course_filter'] ) ? intval( $_GET['course_filter'] ) : 0;

$filtered_questions = array();
foreach ( $questions as $q ) {
    if ( 'unanswered' === $filter_status && $q['is_answered'] ) {
        continue;
    }
    if ( 'answered' === $filter_status && ! $q['is_answered'] ) {
        continue;
    }
    if ( $filter_course > 0 && intval( $q['course_id'] ) !== $filter_course ) {
        continue;
    }
    $filtered_questions[] = $q;
}

$dash_url = function_exists( 'gh_portal_dashboard_url' ) ? gh_portal_dashboard_url() : ( function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url() : home_url( '/dashboard/' ) );
?>

<style>
/* Growth Hub - Discussions & Q&A Master Styles */
.gh-qa-container {
    max-width: 1320px;
    margin: 0 auto;
    padding-bottom: 60px;
    width: 100%;
    box-sizing: border-box;
    font-family: 'Lato', -apple-system, BlinkMacSystemFont, sans-serif;
    color: #1B1240;
}

.gh-qa-header {
    margin-bottom: 24px;
}

.gh-qa-header h1 {
    font-family: 'Poppins', sans-serif !important;
    font-size: 26px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
    margin: 0 0 6px 0 !important;
    letter-spacing: -0.5px;
}

.gh-qa-header p {
    font-size: 14px;
    color: #8C85A6;
    margin: 0;
}

/* Controls Bar */
.gh-qa-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #FFFFFF;
    border: 1px solid #D8D2EE;
    border-radius: 14px;
    padding: 14px 20px;
    margin-bottom: 24px;
    gap: 16px;
    flex-wrap: wrap;
}

.gh-qa-pills-wrap {
    display: inline-flex !important;
    background: #F4F1FB !important;
    padding: 4px !important;
    border-radius: 12px !important;
    gap: 4px !important;
    box-sizing: border-box !important;
}

.gh-qa-pill-btn {
    border: none !important;
    background: transparent !important;
    color: #4A3E72 !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    padding: 8px 16px !important;
    border-radius: 8px !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    text-decoration: none !important;
    transition: all 0.2s ease !important;
    white-space: nowrap !important;
}

.gh-qa-pill-btn:hover {
    color: #632FD1 !important;
}

.gh-qa-pill-btn.active {
    background: #FFFFFF !important;
    color: #632FD1 !important;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08) !important;
}

.gh-qa-count {
    background: rgba(99, 47, 209, 0.12) !important;
    color: #632FD1 !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    padding: 2px 8px !important;
    border-radius: 9999px !important;
}

.gh-qa-pill-btn.active .gh-qa-count {
    background: rgba(99, 47, 209, 0.18) !important;
    color: #632FD1 !important;
}

.gh-qa-right-filters {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    flex-wrap: wrap !important;
    flex: 1 1 auto !important;
    justify-content: flex-end !important;
    margin: 0 !important;
}

.gh-qa-course-select {
    height: 48px !important;
    min-height: 48px !important;
    max-height: 48px !important;
    line-height: 46px !important;
    padding: 0 42px 0 16px !important;
    border: 1.5px solid #D8D2EE !important;
    border-radius: 12px !important;
    background-color: #FFFFFF !important;
    color: #1B1240 !important;
    font-family: 'Lato', sans-serif !important;
    font-size: 14px !important;
    min-width: 220px !important;
    flex: 1 1 220px !important;
    box-sizing: border-box !important;
    margin: 0 !important;
    appearance: none !important;
    -webkit-appearance: none !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23632FD1' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: right 16px center !important;
    background-size: 12px 12px !important;
    cursor: pointer !important;
    outline: none !important;
}

.gh-qa-course-select:focus {
    border-color: #632FD1 !important;
    box-shadow: 0 0 0 3px rgba(99, 47, 209, 0.12) !important;
}

/* Robust Flexbox Search Bar (Zero Absolute Positioning Hacks) */
.gh-qa-search-bar {
    display: flex !important;
    align-items: center !important;
    height: 48px !important;
    min-height: 48px !important;
    max-height: 48px !important;
    background: #FFFFFF !important;
    border: 1.5px solid #D8D2EE !important;
    border-radius: 12px !important;
    padding: 0 14px !important;
    gap: 10px !important;
    box-sizing: border-box !important;
    min-width: 220px !important;
    flex: 1 1 220px !important;
    margin: 0 !important;
    transition: border-color 0.15s ease, box-shadow 0.15s ease !important;
}

.gh-qa-search-bar:focus-within {
    border-color: #632FD1 !important;
    box-shadow: 0 0 0 3px rgba(99, 47, 209, 0.12) !important;
}

.gh-qa-search-bar svg {
    width: 16px !important;
    height: 16px !important;
    flex-shrink: 0 !important;
    color: #8C85A6 !important;
    stroke: #8C85A6 !important;
    display: block !important;
    margin: 0 !important;
}

.gh-qa-search-bar input,
.gh-portal-wrapper .gh-qa-search-bar input,
.gh-portal-wrapper .gh-qa-search-bar input[type="text"],
.gh-qa-search-bar input:focus,
.gh-portal-wrapper .gh-qa-search-bar input:focus,
.gh-portal-wrapper .gh-qa-search-bar input[type="text"]:focus {
    border: none !important;
    outline: none !important;
    background: transparent !important;
    box-shadow: none !important;
    width: 100% !important;
    height: 100% !important;
    min-height: 100% !important;
    padding: 0 !important;
    margin: 0 !important;
    font-family: 'Lato', sans-serif !important;
    font-size: 14px !important;
    color: #1B1240 !important;
    line-height: normal !important;
}

/* Discussion Cards Feed */
.gh-qa-feed {
    display: flex !important;
    flex-direction: column !important;
    gap: 22px !important;
}

.gh-qa-card {
    background: #FFFFFF !important;
    border: 1.5px solid #E6E1F6 !important;
    border-radius: 18px !important;
    padding: 24px 28px !important;
    box-sizing: border-box !important;
    box-shadow: 0 4px 18px rgba(27, 18, 64, 0.04) !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease !important;
}

.gh-qa-card:hover {
    box-shadow: 0 10px 30px rgba(99, 47, 209, 0.09) !important;
    border-color: #B2A2DE !important;
    transform: translateY(-2px) !important;
}

/* Card Top Row */
.gh-qa-card-top {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    gap: 16px !important;
    margin-bottom: 16px !important;
    flex-wrap: wrap !important;
}

.gh-qa-author-wrap {
    display: flex !important;
    align-items: center !important;
    gap: 14px !important;
}

.gh-qa-avatar {
    width: 46px !important;
    height: 46px !important;
    border-radius: 50% !important;
    background: linear-gradient(135deg, #EDE8F8 0%, #D8D2EE 100%) !important;
    color: #632FD1 !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 15px !important;
    font-weight: 700 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    border: 2px solid #FFFFFF !important;
    box-shadow: 0 2px 10px rgba(99, 47, 209, 0.15) !important;
}

.gh-qa-author-info {
    display: flex !important;
    flex-direction: column !important;
    gap: 4px !important;
}

.gh-qa-author-name-row {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
}

.gh-qa-author-name {
    font-family: 'Poppins', sans-serif !important;
    font-size: 15.5px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
    line-height: 1.2 !important;
}

.gh-qa-mentee-badge {
    background: #F4F1FB !important;
    color: #8C85A6 !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 10.5px !important;
    font-weight: 600 !important;
    padding: 1px 7px !important;
    border-radius: 4px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
}

.gh-qa-meta-row {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    font-size: 12px !important;
    color: #8C85A6 !important;
    flex-wrap: wrap !important;
}

.gh-qa-course-tag {
    font-family: 'Poppins', sans-serif !important;
    font-size: 11.5px !important;
    font-weight: 600 !important;
    color: #632FD1 !important;
    background: #F4F1FB !important;
    border: 1px solid #E5E0F6 !important;
    padding: 2px 10px !important;
    border-radius: 6px !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 5px !important;
}

.gh-qa-dot-sep {
    color: #B2A2DE !important;
    font-size: 14px !important;
}

.gh-qa-time-text {
    display: inline-flex !important;
    align-items: center !important;
    gap: 4px !important;
    color: #8C85A6 !important;
}

/* Status Pill */
.gh-qa-status-pill {
    font-family: 'Poppins', sans-serif !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    padding: 6px 14px !important;
    border-radius: 9999px !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    white-space: nowrap !important;
}

.gh-qa-status-dot {
    width: 7px !important;
    height: 7px !important;
    border-radius: 50% !important;
    display: inline-block !important;
}

.gh-qa-status-unanswered {
    background: #FEF3C7 !important;
    color: #92400E !important;
    border: 1px solid #FDE68A !important;
}
.gh-qa-status-dot.unanswered {
    background: #D97706 !important;
    box-shadow: 0 0 0 2px rgba(217, 119, 6, 0.2) !important;
}

.gh-qa-status-answered {
    background: #D1FAE5 !important;
    color: #065F46 !important;
    border: 1px solid #A7F3D0 !important;
}
.gh-qa-status-dot.answered {
    background: #10B981 !important;
    box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2) !important;
}

/* Question Body Blockquote Box */
.gh-qa-question-box {
    background: #FAF9FE !important;
    border-left: 4px solid #632FD1 !important;
    border-radius: 12px !important;
    padding: 16px 20px !important;
    margin: 16px 0 20px 0 !important;
}

.gh-qa-question-text {
    font-family: 'Lato', sans-serif !important;
    font-size: 15px !important;
    color: #1B1240 !important;
    line-height: 1.65 !important;
    white-space: pre-line !important;
}

/* Thread Replies */
.gh-qa-thread {
    margin-bottom: 20px !important;
    display: flex !important;
    flex-direction: column !important;
    gap: 12px !important;
}

.gh-qa-thread-title {
    font-family: 'Poppins', sans-serif !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    color: #632FD1 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.5px !important;
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    margin-bottom: 4px !important;
}

.gh-qa-reply-item {
    background: #FFFFFF !important;
    border: 1.5px solid #EDE8F8 !important;
    border-radius: 14px !important;
    padding: 16px 20px !important;
    box-shadow: 0 2px 8px rgba(27, 18, 64, 0.02) !important;
    animation: ghPopIn 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
}

.gh-qa-reply-header {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    margin-bottom: 8px !important;
    gap: 10px !important;
}

.gh-qa-reply-author-wrap {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
}

.gh-qa-avatar-mini {
    width: 28px !important;
    height: 28px !important;
    border-radius: 50% !important;
    background: #632FD1 !important;
    color: #FFFFFF !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

.gh-qa-reply-author {
    font-family: 'Poppins', sans-serif !important;
    font-size: 14px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
}

.gh-qa-instructor-badge {
    background: #632FD1 !important;
    color: #FFFFFF !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 10px !important;
    font-weight: 700 !important;
    padding: 2px 8px !important;
    border-radius: 9999px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.3px !important;
}

.gh-qa-reply-time {
    font-size: 12px !important;
    color: #8C85A6 !important;
}

.gh-qa-reply-content {
    font-family: 'Lato', sans-serif !important;
    font-size: 14.5px !important;
    color: #332B52 !important;
    line-height: 1.6 !important;
    margin: 0 !important;
    white-space: pre-line !important;
}

/* Polished Reply Composer */
.gh-qa-composer-form {
    margin-top: 16px !important;
}

.gh-qa-composer-card {
    background: #FFFFFF !important;
    border: 1.5px solid #D8D2EE !important;
    border-radius: 14px !important;
    overflow: hidden !important;
    transition: border-color 0.15s ease, box-shadow 0.15s ease !important;
}

.gh-qa-composer-card:focus-within {
    border-color: #632FD1 !important;
    box-shadow: 0 0 0 3px rgba(99, 47, 209, 0.12) !important;
}

.gh-qa-textarea,
.gh-portal-wrapper textarea.gh-qa-textarea {
    width: 100% !important;
    min-height: 75px !important;
    border: none !important;
    outline: none !important;
    padding: 14px 18px !important;
    font-family: 'Lato', sans-serif !important;
    font-size: 14.5px !important;
    line-height: 1.55 !important;
    color: #1B1240 !important;
    background: transparent !important;
    resize: none !important;
    box-sizing: border-box !important;
    margin: 0 !important;
    display: block !important;
}

.gh-qa-composer-footer {
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    padding: 10px 18px !important;
    background: #FAF9FE !important;
    border-top: 1px solid #EDE8F8 !important;
    flex-wrap: wrap !important;
    gap: 10px !important;
}

.gh-qa-composer-hint {
    font-size: 12px !important;
    color: #8C85A6 !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    font-family: 'Lato', sans-serif !important;
}

.gh-btn-reply-main,
.gh-portal-wrapper button.gh-btn-reply-main,
button[type="submit"].gh-btn-reply-main {
    background: #632FD1 !important;
    color: #FFFFFF !important;
    border: none !important;
    border-radius: 9999px !important;
    padding: 9px 22px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 7px !important;
    transition: all 0.15s ease !important;
    box-shadow: 0 2px 8px rgba(99, 47, 209, 0.2) !important;
    line-height: normal !important;
}

.gh-btn-reply-main:hover,
.gh-portal-wrapper button.gh-btn-reply-main:hover,
button[type="submit"].gh-btn-reply-main:hover {
    background: #5224B8 !important;
    color: #FFFFFF !important;
    transform: translateY(-1px) !important;
    box-shadow: 0 4px 14px rgba(99, 47, 209, 0.3) !important;
}

/* Empty State */
.gh-qa-empty {
    background: #FFFFFF !important;
    border: 1.5px dashed #D8D2EE !important;
    border-radius: 18px !important;
    padding: 56px 24px !important;
    text-align: center !important;
}

.gh-qa-empty-icon {
    width: 64px !important;
    height: 64px !important;
    background: #F4F1FB !important;
    color: #632FD1 !important;
    border-radius: 50% !important;
    margin: 0 auto 16px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}

.gh-qa-empty h3 {
    font-family: 'Poppins', sans-serif !important;
    font-size: 18px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
    margin: 0 0 8px 0 !important;
}

.gh-qa-empty p {
    font-size: 14px !important;
    color: #8C85A6 !important;
    max-width: 440px !important;
    margin: 0 auto !important;
    line-height: 1.5 !important;
}

@media (max-width: 768px) {
    .gh-qa-controls {
        flex-direction: column !important;
        align-items: stretch !important;
        padding: 12px !important;
        gap: 12px !important;
        border-radius: 14px !important;
    }
    .gh-qa-pills-wrap {
        display: flex !important;
        flex-wrap: nowrap !important;
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
        scrollbar-width: none !important;
        width: 100% !important;
        box-sizing: border-box !important;
        padding: 4px !important;
    }
    .gh-qa-pills-wrap::-webkit-scrollbar {
        display: none !important;
    }
    .gh-qa-pill-btn {
        white-space: nowrap !important;
        flex-shrink: 0 !important;
        padding: 7px 12px !important;
        font-size: 12.5px !important;
    }
    .gh-qa-pill-btn .gh-qa-count {
        font-size: 11.5px !important;
        padding: 2px 7px !important;
        border-radius: 20px !important;
    }
    .gh-qa-right-filters {
        width: 100% !important;
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 10px !important;
    }
    .gh-qa-course-select {
        width: 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        display: block !important;
    }
    .gh-qa-search-bar {
        width: 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        display: flex !important;
    }
    .gh-qa-composer-footer {
        flex-direction: column !important;
        align-items: stretch !important;
    }
    .gh-btn-reply-main {
        width: 100% !important;
        justify-content: center !important;
    }
}
</style>

<div class="gh-qa-container">
    <!-- Header -->
    <div class="gh-qa-header">
        <h1><?php esc_html_e( 'Discussions (Q&A)', 'growth-hub' ); ?></h1>
        <p><?php esc_html_e( 'Answer mentee questions, provide guidance on assignments, and engage your cohorts.', 'growth-hub' ); ?></p>
    </div>

    <!-- Controls Bar -->
    <div class="gh-qa-controls">
        <div class="gh-qa-pills-wrap">
            <a href="<?php echo esc_url( add_query_arg( array( 'status_filter' => 'all' ) ) ); ?>" class="gh-qa-pill-btn <?php echo ( 'all' === $filter_status ) ? 'active' : ''; ?>">
                <?php esc_html_e( 'All Questions', 'growth-hub' ); ?>
                <span class="gh-qa-count"><?php echo count( $questions ); ?></span>
            </a>
            <a href="<?php echo esc_url( add_query_arg( array( 'status_filter' => 'unanswered' ) ) ); ?>" class="gh-qa-pill-btn <?php echo ( 'unanswered' === $filter_status ) ? 'active' : ''; ?>">
                <?php esc_html_e( 'Unanswered', 'growth-hub' ); ?>
                <span class="gh-qa-count"><?php echo esc_html( $total_unanswered ); ?></span>
            </a>
            <a href="<?php echo esc_url( add_query_arg( array( 'status_filter' => 'answered' ) ) ); ?>" class="gh-qa-pill-btn <?php echo ( 'answered' === $filter_status ) ? 'active' : ''; ?>">
                <?php esc_html_e( 'Answered', 'growth-hub' ); ?>
                <span class="gh-qa-count"><?php echo esc_html( $total_answered ); ?></span>
            </a>
        </div>

        <div class="gh-qa-right-filters">
            <?php if ( ! empty( $courses ) ) : ?>
                <select class="gh-qa-course-select" onchange="window.location.href=this.value">
                    <option value="<?php echo esc_url( remove_query_arg( 'course_filter' ) ); ?>" <?php selected( 0, $filter_course ); ?>>
                        <?php esc_html_e( 'All Assigned Courses', 'growth-hub' ); ?>
                    </option>
                    <?php foreach ( $courses as $c ) : ?>
                        <option value="<?php echo esc_url( add_query_arg( 'course_filter', $c->ID ) ); ?>" <?php selected( $c->ID, $filter_course ); ?>>
                            <?php echo esc_html( $c->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <div class="gh-qa-search-bar">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" id="ghQaSearch" placeholder="<?php esc_attr_e( 'Search questions...', 'growth-hub' ); ?>" onkeyup="ghFilterQuestionsLive()">
            </div>
        </div>
    </div>

    <!-- Questions Feed -->
    <div class="gh-qa-feed" id="ghQaFeed">
        <?php if ( ! empty( $filtered_questions ) ) : ?>
            <?php foreach ( $filtered_questions as $q ) : 
                $initials = '';
                $name_parts = explode( ' ', trim( $q['author'] ) );
                foreach ( $name_parts as $np ) {
                    if ( ! empty( $np ) ) $initials .= strtoupper( $np[0] );
                    if ( strlen( $initials ) >= 2 ) break;
                }
                if ( empty( $initials ) ) $initials = 'ST';

                $q_time = human_time_diff( strtotime( $q['date'] ), current_time( 'timestamp' ) ) . ' ago';
                ?>
                <div class="gh-qa-card" id="qa_card_<?php echo esc_attr( $q['id'] ); ?>" data-search="<?php echo esc_attr( strtolower( $q['author'] . ' ' . $q['content'] . ' ' . $q['course_name'] ) ); ?>">
                    <div class="gh-qa-card-top">
                        <div class="gh-qa-author-wrap">
                            <div class="gh-qa-avatar"><?php echo esc_html( $initials ); ?></div>
                            <div class="gh-qa-author-info">
                                <div class="gh-qa-author-name-row">
                                    <span class="gh-qa-author-name"><?php echo esc_html( $q['author'] ); ?></span>
                                    <span class="gh-qa-mentee-badge">Mentee</span>
                                </div>
                                <div class="gh-qa-meta-row">
                                    <span class="gh-qa-course-tag">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                                        <?php echo esc_html( $q['course_name'] ); ?>
                                    </span>
                                    <span class="gh-qa-dot-sep">&bull;</span>
                                    <span class="gh-qa-time-text">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                        <?php echo esc_html( $q_time ); ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <?php if ( ! $q['is_answered'] ) : ?>
                                <span class="gh-qa-status-pill gh-qa-status-unanswered">
                                    <span class="gh-qa-status-dot unanswered"></span>
                                    <?php esc_html_e( 'Unanswered', 'growth-hub' ); ?>
                                </span>
                            <?php else : ?>
                                <span class="gh-qa-status-pill gh-qa-status-answered">
                                    <span class="gh-qa-status-dot answered"></span>
                                    <?php esc_html_e( 'Answered', 'growth-hub' ); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="gh-qa-question-box">
                        <div class="gh-qa-question-text"><?php echo esc_html( $q['content'] ); ?></div>
                    </div>

                    <!-- Replies Thread -->
                    <div class="gh-qa-thread" id="replies_<?php echo esc_attr( $q['id'] ); ?>">
                        <?php if ( ! empty( $q['replies'] ) ) : ?>
                            <div class="gh-qa-thread-title">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                                Mentor Guidance (<?php echo count( $q['replies'] ); ?>)
                            </div>
                            <?php foreach ( $q['replies'] as $rep ) : 
                                $rep_time = human_time_diff( strtotime( $rep['date'] ), current_time( 'timestamp' ) ) . ' ago';
                                ?>
                                <div class="gh-qa-reply-item">
                                    <div class="gh-qa-reply-header">
                                        <div class="gh-qa-reply-author-wrap">
                                            <div class="gh-qa-avatar-mini">AS</div>
                                            <span class="gh-qa-reply-author"><?php echo esc_html( $rep['author'] ); ?></span>
                                            <?php if ( ! empty( $rep['is_instructor'] ) ) : ?>
                                                <span class="gh-qa-instructor-badge"><?php esc_html_e( 'Mentor', 'growth-hub' ); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="gh-qa-reply-time"><?php echo esc_html( $rep_time ); ?></span>
                                    </div>
                                    <div class="gh-qa-reply-content"><?php echo esc_html( $rep['content'] ); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Polished Reply Composer -->
                    <form class="gh-qa-composer-form" onsubmit="ghSubmitReply(event, '<?php echo esc_js( $q['id'] ); ?>', '<?php echo esc_js( $q['course_id'] ); ?>')">
                        <div class="gh-qa-composer-card">
                            <textarea class="gh-qa-textarea" id="reply_input_<?php echo esc_attr( $q['id'] ); ?>" rows="3" placeholder="<?php esc_attr_e( 'Write your expert guidance or answer for this mentee...', 'growth-hub' ); ?>" required></textarea>
                            <div class="gh-qa-composer-footer">
                                <span class="gh-qa-composer-hint">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#632FD1" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                    Mentee will be notified instantly
                                </span>
                                <button type="submit" class="gh-btn-reply-main">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 10 4 15 9 20"></polyline><path d="M20 4v7a4 4 0 0 1-4 4H4"></path></svg>
                                    <?php esc_html_e( 'Post Reply', 'growth-hub' ); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <div class="gh-qa-empty">
                <div class="gh-qa-empty-icon">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                </div>
                <h3><?php esc_html_e( 'No discussions found', 'growth-hub' ); ?></h3>
                <p><?php esc_html_e( 'There are no questions matching this filter. When mentees ask questions on their lessons, they will appear here.', 'growth-hub' ); ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function ghFilterQuestionsLive() {
    var query = document.getElementById('ghQaSearch').value.toLowerCase().trim();
    var cards = document.querySelectorAll('.gh-qa-card');
    cards.forEach(function(card) {
        var text = card.getAttribute('data-search') || '';
        if (!query || text.indexOf(query) !== -1) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

function ghSubmitReply(e, questionId, courseId) {
    e.preventDefault();
    var input = document.getElementById('reply_input_' + questionId);
    if (!input || !input.value.trim()) return;

    var replyText = input.value.trim();
    var thread = document.getElementById('replies_' + questionId);

    // Create live reply element
    var replyDiv = document.createElement('div');
    replyDiv.className = 'gh-qa-reply-item';
    replyDiv.innerHTML = 
        '<div class="gh-qa-reply-header">' +
            '<div class="gh-qa-reply-author-wrap">' +
                '<div class="gh-qa-avatar-mini">AS</div>' +
                '<span class="gh-qa-reply-author"><?php echo esc_js( $user->display_name ? $user->display_name : "Instructor" ); ?></span>' +
                '<span class="gh-qa-instructor-badge">Mentor</span>' +
            '</div>' +
            '<span class="gh-qa-reply-time">Just now</span>' +
        '</div>' +
        '<div class="gh-qa-reply-content">' + ghEscapeHtml(replyText) + '</div>';

    if (thread) {
        var title = thread.querySelector('.gh-qa-thread-title');
        if (!title) {
            var newTitle = document.createElement('div');
            newTitle.className = 'gh-qa-thread-title';
            newTitle.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg> Mentor Guidance';
            thread.appendChild(newTitle);
        }
        thread.appendChild(replyDiv);
    }

    // Update status badge on card to Answered
    var card = document.getElementById('qa_card_' + questionId);
    if (card) {
        var statusWrap = card.querySelector('.gh-qa-status-pill');
        if (statusWrap) {
            statusWrap.className = 'gh-qa-status-pill gh-qa-status-answered';
            statusWrap.innerHTML = '<span class="gh-qa-status-dot answered"></span> Answered';
        }
    }

    // Persist via AJAX if ajaxurl is defined
    if (typeof ajaxurl !== 'undefined') {
        var fd = new FormData();
        fd.append('action', 'gh_submit_qa_reply');
        fd.append('question_id', questionId);
        fd.append('course_id', courseId);
        fd.append('reply_content', replyText);
        fetch(ajaxurl, { method: 'POST', body: fd }).catch(function(){});
    }

    input.value = '';
    if (typeof ghToast === 'function') {
        ghToast('Reply posted successfully!');
    }
}

function ghEscapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.innerText = str;
    return div.innerHTML;
}
</script>
