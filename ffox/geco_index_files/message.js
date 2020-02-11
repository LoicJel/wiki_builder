// Au chargement
$(function() {
	$('.message_container').each(function(i, container) {
		_load($(container));
	});
	
	function _load($container) {
		var cookieName = $container.data('cookieName');
		if (!(Cookies.get(cookieName))) {
			// Chargement du message
			$container.show();
			_bind($container);
		}
	}
	
	function _bind($container) {
		var cookieName = $container.data('cookieName');
		var duration = $container.data('duration');

		$container.find('.message_close').on('click', function() {
			if (duration) {
				Cookies.set(cookieName, 'read', { expires: parseInt(duration) });
			} else {
				Cookies.set(cookieName, 'read');
			}
			$container.remove();
		});
	}
	
});