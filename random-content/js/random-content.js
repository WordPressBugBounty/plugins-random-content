document.addEventListener('DOMContentLoaded', function () {
	var placeholders = document.querySelectorAll('.rc-placeholder');

	placeholders.forEach(function (el) {
		var group = el.getAttribute('data-rc-group') || '';
		var num = el.getAttribute('data-rc-num') || 1;
		var field = el.getAttribute('data-rc-field') || 'id';

		var url = rcData.restUrl +
			'?group=' + encodeURIComponent(group) +
			'&num_posts=' + encodeURIComponent(num) +
			'&field=' + encodeURIComponent(field) +
			'&_=' + Date.now();

		fetch(url, {
			headers: { 'X-WP-Nonce': rcData.nonce }
		})
			.then(function (response) { return response.json(); })
			.then(function (data) {
				if (data.html) {
					el.innerHTML = data.html;
				}
			});
	});
});
