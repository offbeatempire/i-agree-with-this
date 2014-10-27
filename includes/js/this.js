var iawt = jQuery;
var ucc_iawt_ajax_init = null;
var ucc_iawt_ajax_request = null;

iawt(document).ready(function() {
	ajaxurl = ucc_iawt['ajaxurl'];
	unthis = ucc_iawt['unthis'];

	if (ucc_iawt_ajax_init)
		ucc_iawt_ajax_init.abort();

	var states = [];
	iawt('input.ucc-iawt-comment').each(function(index) {
		states[states.length] = iawt(this).val();
	});

	var data = {
		'action': 'ucc_iawt_init',
		'ucc_iawt_comments': states
	}

	ucc_iawt_ajax_init = iawt.post(ajaxurl, data, function(response) {
		var obj = iawt.parseJSON(response);
		iawt.each(obj, function(key, value) {
			if (value) {
				iawt('#comment-' + key).find('input.ucc-iawt-mode').first().val('delete');
				iawt('#comment-' + key).find('input.ucc-iawt-this').first().val(unthis);
			}
		});	
	});

	iawt('.ucc-iawt-this').on('click', function(event) {
		event.preventDefault();

		inline = iawt(event.target).parent().parent();
		comment = inline.find('input.ucc-iawt-comment').val();
		nonce = inline.find('input.ucc-iawt-nonce').val();
		mode = inline.find('input.ucc-iawt-mode').val();

		if (ucc_iawt_ajax_request)
			ucc_iawt_ajax_request.abort();

		var data = {
			'action': 'ucc_iawt_this',
			'ucc_iawt_comment': comment,
			'ucc_iawt_nonce': nonce,
			'ucc_iawt_mode': mode
		}

		ucc_iawt_ajax_request = iawt.post(ajaxurl, data, function(response) {
			var obj = iawt.parseJSON(response);
			var newform = obj.newform;

			inline.html(newform);
		});
		return false;
	});
});
