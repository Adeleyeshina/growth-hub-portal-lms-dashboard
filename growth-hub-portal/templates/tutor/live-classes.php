<?php
/**
 * Tutor Dashboard - Live Classes Template
 * Build: Growth Hub Custom Live Classes Hub
 * 
 * Features:
 * - Top header with "+ Schedule Live Class" CTA
 * - Status pills (All, Upcoming, Past) & Course Filter
 * - Live Class Cards with Meeting Status, Countdown, and Platform Badges (Google Meet & Zoom)
 * - Quick Actions: "Start Class", "Copy Link", "Log Class ->", "Delete"
 * - Responsive "+ Schedule Live Class" Modal with local storage / WP persistence
 * 
 * Author: Adeleye Shina
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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

// Course mapping
$course_map = array();
if ( ! empty( $courses ) ) {
    foreach ( $courses as $c ) {
        $course_map[ $c->ID ] = $c->post_title;
    }
}

// Fetch stored live classes for this instructor
$meta_key = '_gh_tutor_live_classes_' . $user_id;
$saved_classes = get_user_meta( $user_id, $meta_key, true );

if ( ! is_array( $saved_classes ) ) {
    $saved_classes = array();
}

// Query native Tutor LMS Zoom addon meetings if active on the site
if ( post_type_exists( 'tutor_zoom_meeting' ) ) {
    $zoom_args = array(
        'post_type'      => 'tutor_zoom_meeting',
        'post_status'    => array( 'publish', 'draft' ),
        'posts_per_page' => -1,
    );
    if ( ! current_user_can( 'administrator' ) ) {
        $zoom_args['author'] = $user_id;
    }
    $zoom_meetings = get_posts( $zoom_args );
    if ( ! empty( $zoom_meetings ) ) {
        foreach ( $zoom_meetings as $zm ) {
            $m_date = get_post_meta( $zm->ID, '_tutor_zm_start_date', true );
            $m_time = get_post_meta( $zm->ID, '_tutor_zm_start_time', true );
            $m_dur  = get_post_meta( $zm->ID, '_tutor_zm_duration', true );
            $m_url  = get_post_meta( $zm->ID, '_tutor_zm_join_url', true );
            if ( empty( $m_url ) ) {
                $m_url = get_post_meta( $zm->ID, '_tutor_zm_meeting_url', true );
            }
            $m_course_id = $zm->post_parent;
            $m_course_title = isset( $course_map[ $m_course_id ] ) ? $course_map[ $m_course_id ] : get_the_title( $m_course_id );

            $saved_classes[] = array(
                'id'          => 'zm_' . $zm->ID,
                'course_id'   => $m_course_id,
                'course_name' => $m_course_title ? $m_course_title : __( 'Assigned Course', 'growth-hub' ),
                'title'       => $zm->post_title,
                'platform'    => 'zoom',
                'link'        => $m_url,
                'date'        => $m_date ? $m_date : date( 'Y-m-d' ),
                'time'        => $m_time ? $m_time : '10:00',
                'duration'    => $m_dur ? $m_dur : '60',
                'status'      => ( strtotime( $m_date ) < strtotime( 'today' ) ) ? 'completed' : 'upcoming',
                'notes'       => __( 'Synced from Tutor LMS Zoom addon', 'growth-hub' ),
                'is_native'   => true,
            );
        }
    }
}

// Current Filter
$filter_status = isset( $_GET['status_filter'] ) ? sanitize_text_field( $_GET['status_filter'] ) : 'all';
$filter_course = isset( $_GET['course_filter'] ) ? intval( $_GET['course_filter'] ) : 0;

$filtered_classes = array();
$total_upcoming = 0;
$total_completed = 0;

foreach ( $saved_classes as $sc ) {
    $c_status = isset( $sc['status'] ) ? $sc['status'] : 'upcoming';
    if ( 'upcoming' === $c_status ) {
        $total_upcoming++;
    } elseif ( 'completed' === $c_status ) {
        $total_completed++;
    }

    if ( 'upcoming' === $filter_status && 'upcoming' !== $c_status ) {
        continue;
    }
    if ( 'completed' === $filter_status && 'completed' !== $c_status ) {
        continue;
    }
    if ( $filter_course > 0 && intval( $sc['course_id'] ) !== $filter_course ) {
        continue;
    }

    $filtered_classes[] = $sc;
}

$dash_url = function_exists( 'gh_portal_dashboard_url' ) ? gh_portal_dashboard_url() : ( function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url() : home_url( '/dashboard/' ) );
$class_log_url = function_exists( 'gh_portal_dashboard_url' ) ? gh_portal_dashboard_url( 'class-log' ) : add_query_arg( 'tab', 'class-log', $dash_url );
?>

<style>
/* Growth Hub - Live Classes Screen Master CSS */
.gh-live-container {
    max-width: 1320px;
    margin: 0 auto;
    padding-bottom: 60px;
    width: 100%;
    box-sizing: border-box;
    font-family: 'Lato', -apple-system, BlinkMacSystemFont, sans-serif;
    color: #1B1240;
}

