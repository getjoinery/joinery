
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
	<form id="contactForm1" action="/none" method="post">
		<p id="results"></p>
	</form>

<script type="text/javascript">
	// this is the id of the form
	$( document ).ready(function() {

		var form = $(this);
		var actionUrl = 'https://jeremytunnell.net/api/v1/posts?published=true';
		var pub_key = 'public_fn4ini750e8pkjwq';
		var secret_key = 'test1';
		
		$.ajax({
			type: "GET",
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
				//console.log(data);
				var resulthtml = '';
				data['data'].forEach(function(result) { 
					resulthtml += result['pst_title']; 
					resulthtml += '<br>';
				})
				$("#results").html(resulthtml);
			  }
              
			}
		});
		
	}); 
	</script>
