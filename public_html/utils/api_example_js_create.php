
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
	<form id="contactForm1" action="/none" method="post">
		<p id="results"></p>
		<input type="text" name="usr_first_name">
		<input type="text" name="usr_last_name">
		<input type="text" name="usr_email">
		<input type="submit" id="submitbutton" value="Send Request" />
	</form>

<script type="text/javascript">
	// this is the id of the form
	$("#contactForm1").submit(function(e) {

		e.preventDefault(); // avoid to execute the actual submit of the form.

		var form = $(this);
		var actionUrl = 'https://jeremytunnell.net/api/v1/user';
		var pub_key = 'public_fn4ini750e8pkjwq';
		var secret_key = 'test1';
		
		$.ajax({
			type: "POST",
			headers: { 'public_key': pub_key, 'secret_key': secret_key },
			url: actionUrl,
			data: form.serialize(), // serializes the form's elements.
			success: function(data)
			{
			  if(data['errortype'] == 'TransactionError'){
				  $("#results").html(data['error']);
				  console.log(data);
			  }
			  else if(data['errortype'] == 'AuthenticationError'){
				  $("#results").html('There was an error.  Please contact the webmaster.');
				  console.log(data);
			  }
			  else{
				$("#submitbutton").attr('disabled', true);
				$("#results").html('Submission was successful.');
			  }
              
			}
		});
		
	}); 
	</script>
