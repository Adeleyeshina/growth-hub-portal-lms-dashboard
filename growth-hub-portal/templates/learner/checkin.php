<?php
/**
 * Learner Check-in & Weekly Pulse Template (Build 4 of 8)
 * Direct match for Figma design:
 * - 4a Physical class check-in with dynamic QR code
 * - 4b Virtual class confirmation (Zoom automatic detection)
 * - 4c Weekly pulse after class (1-5 rating, pace selector, comments)
 * - 4d Thank you and next class confirmation
 *
 * Author: Adeleye Shina
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$class_id = isset( $_GET['class_id'] ) ? absint( $_GET['class_id'] ) : 0;
$user_id  = get_current_user_id();

// If class_id is not specified in URL, dynamically resolve to the latest logged class
if ( ! $class_id ) {
    $latest_logs = get_posts( array(
        'post_type'      => 'gh_class_log',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );
    if ( ! empty( $latest_logs ) ) {
        $class_id = $latest_logs[0]->ID;
    }
}

$course_badge  = __( 'Class Session', 'growth-hub' );
$class_topic   = __( 'No active class session scheduled', 'growth-hub' );
$class_details = __( 'Please scan the projector QR code or use the link provided by your tutor.', 'growth-hub' );
$class_mode    = isset( $_GET['mode'] ) && 'virtual' === $_GET['mode'] ? 'virtual' : 'physical';

if ( $class_id ) {
    $class_post = get_post( $class_id );
    if ( $class_post && 'gh_class_log' === $class_post->post_type ) {
        $course_id = get_post_meta( $class_id, '_gh_course_id', true );
        $course_obj = $course_id ? get_post( $course_id ) : null;
        $course_title = $course_obj ? $course_obj->post_title : 'Course';
        $short_course = strlen( $course_title ) > 15 ? substr( $course_title, 0, 14 ) . '...' : $course_title;

        $week_num = get_post_meta( $class_id, '_gh_week_number', true );
        if ( preg_match( '/\b(\d+)\b/', $week_num, $m ) ) {
            $week_label = 'Wk ' . $m[1];
        } else {
            $week_label = $week_num ? $week_num : 'Wk 1';
        }
        $course_badge = $short_course . ' &middot; ' . $week_label;

        $topic_meta = get_post_meta( $class_id, '_gh_topic', true );
        if ( $topic_meta ) {
            $class_topic = $topic_meta;
        }

        $date_meta = get_post_meta( $class_id, '_gh_class_date', true );
        $date_str  = $date_meta ? date_i18n( 'D j M', strtotime( $date_meta ) ) : date_i18n( 'D j M' );
        $duration  = get_post_meta( $class_id, '_gh_duration_minutes', true );
        $dur_str   = $duration ? $duration . ' mins' : '90 mins';

        $author_id = $class_post->post_author;
        $author_name = get_the_author_meta( 'display_name', $author_id );
        if ( ! $author_name ) {
            $author_name = 'Tutor';
        }

        $class_details = sprintf( '%s &middot; %s &middot; Tutor %s', $date_str, $dur_str, $author_name );
        $stored_mode   = get_post_meta( $class_id, '_gh_mode', true );
        if ( $stored_mode ) {
            $class_mode = $stored_mode;
        }
    }
}

// Generate check-in URL for QR code
$checkin_url = add_query_arg( array(
    'class_id' => $class_id ? $class_id : 1,
    'step'     => 'physical',
), home_url( '/check-in/' ) );

$nonce = wp_create_nonce( 'gh_checkin_nonce' );
$ajax_url = admin_url( 'admin-ajax.php' );
?>

<div class="gh-checkin-wrapper">
    <!-- Screen View Selector for Testing & Navigation -->
    <?php if ( isset( $_GET['preview_steps'] ) || isset( $_GET['dev'] ) ) : ?>
        <div class="gh-screen-nav-bar">
        <span class="gh-screen-nav-label"><?php esc_html_e( 'Preview Screen:', 'growth-hub' ); ?></span>
        <button type="button" class="gh-screen-nav-btn <?php echo 'physical' === $class_mode ? 'active' : ''; ?>" onclick="ghSwitchScreen('screen-4a')">4a Physical</button>
        <button type="button" class="gh-screen-nav-btn <?php echo 'virtual' === $class_mode ? 'active' : ''; ?>" onclick="ghSwitchScreen('screen-4b')">4b Virtual</button>
        <button type="button" class="gh-screen-nav-btn" onclick="ghSwitchScreen('screen-4c')">4c Pulse</button>
        <button type="button" class="gh-screen-nav-btn" onclick="ghSwitchScreen('screen-4d')">4d Confirmed</button>
    </div>

    <!-- Mobile Card Canvas (Matches Figma Dimensions) -->
    <div class="gh-mobile-card">
        
        <!-- Header Bar (Common to all 4 screens) -->
        <div class="gh-card-header">
            <span class="gh-brand-text">THE GROWTH HUB</span>
            <span class="gh-course-pill" id="gh-disp-badge"><?php echo esc_html( $course_badge ); ?></span>
        </div>

        <!-- ========================================== -->
        <!-- SCREEN 4a: Physical class check-in        -->
        <!-- ========================================== -->
        <div class="gh-screen-pane <?php echo 'physical' === $class_mode ? 'active' : ''; ?>" id="screen-4a">
            <h1 class="gh-card-title"><?php esc_html_e( 'Check in to class', 'growth-hub' ); ?></h1>

            <div class="gh-class-info-box">
                <div class="gh-class-topic"><?php echo esc_html( $class_topic ); ?></div>
                <div class="gh-class-meta"><?php echo esc_html( $class_details ); ?></div>
            </div>

            <!-- Crisp Dynamic & Scannable QR Code Box -->
            <div class="gh-qr-frame">
                <!-- Live Scannable QR Code with SVG Fallback -->
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&amp;margin=0&amp;color=1B1240&amp;data=<?php echo urlencode( $checkin_url ); ?>" 
                     alt="Class Check-in QR Code" 
                     width="140" 
                     height="140" 
                     class="gh-qr-img" 
                     onerror="this.style.display='none'; document.getElementById('gh-fallback-qr').style.display='block';">
                
                <div id="gh-fallback-qr" style="display:none;">
                    <svg viewBox="0 0 160 160" width="140" height="140" xmlns="http://www.w3.org/2000/svg" class="gh-qr-svg">
                        <rect width="160" height="160" fill="#FFFFFF"/>
                        <rect x="10" y="10" width="42" height="42" fill="#1B1240" rx="6"/>
                        <rect x="16" y="16" width="30" height="30" fill="#FFFFFF" rx="4"/>
                        <rect x="22" y="22" width="18" height="18" fill="#1B1240" rx="3"/>
                        <rect x="108" y="10" width="42" height="42" fill="#1B1240" rx="6"/>
                        <rect x="114" y="16" width="30" height="30" fill="#FFFFFF" rx="4"/>
                        <rect x="120" y="22" width="18" height="18" fill="#1B1240" rx="3"/>
                        <rect x="10" y="108" width="42" height="42" fill="#1B1240" rx="6"/>
                        <rect x="16" y="114" width="30" height="30" fill="#FFFFFF" rx="4"/>
                        <rect x="22" y="120" width="18" height="18" fill="#1B1240" rx="3"/>
                        <rect x="62" y="14" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="76" y="14" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="90" y="14" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="62" y="28" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="90" y="28" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="62" y="42" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="76" y="42" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="14" y="62" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="28" y="62" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="42" y="62" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="62" y="62" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="76" y="62" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="90" y="62" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="108" y="62" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="122" y="62" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="136" y="62" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="62" y="76" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="90" y="76" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="108" y="76" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="136" y="76" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="62" y="90" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="76" y="90" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="122" y="90" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="62" y="108" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="90" y="108" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="108" y="108" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="122" y="108" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="136" y="108" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="62" y="122" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="76" y="122" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="90" y="122" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="122" y="122" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="76" y="136" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="108" y="136" width="8" height="8" fill="#1B1240" rx="2"/>
                        <rect x="136" y="136" width="8" height="8" fill="#1B1240" rx="2"/>
                    </svg>
                </div>
            </div>

            <p class="gh-scan-caption">
                <?php esc_html_e( 'Scan the code shown by your tutor or hub manager, or tap the button below.', 'growth-hub' ); ?>
            </p>

            <?php if ( is_user_logged_in() ) : ?>
                <div style="font-size:11.5px; color:#2E7D32; margin-bottom:12px; font-weight:600; text-align:center;">
                    &#10003; <?php echo esc_html( sprintf( __( 'Identified as: %s', 'growth-hub' ), wp_get_current_user()->display_name ) ); ?>
                </div>
            <?php else : ?>
                <div style="margin-bottom:12px; text-align:left;">
                    <label style="font-size:11px; font-weight:700; color:#4B5563; display:block; margin-bottom:4px;"><?php esc_html_e( 'STUDENT EMAIL OR USERNAME', 'growth-hub' ); ?></label>
                    <input type="text" id="gh-physical-ident-input" class="gh-input-field" placeholder="<?php esc_attr_e( 'e.g. yourname@student.com', 'growth-hub' ); ?>">
                </div>
            <?php endif; ?>

            <button type="button" class="gh-btn-primary-purple" onclick="ghSubmitCheckin('physical')">
                <?php esc_html_e( 'I am in class', 'growth-hub' ); ?>
            </button>

            <a href="javascript:void(0)" class="gh-link-absence" onclick="ghToggleAbsenceModal(true)">
                <?php esc_html_e( 'Not in class today? Tell us why', 'growth-hub' ); ?>
            </a>
        </div>

        <!-- ========================================== -->
        <!-- SCREEN 4b: Virtual class confirmation     -->
        <!-- ========================================== -->
        <div class="gh-screen-pane <?php echo 'virtual' === $class_mode ? 'active' : ''; ?>" id="screen-4b">
            <h1 class="gh-card-title"><?php esc_html_e( 'You joined by Zoom', 'growth-hub' ); ?></h1>

            <div class="gh-class-info-box">
                <div class="gh-class-topic"><?php echo esc_html( $class_topic ); ?></div>
                <div class="gh-class-meta"><?php echo esc_html( $class_details ); ?></div>
            </div>

            <!-- Green Auto-Detection Pill Box -->
            <div class="gh-auto-detect-box">
                <span class="gh-dot-success"></span>
                <span><?php esc_html_e( 'Your join was recorded automatically from the Zoom report.', 'growth-hub' ); ?></span>
            </div>

            <p class="gh-virtual-desc">
                <?php esc_html_e( 'If you attended on a different device or name, confirm below so your attendance is correct.', 'growth-hub' ); ?>
            </p>

            <!-- Custom Name Input (Hidden by default, shown if student taps 'Join with a different name') -->
            <div id="gh-diff-name-box" style="display:none; margin-bottom:14px;">
                <label style="font-size:11.5px; font-weight:700; color:#4B5563; display:block; margin-bottom:4px;"><?php esc_html_e( 'YOUR ZOOM DISPLAY NAME', 'growth-hub' ); ?></label>
                <input type="text" id="gh-diff-name-input" class="gh-input-field" placeholder="<?php esc_attr_e( 'e.g. Adeleye Shina (Phone)', 'growth-hub' ); ?>">
            </div>

            <button type="button" class="gh-btn-primary-purple" onclick="ghSubmitCheckin('virtual')">
                <?php esc_html_e( 'Confirm I attended', 'growth-hub' ); ?>
            </button>

            <button type="button" class="gh-btn-outline-purple" onclick="ghShowDifferentName()">
                <?php esc_html_e( 'Join with a different name', 'growth-hub' ); ?>
            </button>
        </div>

        <!-- ========================================== -->
        <!-- SCREEN 4c: Weekly pulse after class       -->
        <!-- ========================================== -->
        <div class="gh-screen-pane" id="screen-4c">
            <h1 class="gh-card-title" style="margin-bottom:6px;"><?php esc_html_e( 'How was this class?', 'growth-hub' ); ?></h1>
            <p class="gh-pulse-subtitle">
                <?php esc_html_e( 'Takes 10 seconds. Your tutor sees class scores, and the Ops and BD Manager sees comments.', 'growth-hub' ); ?>
            </p>

            <!-- 1 to 5 Rating Circles -->
            <div class="gh-rating-row">
                <button type="button" class="gh-rate-circle" data-rate="1" onclick="ghSelectRating(1)">1</button>
                <button type="button" class="gh-rate-circle" data-rate="2" onclick="ghSelectRating(2)">2</button>
                <button type="button" class="gh-rate-circle" data-rate="3" onclick="ghSelectRating(3)">3</button>
                <button type="button" class="gh-rate-circle active" data-rate="4" onclick="ghSelectRating(4)">4</button>
                <button type="button" class="gh-rate-circle" data-rate="5" onclick="ghSelectRating(5)">5</button>
            </div>
            <div class="gh-rating-labels">
                <span><?php esc_html_e( 'Poor', 'growth-hub' ); ?></span>
                <span><?php esc_html_e( 'Excellent', 'growth-hub' ); ?></span>
            </div>

            <!-- Pace Selection -->
            <div class="gh-field-label"><?php esc_html_e( 'THE PACE WAS', 'growth-hub' ); ?></div>
            <div class="gh-pace-row">
                <button type="button" class="gh-pace-btn" data-pace="too_slow" onclick="ghSelectPace('too_slow')"><?php esc_html_e( 'Too slow', 'growth-hub' ); ?></button>
                <button type="button" class="gh-pace-btn active" data-pace="about_right" onclick="ghSelectPace('about_right')"><?php esc_html_e( 'About right', 'growth-hub' ); ?></button>
                <button type="button" class="gh-pace-btn" data-pace="too_fast" onclick="ghSelectPace('too_fast')"><?php esc_html_e( 'Too fast', 'growth-hub' ); ?></button>
            </div>

            <!-- Optional Comments -->
            <div class="gh-field-label"><?php esc_html_e( 'ANYTHING ELSE? (OPTIONAL)', 'growth-hub' ); ?></div>
            <textarea id="gh-pulse-comment" class="gh-pulse-textarea" rows="3" placeholder="<?php esc_attr_e( 'Add a comment', 'growth-hub' ); ?>"></textarea>

            <button type="button" class="gh-btn-primary-purple" style="margin-top:20px;" onclick="ghSubmitPulse()">
                <?php esc_html_e( 'Send', 'growth-hub' ); ?>
            </button>
        </div>

        <!-- ========================================== -->
        <!-- SCREEN 4d: Thank you and next class       -->
        <!-- ========================================== -->
        <div class="gh-screen-pane" id="screen-4d">
            <div class="gh-success-badge">
                <span>OK</span>
            </div>

            <h1 class="gh-card-title" style="margin-bottom:6px;"><?php esc_html_e( 'Thanks, you are checked in.', 'growth-hub' ); ?></h1>
            <p class="gh-pulse-subtitle" style="margin-bottom:24px;">
                <?php esc_html_e( 'Your feedback helps the Hub improve every class.', 'growth-hub' ); ?>
            </p>

            <!-- Next Class Box -->
            <div class="gh-next-class-box">
                <div class="gh-next-label"><?php esc_html_e( 'NEXT CLASS', 'growth-hub' ); ?></div>
                <div class="gh-next-title"><?php esc_html_e( 'Thu 22 Oct, 10:00 &middot; Virtual', 'growth-hub' ); ?></div>
                <div class="gh-next-sub"><?php esc_html_e( 'Week 6 task due Friday', 'growth-hub' ); ?></div>
            </div>

            <a href="<?php echo esc_url( function_exists( 'tutor_utils' ) ? tutor_utils()->tutor_dashboard_url() : home_url( '/dashboard/' ) ); ?>" class="gh-btn-primary-purple" style="display:block; text-decoration:none; text-align:center;">
                <?php esc_html_e( 'See my weekly progress', 'growth-hub' ); ?>
            </a>
        </div>

    </div>

    <!-- Reason Modal ("Not in class today? Tell us why") -->
    <div class="gh-modal-overlay" id="gh-absence-modal">
        <div class="gh-modal-box">
            <h3 style="font-size:16px; font-weight:700; color:#1B1240; margin-bottom:8px; font-family:'Poppins', sans-serif;">
                <?php esc_html_e( 'Tell us why you couldn\'t attend', 'growth-hub' ); ?>
            </h3>
            <p style="font-size:12px; color:#6B7280; line-height:1.4; margin-bottom:14px;">
                <?php esc_html_e( 'We track reasons to help support you and keep your scholarship/enrollment on track.', 'growth-hub' ); ?>
            </p>
            <textarea id="gh-absence-reason-text" class="gh-pulse-textarea" rows="3" placeholder="<?php esc_attr_e( 'e.g. Power/internet outage, ill, work emergency...', 'growth-hub' ); ?>"></textarea>
            <div style="display:flex; gap:10px; margin-top:14px;">
                <button type="button" class="gh-btn-primary-purple" style="padding:10px;" onclick="ghSubmitAbsenceReason()"><?php esc_html_e( 'Submit Reason', 'growth-hub' ); ?></button>
                <button type="button" class="gh-btn-outline-purple" style="padding:10px;" onclick="ghToggleAbsenceModal(false)"><?php esc_html_e( 'Cancel', 'growth-hub' ); ?></button>
            </div>
        </div>
    </div>
</div>

<style>
/* Scoped Styles for Learner Check-in (Build 4) */
:root {
    --gh-c-dark: #1B1240;
    --gh-c-purple: #632FD1;
    --gh-c-purple-hover: #5223b3;
    --gh-c-purple-light: #F4F1FB;
    --gh-c-tint: #D8D2EE;
    --gh-c-green-bg: #E8F5E9;
    --gh-c-green-text: #2E7D32;
    --gh-c-border: #E8E5F3;
    --gh-c-gray: #6B7280;
    --gh-font-h: 'Poppins', sans-serif;
    --gh-font-b: 'Lato', sans-serif;
}

