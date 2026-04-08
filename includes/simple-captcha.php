<?php
// inc/simple-captcha.php
if (!defined('ABSPATH')) exit;

/**
 * !!! ОБЯЗАТЕЛЬНО !!! Поменяйте секрет на длинную случайную строку (32+ символов).
 * Можно вынести в wp-config.php, а здесь только читать.
 */
if (!defined('MC_CAPTCHA_SECRET')) {
    define('MC_CAPTCHA_SECRET', 'HIGc76w66457b^%S^&drfwvgejrf7^fg6$%#@!@#RDFG^%$#@!@#RFG^%$#@!@#RFG');
}

/**
 * Простая капча вида "Сколько будет: 7 + 12?"
 * - Stateless: ответ не храним в БД, всё зашито в токене + HMAC.
 * - Анти-replay: одноразовый rid помечаем как использованный через transient.
 */
final class MC_Simple_Captcha
{
    // Срок жизни примера (сек)
    const TTL = 180;

    /** Сгенерировать задание: [вопрос, токен] */
    public static function generate(): array
    {
        $a = wp_rand(5, 19);
        $b = wp_rand(2, 13);

        // Иногда делаем вычитание, но без отрицательных
        $ops = ['+', '+', '+', '-']; // чаще сложение
        $op  = $ops[array_rand($ops)];
        if ($op === '-' && $b > $a) {
            [$a, $b] = [$b, $a];
        }

        $payload = [
            'a'   => $a,
            'b'   => $b,
            'op'  => $op,
            'ts'  => time(),
            'rid' => bin2hex(random_bytes(6)), // одноразовый id
        ];

        $base = base64_encode(wp_json_encode($payload));
        $sig  = hash_hmac('sha256', $base, MC_CAPTCHA_SECRET);
        $token = $base . '.' . $sig;

        $q = "{$a} {$op} {$b}";
        return [$q, $token];
    }

    /** Вывести HTML-поля капчи */
    public static function render_field(string $label = 'Решите', bool $withRefresh = true): void
    {
        [$q, $token] = self::generate();
?>
        <div class="mc-captcha">
            <label class="mc-captcha__label" for="mc_captcha_answer">
                <?php echo esc_html($label); ?>
            </label>
            <div class="mc-captcha__question"><?php echo esc_html($q); ?> = ?</div>
            <div class="mc-captcha__row">
                <input type="text"
                    id="mc_captcha_answer"
                    class="mc-captcha__input form-control"
                    name="mc_captcha_answer"
                    inputmode="numeric"
                    pattern="\d+"
                    required
                    autocomplete="off"
                    placeholder="Введите ответ"
                    >
                <input type="hidden" name="mc_captcha_token" value="<?php echo esc_attr($token); ?>">
                <?php if ($withRefresh): ?>
                    <button type="button" class="mc-captcha-refresh btn btn-light">Обновить задачу</button>
                <?php endif; ?>
            </div>
            <small class="mc-captcha__note">Задание действительно <?php echo (int) self::TTL; ?> секунд.</small>
        </div>
<?php
    }

    /** Проверить из $_POST — true / WP_Error */
    public static function verify_from_post()
    {
        $answer = isset($_POST['mc_captcha_answer']) ? trim((string)$_POST['mc_captcha_answer']) : '';
        $token  = isset($_POST['mc_captcha_token'])  ? (string)$_POST['mc_captcha_token'] : '';
        return self::verify($token, $answer);
    }

    /** Основная проверка — true / WP_Error */
    public static function verify(string $token, string $answer)
    {
        if ($token === '' || $answer === '') {
            return new WP_Error('captcha_empty', 'Подтвердите, что вы человек (заполните капчу).');
        }

        // распаковываем токен
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return new WP_Error('captcha_bad', 'Неверный токен капчи.');
        }
        [$base, $sig] = $parts;

        // проверка подписи
        $calc = hash_hmac('sha256', $base, MC_CAPTCHA_SECRET);
        if (!hash_equals($calc, $sig)) {
            return new WP_Error('captcha_sig', 'Неверная подпись токена капчи.');
        }

        $json = base64_decode($base, true);
        if ($json === false) {
            return new WP_Error('captcha_decode', 'Данные капчи повреждены.');
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['a'], $data['b'], $data['op'], $data['ts'], $data['rid'])) {
            return new WP_Error('captcha_payload', 'Неверная структура данных капчи.');
        }

        // срок действия
        if ((time() - (int)$data['ts']) > self::TTL) {
            return new WP_Error('captcha_expired', 'Срок действия капчи истёк. Обновите задачу.');
        }

        // anti-replay: rid ещё не использовался?
        $rid = preg_replace('~[^a-f0-9]~', '', (string)$data['rid']);
        $used_key = 'mc_cu_' . $rid;
        if (get_transient($used_key)) {
            return new WP_Error('captcha_replay', 'Это задание уже использовано. Обновите его.');
        }

        // вычисляем правильный ответ
        $a = (int)$data['a'];
        $b = (int)$data['b'];
        $op = (string)$data['op'];
        $expected = ($op === '-') ? ($a - $b) : ($a + $b);

        // проверка ответа (только цифры)
        if (!preg_match('~^\d+$~', $answer)) {
            return new WP_Error('captcha_format', 'Ответ должен быть числом.');
        }
        if ((int)$answer !== $expected) {
            return new WP_Error('captcha_wrong', 'Неверный ответ на задание.');
        }

        // помечаем rid использованным (чтобы нельзя было переиспользовать токен)
        set_transient($used_key, 1, max(60, self::TTL));

        return true;
    }
}

// (необязательно но пусть будет может понадобится?) AJAX-обновление «Обновить пример»
// JSON: {question, token}
add_action('wp_ajax_nopriv_mc_captcha_new', function () {
    [$q, $t] = MC_Simple_Captcha::generate();
    wp_send_json_success(['question' => $q, 'token' => $t]);
});
add_action('wp_ajax_mc_captcha_new', function () {
    [$q, $t] = MC_Simple_Captcha::generate();
    wp_send_json_success(['question' => $q, 'token' => $t]);
});
