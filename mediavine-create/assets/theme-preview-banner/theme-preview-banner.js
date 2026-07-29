(function () {
	var select = document.getElementById('mv-preview-bar-select');
	if (!select) {
		return;
	}

	// Get the card hash: preserve existing hash, or find the first card on the page.
	function getCardHash() {
		var existing = window.location.hash;
		if (existing && existing.indexOf('mv-creation') !== -1) {
			return existing;
		}
		var card = document.querySelector('[id^="mv-creation-"]');
		return card ? '#' + card.id : '';
	}

	select.addEventListener('change', function () {
		if (this.value) {
			window.location.href = this.value + getCardHash();
		}
	});
})();
