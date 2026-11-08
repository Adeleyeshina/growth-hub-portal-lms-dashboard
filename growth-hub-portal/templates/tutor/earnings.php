<?php
/**
 * Tutor / Admin Earnings & Revenue Template
 *
 * Role Scoping:
 * - Administrators: Comprehensive overview across all instructors, courses, and platform commission.
 * - Tutors / Instructors: Strictly scoped to earnings for their assigned courses.
 *
 * Author: Adeleye Shina
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

$user_id   = get_current_user_id();
$user      = wp_get_current_user();
$is_admin  = current_user_can( 'administrator' );
$is_tutor  = current_user_can( 'tutor_instructor' ) || $is_admin;

if ( ! $user_id || ! $is_tutor ) {
    ?>
    <div class="gh-section-card" style="padding: 40px; text-align: center;">
        <h3 style="color: #1B1240; font-family: 'Poppins', sans-serif;"><?php esc_html_e( 'Access Denied', 'growth-hub' ); ?></h3>
        <p style="color: #6B7280; font-family: 'Lato', sans-serif;"><?php esc_html_e( 'You must be logged in as an instructor or administrator to view earnings.', 'growth-hub' ); ?></p>
    </div>
    <?php
    return;
}

$dash_url = function_exists( 'gh_portal_dashboard_url' ) ? gh_portal_dashboard_url() : home_url( '/dashboard/' );

$preview_tutor_id = isset( $_GET['preview_tutor'] ) ? absint( $_GET['preview_tutor'] ) : 0;
$target_user_id   = ( $is_admin && $preview_tutor_id ) ? $preview_tutor_id : ( $is_admin ? 0 : $user_id );

$currency_symbol = '$';
if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_currency_symbol' ) ) {
    $currency_symbol = tutor_utils()->get_currency_symbol();
} elseif ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
    $currency_symbol = get_woocommerce_currency_symbol();
}
$currency_symbol = html_entity_decode( $currency_symbol, ENT_QUOTES, 'UTF-8' );

$all_tutors = array();
if ( $is_admin ) {
    $tutor_users = get_users( array(
        'role__in' => array( 'tutor_instructor', 'administrator' ),
        'orderby'  => 'display_name',
        'order'    => 'ASC',
    ) );
    foreach ( $tutor_users as $tu ) {
        $all_tutors[] = array(
            'id'   => $tu->ID,
            'name' => $tu->display_name ? $tu->display_name : $tu->user_login,
        );
    }
}

$courses = array();
if ( $is_admin && ! $target_user_id ) {
    $courses = get_posts( array(
        'post_type'      => 'courses',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ) );
} elseif ( $user_id ) {
    $uid = $target_user_id ? $target_user_id : $user_id;
    $merged_courses = array();
    if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_courses_by_instructor' ) ) {
        $nat_courses = tutor_utils()->get_courses_by_instructor( $uid, array( 'publish' ) );
        if ( ! empty( $nat_courses ) && is_array( $nat_courses ) ) {
            foreach ( $nat_courses as $nc ) {
                $nc_obj = is_object( $nc ) ? $nc : get_post( $nc );
                if ( $nc_obj ) $merged_courses[ $nc_obj->ID ] = $nc_obj;
            }
        }
    }
    $authored = get_posts( array(
        'post_type'      => 'courses',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'author'         => $uid,
    ) );
    if ( ! empty( $authored ) ) {
        foreach ( $authored as $c ) $merged_courses[ $c->ID ] = $c;
    }
    $courses = array_values( $merged_courses );
}

$course_map = array();
foreach ( $courses as $c ) {
    $course_map[ $c->ID ] = $c->post_title;
}

$earnings_table  = $wpdb->prefix . 'tutor_earnings';
$withdraws_table = $wpdb->prefix . 'tutor_withdraws';

$has_earnings_table  = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $earnings_table ) ) === $earnings_table;
$has_withdraws_table = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $withdraws_table ) ) === $withdraws_table;

$total_sales        = 0.00;
$total_instructor   = 0.00;
$total_admin        = 0.00;
$total_withdrawn    = 0.00;
$current_balance    = 0.00;
$total_transactions = 0;
$transactions       = array();

if ( $has_earnings_table ) {
    if ( $is_admin && ! $target_user_id ) {
        $earnings_rows = $wpdb->get_results( "
            SELECT e.*, p.post_title as course_title, u.display_name as tutor_name, u.user_email as tutor_email
            FROM {$earnings_table} e
            LEFT JOIN {$wpdb->posts} p ON e.course_id = p.ID
            LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
            ORDER BY e.created_at DESC
            LIMIT 300
        " );

        $totals = $wpdb->get_row( "
            SELECT 
                SUM(course_price_total) as sum_sales,
                SUM(instructor_amount) as sum_instructor,
                SUM(admin_amount) as sum_admin,
                COUNT(earning_id) as count_tx
            FROM {$earnings_table}
        " );

        if ( $totals ) {
            $total_sales        = floatval( $totals->sum_sales );
            $total_instructor   = floatval( $totals->sum_instructor );
            $total_admin        = floatval( $totals->sum_admin );
            $total_transactions = intval( $totals->count_tx );
        }

        if ( $has_withdraws_table ) {
            $total_withdrawn = floatval( $wpdb->get_var( "
                SELECT SUM(amount) FROM {$withdraws_table} WHERE status = 'approved'
            " ) );
        }
    } else {
        $scoped_uid = $target_user_id ? $target_user_id : $user_id;

        $earnings_rows = $wpdb->get_results( $wpdb->prepare( "
            SELECT e.*, p.post_title as course_title, u.display_name as tutor_name, u.user_email as tutor_email
            FROM {$earnings_table} e
            LEFT JOIN {$wpdb->posts} p ON e.course_id = p.ID
            LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
            WHERE e.user_id = %d
            ORDER BY e.created_at DESC
            LIMIT 300
        ", $scoped_uid ) );

        $totals = $wpdb->get_row( $wpdb->prepare( "
            SELECT 
                SUM(course_price_total) as sum_sales,
                SUM(instructor_amount) as sum_instructor,
                SUM(admin_amount) as sum_admin,
                COUNT(earning_id) as count_tx
            FROM {$earnings_table}
            WHERE user_id = %d
        ", $scoped_uid ) );

        if ( $totals ) {
            $total_sales        = floatval( $totals->sum_sales );
            $total_instructor   = floatval( $totals->sum_instructor );
            $total_admin        = floatval( $totals->sum_admin );
            $total_transactions = intval( $totals->count_tx );
        }

        if ( $has_withdraws_table ) {
            $total_withdrawn = floatval( $wpdb->get_var( $wpdb->prepare( "
                SELECT SUM(amount) FROM {$withdraws_table} 
                WHERE user_id = %d AND status = 'approved'
            ", $scoped_uid ) ) );
        }

        $current_balance = max( 0, $total_instructor - $total_withdrawn );
    }

    if ( ! empty( $earnings_rows ) ) {
        foreach ( $earnings_rows as $row ) {
            $order_id = ! empty( $row->order_id ) ? $row->order_id : $row->earning_id;
            $course_title = ! empty( $row->course_title ) ? $row->course_title : ( isset( $course_map[ $row->course_id ] ) ? $course_map[ $row->course_id ] : __( 'Course #' . $row->course_id, 'growth-hub' ) );
            $tutor_display = ! empty( $row->tutor_name ) ? $row->tutor_name : __( 'Mentor #' . $row->user_id, 'growth-hub' );
            
            $transactions[] = array(
                'id'                => $row->earning_id,
                'order_id'          => $order_id,
                'course_id'         => $row->course_id,
                'course_title'      => $course_title,
                'user_id'           => $row->user_id,
                'tutor_name'        => $tutor_display,
                'total_amount'      => floatval( $row->course_price_total ),
                'instructor_amount' => floatval( $row->instructor_amount ),
                'admin_amount'      => floatval( $row->admin_amount ),
                'date'              => ! empty( $row->created_at ) ? $row->created_at : current_time( 'mysql' ),
                'status'            => ! empty( $row->order_status ) ? $row->order_status : ( ! empty( $row->process_status ) ? $row->process_status : 'completed' ),
            );
        }
    }
}
?>

<div class="gh-earnings-container">
    <div class="gh-page-header">
        <div>
            <h1 class="gh-page-title"><?php esc_html_e( 'Earnings & Revenue', 'growth-hub' ); ?></h1>
            <p class="gh-page-subtitle">
                <?php if ( $is_admin && ! $target_user_id ) : ?>
                    <?php esc_html_e( 'Comprehensive platform view: Monitor total course sales, tutor payouts, and admin commissions.', 'growth-hub' ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'Track course sales, instructor earnings, and available payout balances.', 'growth-hub' ); ?>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <!-- 4 Key Metric Cards -->
    <div class="gh-stats-grid">
        <div class="gh-stat-card">
            <div class="gh-stat-icon-wrap" style="background: rgba(99, 47, 209, 0.1); color: #632FD1;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="1" x2="12" y2="23"></line>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                </svg>
            </div>
            <div class="gh-stat-info">
                <span class="gh-stat-label">
                    <?php echo $is_admin && ! $target_user_id ? esc_html__( 'Total Platform Sales', 'growth-hub' ) : esc_html__( 'Total Course Sales', 'growth-hub' ); ?>
                </span>
                <span class="gh-stat-value"><?php echo esc_html( $currency_symbol . number_format( $total_sales, 2 ) ); ?></span>
            </div>
        </div>

        <div class="gh-stat-card">
            <div class="gh-stat-icon-wrap" style="background: rgba(16, 185, 129, 0.12); color: #10B981;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                    <line x1="2" y1="10" x2="22" y2="10"></line>
                </svg>
            </div>
            <div class="gh-stat-info">
                <span class="gh-stat-label">
                    <?php echo $is_admin && ! $target_user_id ? esc_html__( 'Instructor Payouts', 'growth-hub' ) : esc_html__( 'Your Earnings', 'growth-hub' ); ?>
                </span>
                <span class="gh-stat-value" style="color: #10B981;"><?php echo esc_html( $currency_symbol . number_format( $total_instructor, 2 ) ); ?></span>
            </div>
        </div>

        <div class="gh-stat-card">
            <div class="gh-stat-icon-wrap" style="background: rgba(79, 70, 229, 0.1); color: #4F46E5;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <path d="M16 10a4 4 0 0 1-8 0"></path>
                </svg>
            </div>
            <div class="gh-stat-info">
                <span class="gh-stat-label">
                    <?php echo $is_admin && ! $target_user_id ? esc_html__( 'Platform Commission', 'growth-hub' ) : esc_html__( 'Total Withdrawn', 'growth-hub' ); ?>
                </span>
                <span class="gh-stat-value" style="color: #4F46E5;">
                    <?php 
                    if ( $is_admin && ! $target_user_id ) {
                        echo esc_html( $currency_symbol . number_format( $total_admin, 2 ) );
                    } else {
                        echo esc_html( $currency_symbol . number_format( $total_withdrawn, 2 ) );
                    }
                    ?>
                </span>
            </div>
        </div>

        <div class="gh-stat-card">
            <div class="gh-stat-icon-wrap" style="background: rgba(245, 158, 11, 0.12); color: #D97706;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
            </div>
            <div class="gh-stat-info">
                <span class="gh-stat-label">
                    <?php echo $is_admin && ! $target_user_id ? esc_html__( 'Transactions', 'growth-hub' ) : esc_html__( 'Available Balance', 'growth-hub' ); ?>
                </span>
                <span class="gh-stat-value" style="color: #D97706;">
                    <?php 
                    if ( $is_admin && ! $target_user_id ) {
                        echo esc_html( $total_transactions );
                    } else {
                        echo esc_html( $currency_symbol . number_format( $current_balance, 2 ) );
                    }
                    ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Toolbar Filters -->
    <div class="gh-earnings-toolbar">
        <div class="gh-search-input-box">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6B7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input type="text" id="ghEarningsSearch" placeholder="<?php esc_attr_e( 'Search order ID or course...', 'growth-hub' ); ?>" onkeyup="ghFilterEarningsList()" />
        </div>

        <div class="gh-earnings-filters-right">
            <?php if ( ! empty( $courses ) ) : ?>
                <div class="gh-select-wrap">
                    <select id="ghEarningsCourseFilter" class="gh-custom-select" onchange="ghFilterEarningsList()">
                        <option value="all"><?php esc_html_e( 'All Courses', 'growth-hub' ); ?></option>
                        <?php foreach ( $courses as $c ) : ?>
                            <option value="<?php echo esc_attr( $c->ID ); ?>"><?php echo esc_html( $c->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ( $is_admin && ! empty( $all_tutors ) ) : ?>
                <div class="gh-select-wrap">
                    <select id="ghEarningsTutorFilter" class="gh-custom-select" onchange="ghFilterEarningsList()">
                        <option value="all"><?php esc_html_e( 'All Instructors', 'growth-hub' ); ?></option>
                        <?php foreach ( $all_tutors as $t ) : ?>
                            <option value="<?php echo esc_attr( $t['id'] ); ?>" <?php selected( $target_user_id, $t['id'] ); ?>>
                                <?php echo esc_html( $t['name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="gh-select-wrap">
                <select id="ghEarningsStatusFilter" class="gh-custom-select" onchange="ghFilterEarningsList()">
                    <option value="all"><?php esc_html_e( 'All Statuses', 'growth-hub' ); ?></option>
                    <option value="completed"><?php esc_html_e( 'Completed', 'growth-hub' ); ?></option>
                    <option value="processing"><?php esc_html_e( 'Processing', 'growth-hub' ); ?></option>
                    <option value="pending"><?php esc_html_e( 'Pending', 'growth-hub' ); ?></option>
                </select>
            </div>
        </div>
    </div>

    <!-- Earnings Records Table -->
    <div class="gh-section-card" style="padding: 0; overflow: hidden; border-radius: 16px;">
        <div class="gh-table-wrap" style="margin: 0;">
            <table class="gh-table" id="ghEarningsTable" style="min-width: 860px !important;">
                <thead>
                    <tr>
                        <th style="padding-left: 24px;"><?php esc_html_e( 'ORDER / TXN', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'COURSE / COHORT', 'growth-hub' ); ?></th>
                        <?php if ( $is_admin && ! $target_user_id ) : ?>
                            <th><?php esc_html_e( 'INSTRUCTOR', 'growth-hub' ); ?></th>
                        <?php endif; ?>
                        <th><?php esc_html_e( 'GROSS SALE', 'growth-hub' ); ?></th>
                        <th><?php esc_html_e( 'INSTRUCTOR SHARE', 'growth-hub' ); ?></th>
                        <?php if ( $is_admin && ! $target_user_id ) : ?>
                            <th><?php esc_html_e( 'COMMISSION', 'growth-hub' ); ?></th>
                        <?php endif; ?>
                        <th><?php esc_html_e( 'DATE', 'growth-hub' ); ?></th>
                        <th style="text-align: right; padding-right: 24px;"><?php esc_html_e( 'STATUS', 'growth-hub' ); ?></th>
                    </tr>
                </thead>
                <tbody id="ghEarningsTableBody">
                    <?php if ( empty( $transactions ) ) : ?>
                        <tr id="ghEarningsEmptyRow">
                            <td colspan="<?php echo ( $is_admin && ! $target_user_id ) ? '8' : '6'; ?>" style="text-align: center; padding: 60px 20px;">
                                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
                                    <div style="width: 56px; height: 56px; border-radius: 16px; background: rgba(99, 47, 209, 0.08); display: flex; align-items: center; justify-content: center; margin-bottom: 16px;">
                                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#632FD1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <line x1="12" y1="1" x2="12" y2="23"></line>
                                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                                        </svg>
                                    </div>
                                    <h4 style="margin: 0 0 6px; color: #1B1240; font-family: 'Poppins', sans-serif; font-size: 17px;"><?php esc_html_e( 'No Earnings Records Yet', 'growth-hub' ); ?></h4>
                                    <p style="margin: 0; color: #6B7280; font-size: 14px; max-width: 440px; line-height: 1.5;">
                                        <?php esc_html_e( 'Purchases and enrollments across your courses will automatically appear here with automated commission tracking.', 'growth-hub' ); ?>
                                    </p>
                                </div>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $transactions as $tx ) : 
                            $status_clean = strtolower( $tx['status'] );
                            $is_comp      = in_array( $status_clean, array( 'completed', 'approved', 'paid' ), true );
                            $badge_bg     = $is_comp ? '#E8F8F0' : '#FEF3C7';
                            $badge_color  = $is_comp ? '#10B981' : '#D97706';
                            $badge_label  = ucfirst( $status_clean );
                            $formatted_date = date_i18n( get_option( 'date_format' ), strtotime( $tx['date'] ) );
                            ?>
                            <tr class="gh-earnings-row" 
                                data-course-id="<?php echo esc_attr( $tx['course_id'] ); ?>"
                                data-user-id="<?php echo esc_attr( $tx['user_id'] ); ?>"
                                data-status="<?php echo esc_attr( $status_clean ); ?>"
                                data-search="<?php echo esc_attr( strtolower( $tx['order_id'] . ' ' . $tx['course_title'] . ' ' . $tx['tutor_name'] ) ); ?>">
                                
                                <td style="padding-left: 24px;">
                                    <strong style="color: #1B1240; font-family: 'Poppins', sans-serif; font-size: 13.5px;">
                                        #<?php echo esc_html( $tx['order_id'] ); ?>
                                    </strong>
                                </td>
                                
                                <td>
                                    <span style="font-weight: 600; color: #1B1240; font-size: 14px; display: block; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <?php echo esc_html( $tx['course_title'] ); ?>
                                    </span>
                                </td>

                                <?php if ( $is_admin && ! $target_user_id ) : ?>
                                    <td>
                                        <span style="color: #4A3E72; font-size: 13.5px; font-weight: 500;">
                                            <?php echo esc_html( $tx['tutor_name'] ); ?>
                                        </span>
                                    </td>
                                <?php endif; ?>

                                <td>
                                    <strong style="color: #1B1240; font-size: 14px;">
                                        <?php echo esc_html( $currency_symbol . number_format( $tx['total_amount'], 2 ) ); ?>
                                    </strong>
                                </td>

                                <td>
                                    <span style="color: #10B981; font-weight: 700; font-size: 14px;">
                                        <?php echo esc_html( $currency_symbol . number_format( $tx['instructor_amount'], 2 ) ); ?>
                                    </span>
                                </td>

                                <?php if ( $is_admin && ! $target_user_id ) : ?>
                                    <td>
                                        <span style="color: #632FD1; font-weight: 700; font-size: 14px;">
                                            <?php echo esc_html( $currency_symbol . number_format( $tx['admin_amount'], 2 ) ); ?>
                                        </span>
                                    </td>
                                <?php endif; ?>

                                <td>
                                    <span style="color: #6B7280; font-size: 13px;">
                                        <?php echo esc_html( $formatted_date ); ?>
                                    </span>
                                </td>

                                <td style="text-align: right; padding-right: 24px;">
                                    <span style="display: inline-block; padding: 4px 10px; border-radius: 9999px; font-size: 12px; font-weight: 600; background: <?php echo esc_attr( $badge_bg ); ?>; color: <?php echo esc_attr( $badge_color ); ?>;">
                                        <?php echo esc_html( $badge_label ); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <tr id="ghEarningsNoFilterMatch" style="display: none;">
                            <td colspan="<?php echo ( $is_admin && ! $target_user_id ) ? '8' : '6'; ?>" style="text-align: center; padding: 40px 20px;">
                                <p style="color: #6B7280; font-size: 14px; margin: 0;">
                                    <?php esc_html_e( 'No transactions match the selected filters.', 'growth-hub' ); ?>
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.gh-earnings-container {
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

.gh-page-header {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.gh-page-title {
    font-family: 'Poppins', sans-serif !important;
    font-size: 26px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
    margin: 0 0 6px 0 !important;
    line-height: 1.2 !important;
}

.gh-page-subtitle {
    color: #6B7280 !important;
    font-size: 14px !important;
    margin: 0 !important;
    line-height: 1.5 !important;
}

.gh-stats-grid {
    display: grid !important;
    grid-template-columns: repeat(4, 1fr) !important;
    gap: 20px !important;
    width: 100% !important;
    box-sizing: border-box !important;
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
    box-sizing: border-box !important;
    transition: transform 0.2s ease, box-shadow 0.2s ease !important;
}

.gh-stat-card:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 6px 16px rgba(27, 18, 64, 0.06) !important;
}

.gh-stat-icon-wrap {
    width: 48px !important;
    height: 48px !important;
    min-width: 48px !important;
    max-width: 48px !important;
    border-radius: 12px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
}

.gh-stat-icon-wrap svg {
    display: block !important;
}

.gh-stat-info {
    display: flex !important;
    flex-direction: column !important;
    gap: 3px !important;
    min-width: 0 !important;
}

.gh-stat-label {
    font-size: 13px !important;
    color: #6B7280 !important;
    font-weight: 500 !important;
    margin: 0 !important;
    line-height: 1.3 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

.gh-stat-value {
    font-family: 'Poppins', sans-serif !important;
    font-size: 22px !important;
    font-weight: 700 !important;
    color: #1B1240 !important;
    line-height: 1.1 !important;
    margin: 0 !important;
    white-space: nowrap !important;
}

.gh-earnings-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    width: 100%;
    box-sizing: border-box;
}

.gh-earnings-toolbar .gh-search-input-box {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    background: #FFFFFF !important;
    border: 1px solid #D8D2EE !important;
    border-radius: 12px !important;
    padding: 0 14px !important;
    height: 46px !important;
    min-height: 46px !important;
    width: 300px !important;
    max-width: 100% !important;
    box-sizing: border-box !important;
    transition: border-color 0.2s ease, box-shadow 0.2s ease !important;
}

.gh-earnings-toolbar .gh-search-input-box:focus-within {
    border-color: #632FD1 !important;
    box-shadow: 0 0 0 3px rgba(99, 47, 209, 0.12) !important;
}

.gh-earnings-toolbar .gh-search-input-box input {
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
    background: transparent !important;
    font-family: 'Lato', sans-serif !important;
    font-size: 13.5px !important;
    color: #1B1240 !important;
    width: 100% !important;
    height: 100% !important;
    padding: 0 !important;
    margin: 0 !important;
}

.gh-earnings-filters-right {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.gh-earnings-toolbar .gh-custom-select {
    display: block !important;
    width: auto !important;
    min-width: 180px !important;
    height: 46px !important;
    line-height: 44px !important;
    padding: 0 36px 0 14px !important;
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
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg width='12' height='8' viewBox='0 0 12 8' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1.5L6 6.5L11 1.5' stroke='%23632FD1' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: right 14px center !important;
    box-sizing: border-box !important;
}

@media (max-width: 1024px) {
    .gh-stats-grid {
        grid-template-columns: repeat(2, 1fr) !important;
        gap: 16px !important;
    }
}

@media (max-width: 768px) {
    .gh-stats-grid {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
    }
    .gh-stat-card {
        padding: 16px 18px !important;
    }
    .gh-earnings-toolbar {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 10px !important;
    }
    .gh-earnings-toolbar .gh-search-input-box {
        width: 100% !important;
        max-width: 100% !important;
        height: 42px !important;
        min-height: 42px !important;
        padding: 0 12px !important;
    }
    .gh-earnings-toolbar .gh-search-input-box input {
        font-size: 13px !important;
    }
    .gh-earnings-filters-right {
        width: 100% !important;
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 10px !important;
    }
    .gh-earnings-toolbar .gh-select-wrap,
    .gh-earnings-toolbar .gh-custom-select {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        height: 42px !important;
        min-height: 42px !important;
        line-height: 40px !important;
        font-size: 13px !important;
    }
}
</style>

<script>
function ghFilterEarningsList() {
    var searchInput  = document.getElementById('ghEarningsSearch');
    var courseFilter = document.getElementById('ghEarningsCourseFilter');
    var tutorFilter  = document.getElementById('ghEarningsTutorFilter');
    var statusFilter = document.getElementById('ghEarningsStatusFilter');

    var searchVal = searchInput ? (searchInput.value || '').toLowerCase().trim() : '';
    var courseVal = courseFilter ? courseFilter.value : 'all';
    var tutorVal  = tutorFilter ? tutorFilter.value : 'all';
    var statusVal = statusFilter ? statusFilter.value.toLowerCase() : 'all';

    var rows = document.querySelectorAll('.gh-earnings-row');
    var visibleCount = 0;

    rows.forEach(function(row) {
        var rowCourse = row.getAttribute('data-course-id') || '';
        var rowUser   = row.getAttribute('data-user-id') || '';
        var rowStatus = (row.getAttribute('data-status') || '').toLowerCase();
        var rowSearch = (row.getAttribute('data-search') || '').toLowerCase();

        var matchSearch = !searchVal || rowSearch.indexOf(searchVal) !== -1;
        var matchCourse = (courseVal === 'all') || (rowCourse === courseVal);
        var matchTutor  = (tutorVal === 'all') || (rowUser === tutorVal);
        var matchStatus = (statusVal === 'all') || (rowStatus === statusVal);

        if (matchSearch && matchCourse && matchTutor && matchStatus) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });

    var emptyMatchRow = document.getElementById('ghEarningsNoFilterMatch');
    if (emptyMatchRow) {
        emptyMatchRow.style.display = (visibleCount === 0 && rows.length > 0) ? '' : 'none';
    }
}
</script>