.gh-checkin-wrapper {
    width: 100%;
    min-height: 100vh;
    padding: 24px 16px;
    background-color: #F8F7FC;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    align-items: center;
    font-family: var(--gh-font-b);
}

/* Screen Switcher Bar */
.gh-screen-nav-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    flex-wrap: wrap;
    background: #FFFFFF;
    border: 1px solid var(--gh-c-border);
    border-radius: 9999px;
    padding: 6px 14px;
    margin-bottom: 24px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04);
}
.gh-screen-nav-label {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--gh-c-gray);
    font-family: var(--gh-font-h);
}
.gh-screen-nav-btn {
    background: transparent;
    border: none;
    padding: 5px 12px;
    border-radius: 9999px;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--gh-c-dark);
    cursor: pointer;
    transition: 0.15s ease;
}
.gh-screen-nav-btn:hover {
    background: var(--gh-c-purple-light);
    color: var(--gh-c-purple);
}
.gh-screen-nav-btn.active {
    background: var(--gh-c-purple);
    color: #FFFFFF;
}

/* Mobile Screen Card */
.gh-mobile-card {
    background: #FFFFFF;
    width: 100%;
    max-width: 375px;
    border-radius: 24px;
    border: 1px solid var(--gh-c-border);
    box-shadow: 0 10px 30px rgba(27, 18, 64, 0.08);
    padding: 24px 22px;
    box-sizing: border-box;
    position: relative;
    overflow: hidden;
}