/* Header Area */
.gh-live-header {
    display: flex !important;
    justify-content: space-between !important;
    align-items: flex-start !important;
    margin-bottom: 24px !important;
    flex-wrap: wrap !important;
    gap: 16px !important;
}

.gh-live-header h1 {
    font-family: 'Poppins', sans-serif !important;
    font-size: 28px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
    margin: 0 0 6px 0 !important;
    line-height: 1.2 !important;
}

.gh-live-header p {
    font-size: 14.5px !important;
    color: #6B6580 !important;
    margin: 0 !important;
    line-height: 1.4 !important;
}

.gh-btn-schedule-main {
    background: #632FD1 !important;
    color: #FFFFFF !important;
    border: none !important;
    border-radius: 9999px !important;
    padding: 11px 24px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
    box-shadow: 0 2px 8px rgba(99, 47, 209, 0.25) !important;
    transition: background 0.15s ease !important;
    text-decoration: none !important;
    white-space: nowrap !important;
}

.gh-btn-schedule-main:hover {
    background: #5224B8 !important;
    color: #FFFFFF !important;
}

/* Filter Controls Bar */
/* Live Classes Toolbar (Exact match with Grading & Mentees) */
.gh-live-toolbar {
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
    box-sizing: border-box !important;
}

.gh-filter-tab {
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

.gh-filter-tab:hover {
    color: #632FD1 !important;
}

.gh-filter-tab.active {
    background: #FFFFFF !important;
    color: #632FD1 !important;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08) !important;
}

.gh-count-pill {
    background: rgba(99, 47, 209, 0.12) !important;
    color: #632FD1 !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    padding: 2px 8px !important;
    border-radius: 9999px !important;
}

.gh-count-upcoming {
    background: #D1FAE5 !important;
    color: #065F46 !important;
}

.gh-filter-controls {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
}

.gh-select-wrap {
    position: relative !important;
}

select.gh-custom-select {
    height: 48px !important;
    min-height: 48px !important;
    border: 1.5px solid #D8D2EE !important;
    border-radius: 12px !important;
    background: #FFFFFF !important;
    padding: 0 40px 0 16px !important;
    font-family: 'Lato', sans-serif !important;
    font-size: 14px !important;
    font-weight: 500 !important;
    color: #1B1240 !important;
    cursor: pointer !important;
    appearance: none !important;
    -webkit-appearance: none !important;
    background-image: url("data:image/svg+xml,%3Csvg width='12' height='8' viewBox='0 0 12 8' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1.5L6 6.5L11 1.5' stroke='%23632FD1' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: calc(100% - 14px) center !important;
    outline: none !important;
    min-width: 220px !important;
    box-sizing: border-box !important;
}

/* Class Cards Grid */
.gh-live-grid {
    display: flex;
    flex-direction: column;
    gap: 16px;
    width: 100%;
}

.gh-live-card {
    background: #FFFFFF !important;
    border: 1px solid #D8D2EE !important;
    border-radius: 16px !important;
    padding: 22px 24px !important;
    display: flex !important;
    justify-content: space-between !important;
    align-items: center !important;
    gap: 20px !important;
    box-sizing: border-box !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02) !important;
    transition: transform 0.15s ease, box-shadow 0.15s ease !important;
}

.gh-live-card:hover {
    box-shadow: 0 4px 14px rgba(99, 47, 209, 0.08) !important;
    border-color: #B2A2DE !important;
}

.gh-live-card-left {
    display: flex;
    align-items: flex-start;
    gap: 18px;
    flex: 1 1 auto;
    min-width: 0;
}

.gh-date-badge {
    background: #F4F1FB;
    border: 1.5px solid #D8D2EE;
    border-radius: 12px;
    padding: 10px 14px;
    text-align: center;
    min-width: 62px;
    box-sizing: border-box;
    flex-shrink: 0;
}

.gh-date-badge-month {
    font-family: 'Poppins', sans-serif;
    font-size: 11px;
    font-weight: 700;
    color: #632FD1;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    line-height: 1;
}

.gh-date-badge-day {
    font-family: 'Poppins', sans-serif;
    font-size: 22px;
    font-weight: 800;
    color: #1B1240;
    line-height: 1.1;
    margin-top: 4px;
}

.gh-card-details {
    flex: 1 1 auto;
    min-width: 0;
}

.gh-card-meta-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 6px;
    flex-wrap: wrap;
}

.gh-tag-course {
    font-size: 12px;
    font-weight: 700;
    color: #632FD1;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.gh-platform-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11.5px;
    font-weight: 700;
    padding: 3px 10px;
    border-radius: 9999px;
    line-height: 1.2;
}

.gh-badge-meet {
    background: #E6F4EA;
    color: #137333;
    border: 1px solid #CEEAD6;
}

