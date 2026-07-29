/**
 * Password reset admin notice interactions.
 *
 * Calls WordPress REST proxies only — never receives the Create Studio JWT.
 */
(function ($) {
	'use strict';

	var config = window.mvCreatePasswordResetNotice || {};
	var i18n = config.i18n || {};

	$(function () {
		$('.mv-create-request-password-reset').on('click', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var email = $btn.data('email');

			$btn.prop('disabled', true).text(i18n.sending || 'Sending...');

			$.ajax({
				url: config.requestPasswordResetUrl,
				method: 'POST',
				contentType: 'application/json',
				headers: {
					'X-WP-Nonce': config.nonce
				},
				data: JSON.stringify({ email: email }),
				success: function () {
					$btn.closest('.notice').html(
						'<p><strong>' +
							(i18n.success || 'Success!') +
							'</strong><br>' +
							(i18n.emailSentReload || 'Check your email for instructions. Reloading...') +
							'</p>'
					);
					setTimeout(function () {
						location.reload();
					}, 2000);
				},
				error: function (xhr) {
					var errorMsg =
						xhr.responseJSON && xhr.responseJSON.message
							? xhr.responseJSON.message
							: i18n.sendFailed || 'Failed to send email. Please try again.';
					window.alert(errorMsg);
					$btn.prop('disabled', false).text(i18n.sendEmail || 'Send Password Reset Email');
				}
			});
		});

		$('.mv-create-check-password').on('click', function (e) {
			e.preventDefault();
			var $link = $(this);
			var originalText = $link.text();

			$link.text(i18n.checking || 'Checking...');

			$.ajax({
				url: config.passwordStatusUrl,
				method: 'GET',
				headers: {
					'X-WP-Nonce': config.nonce
				},
				success: function (response) {
					if (response && response.password_set) {
						$link.closest('.notice').html(
							'<p><strong>' +
								(i18n.success || 'Success!') +
								'</strong><br>' +
								(i18n.passwordSetReload || 'Your password is set. Reloading...') +
								'</p>'
						);
						setTimeout(function () {
							location.reload();
						}, 1000);
						return;
					}

					window.alert(
						i18n.passwordNotSet ||
							'Password not yet set. Please check your email and follow the instructions.'
					);
					$link.text(originalText);
				},
				error: function (xhr) {
					if (xhr.status === 401 || xhr.status === 403) {
						window.alert(
							i18n.passwordNotSet ||
								'Password not yet set. Please check your email and follow the instructions.'
						);
					} else {
						window.alert(
							i18n.verifyFailed || 'Unable to verify password status. Please try again.'
						);
					}
					$link.text(originalText);
				}
			});
		});

		$('.mv-create-resend-password-reset').on('click', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var email = $btn.data('email');

			$btn.prop('disabled', true).text(i18n.sending || 'Sending...');

			$.ajax({
				url: config.requestPasswordResetUrl,
				method: 'POST',
				contentType: 'application/json',
				headers: {
					'X-WP-Nonce': config.nonce
				},
				data: JSON.stringify({ email: email }),
				success: function () {
					$btn.closest('.notice').html(
						'<p><strong>' +
							(i18n.success || 'Success!') +
							'</strong><br>' +
							(i18n.emailResentReload || 'Email resent! Check your inbox. Reloading...') +
							'</p>'
					);
					setTimeout(function () {
						location.reload();
					}, 2000);
				},
				error: function (xhr) {
					var errorMsg =
						xhr.responseJSON && xhr.responseJSON.message
							? xhr.responseJSON.message
							: i18n.sendFailed || 'Failed to send email. Please try again.';
					window.alert(errorMsg);
					$btn.prop('disabled', false).text(i18n.resend || "Didn't get an email? Resend");
				}
			});
		});

		var $resendBtn = $('.mv-create-resend-password-reset');
		var $timer = $('.mv-resend-timer');
		if ($resendBtn.length) {
			var timeRemaining = 300;
			var timerInterval = setInterval(function () {
				timeRemaining--;

				if (timeRemaining <= 0) {
					clearInterval(timerInterval);
					$resendBtn.show();
					$timer.html('');
				} else {
					var minutes = Math.floor(timeRemaining / 60);
					var seconds = timeRemaining % 60;
					seconds = seconds < 10 ? '0' + seconds : seconds;
					$timer.html((i18n.resendAvailableIn || 'Resend available in:') + ' ' + minutes + ':' + seconds);
				}
			}, 1000);
		}
	});
})(jQuery);