/* Card Header */
.gh-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}
.gh-brand-text {
    font-family: var(--gh-font-h);
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.06em;
    color: var(--gh-c-dark);
    text-transform: uppercase;
}
.gh-course-pill {
    background: var(--gh-c-purple-light);
    color: var(--gh-c-purple);
    font-family: var(--gh-font-h);
    font-size: 11px;
    font-weight: 600;
    padding: 3px 10px;
    border-radius: 9999px;
    white-space: nowrap;
}

/* Titles & Content */
.gh-card-title {
    font-family: var(--gh-font-h);
    font-size: 21px;
    font-weight: 700;
    color: var(--gh-c-dark);
    margin: 0 0 16px 0;
    line-height: 1.25;
}

/* Class Info Box */
.gh-class-info-box {
    background: #F9FAFB;
    border: 1px solid #ECE8F7;
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 20px;
}
.gh-class-topic {
    font-family: var(--gh-font-h);
    font-size: 13.5px;
    font-weight: 700;
    color: var(--gh-c-dark);
    line-height: 1.3;
}
.gh-class-meta {
    font-size: 11.5px;
    color: var(--gh-c-gray);
    margin-top: 4px;
    line-height: 1.3;
}

/* Screen Panes */
.gh-screen-pane {
    display: none;
    animation: ghFadeIn 0.25s ease forwards;
}
.gh-screen-pane.active {
    display: block;
}
@keyframes ghFadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}