.gh-badge-zoom {
    background: #E8F0FE;
    color: #1A73E8;
    border: 1px solid #D2E3FC;
}

.gh-live-title {
    font-family: 'Poppins', sans-serif !important;
    font-size: 16.5px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
    margin: 0 0 8px 0 !important;
    line-height: 1.35 !important;
}

.gh-live-info-row {
    display: flex;
    align-items: center;
    gap: 16px;
    font-size: 13.5px;
    color: #6B6580;
    flex-wrap: wrap;
}

.gh-live-info-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.gh-live-card-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
    flex-wrap: wrap;
}

.gh-btn-start-meeting {
    background: #632FD1 !important;
    color: #FFFFFF !important;
    border: none !important;
    border-radius: 9999px !important;
    padding: 9px 20px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    text-decoration: none !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 6px !important;
    transition: background 0.15s ease !important;
    white-space: nowrap !important;
}

.gh-btn-start-meeting:hover {
    background: #5224B8 !important;
    color: #FFFFFF !important;
}

.gh-btn-copy-link {
    background: #FFFFFF !important;
    color: #632FD1 !important;
    border: 1.5px solid #632FD1 !important;
    border-radius: 9999px !important;
    padding: 8px 16px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    text-decoration: none !important;
    transition: all 0.15s ease !important;
    white-space: nowrap !important;
}

.gh-btn-copy-link:hover {
    background: #F4F1FB !important;
    color: #5224B8 !important;
}

.gh-btn-log-shortcut {
    color: #10B981 !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    text-decoration: none !important;
    padding: 8px 12px !important;
    white-space: nowrap !important;
}

.gh-btn-log-shortcut:hover {
    text-decoration: underline !important;
}

.gh-btn-delete-class {
    color: #DC2626 !important;
    background: transparent !important;
    border: none !important;
    cursor: pointer !important;
    font-size: 12.5px !important;
    padding: 8px !important;
    opacity: 0.7;
    transition: opacity 0.2s;
}
.gh-btn-delete-class:hover {
    opacity: 1;
}

/* Empty State */
.gh-live-empty {
    background: #FFFFFF;
    border: 2px dashed #D8D2EE;
    border-radius: 16px;
    padding: 50px 24px;
    text-align: center;
}
.gh-live-empty h3 {
    font-family: 'Poppins', sans-serif;
    font-size: 18px;
    font-weight: 700;
    color: #1B1240;
    margin: 0 0 8px 0;
}
.gh-live-empty p {
    font-size: 14px;
    color: #6B6580;
    max-width: 420px;
    margin: 0 auto 20px auto;
}

/* Modal Dialog Styles */
.gh-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(27, 18, 64, 0.6);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 99999;
    padding: 20px;
    box-sizing: border-box;
}

.gh-modal-box {
    background: #FFFFFF;
    border-radius: 18px;
    width: 100%;
    max-width: 580px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 30px;
    box-sizing: border-box;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    position: relative;
}

.gh-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 14px;
    border-bottom: 1px solid #F4F1FB;
}

.gh-modal-header h2 {
    font-family: 'Poppins', sans-serif;
    font-size: 20px;
    font-weight: 700;
    color: #1B1240;
    margin: 0;
}

.gh-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    color: #8C85A6;
    cursor: pointer;
    line-height: 1;
}

.gh-form-group {
    margin-bottom: 18px;
}

.gh-form-group label {
    display: block;
    font-family: 'Poppins', sans-serif;
    font-size: 13px;
    font-weight: 600;
    color: #1B1240;
    margin-bottom: 6px;
}

.gh-input-text,
.gh-input-select,
.gh-input-textarea {
    width: 100%;
    box-sizing: border-box;
    border: 1.5px solid #D8D2EE;
    border-radius: 10px;
    padding: 10px 14px;
    font-family: 'Lato', sans-serif;
    font-size: 14px;
    color: #1B1240;
    outline: none;
}
.gh-input-text:focus,
.gh-input-select:focus,
.gh-input-textarea:focus {
    border-color: #632FD1;
}

.gh-form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}

.gh-platform-toggles {
    display: flex;
    gap: 12px;
}

.gh-platform-choice {
    flex: 1;
    border: 1.5px solid #D8D2EE;
    border-radius: 10px;
    padding: 10px;
    text-align: center;
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s;
}

.gh-platform-choice.selected {
    border-color: #632FD1;
    background: #F4F1FB;
    color: #632FD1;
}

