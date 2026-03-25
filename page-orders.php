<?php
/*
Template Name: Orders
*/
malibu_exchange_require_login();
get_header();
?>
<div class="me-app-shell">
    <?php get_template_part('sidebar'); ?>
    <div class="me-main">
        <?php get_template_part('topbar'); ?>
        <main class="me-content">
            <div class="me-card">
                <h2>Orders</h2>
                <p>This is a starter page. Replace the demo table with your real WordPress, AJAX or custom table data source.</p>
                <table class="me-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Amount</th>
                            <th>Rate</th>
                            <th>Result</th>
                            <th>Manager</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>#2081</td><td>250,000 RUB</td><td>0.4210</td><td>105,250 THB</td><td>Kate</td></tr>
                        <tr><td>#2080</td><td>1,800 USD</td><td>35.90</td><td>64,620 THB</td><td>Alex</td></tr>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>
<?php get_footer(); ?>