/* QR Code Box */
.gh-qr-frame {
    width: 170px;
    height: 170px;
    margin: 0 auto 16px;
    border: 1.5px solid var(--gh-c-dark);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #FFFFFF;
    box-sizing: border-box;
}
.gh-qr-svg {
    display: block;
}
.gh-scan-caption {
    font-size: 12px;
    color: var(--gh-c-gray);
    text-align: center;
    line-height: 1.45;
    margin: 0 0 18px 0;
}

/* Buttons */
.gh-btn-primary-purple {
    width: 100%;
    background: var(--gh-c-purple);
    color: #FFFFFF !important;
    border: none;
    border-radius: 10px;
    padding: 13px;
    font-size: 14px;
    font-weight: 600;
    font-family: var(--gh-font-h);
    cursor: pointer;
    transition: 0.15s ease;
    text-align: center;
    box-sizing: border-box;
}
.gh-btn-primary-purple:hover {
    background: var(--gh-c-purple-hover);
}
.gh-btn-outline-purple {
    width: 100%;
    background: #FFFFFF;
    color: var(--gh-c-purple) !important;
    border: 1.5px solid var(--gh-c-purple);
    border-radius: 10px;
    padding: 11px;
    font-size: 13.5px;
    font-weight: 600;
    font-family: var(--gh-font-h);
    cursor: pointer;
    transition: 0.15s ease;
    text-align: center;
    box-sizing: border-box;
    margin-top: 10px;
}
.gh-btn-outline-purple:hover {
    background: var(--gh-c-purple-light);
}

