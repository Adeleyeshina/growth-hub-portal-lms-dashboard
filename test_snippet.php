<?php
/**
 * Snippet Name: Growth Hub - Earnings & Revenue Test Portal
 * Description: Standalone test snippet for Growth Hub Earnings & Revenue screen.
 * Shortcode: [growth_hub_test_page] or [growth_hub_earnings]
 * Author: Adeleye Shina
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_shortcode( 'growth_hub_test_page', 'gh_render_standalone_earnings_test' );
add_shortcode( 'growth_hub_earnings', 'gh_render_standalone_earnings_test' );
add_shortcode( 'growth_hub_earning', 'gh_render_standalone_earnings_test' );
add_shortcode( 'growth_hub_certificates', 'gh_render_standalone_earnings_test' );
add_shortcode( 'growth_hub_my_certificates', 'gh_render_standalone_earnings_test' );

function gh_render_standalone_earnings_test( $atts = array() ) {
    global $wpdb;

    $current_user   = wp_get_current_user();
    $user_id        = $current_user->exists() ? $current_user->ID : 1;
    $is_admin_user  = current_user_can( 'administrator' );
    $display_name   = $current_user->exists() ? $current_user->display_name : 'Adeleye Shina';
    $role_label     = $is_admin_user ? 'Administrator' : 'Mentor / Instructor';

    $view_mode      = isset( $_GET['view'] ) ? sanitize_text_field( $_GET['view'] ) : ( $is_admin_user ? 'admin' : 'tutor' );
    $is_admin_view  = ( 'admin' === $view_mode );

    $currency_symbol = '$';
    if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_currency_symbol' ) ) {
        $currency_symbol = tutor_utils()->get_currency_symbol();
    } elseif ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
        $currency_symbol = get_woocommerce_currency_symbol();
    }
    $currency_symbol = html_entity_decode( $currency_symbol, ENT_QUOTES, 'UTF-8' );

    $earnings_table      = $wpdb->prefix . 'tutor_earnings';
    $withdraws_table     = $wpdb->prefix . 'tutor_withdraws';
    $has_earnings_table  = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $earnings_table ) ) === $earnings_table;
    $has_withdraws_table = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $withdraws_table ) ) === $withdraws_table;

    $db_transactions = array();
    $total_sales        = 0.00;
    $total_instructor   = 0.00;
    $total_admin        = 0.00;
    $total_withdrawn    = 0.00;
    $current_balance    = 0.00;
    $total_transactions = 0;

    if ( $has_earnings_table ) {
        if ( $is_admin_view ) {
            $rows = $wpdb->get_results( "
                SELECT e.*, p.post_title as course_title, u.display_name as tutor_name
                FROM {$earnings_table} e
                LEFT JOIN {$wpdb->posts} p ON e.course_id = p.ID
                LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
                ORDER BY e.created_at DESC
                LIMIT 200
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
            $rows = $wpdb->get_results( $wpdb->prepare( "
                SELECT e.*, p.post_title as course_title, u.display_name as tutor_name
                FROM {$earnings_table} e
                LEFT JOIN {$wpdb->posts} p ON e.course_id = p.ID
                LEFT JOIN {$wpdb->users} u ON e.user_id = u.ID
                WHERE e.user_id = %d
                ORDER BY e.created_at DESC
                LIMIT 200
            ", $user_id ) );

            $totals = $wpdb->get_row( $wpdb->prepare( "
                SELECT 
                    SUM(course_price_total) as sum_sales,
                    SUM(instructor_amount) as sum_instructor,
                    SUM(admin_amount) as sum_admin,
                    COUNT(earning_id) as count_tx
                FROM {$earnings_table}
                WHERE user_id = %d
            ", $user_id ) );

            if ( $totals ) {
                $total_sales        = floatval( $totals->sum_sales );
                $total_instructor   = floatval( $totals->sum_instructor );
                $total_admin        = floatval( $totals->sum_admin );
                $total_transactions = intval( $totals->count_tx );
            }

            if ( $has_withdraws_table ) {
                $total_withdrawn = floatval( $wpdb->get_var( $wpdb->prepare( "
                    SELECT SUM(amount) FROM {$withdraws_table} WHERE user_id = %d AND status = 'approved'
                ", $user_id ) ) );
            }
            $current_balance = max( 0.00, $total_instructor - $total_withdrawn );
        }

        if ( ! empty( $rows ) ) {
            foreach ( $rows as $r ) {
                $db_transactions[] = array(
                    'order_id'          => ! empty( $r->order_id ) ? '#' . $r->order_id : '#TXN-' . $r->earning_id,
                    'course_title'      => ! empty( $r->course_title ) ? $r->course_title : 'General Course',
                    'category'          => 'Cohort Course',
                    'tutor_name'        => ! empty( $r->tutor_name ) ? $r->tutor_name : $display_name,
                    'course_price'      => floatval( $r->course_price_total ),
                    'instructor_amount' => floatval( $r->instructor_amount ),
                    'admin_amount'      => floatval( $r->admin_amount ),
                    'order_status'      => ! empty( $r->order_status ) ? strtolower( $r->order_status ) : 'completed',
                    'date'              => ! empty( $r->created_at ) ? date( 'M j, Y', strtotime( $r->created_at ) ) : date( 'M j, Y' ),
                );
            }
        }
    }

    ob_start();
    ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,300;0,400;0,700;0,900;1,400&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">

    <style>
    :root {
        --gh-purple: #632FD1 !important;
        --gh-purple-hover: #5224B8 !important;
        --gh-dark: #1B1240 !important;
        --gh-dark-surface: #241A4F !important;
        --gh-border: #D8D2EE !important;
        --gh-soft-bg: #F4F1FB !important;
        --gh-font-h: 'Poppins', sans-serif !important;
        --gh-font-b: 'Lato', sans-serif !important;
    }

    html, body {
        margin: 0 !important;
        padding: 0 !important;
    }

    body .ast-container,
    body .entry-content,
    body .site-content,
    body #content,
    body .ast-plain-container,
    body .elementor,
    body .wp-block-post-content,
    body .site-main,
    body #primary,
    body article,
    body .post-content,
    body .wp-site-blocks,
    body #page,
    body .content-area,
    body .site {
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
        width: 100% !important;
    }

    .gh-portal-wrapper {
        font-family: var(--gh-font-b) !important;
        color: var(--gh-dark) !important;
        background-color: #F8F7FC !important;
        margin: 0 !important;
        padding: 0 !important;
        box-sizing: border-box !important;
        width: 100% !important;
        min-height: 100vh !important;
    }

    .gh-portal-wrapper * {
        box-sizing: border-box !important;
    }

    .gh-portal-wrapper select,
    .gh-custom-select {
        display: block !important;
        width: auto !important;
        min-width: 200px !important;
        height: 48px !important;
        min-height: 48px !important;
        max-height: 48px !important;
        line-height: 46px !important;
        padding: 0 38px 0 14px !important;
        font-family: var(--gh-font-b) !important;
        font-size: 13.5px !important;
        font-weight: 500 !important;
        color: #1B1240 !important;
        background-color: #FFFFFF !important;
        border: 1.5px solid #D8D2EE !important;
        border-radius: 12px !important;
        outline: none !important;
        cursor: pointer !important;
        appearance: none !important;
        -webkit-appearance: none !important;
        background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg width='12' height='8' viewBox='0 0 12 8' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1.5L6 6.5L11 1.5' stroke='%23632FD1' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") !important;
        background-repeat: no-repeat !important;
        background-position: right 14px center !important;
        box-sizing: border-box !important;
        vertical-align: middle !important;
        transition: border-color 0.15s ease, box-shadow 0.15s ease !important;
    }

    .gh-portal-wrapper select:focus,
    .gh-custom-select:focus {
        border-color: #632FD1 !important;
        box-shadow: 0 0 0 3px rgba(99, 47, 209, 0.12) !important;
    }

    .gh-mobile-bar {
        display: none;
        align-items: center;
        justify-content: space-between;
        padding: 16px 20px;
        background-color: var(--gh-dark);
        color: #FFFFFF;
    }

    .gh-mobile-bar-brand {
        font-family: var(--gh-font-h) !important;
        font-size: 17px !important;
        font-weight: 700 !important;
        letter-spacing: 0.5px !important;
        color: #FFFFFF !important;
        text-decoration: none !important;
    }

    .gh-hamburger {
        background: transparent !important;
        border: none !important;
        color: #FFFFFF !important;
        font-size: 24px !important;
        cursor: pointer !important;
        padding: 4px !important;
        line-height: 1 !important;
    }

    .gh-dash-grid {
        display: grid !important;
        grid-template-columns: 280px minmax(0, 1fr) !important;
        min-height: 100vh !important;
        width: 100% !important;
    }

    .gh-sidebar {
        background-color: var(--gh-dark) !important;
        color: #FFFFFF !important;
        padding: 32px 20px 40px !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 28px !important;
        box-shadow: 4px 0 20px rgba(0, 0, 0, 0.05) !important;
        z-index: 50 !important;
    }

    .gh-brand {
        font-family: var(--gh-font-h) !important;
        font-size: 19px !important;
        font-weight: 700 !important;
        color: #FFFFFF !important;
        text-decoration: none !important;
        letter-spacing: 0.5px !important;
        padding: 0 8px !important;
    }

    .gh-user-card {
        background-color: var(--gh-dark-surface) !important;
        border: 1px solid rgba(216, 210, 238, 0.15) !important;
        padding: 14px 16px !important;
        border-radius: 12px !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 4px !important;
    }

    .gh-user-name {
        font-family: var(--gh-font-h) !important;
        font-size: 13.5px !important;
        font-weight: 700 !important;
        color: #FFFFFF !important;
        letter-spacing: 0.5px !important;
    }

    .gh-user-role {
        font-size: 12px !important;
        color: #B3A8DE !important;
    }

    .gh-nav-list {
        list-style: none !important;
        padding: 0 !important;
        margin: 0 !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 6px !important;
    }

    .gh-nav-item a {
        display: flex !important;
        align-items: center !important;
        padding: 11px 16px !important;
        border-radius: 10px !important;
        color: #D2CBED !important;
        text-decoration: none !important;
        font-size: 13.5px !important;
        font-weight: 500 !important;
        transition: all 0.15s ease !important;
    }

    .gh-nav-item a:hover {
        background-color: rgba(99, 47, 209, 0.15) !important;
        color: #FFFFFF !important;
    }

    .gh-nav-item.active a {
        background-color: var(--gh-purple) !important;
        color: #FFFFFF !important;
        font-weight: 600 !important;
    }

    .gh-main-area {
        padding: 36px 40px 60px !important;
        background-color: #F8F7FC !important;
        min-width: 0 !important;
    }

    .gh-page-header {
        display: flex !important;
        justify-content: space-between !important;
        align-items: flex-start !important;
        gap: 20px !important;
        margin-bottom: 28px !important;
        flex-wrap: wrap !important;
    }

    .gh-page-title {
        font-family: var(--gh-font-h) !important;
        font-size: 28px !important;
        font-weight: 700 !important;
        color: var(--gh-dark) !important;
        margin: 0 0 6px 0 !important;
        line-height: 1.2 !important;
    }

    .gh-page-subtitle {
        color: #6B7280 !important;
        font-size: 14.5px !important;
        margin: 0 !important;
        line-height: 1.5 !important;
    }

    .gh-header-actions {
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
        flex-wrap: wrap !important;
    }

    .gh-btn-secondary {
        background: #FFFFFF !important;
        border: 1.5px solid #D8D2EE !important;
        color: #6B7280 !important;
        padding: 10px 18px !important;
        border-radius: 12px !important;
        font-family: var(--gh-font-h) !important;
        font-weight: 600 !important;
        font-size: 13px !important;
        cursor: pointer !important;
        text-decoration: none !important;
        transition: all 0.15s ease !important;
        white-space: nowrap !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
    }

    .gh-btn-secondary:hover {
        background: #F4F1FB !important;
        color: #1B1240 !important;
        border-color: #632FD1 !important;
    }

    .gh-btn-role-active {
        background: #632FD1 !important;
        color: #FFFFFF !important;
        border-color: #632FD1 !important;
    }

    .gh-btn-role-active:hover {
        background: #5224B8 !important;
        color: #FFFFFF !important;
    }

    .gh-stats-grid {
        display: grid !important;
        grid-template-columns: repeat(4, 1fr) !important;
        gap: 20px !important;
        margin-bottom: 30px !important;
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
        font-family: var(--gh-font-h) !important;
        font-size: 24px !important;
        font-weight: 700 !important;
        color: #1B1240 !important;
        line-height: 1.1 !important;
    }

    .gh-earnings-toolbar {
        background: #FFFFFF !important;
        border: 1px solid #EBE7F7 !important;
        border-radius: 16px !important;
        padding: 16px 20px !important;
        margin-bottom: 24px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        gap: 16px !important;
        flex-wrap: wrap !important;
    }

    .gh-search-bar-wrap {
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
        min-width: 280px !important;
        flex: 1 1 280px !important;
        margin: 0 !important;
        transition: border-color 0.15s ease, box-shadow 0.15s ease !important;
    }

    .gh-search-bar-wrap:focus-within {
        border-color: #632FD1 !important;
        box-shadow: 0 0 0 3px rgba(99, 47, 209, 0.12) !important;
    }

    .gh-search-bar-wrap input {
        border: none !important;
        outline: none !important;
        background: transparent !important;
        font-family: var(--gh-font-b) !important;
        font-size: 14px !important;
        color: #1B1240 !important;
        width: 100% !important;
        height: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .gh-earnings-filters-right {
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
        flex-wrap: wrap !important;
    }

    .gh-table-card {
        background: #FFFFFF !important;
        border: 1px solid #EBE7F7 !important;
        border-radius: 18px !important;
        overflow: hidden !important;
        box-shadow: 0 4px 16px rgba(27, 18, 64, 0.04) !important;
    }

    .gh-table-wrap {
        width: 100% !important;
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
    }

    .gh-table {
        width: 100% !important;
        min-width: 980px !important;
        border-collapse: collapse !important;
        text-align: left !important;
    }

    .gh-table th {
        background: #F8F7FC !important;
        color: #4B5563 !important;
        font-family: var(--gh-font-h) !important;
        font-size: 12.5px !important;
        font-weight: 600 !important;
        padding: 16px 20px !important;
        border-bottom: 1px solid #EBE7F7 !important;
        text-transform: uppercase !important;
        letter-spacing: 0.5px !important;
    }

    .gh-table td {
        padding: 18px 20px !important;
        border-bottom: 1px solid #F3F1FA !important;
        vertical-align: middle !important;
        font-size: 14px !important;
    }

    .gh-table tr:hover td {
        background: #FAF9FE !important;
    }

    .gh-order-code {
        font-family: monospace !important;
        font-size: 13.5px !important;
        font-weight: 700 !important;
        color: #1B1240 !important;
        background: #F4F1FB !important;
        padding: 4px 8px !important;
        border-radius: 6px !important;
        border: 1px solid #D8D2EE !important;
        display: inline-block !important;
    }

    .gh-course-col {
        display: flex !important;
        flex-direction: column !important;
        gap: 4px !important;
    }

    .gh-course-name {
        font-family: var(--gh-font-h) !important;
        font-size: 14px !important;
        font-weight: 600 !important;
        color: #1B1240 !important;
    }

    .gh-badge-track {
        display: inline-block !important;
        background: #F4F1FB !important;
        color: #632FD1 !important;
        border: 1px solid #D8D2EE !important;
        padding: 2px 10px !important;
        border-radius: 999px !important;
        font-size: 11px !important;
        font-weight: 600 !important;
        width: fit-content !important;
    }

    .gh-badge-status {
        display: inline-flex !important;
        align-items: center !important;
        padding: 4px 12px !important;
        border-radius: 999px !important;
        font-size: 12px !important;
        font-weight: 600 !important;
        text-transform: capitalize !important;
        white-space: nowrap !important;
    }

    .gh-badge-completed {
        background: rgba(16, 185, 129, 0.12) !important;
        color: #059669 !important;
        border: 1px solid rgba(16, 185, 129, 0.25) !important;
    }

    .gh-badge-processing {
        background: rgba(99, 47, 209, 0.12) !important;
        color: #632FD1 !important;
        border: 1px solid rgba(99, 47, 209, 0.25) !important;
    }

    .gh-badge-pending {
        background: rgba(245, 158, 11, 0.12) !important;
        color: #D97706 !important;
        border: 1px solid rgba(245, 158, 11, 0.25) !important;
    }

    .gh-badge-refunded, .gh-badge-cancelled {
        background: rgba(239, 68, 68, 0.12) !important;
        color: #DC2626 !important;
        border: 1px solid rgba(239, 68, 68, 0.25) !important;
    }

    .gh-toast-container {
        position: fixed !important;
        bottom: 24px !important;
        right: 24px !important;
        z-index: 100000 !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 10px !important;
        pointer-events: none !important;
    }

    .gh-toast {
        background: #1B1240 !important;
        color: #FFFFFF !important;
        padding: 14px 20px !important;
        border-radius: 12px !important;
        font-family: var(--gh-font-b) !important;
        font-size: 14px !important;
        box-shadow: 0 10px 30px rgba(27, 18, 64, 0.35) !important;
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
        pointer-events: auto !important;
    }

    @media (max-width: 1024px) {
        .gh-stats-grid {
            grid-template-columns: repeat(2, 1fr) !important;
        }
    }

    @media (max-width: 768px) {
        .gh-dash-grid {
            grid-template-columns: 1fr !important;
        }

        .gh-sidebar {
            display: none !important;
        }

        .gh-mobile-bar {
            display: flex !important;
        }

        .gh-main-area {
            padding: 24px 16px 40px !important;
        }

        .gh-page-header {
            flex-direction: column !important;
            align-items: stretch !important;
        }

        .gh-stats-grid {
            grid-template-columns: 1fr !important;
            gap: 12px !important;
        }

        .gh-earnings-toolbar {
            flex-direction: column !important;
            align-items: stretch !important;
            padding: 14px !important;
            gap: 12px !important;
        }

        .gh-search-bar-wrap {
            width: 100% !important;
            min-width: 0 !important;
        }

        .gh-earnings-filters-right {
            width: 100% !important;
            flex-direction: column !important;
            align-items: stretch !important;
            gap: 10px !important;
        }

        .gh-custom-select {
            width: 100% !important;
        }
    }
    </style>

    <div class="gh-portal-wrapper">
        <div class="gh-mobile-bar">
            <a href="#" class="gh-mobile-bar-brand">THE GROWTH HUB</a>
            <button class="gh-hamburger" type="button" onclick="ghToggleMobileMenu()">&#9776;</button>
        </div>

        <div class="gh-dash-grid">
            <aside class="gh-sidebar" id="gh-sidebar">
                <a href="#" class="gh-brand">THE GROWTH HUB</a>

                <div class="gh-user-card">
                    <span class="gh-user-name"><?php echo esc_html( strtoupper( $display_name ) ); ?></span>
                    <span class="gh-user-role"><?php echo esc_html( $role_label ); ?></span>
                </div>

                <ul class="gh-nav-list">
                    <li class="gh-nav-item"><a href="#">Overview</a></li>
                    <li class="gh-nav-item"><a href="#">My Courses</a></li>
                    <li class="gh-nav-item"><a href="#">Mentees & Students</a></li>
                    <li class="gh-nav-item"><a href="#">Live Classes</a></li>
                    <li class="gh-nav-item"><a href="#">Class Log</a></li>
                    <li class="gh-nav-item"><a href="#">Announcements</a></li>
                    <li class="gh-nav-item"><a href="#">Discussions (Q&A)</a></li>
                    <li class="gh-nav-item"><a href="#">Grading</a></li>
                    <li class="gh-nav-item"><a href="#">Certificates</a></li>
                    <li class="gh-nav-item"><a href="#">Analytics</a></li>
                    <li class="gh-nav-item"><a href="#">Weekly Report</a></li>
                    <li class="gh-nav-item active"><a href="<?php echo esc_url( add_query_arg( 'tab', 'earning' ) ); ?>">Earnings</a></li>
                </ul>
            </aside>

            <main class="gh-main-area">
                <div class="gh-page-header">
                    <div>
                        <h1 class="gh-page-title"><?php esc_html_e( 'Earnings & Revenue', 'growth-hub' ); ?></h1>
                        <p class="gh-page-subtitle">
                            <?php if ( $is_admin_view ) : ?>
                                <?php esc_html_e( 'Comprehensive platform view: Monitor total course sales, tutor payouts, and admin commissions.', 'growth-hub' ); ?>
                            <?php else : ?>
                                <?php esc_html_e( 'Track course sales, instructor earnings, and available payout balances.', 'growth-hub' ); ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="gh-header-actions">
                        <span style="font-size: 13px; font-weight: 600; color: #6B7280;"><?php esc_html_e( 'Test Role:', 'growth-hub' ); ?></span>
                        <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'admin' ) ) ); ?>" class="gh-btn-secondary <?php echo $is_admin_view ? 'gh-btn-role-active' : ''; ?>">
                            <?php esc_html_e( 'Admin View (All)', 'growth-hub' ); ?>
                        </a>
                        <a href="<?php echo esc_url( add_query_arg( array( 'view' => 'tutor' ) ) ); ?>" class="gh-btn-secondary <?php echo ! $is_admin_view ? 'gh-btn-role-active' : ''; ?>">
                            <?php esc_html_e( 'Tutor View (My Courses)', 'growth-hub' ); ?>
                        </a>
                        <button type="button" class="gh-btn-secondary" onclick="ghResetDemoEarningsData()">
                            <?php esc_html_e( 'Reset Demo Data', 'growth-hub' ); ?>
                        </button>
                    </div>
                </div>

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
                                <?php echo $is_admin_view ? esc_html__( 'Total Platform Sales', 'growth-hub' ) : esc_html__( 'Total Course Sales', 'growth-hub' ); ?>
                            </span>
                            <span class="gh-stat-value" id="statEarningsSales">$0.00</span>
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
                                <?php echo $is_admin_view ? esc_html__( 'Instructor Payouts', 'growth-hub' ) : esc_html__( 'Your Earnings', 'growth-hub' ); ?>
                            </span>
                            <span class="gh-stat-value" id="statEarningsInstructor" style="color: #10B981;">$0.00</span>
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
                                <?php echo $is_admin_view ? esc_html__( 'Platform Commission', 'growth-hub' ) : esc_html__( 'Total Withdrawn', 'growth-hub' ); ?>
                            </span>
                            <span class="gh-stat-value" id="statEarningsCommission" style="color: #4F46E5;">$0.00</span>
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
                                <?php echo $is_admin_view ? esc_html__( 'Total Transactions', 'growth-hub' ) : esc_html__( 'Available Balance', 'growth-hub' ); ?>
                            </span>
                            <span class="gh-stat-value" id="statEarningsTransactions" style="color: #D97706;">0</span>
                        </div>
                    </div>
                </div>

                <div class="gh-earnings-toolbar">
                    <div class="gh-search-bar-wrap">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6B7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <input type="text" id="earningsSearchInput" placeholder="<?php esc_attr_e( 'Search order ID, course, or mentor...', 'growth-hub' ); ?>" oninput="ghFilterEarningsTest()">
                    </div>

                    <div class="gh-earnings-filters-right">
                        <select id="earningsCourseFilter" class="gh-custom-select" onchange="ghFilterEarningsTest()" aria-label="Course Filter">
                            <option value="all"><?php esc_html_e( 'All Courses', 'growth-hub' ); ?></option>
                        </select>

                        <?php if ( $is_admin_view ) : ?>
                        <select id="earningsTutorFilter" class="gh-custom-select" onchange="ghFilterEarningsTest()" aria-label="Instructor Filter">
                            <option value="all"><?php esc_html_e( 'All Instructors', 'growth-hub' ); ?></option>
                        </select>
                        <?php endif; ?>

                        <select id="earningsStatusFilter" class="gh-custom-select" onchange="ghFilterEarningsTest()" aria-label="Status Filter">
                            <option value="all"><?php esc_html_e( 'All Statuses', 'growth-hub' ); ?></option>
                            <option value="completed"><?php esc_html_e( 'Completed', 'growth-hub' ); ?></option>
                            <option value="processing"><?php esc_html_e( 'Processing', 'growth-hub' ); ?></option>
                            <option value="pending"><?php esc_html_e( 'Pending', 'growth-hub' ); ?></option>
                            <option value="refunded"><?php esc_html_e( 'Refunded', 'growth-hub' ); ?></option>
                        </select>
                    </div>
                </div>

                <div class="gh-table-card">
                    <div class="gh-table-wrap">
                        <table class="gh-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'ORDER / TXN', 'growth-hub' ); ?></th>
                                    <th><?php esc_html_e( 'COURSE / COHORT', 'growth-hub' ); ?></th>
                                    <?php if ( $is_admin_view ) : ?>
                                        <th><?php esc_html_e( 'INSTRUCTOR', 'growth-hub' ); ?></th>
                                    <?php endif; ?>
                                    <th><?php esc_html_e( 'GROSS SALE', 'growth-hub' ); ?></th>
                                    <th><?php echo $is_admin_view ? esc_html__( 'INSTRUCTOR SHARE', 'growth-hub' ) : esc_html__( 'YOUR EARNINGS', 'growth-hub' ); ?></th>
                                    <?php if ( $is_admin_view ) : ?>
                                        <th><?php esc_html_e( 'COMMISSION', 'growth-hub' ); ?></th>
                                    <?php endif; ?>
                                    <th><?php esc_html_e( 'DATE', 'growth-hub' ); ?></th>
                                    <th style="text-align: right;"><?php esc_html_e( 'STATUS', 'growth-hub' ); ?></th>
                                </tr>
                            </thead>
                            <tbody id="earningsTableBody"></tbody>
                        </table>
                    </div>

                    <div id="emptyEarningsState" style="display: none; padding: 60px 24px; text-align: center;">
                        <div style="width: 64px; height: 64px; border-radius: 50%; background: #F4F1FB; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#632FD1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="12" y1="1" x2="12" y2="23"></line>
                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                            </svg>
                        </div>
                        <h3 style="font-family: var(--gh-font-h); font-size: 18px; margin: 0 0 6px; color: #1B1240;"><?php esc_html_e( 'No Transactions Found', 'growth-hub' ); ?></h3>
                        <p style="color: #6B7280; font-size: 14px; margin: 0;"><?php esc_html_e( 'No earnings records match your search or filter selection.', 'growth-hub' ); ?></p>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="gh-toast-container" id="ghToastContainer"></div>

    <script>
    const IS_ADMIN_VIEW = <?php echo $is_admin_view ? 'true' : 'false'; ?>;
    const CURRENT_USER_NAME = <?php echo wp_json_encode( $display_name ); ?>;
    const CURRENCY = <?php echo wp_json_encode( $currency_symbol ); ?>;

    const DB_ROWS = <?php echo wp_json_encode( $db_transactions ); ?>;

    const DEFAULT_TEST_EARNINGS = [
        {
            order_id: "#1048",
            course_title: "6 Months Data Analytics Course",
            category: "Data & Analytics",
            tutor_name: "Adeleye Shina",
            course_price: 250.00,
            instructor_amount: 200.00,
            admin_amount: 50.00,
            order_status: "completed",
            date: "Nov 7, 2026"
        },
        {
            order_id: "#1042",
            course_title: "Cybersecurity Immersion Cohort",
            category: "Information Security",
            tutor_name: "Adeleye Shina",
            course_price: 320.00,
            instructor_amount: 256.00,
            admin_amount: 64.00,
            order_status: "completed",
            date: "Nov 5, 2026"
        },
        {
            order_id: "#1039",
            course_title: "UI/UX Product Design Masterclass",
            category: "Product Design",
            tutor_name: "Sarah Jenkins",
            course_price: 180.00,
            instructor_amount: 144.00,
            admin_amount: 36.00,
            order_status: "processing",
            date: "Nov 3, 2026"
        },
        {
            order_id: "#1031",
            course_title: "Fullstack Web Development Bootcamp",
            category: "Software Engineering",
            tutor_name: "Michael Chen",
            course_price: 400.00,
            instructor_amount: 320.00,
            admin_amount: 80.00,
            order_status: "completed",
            date: "Oct 30, 2026"
        },
        {
            order_id: "#1025",
            course_title: "6 Months Data Analytics Course",
            category: "Data & Analytics",
            tutor_name: "Adeleye Shina",
            course_price: 250.00,
            instructor_amount: 200.00,
            admin_amount: 50.00,
            order_status: "pending",
            date: "Oct 27, 2026"
        },
        {
            order_id: "#1018",
            course_title: "Cybersecurity Immersion Cohort",
            category: "Information Security",
            tutor_name: "Adeleye Shina",
            course_price: 320.00,
            instructor_amount: 256.00,
            admin_amount: 64.00,
            order_status: "completed",
            date: "Oct 21, 2026"
        }
    ];

    function ghGetEarningsData() {
        if (Array.isArray(DB_ROWS) && DB_ROWS.length > 0) {
            return DB_ROWS;
        }
        const stored = localStorage.getItem('gh_test_earnings_data');
        if (stored) {
            try { return JSON.parse(stored); } catch (e) { console.error(e); }
        }
        localStorage.setItem('gh_test_earnings_data', JSON.stringify(DEFAULT_TEST_EARNINGS));
        return DEFAULT_TEST_EARNINGS;
    }

    function ghFormatMoney(amount) {
        return CURRENCY + Number(amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function ghInitEarningsFilters() {
        const data = ghGetEarningsData();
        const courseSelect = document.getElementById('earningsCourseFilter');
        const tutorSelect = document.getElementById('earningsTutorFilter');

        if (courseSelect) {
            const courses = [...new Set(data.map(d => d.course_title))].filter(Boolean);
            courseSelect.innerHTML = '<option value="all">All Courses</option>';
            courses.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.toLowerCase();
                opt.textContent = c;
                courseSelect.appendChild(opt);
            });
        }

        if (tutorSelect) {
            const tutors = [...new Set(data.map(d => d.tutor_name))].filter(Boolean);
            tutorSelect.innerHTML = '<option value="all">All Instructors</option>';
            tutors.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t.toLowerCase();
                opt.textContent = t;
                tutorSelect.appendChild(opt);
            });
        }
    }

    function ghRenderEarnings() {
        const allData = ghGetEarningsData();
        const tbody = document.getElementById('earningsTableBody');
        const emptyState = document.getElementById('emptyEarningsState');
        if (!tbody) return;

        let scopedData = allData;
        if (!IS_ADMIN_VIEW) {
            scopedData = allData.filter(d => {
                return (d.tutor_name && d.tutor_name.toLowerCase() === CURRENT_USER_NAME.toLowerCase()) || 
                       (!d.tutor_name || d.tutor_name.toLowerCase().includes('adeleye') || d.tutor_name.toLowerCase().includes('shina'));
            });
            if (scopedData.length === 0) {
                scopedData = allData;
            }
        }

        const totalSales = scopedData.reduce((acc, d) => acc + (parseFloat(d.course_price) || 0), 0);
        const totalInstructor = scopedData.reduce((acc, d) => acc + (parseFloat(d.instructor_amount) || 0), 0);
        const totalAdmin = scopedData.reduce((acc, d) => acc + (parseFloat(d.admin_amount) || 0), 0);

        document.getElementById('statEarningsSales').innerText = ghFormatMoney(totalSales);
        document.getElementById('statEarningsInstructor').innerText = ghFormatMoney(totalInstructor);

        if (IS_ADMIN_VIEW) {
            document.getElementById('statEarningsCommission').innerText = ghFormatMoney(totalAdmin);
            document.getElementById('statEarningsTransactions').innerText = scopedData.length;
        } else {
            const mockWithdrawn = totalInstructor > 400 ? 400.00 : 0.00;
            const availableBalance = Math.max(0, totalInstructor - mockWithdrawn);
            document.getElementById('statEarningsCommission').innerText = ghFormatMoney(mockWithdrawn);
            document.getElementById('statEarningsTransactions').innerText = ghFormatMoney(availableBalance);
        }

        const search = (document.getElementById('earningsSearchInput')?.value || '').trim().toLowerCase();
        const course = (document.getElementById('earningsCourseFilter')?.value || 'all').toLowerCase();
        const tutor = (document.getElementById('earningsTutorFilter')?.value || 'all').toLowerCase();
        const status = (document.getElementById('earningsStatusFilter')?.value || 'all').toLowerCase();

        const filtered = scopedData.filter(d => {
            const matchesCourse = (course === 'all') || (d.course_title && d.course_title.toLowerCase().includes(course));
            const matchesTutor  = (tutor === 'all') || (d.tutor_name && d.tutor_name.toLowerCase().includes(tutor));
            const matchesStatus = (status === 'all') || (d.order_status && d.order_status.toLowerCase() === status);
            const matchesSearch = !search ||
                (d.order_id && d.order_id.toLowerCase().includes(search)) ||
                (d.course_title && d.course_title.toLowerCase().includes(search)) ||
                (d.tutor_name && d.tutor_name.toLowerCase().includes(search));

            return matchesCourse && matchesTutor && matchesStatus && matchesSearch;
        });

        if (filtered.length === 0) {
            tbody.innerHTML = '';
            if (emptyState) emptyState.style.display = 'block';
            return;
        }

        if (emptyState) emptyState.style.display = 'none';

        let html = '';
        filtered.forEach(d => {
            const statusClass = 'gh-badge-' + (d.order_status || 'completed').toLowerCase();
            const categoryText = d.category || 'Cohort Track';

            html += `
                <tr>
                    <td>
                        <span class="gh-order-code">${d.order_id}</span>
                    </td>
                    <td>
                        <div class="gh-course-col">
                            <span class="gh-course-name">${d.course_title}</span>
                            <span class="gh-badge-track">${categoryText}</span>
                        </div>
                    </td>
                    ${IS_ADMIN_VIEW ? `<td><strong style="color:#1B1240;">${d.tutor_name}</strong></td>` : ''}
                    <td><strong style="color:#1B1240;">${ghFormatMoney(d.course_price)}</strong></td>
                    <td><strong style="color:#10B981;">${ghFormatMoney(d.instructor_amount)}</strong></td>
                    ${IS_ADMIN_VIEW ? `<td><strong style="color:#632FD1;">${ghFormatMoney(d.admin_amount)}</strong></td>` : ''}
                    <td style="color:#6B7280; font-size:13.5px;">${d.date}</td>
                    <td style="text-align: right;">
                        <span class="gh-badge-status ${statusClass}">
                            ${d.order_status}
                        </span>
                    </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    }

    function ghFilterEarningsTest() {
        ghRenderEarnings();
    }

    function ghResetDemoEarningsData() {
        localStorage.setItem('gh_test_earnings_data', JSON.stringify(DEFAULT_TEST_EARNINGS));
        const s = document.getElementById('earningsSearchInput');
        if (s) s.value = '';
        const c = document.getElementById('earningsCourseFilter');
        if (c) c.value = 'all';
        const t = document.getElementById('earningsTutorFilter');
        if (t) t.value = 'all';
        const st = document.getElementById('earningsStatusFilter');
        if (st) st.value = 'all';

        ghInitEarningsFilters();
        ghRenderEarnings();
        ghShowToast('Earnings test data reset successfully!');
    }

    function ghToggleMobileMenu() {
        const sidebar = document.getElementById('gh-sidebar');
        if (!sidebar) return;
        sidebar.style.display = (sidebar.style.display === 'flex') ? 'none' : 'flex';
    }

    function ghShowToast(msg) {
        const container = document.getElementById('ghToastContainer');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = 'gh-toast';
        toast.innerHTML = `
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#A7F3D0" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="16 10 11 15 8 12"></polyline>
            </svg>
            <span>${msg}</span>
        `;
        container.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(10px)';
            toast.style.transition = 'all 0.25s ease';
            setTimeout(() => toast.remove(), 250);
        }, 3000);
    }

    document.addEventListener('DOMContentLoaded', function() {
        ghInitEarningsFilters();
        ghRenderEarnings();
    });
    if (document.readyState === 'interactive' || document.readyState === 'complete') {
        ghInitEarningsFilters();
        ghRenderEarnings();
    }
    </script>
    <?php
    return ob_get_clean();
}
