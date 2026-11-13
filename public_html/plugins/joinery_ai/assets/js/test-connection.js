/*
 * The AI assistant's "Test it" button (includes/test_connection_button.php):
 * sends one message to the endpoint this site would use and says what came back.
 *
 * @version 1.0
 */
(function () {
	var test = document.getElementById('joinery-ai-test');
	if (!test) return;
	test.addEventListener('click', function () {
		var out = document.getElementById('joinery-ai-test-result');
		out.textContent = 'Testing…';
		test.disabled = true;
		joineryApi.post('joinery_ai/test_connection', {}).then(function (data) {
			out.textContent = 'It answers — ' + data.model + ' replied in ' + data.ms + ' ms.';
			test.disabled = false;
		}).catch(function (e) {
			out.textContent = e.message || 'The test failed.';
			test.disabled = false;
		});
	});
})();