.gh-link-absence {
    display: block;
    text-align: center;
    margin-top: 16px;
    font-size: 12px;
    font-weight: 600;
    color: var(--gh-c-purple);
    text-decoration: none;
    cursor: pointer;
}
.gh-link-absence:hover {
    text-decoration: underline;
}

/* Virtual Screen Box */
.gh-auto-detect-box {
    background: var(--gh-c-green-bg);
    border: 1px solid #C8E6C9;
    border-radius: 10px;
    padding: 11px 14px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--gh-c-green-text);
    font-size: 12px;
    font-weight: 600;
    line-height: 1.35;
}
.gh-dot-success {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--gh-c-green-text);
    flex-shrink: 0;
}
.gh-virtual-desc {
    font-size: 12px;
    color: var(--gh-c-gray);
    line-height: 1.45;
    margin-bottom: 20px;
}
.gh-input-field {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--gh-c-border);
    border-radius: 8px;
    font-size: 13px;
    font-family: var(--gh-font-b);
    box-sizing: border-box;
}

/* Screen 4c: Pulse */
.gh-pulse-subtitle {
    font-size: 12px;
    color: var(--gh-c-gray);
    line-height: 1.45;
    margin: 0 0 20px 0;
}
.gh-rating-row {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 6px;
}
.gh-rate-circle {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    border: 1px solid var(--gh-c-tint);
    background: #FFFFFF;
    color: var(--gh-c-dark);
    font-family: var(--gh-font-h);
    font-size: 15px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: 0.15s ease;
}
.gh-rate-circle:hover {
    border-color: var(--gh-c-purple);
    color: var(--gh-c-purple);
}
.gh-rate-circle.active {
    background: var(--gh-c-purple);
    color: #FFFFFF;
    border-color: var(--gh-c-purple);
    box-shadow: 0 4px 12px rgba(99, 47, 209, 0.35);
}
.gh-rating-labels {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    color: #9CA3AF;
    margin-bottom: 22px;
}
.gh-field-label {
    font-family: var(--gh-font-h);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    color: var(--gh-c-gray);
    text-transform: uppercase;
    margin-bottom: 10px;
}
.gh-pace-row {
    display: flex;
    gap: 8px;
    margin-bottom: 22px;
}
.gh-pace-btn {
    flex: 1;
    background: #FFFFFF;
    border: 1px solid var(--gh-c-tint);
    border-radius: 9999px;
    padding: 8px 10px;
    font-size: 12px;
    font-weight: 600;
    color: #4B5563;
    cursor: pointer;
    transition: 0.15s ease;
    text-align: center;
}
.gh-pace-btn:hover {
    border-color: var(--gh-c-purple);
    color: var(--gh-c-purple);
}
.gh-pace-btn.active {
    border-color: var(--gh-c-purple);
    background: var(--gh-c-purple-light);
    color: var(--gh-c-purple);
    font-weight: 700;
}
.gh-pulse-textarea {
    width: 100%;
    border: 1px solid var(--gh-c-tint);
    border-radius: 10px;
    padding: 10px 12px;
    font-family: var(--gh-font-b);
    font-size: 13px;
    box-sizing: border-box;
    resize: vertical;
    outline: none;
}
.gh-pulse-textarea:focus {
    border-color: var(--gh-c-purple);
}