.gh-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 24px;
    padding-top: 16px;
    border-top: 1px solid #F4F1FB;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .gh-live-toolbar {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 12px !important;
        margin-bottom: 20px !important;
    }
    .gh-filter-tabs {
        display: flex !important;
        flex-wrap: nowrap !important;
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
        scrollbar-width: none !important;
        width: 100% !important;
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
    .gh-filter-tab .gh-count-pill {
        font-size: 11.5px !important;
        padding: 2px 7px !important;
        border-radius: 20px !important;
    }
    .gh-filter-controls {
        width: 100% !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: stretch !important;
    }
    .gh-select-wrap {
        width: 100% !important;
        box-sizing: border-box !important;
    }
    select.gh-custom-select {
        width: 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        display: block !important;
    }
    .gh-live-header {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 14px !important;
    }
    .gh-btn-schedule-main {
        width: 100% !important;
        justify-content: center !important;
        text-align: center !important;
    }
    .gh-live-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
        padding: 16px !important;
        border-radius: 14px !important;
    }
    .gh-live-card-actions {
        width: 100%;
        justify-content: flex-start;
        border-top: 1px solid #F4F1FB;
        padding-top: 12px;
    }
    .gh-btn-start-meeting,
    .gh-btn-copy-link {
        flex: 1 1 auto;
        text-align: center;
        justify-content: center;
    }
    .gh-form-row {
        grid-template-columns: 1fr;
    }
}

