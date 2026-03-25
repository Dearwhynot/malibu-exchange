<?php
/*
Template Name: Dashboard
*/
malibu_exchange_require_login();
get_header();
?>
<div class="me-app-shell">
    <?php get_template_part('sidebar'); ?>
    <div class="me-main">
        <?php get_template_part('topbar'); ?>
        <main class="me-content">
            <section class="me-grid me-grid--stats">
                <?php
                $stats = [
                    ['label' => 'Today orders', 'value' => '24'],
                    ['label' => 'Pending', 'value' => '7'],
                    ['label' => 'THB payout', 'value' => '฿ 182,450'],
                    ['label' => 'Bot uptime', 'value' => '99.9%'],
                ];
                foreach ($stats as $item) {
                    get_template_part('templates/widgets/stat-card', null, $item);
                }
                ?>
            </section>

            <section class="me-grid me-grid--2">
                <div class="me-card">
                    <h2>Recent exchange requests</h2>
                    <table class="me-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Client</th>
                                <th>Route</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>#2081</td><td>Ivan</td><td>RUB → THB</td><td><span class="me-badge me-badge--ok">Ready</span></td></tr>
                            <tr><td>#2080</td><td>Anna</td><td>RUB → THB</td><td><span class="me-badge me-badge--wait">Pending</span></td></tr>
                            <tr><td>#2079</td><td>Max</td><td>USD → THB</td><td><span class="me-badge">Review</span></td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="me-card">
                    <h2>Notes</h2>
                    <ul class="me-list">
                        <li>Keep rates editable manually.</li>
                        <li>Telegram bot remains the operator entry point.</li>
                        <li>Theme is intentionally lightweight: no heavy framework, no visual clutter.</li>
                        <li>Visual mood: Thailand, Russia, surf, sun, beach, premium backoffice.</li>
                    </ul>
                </div>
            </section>
        </main>
    </div>
</div>
<?php get_footer(); ?>