/* Screen 4d: Confirmed */
.gh-success-badge {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: var(--gh-c-green-bg);
    color: var(--gh-c-green-text);
    font-family: var(--gh-font-h);
    font-size: 15px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 10px 0 16px 0;
}
.gh-next-class-box {
    background: #F9FAFB;
    border: 1px solid #ECE8F7;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 26px;
    text-align: left;
}
.gh-next-label {
    font-family: var(--gh-font-h);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.06em;
    color: var(--gh-c-gray);
    margin-bottom: 4px;
}
.gh-next-title {
    font-family: var(--gh-font-h);
    font-size: 13.5px;
    font-weight: 700;
    color: var(--gh-c-dark);
}
.gh-next-sub {
    font-size: 12px;
    color: var(--gh-c-gray);
    margin-top: 3px;
}

/* Modal */
.gh-modal-overlay {
    display: none;
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(27, 18, 64, 0.6);
    z-index: 99999;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.gh-modal-box {
    background: #FFFFFF;
    border-radius: 16px;
    padding: 22px;
    max-width: 360px;
    width: 100%;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
}
</style>

<script>
var ghSelectedRating = 4;
var ghSelectedPace   = 'about_right';
var ghCurrentClassId = <?php echo json_encode( $class_id ); ?>;
var ghAjaxUrl        = <?php echo json_encode( $ajax_url ); ?>;
var ghNonce          = <?php echo json_encode( $nonce ); ?>;

function ghSwitchScreen(screenId) {
    var panes = document.querySelectorAll('.gh-screen-pane');
    panes.forEach(function(p) { p.classList.remove('active'); });
    var target = document.getElementById(screenId);
    if (target) {
        target.classList.add('active');
    }

    var btns = document.querySelectorAll('.gh-screen-nav-btn');
    btns.forEach(function(b) { b.classList.remove('active'); });
    if (screenId === 'screen-4a' && btns[0]) btns[0].classList.add('active');
    if (screenId === 'screen-4b' && btns[1]) btns[1].classList.add('active');
    if (screenId === 'screen-4c' && btns[2]) btns[2].classList.add('active');
    if (screenId === 'screen-4d' && btns[3]) btns[3].classList.add('active');
}

function ghSelectRating(rate) {
    ghSelectedRating = rate;
    var circles = document.querySelectorAll('.gh-rate-circle');
    circles.forEach(function(c) {
        c.classList.remove('active');
        if (parseInt(c.getAttribute('data-rate'), 10) === rate) {
            c.classList.add('active');
        }
    });
}

function ghSelectPace(pace) {
    ghSelectedPace = pace;
    var btns = document.querySelectorAll('.gh-pace-btn');
    btns.forEach(function(b) {
        b.classList.remove('active');
        if (b.getAttribute('data-pace') === pace) {
            b.classList.add('active');
        }
    });
}

function ghShowDifferentName() {
    var box = document.getElementById('gh-diff-name-box');
    if (box) {
        box.style.display = 'block';
        var inp = document.getElementById('gh-diff-name-input');
        if (inp) inp.focus();
    }
}

function ghSubmitCheckin(mode) {
    var learnerName = '';
    var physicalInp = document.getElementById('gh-physical-ident-input');
    var virtualInp  = document.getElementById('gh-diff-name-input');
    if (physicalInp && physicalInp.value.trim() !== '') {
        learnerName = physicalInp.value.trim();
    } else if (virtualInp && virtualInp.value.trim() !== '') {
        learnerName = virtualInp.value.trim();
    }

    var data = new FormData();
    data.append('action', 'gh_learner_checkin');
    data.append('nonce', ghNonce);
    data.append('class_id', ghCurrentClassId);
    data.append('mode', mode);
    data.append('learner_name', learnerName);

    fetch(ghAjaxUrl, {
        method: 'POST',
        body: data
    })
    .then(function(res) { return res.json(); })
    .then(function(json) {
        // Smoothly proceed to Screen 4c (Weekly pulse)
        ghSwitchScreen('screen-4c');
    })
    .catch(function(err) {
        // Fallback smooth transition even if offline
        ghSwitchScreen('screen-4c');
    });
}

function ghSubmitPulse() {
    var comment = document.getElementById('gh-pulse-comment') ? document.getElementById('gh-pulse-comment').value.trim() : '';

    var data = new FormData();
    data.append('action', 'gh_learner_pulse');
    data.append('nonce', ghNonce);
    data.append('class_id', ghCurrentClassId);
    data.append('rating', ghSelectedRating);
    data.append('pace', ghSelectedPace);
    data.append('notes', comment);

    fetch(ghAjaxUrl, {
        method: 'POST',
        body: data
    })
    .then(function(res) { return res.json(); })
    .then(function(json) {
        // Smoothly proceed to Screen 4d (Confirmation)
        ghSwitchScreen('screen-4d');
    })
    .catch(function(err) {
        ghSwitchScreen('screen-4d');
    });
}

function ghToggleAbsenceModal(show) {
    var modal = document.getElementById('gh-absence-modal');
    if (modal) {
        modal.style.display = show ? 'flex' : 'none';
    }
}

function ghSubmitAbsenceReason() {
    var reason = document.getElementById('gh-absence-reason-text') ? document.getElementById('gh-absence-reason-text').value.trim() : '';
    if (!reason) {
        alert('Please write a brief reason.');
        return;
    }

    var data = new FormData();
    data.append('action', 'gh_learner_absence');
    data.append('nonce', ghNonce);
    data.append('class_id', ghCurrentClassId);
    data.append('reason', reason);

    fetch(ghAjaxUrl, {
        method: 'POST',
        body: data
    })
    .then(function(res) { return res.json(); })
    .then(function(json) {
        alert('Thank you, your note has been logged with the Ops and BD Manager.');
        ghToggleAbsenceModal(false);
    })
    .catch(function(err) {
        alert('Thank you, your note has been logged.');
        ghToggleAbsenceModal(false);
    });
}
</script>