/* Toast Notifications & Custom Confirm Modal */
#gh-toast-container {
    position: fixed;
    bottom: 28px;
    right: 28px;
    z-index: 100000;
    display: flex;
    flex-direction: column;
    gap: 10px;
    pointer-events: none;
}
.gh-toast {
    pointer-events: auto;
    background: #1B1240;
    color: #FFFFFF;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 9999px;
    padding: 12px 22px;
    box-shadow: 0 10px 30px rgba(27, 18, 64, 0.25);
    display: inline-flex;
    align-items: center;
    gap: 12px;
    font-family: 'Poppins', sans-serif;
    font-size: 13.5px;
    font-weight: 500;
    animation: ghToastIn 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    transition: opacity 0.3s, transform 0.3s;
    max-width: 420px;
}
.gh-toast.fade-out {
    opacity: 0;
    transform: translateY(10px);
}
.gh-toast-icon {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.gh-toast-success .gh-toast-icon {
    background: #10B981;
    color: #FFFFFF;
}
.gh-toast-info .gh-toast-icon {
    background: #632FD1;
    color: #FFFFFF;
}
.gh-toast-error .gh-toast-icon {
    background: #EF4444;
    color: #FFFFFF;
}
@keyframes ghToastIn {
    from { opacity: 0; transform: translateY(15px); }
    to { opacity: 1; transform: translateY(0); }
}
@media (max-width: 768px) {
    #gh-toast-container {
        top: 20px !important;
        top: max(20px, env(safe-area-inset-top, 20px)) !important;
        bottom: auto !important;
        right: 16px !important;
        left: 16px !important;
        align-items: center !important;
    }
    .gh-toast {
        max-width: 100% !important;
        width: auto !important;
        box-sizing: border-box !important;
        justify-content: center !important;
        text-align: center !important;
        box-shadow: 0 12px 35px rgba(27, 18, 64, 0.45) !important;
        animation: ghToastInMobile 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
    }
    .gh-toast.fade-out {
        opacity: 0 !important;
        transform: translateY(-16px) !important;
    }
}
@keyframes ghToastInMobile {
    from { opacity: 0; transform: translateY(-24px) scale(0.96); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

#gh-confirm-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(27, 18, 64, 0.55);
    backdrop-filter: blur(5px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 100001;
    padding: 20px;
    box-sizing: border-box;
}
.gh-confirm-card {
    background: #FFFFFF;
    border-radius: 20px;
    width: 100%;
    max-width: 440px;
    padding: 32px 26px;
    box-sizing: border-box;
    box-shadow: 0 15px 40px rgba(27, 18, 64, 0.2);
    text-align: center;
    animation: ghPopIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
@keyframes ghPopIn {
    from { opacity: 0; transform: scale(0.92); }
    to { opacity: 1; transform: scale(1); }
}
.gh-confirm-icon-wrap {
    width: 54px;
    height: 54px;
    border-radius: 50%;
    margin: 0 auto 16px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.gh-confirm-icon-danger {
    background: #FEE2E2;
    color: #DC2626;
}
.gh-confirm-icon-primary {
    background: #F4F1FB;
    color: #632FD1;
}
.gh-confirm-title {
    font-family: 'Poppins', sans-serif;
    font-size: 19px;
    font-weight: 700;
    color: #1B1240;
    margin: 0 0 8px;
}
.gh-confirm-desc {
    font-family: 'Lato', sans-serif;
    font-size: 14px;
    color: #6B6580;
    line-height: 1.5;
    margin: 0 0 24px;
}
.gh-confirm-actions {
    display: flex;
    gap: 12px;
    justify-content: center;
}
.gh-btn-cancel-modal {
    background: #FFFFFF !important;
    border: 1.5px solid #D8D2EE !important;
    color: #1B1240 !important;
    border-radius: 9999px !important;
    padding: 10px 20px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    flex: 1 !important;
    transition: all 0.15s ease !important;
    white-space: nowrap !important;
    text-align: center !important;
}
.gh-btn-cancel-modal:hover {
    background: #F4F1FB !important;
    border-color: #632FD1 !important;
    color: #632FD1 !important;
}
.gh-btn-action-modal {
    border: none !important;
    border-radius: 9999px !important;
    padding: 10px 20px !important;
    font-family: 'Poppins', sans-serif !important;
    font-size: 13.5px !important;
    font-weight: 600 !important;
    cursor: pointer !important;
    color: #FFFFFF !important;
    flex: 1 !important;
    transition: all 0.15s ease !important;
    white-space: nowrap !important;
    text-align: center !important;
}
.gh-btn-action-modal.danger {
    background: #DC2626 !important;
    color: #FFFFFF !important;
}
.gh-btn-action-modal.danger:hover {
    background: #B91C1C !important;
    color: #FFFFFF !important;
}
.gh-btn-action-modal.primary {
    background: #632FD1 !important;
    color: #FFFFFF !important;
}
.gh-btn-action-modal.primary:hover {
    background: #5224B8 !important;
    color: #FFFFFF !important;
}
</style>

<div class="gh-live-container">
    <!-- Top Header -->
    <div class="gh-live-header">
        <div>
            <h1><?php esc_html_e( 'Live Classes', 'growth-hub' ); ?></h1>
            <p><?php esc_html_e( 'Schedule, manage, and launch your live Zoom and Google Meet sessions with your mentees.', 'growth-hub' ); ?></p>
        </div>
        <div>
            <button type="button" class="gh-btn-schedule-main" onclick="ghOpenScheduleModal()">
                <span>+</span> <?php esc_html_e( 'Schedule Live Class', 'growth-hub' ); ?>
            </button>
        </div>
    </div>

    <!-- Controls & Filters Toolbar (Matching Grading & Mentees) -->
    <div class="gh-live-toolbar">
        <div class="gh-filter-tabs">
            <a href="<?php echo esc_url( add_query_arg( array( 'status_filter' => 'all' ) ) ); ?>" class="gh-filter-tab <?php echo ( 'all' === $filter_status ) ? 'active' : ''; ?>">
                <?php esc_html_e( 'All Classes', 'growth-hub' ); ?>
                <span class="gh-count-pill"><?php echo count( $saved_classes ); ?></span>
            </a>
            <a href="<?php echo esc_url( add_query_arg( array( 'status_filter' => 'upcoming' ) ) ); ?>" class="gh-filter-tab <?php echo ( 'upcoming' === $filter_status ) ? 'active' : ''; ?>">
                <?php esc_html_e( 'Upcoming', 'growth-hub' ); ?>
                <span class="gh-count-pill gh-count-upcoming"><?php echo esc_html( $total_upcoming ); ?></span>
            </a>
            <a href="<?php echo esc_url( add_query_arg( array( 'status_filter' => 'completed' ) ) ); ?>" class="gh-filter-tab <?php echo ( 'completed' === $filter_status ) ? 'active' : ''; ?>">
                <?php esc_html_e( 'Completed / Past', 'growth-hub' ); ?>
                <span class="gh-count-pill"><?php echo esc_html( $total_completed ); ?></span>
            </a>
        </div>

        <?php if ( ! empty( $courses ) ) : ?>
            <div class="gh-filter-controls">
                <div class="gh-select-wrap">
                    <select class="gh-custom-select" onchange="window.location.href=this.value">
                        <option value="<?php echo esc_url( remove_query_arg( 'course_filter' ) ); ?>" <?php selected( 0, $filter_course ); ?>>
                            <?php esc_html_e( 'All Assigned Courses', 'growth-hub' ); ?>
                        </option>
                        <?php foreach ( $courses as $c ) : ?>
                            <option value="<?php echo esc_url( add_query_arg( 'course_filter', $c->ID ) ); ?>" <?php selected( $c->ID, $filter_course ); ?>>
                                <?php echo esc_html( $c->post_title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Live Classes Grid / List -->
    <div class="gh-live-grid">
        <?php if ( ! empty( $filtered_classes ) ) : ?>
            <?php foreach ( $filtered_classes as $cls ) : 
                $c_date = strtotime( $cls['date'] );
                $month_str = date( 'M', $c_date );
                $day_str = date( 'd', $c_date );
                $time_str = date( 'g:i A', strtotime( $cls['time'] ) );
                $is_meet = ( 'google-meet' === $cls['platform'] );
                $platform_label = $is_meet ? 'Google Meet' : 'Zoom';
                $platform_class = $is_meet ? 'gh-badge-meet' : 'gh-badge-zoom';
                $is_past = ( 'completed' === $cls['status'] || $c_date < strtotime( 'today' ) );
                
                // Pre-fill Class Log URL shortcut
                $log_shortcut = add_query_arg( array(
                    'tab'        => 'class-log',
                    'course_id'  => $cls['course_id'],
                    'topic'      => rawurlencode( $cls['title'] ),
                    'class_date' => $cls['date'],
                ), $dash_url );
                ?>
                <div class="gh-live-card" id="card_<?php echo esc_attr( $cls['id'] ); ?>">
                    <div class="gh-live-card-left">
                        <div class="gh-date-badge">
                            <div class="gh-date-badge-month"><?php echo esc_html( $month_str ); ?></div>
                            <div class="gh-date-badge-day"><?php echo esc_html( $day_str ); ?></div>
                        </div>
                        <div class="gh-card-details">
                            <div class="gh-card-meta-row">
                                <span class="gh-tag-course"><?php echo esc_html( $cls['course_name'] ); ?></span>
                                <span class="gh-platform-badge <?php echo esc_attr( $platform_class ); ?>">
                                    <?php echo esc_html( $platform_label ); ?>
                                </span>
                                <?php if ( ! $is_past ) : ?>
                                    <span style="font-size:11px; font-weight:700; color:#10B981; background:#E8F8F0; padding:2px 8px; border-radius:9999px;">
                                        ● <?php esc_html_e( 'Scheduled', 'growth-hub' ); ?>
                                    </span>
                                <?php else : ?>
                                    <span style="font-size:11px; font-weight:600; color:#8C85A6; background:#F4F1FB; padding:2px 8px; border-radius:9999px;">
                                        <?php esc_html_e( 'Ended', 'growth-hub' ); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <h3 class="gh-live-title"><?php echo esc_html( $cls['title'] ); ?></h3>
                            <div class="gh-live-info-row">
                                <div class="gh-live-info-item">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#632FD1" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                    <span><?php echo esc_html( $time_str ); ?> (<?php echo esc_html( $cls['duration'] ); ?> mins)</span>
                                </div>
                                <?php if ( ! empty( $cls['notes'] ) ) : ?>
                                    <div class="gh-live-info-item" style="color:#8C85A6;">
                                        <span><?php echo esc_html( $cls['notes'] ); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="gh-live-card-actions">
                        <?php if ( ! $is_past ) : ?>
                            <a href="<?php echo esc_url( $cls['link'] ); ?>" target="_blank" rel="noopener noreferrer" class="gh-btn-start-meeting">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"></polygon></svg>
                                <?php esc_html_e( 'Start Class', 'growth-hub' ); ?>
                            </a>
                        <?php endif; ?>

                        <button type="button" class="gh-btn-copy-link" onclick="ghCopyLiveLink('<?php echo esc_js( $cls['link'] ); ?>')">
                            <?php esc_html_e( 'Copy Link', 'growth-hub' ); ?>
                        </button>

                        <a href="<?php echo esc_url( $log_shortcut ); ?>" class="gh-btn-log-shortcut">
                            <?php esc_html_e( 'Log class →', 'growth-hub' ); ?>
                        </a>

                        <button type="button" class="gh-btn-delete-class" title="<?php esc_attr_e( 'Remove session', 'growth-hub' ); ?>" onclick="ghDeleteLiveClass('<?php echo esc_js( $cls['id'] ); ?>')">
                            ✕
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else : ?>
            <div class="gh-live-empty">
                <h3><?php esc_html_e( 'No live classes found', 'growth-hub' ); ?></h3>
                <p><?php esc_html_e( 'You do not have any live classes matching this filter. Schedule a session to meet live with your cohort.', 'growth-hub' ); ?></p>
                <button type="button" class="gh-btn-schedule-main" onclick="ghOpenScheduleModal()">
                    + <?php esc_html_e( 'Schedule a Class Now', 'growth-hub' ); ?>
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Schedule Live Class -->
<div class="gh-modal-overlay" id="ghScheduleModal">
    <div class="gh-modal-box">
        <div class="gh-modal-header">
            <h2><?php esc_html_e( 'Schedule a Live Class', 'growth-hub' ); ?></h2>
            <button type="button" class="gh-modal-close" onclick="ghCloseScheduleModal()">&times;</button>
        </div>

        <form id="ghScheduleClassForm" onsubmit="ghSaveLiveClass(event)">
            <div class="gh-form-group">
                <label><?php esc_html_e( 'Course / Cohort', 'growth-hub' ); ?> *</label>
                <select name="course_id" id="gh_modal_course" class="gh-input-select" required>
                    <?php if ( ! empty( $courses ) ) : ?>
                        <?php foreach ( $courses as $c ) : ?>
                            <option value="<?php echo esc_attr( $c->ID ); ?>" data-title="<?php echo esc_attr( $c->post_title ); ?>">
                                <?php echo esc_html( $c->post_title ); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <option value="" disabled selected><?php esc_html_e( 'No active courses assigned', 'growth-hub' ); ?></option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="gh-form-group">
                <label><?php esc_html_e( 'Class Topic / Title', 'growth-hub' ); ?> *</label>
                <input type="text" name="topic" id="gh_modal_topic" class="gh-input-text" placeholder="<?php esc_attr_e( 'e.g. Week 5: Data Cleaning with Python Pandas', 'growth-hub' ); ?>" required>
            </div>

            <div class="gh-form-row">
                <div class="gh-form-group">
                    <label><?php esc_html_e( 'Date', 'growth-hub' ); ?> *</label>
                    <input type="date" name="date" id="gh_modal_date" class="gh-input-text" value="<?php echo date( 'Y-m-d', strtotime( '+1 day' ) ); ?>" required>
                </div>
                <div class="gh-form-group">
                    <label><?php esc_html_e( 'Time', 'growth-hub' ); ?> *</label>
                    <input type="time" name="time" id="gh_modal_time" class="gh-input-text" value="10:00" required>
                </div>
            </div>

            <div class="gh-form-row">
                <div class="gh-form-group">
                    <label><?php esc_html_e( 'Duration', 'growth-hub' ); ?></label>
                    <select name="duration" id="gh_modal_duration" class="gh-input-select">
                        <option value="60">60 mins (1 hour)</option>
                        <option value="90" selected>90 mins (1.5 hours)</option>
                        <option value="120">120 mins (2 hours)</option>
                    </select>
                </div>
                <div class="gh-form-group">
                    <label><?php esc_html_e( 'Platform', 'growth-hub' ); ?></label>
                    <div class="gh-platform-toggles">
                        <div class="gh-platform-choice selected" id="plat_meet" onclick="ghSelectPlatform('google-meet')">
                            Google Meet
                        </div>
                        <div class="gh-platform-choice" id="plat_zoom" onclick="ghSelectPlatform('zoom')">
                            Zoom
                        </div>
                    </div>
                    <input type="hidden" name="platform" id="gh_modal_platform" value="google-meet">
                </div>
            </div>

            <div class="gh-form-group">
                <label><?php esc_html_e( 'Meeting Link (URL)', 'growth-hub' ); ?> *</label>
                <input type="url" name="link" id="gh_modal_link" class="gh-input-text" placeholder="https://meet.google.com/xyz-abcd-efg" required>
            </div>

            <div class="gh-form-group">
                <label><?php esc_html_e( 'Instructions / Notes for Mentees', 'growth-hub' ); ?></label>
                <textarea name="notes" id="gh_modal_notes" class="gh-input-textarea" rows="2" placeholder="<?php esc_attr_e( 'e.g. Please install Jupyter Notebook prior to joining.', 'growth-hub' ); ?>"></textarea>
            </div>

            <div class="gh-modal-footer">
                <button type="button" class="gh-btn-copy-link" onclick="ghCloseScheduleModal()"><?php esc_html_e( 'Cancel', 'growth-hub' ); ?></button>
                <button type="submit" class="gh-btn-schedule-main"><?php esc_html_e( 'Schedule Class', 'growth-hub' ); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function ghOpenScheduleModal() {
    document.getElementById('ghScheduleModal').style.display = 'flex';
}

function ghCloseScheduleModal() {
    document.getElementById('ghScheduleModal').style.display = 'none';
}

function ghSelectPlatform(platform) {
    document.getElementById('gh_modal_platform').value = platform;
    var meet = document.getElementById('plat_meet');
    var zoom = document.getElementById('plat_zoom');
    var linkInput = document.getElementById('gh_modal_link');
    
    if (platform === 'google-meet') {
        meet.classList.add('selected');
        zoom.classList.remove('selected');
        if (!linkInput.value || linkInput.value.includes('zoom.us')) {
            linkInput.placeholder = 'https://meet.google.com/xyz-abcd-efg';
        }
    } else {
        zoom.classList.add('selected');
        meet.classList.remove('selected');
        if (!linkInput.value || linkInput.value.includes('meet.google.com')) {
            linkInput.placeholder = 'https://zoom.us/j/1234567890';
        }
    }
}

function ghCopyLiveLink(url) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function() {
            ghToast('Meeting link copied to clipboard!');
        });
    } else {
        prompt('Copy meeting link:', url);
    }
}

function ghSaveLiveClass(e) {
    e.preventDefault();
    var courseSel = document.getElementById('gh_modal_course');
    var courseId = courseSel.value;
    var courseName = courseSel.options[courseSel.selectedIndex].getAttribute('data-title') || 'Course';
    var topic = document.getElementById('gh_modal_topic').value;
    var dateVal = document.getElementById('gh_modal_date').value;
    var timeVal = document.getElementById('gh_modal_time').value;
    var duration = document.getElementById('gh_modal_duration').value;
    var platform = document.getElementById('gh_modal_platform').value;
    var link = document.getElementById('gh_modal_link').value;
    var notes = document.getElementById('gh_modal_notes').value;

    var newClass = {
        id: 'lc_' + Date.now(),
        course_id: courseId,
        course_name: courseName,
        title: topic,
        platform: platform,
        link: link,
        date: dateVal,
        time: timeVal,
        duration: duration,
        status: 'upcoming',
        notes: notes
    };

    var ajaxEndpoint = (typeof ghPortalData !== 'undefined' && ghPortalData.ajax_url) ? ghPortalData.ajax_url : (typeof ajaxurl !== 'undefined' ? ajaxurl : '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>');
    var fd = new FormData();
    fd.append('action', 'gh_save_live_class');
    fd.append('class_data', JSON.stringify(newClass));

    fetch(ajaxEndpoint, { method: 'POST', body: fd })
        .then(function() {
            ghToast('Live class scheduled successfully!');
            ghCloseScheduleModal();
            setTimeout(function() { window.location.reload(); }, 600);
        })
        .catch(function(err) {
            ghToast('Error saving live class', 'danger');
        });
}

function ghDeleteLiveClass(id) {
    ghConfirm(
        'Remove Live Class?',
        'Are you sure you want to remove this scheduled session? This cannot be undone.',
        function() {
            var card = document.getElementById('card_' + id);
            if (card) {
                card.style.opacity = '0.3';
                card.style.pointerEvents = 'none';
            }

            var ajaxEndpoint = (typeof ghPortalData !== 'undefined' && ghPortalData.ajax_url) ? ghPortalData.ajax_url : (typeof ajaxurl !== 'undefined' ? ajaxurl : '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>');
            var fd = new FormData();
            fd.append('action', 'gh_delete_live_class');
            fd.append('class_id', id);

            fetch(ajaxEndpoint, { method: 'POST', body: fd })
                .then(function() {
                    setTimeout(function() {
                        if (card) card.remove();
                        ghToast('Live class removed', 'info');
                        // If no cards left, reload to show empty state
                        var remaining = document.querySelectorAll('.gh-live-card');
                        if (!remaining || remaining.length <= 1) {
                            setTimeout(function() { window.location.reload(); }, 500);
                        }
                    }, 300);
                });
        },
        'Remove',
        true
    );
}

// Custom Floating Toast Notification System
function ghToast(msg, type) {
    type = type || 'success';
    var container = document.getElementById('gh-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'gh-toast-container';
        document.body.appendChild(container);
    }
    var toast = document.createElement('div');
    toast.className = 'gh-toast gh-toast-' + type;
    
    var iconSvg = type === 'success' 
        ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>'
        : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
        
    toast.innerHTML = '<span class="gh-toast-icon">' + iconSvg + '</span><span>' + msg + '</span>';
    container.appendChild(toast);
    
    setTimeout(function() {
        toast.classList.add('fade-out');
        setTimeout(function() { toast.remove(); }, 300);
    }, 2800);
}

// Custom Growth Hub Confirmation Modal System
var ghConfirmCallback = null;
function ghConfirm(title, desc, onConfirm, confirmText, isDanger) {
    confirmText = confirmText || 'Confirm';
    isDanger = (typeof isDanger === 'undefined') ? true : isDanger;
    ghConfirmCallback = onConfirm;
    
    var modal = document.getElementById('gh-confirm-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'gh-confirm-modal';
        modal.innerHTML = '<div class="gh-confirm-card">' +
            '<div class="gh-confirm-icon-wrap ' + (isDanger ? 'gh-confirm-icon-danger' : 'gh-confirm-icon-primary') + '" id="gh-confirm-icon">' +
                '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>' +
            '</div>' +
            '<h3 class="gh-confirm-title" id="gh-confirm-title">' + title + '</h3>' +
            '<p class="gh-confirm-desc" id="gh-confirm-desc">' + desc + '</p>' +
            '<div class="gh-confirm-actions">' +
                '<button type="button" class="gh-btn-cancel-modal" onclick="ghCloseConfirmModal()">Cancel</button>' +
                '<button type="button" class="gh-btn-action-modal ' + (isDanger ? 'danger' : 'primary') + '" id="gh-confirm-action-btn" onclick="ghExecuteConfirm()">' + confirmText + '</button>' +
            '</div>' +
        '</div>';
        document.body.appendChild(modal);
    } else {
        document.getElementById('gh-confirm-title').innerText = title;
        document.getElementById('gh-confirm-desc').innerText = desc;
        var btn = document.getElementById('gh-confirm-action-btn');
        btn.innerText = confirmText;
        btn.className = 'gh-btn-action-modal ' + (isDanger ? 'danger' : 'primary');
        var iconWrap = document.getElementById('gh-confirm-icon');
        iconWrap.className = 'gh-confirm-icon-wrap ' + (isDanger ? 'gh-confirm-icon-danger' : 'gh-confirm-icon-primary');
    }
    modal.style.display = 'flex';
}

function ghCloseConfirmModal() {
    var modal = document.getElementById('gh-confirm-modal');
    if (modal) modal.style.display = 'none';
    ghConfirmCallback = null;
}

function ghExecuteConfirm() {
    if (typeof ghConfirmCallback === 'function') {
        ghConfirmCallback();
    }
    ghCloseConfirmModal();
}
</script>

