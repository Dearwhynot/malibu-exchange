<?php
function malibu_exchange_page_title() {
    if (is_page()) {
        return get_the_title();
    }
    return 'Malibu Exchange';
}
