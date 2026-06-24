<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$exchange_pairs     = function_exists( 'crm_company_exchange_pair_definitions' ) ? crm_company_exchange_pair_definitions() : [];
$fintech_providers  = function_exists( 'crm_company_fintech_provider_definitions' ) ? crm_company_fintech_provider_definitions() : [];
$company_modules    = function_exists( 'crm_company_module_definitions' ) ? crm_company_module_definitions() : [];
$rub_usdt_fixation_modes = function_exists( 'crm_company_rub_usdt_fixation_mode_definitions' ) ? crm_company_rub_usdt_fixation_mode_definitions() : [];
?>
<div class="modal fade" id="modal-company-settings" tabindex="-1"
     aria-labelledby="modal-company-settings-title" aria-hidden="true">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header clearfix text-left">
				<button aria-label="Закрыть" type="button" class="close" data-bs-dismiss="modal" aria-hidden="true">
					<i class="pg-icon">close</i>
				</button>
				<h5 class="modal-title" id="modal-company-settings-title">Настройки компании</h5>
				<p class="p-b-10 m-b-0">
					Root управляет доступностью направлений обмена и платёжных контуров компании из одного места.
				</p>
			</div>
			<div class="modal-body">
				<form id="form-company-settings" novalidate>
					<input type="hidden" id="cfs-company-id" value="0">

					<div class="form-group-attached">
						<div class="row">
							<div class="col-md-8">
								<div class="form-group form-group-default">
									<label>Компания</label>
									<input type="text" class="form-control" id="cfs-company-name" value="" readonly>
								</div>
							</div>
							<div class="col-md-4">
								<div class="form-group form-group-default">
									<label>ID / код</label>
									<input type="text" class="form-control" id="cfs-company-code" value="" readonly>
								</div>
							</div>
						</div>

						<div class="row">
							<div class="col-12">
								<div class="form-group form-group-default">
									<label>Направления обмена</label>
									<p class="hint-text small m-b-0">
										Выключенное направление не показывается в настройках коэффициентов и не должно принимать company-scoped действия.
									</p>
								</div>
							</div>
						</div>

						<div class="row">
							<?php foreach ( $exchange_pairs as $pair ) : ?>
								<?php $pair_slug = sanitize_html_class( strtolower( (string) $pair['code'] ) ); ?>
								<div class="col-md-6">
									<div class="form-group form-group-default">
										<label><?php echo esc_html( $pair['title'] ); ?></label>
										<div class="form-check complete m-t-5">
											<input type="checkbox"
											       id="cfs-pair-<?php echo esc_attr( $pair_slug ); ?>"
											       class="js-company-pair"
											       value="<?php echo esc_attr( $pair['code'] ); ?>">
											<label for="cfs-pair-<?php echo esc_attr( $pair_slug ); ?>">
												<?php echo esc_html( $pair['label'] ); ?>
											</label>
										</div>
										<p class="hint-text small m-b-0">
											<?php echo esc_html( (string) ( $pair['hint'] ?? '' ) ); ?>
										</p>
									</div>
								</div>
							<?php endforeach; ?>
						</div>

						<div class="row">
							<div class="col-12">
								<div class="form-group form-group-default">
									<label>Платёжные контуры</label>
									<p class="hint-text small m-b-0">
										Эти чекбоксы управляют доступными провайдерами для новых ордеров и company-scoped fintech-настроек.
									</p>
								</div>
							</div>
						</div>

						<div class="row">
							<?php foreach ( $fintech_providers as $provider ) : ?>
								<?php $provider_slug = sanitize_html_class( strtolower( (string) $provider['code'] ) ); ?>
								<div class="col-md-6">
									<div class="form-group form-group-default">
										<label><?php echo esc_html( $provider['title'] ); ?></label>
										<div class="form-check complete m-t-5">
											<input type="checkbox"
											       id="cfs-provider-<?php echo esc_attr( $provider_slug ); ?>"
											       class="js-company-provider"
											       value="<?php echo esc_attr( $provider['code'] ); ?>">
											<label for="cfs-provider-<?php echo esc_attr( $provider_slug ); ?>">Разрешить компании</label>
										</div>
										<p class="hint-text small m-b-0">
											<?php echo esc_html( (string) ( $provider['hint'] ?? '' ) ); ?>
										</p>
									</div>
								</div>
							<?php endforeach; ?>
						</div>

						<div class="row">
							<div class="col-12">
								<div class="form-group form-group-default">
									<label>RUB/USDT — фиксация базового курса</label>
									<select id="cfs-rub-usdt-fixation-mode" class="form-control">
										<?php foreach ( $rub_usdt_fixation_modes as $mode ) : ?>
											<option value="<?php echo esc_attr( (string) $mode['code'] ); ?>">
												<?php echo esc_html( (string) $mode['label'] ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="hint-text small m-b-0">
										Выбирает, можно ли фиксировать базовый курс USDT/RUB вручную из блока Rapira или этот курс должен приходить через сервисный Telegram-контур.
									</p>
								</div>
							</div>
						</div>

						<?php if ( ! empty( $company_modules ) ) : ?>
							<div class="row">
								<div class="col-12">
									<div class="form-group form-group-default">
										<label>Модули компании</label>
										<p class="hint-text small m-b-0">
											Root включает модуль для конкретной компании. Данные модуля остаются company-scoped и не удаляются при выключении.
										</p>
									</div>
								</div>
							</div>

							<div class="row">
								<?php foreach ( $company_modules as $module ) : ?>
									<?php $module_slug = sanitize_html_class( strtolower( (string) $module['code'] ) ); ?>
									<div class="col-md-6">
										<div class="form-group form-group-default">
											<label><?php echo esc_html( $module['title'] ); ?></label>
											<div class="form-check complete m-t-5">
												<input type="checkbox"
												       id="cfs-module-<?php echo esc_attr( $module_slug ); ?>"
												       class="js-company-module"
												       value="<?php echo esc_attr( $module['code'] ); ?>">
												<label for="cfs-module-<?php echo esc_attr( $module_slug ); ?>">Включить модуль</label>
											</div>
											<p class="hint-text small m-b-0">
												<?php echo esc_html( (string) ( $module['hint'] ?? '' ) ); ?>
											</p>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>

					<div class="alert alert-danger d-none m-t-10" id="cfs-error"></div>
				</form>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-default" data-bs-dismiss="modal">Отмена</button>
				<button type="button" class="btn btn-primary" id="btn-save-company-settings">
					<span class="btn-label">Сохранить</span>
					<i class="pg-icon spin d-none" id="btn-company-settings-spinner">refresh</i>
				</button>
			</div>
		</div>
	</div>
</div>
