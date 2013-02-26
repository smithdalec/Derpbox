// Auto-highlight the URL when user clicks inside the "share" text box
$("#share-url").click(function(){
    this.select();
});

// Activate Bootstrap tooltips
$("a").tooltip({
	'delay' : 300
});

// When user chooses a file to upload
$('#form_file').change(function() {
  var file = $('#form_file')[0]['files'][0];
  if (file) {
    var fileSize = 0;
    if (file.size > 1024 * 1024)
      fileSize = (Math.round(file.size * 100 / (1024 * 1024)) / 100).toString() + 'MB';
    else
      fileSize = (Math.round(file.size * 100 / 1024) / 100).toString() + 'KB';
  }
  $('#file-size').html(fileSize);
  if (!fileWithinLimit(file.size)) {
  	$('#upload-form .notice').addClass('alert alert-error').html('<strong>Error</strong>: File must must be 2 GB or smaller');
  	$('#file-upload-submit').attr('disabled', 'disabled');
  } else {
  	$('#upload-form .notice').hide();
  	$('#file-upload-submit').removeAttr('disabled');
  }
});

// When user clicks upload submit button, submit the form via ajax
$('#file-upload-submit').click(function() {
  $('#upload-progress-bar').show();

	var action = $('#upload-form').attr('action');
  var fd = new FormData($('#upload-form')[0]);
  var xhr = new XMLHttpRequest();

  xhr.upload.addEventListener("progress", uploadProgress, false);
  xhr.addEventListener("load", uploadComplete, false);
  xhr.addEventListener("error", uploadFailed, false);
  xhr.addEventListener("abort", uploadCanceled, false);
  xhr.open("POST", action);
  xhr.send(fd);
});

// XHR "progress" event callback
// Hook callback. Run throughout the upload process to calculate progress
function uploadProgress(evt) {
  if (evt.lengthComputable) {
    var percentComplete = Math.round(evt.loaded * 100 / evt.total);
    var percentStr = percentComplete.toString() + '%';
    $('#upload-progress-bar .bar').attr('style', 'width: ' + percentStr);
    $('#upload-progress-bar .percent').html(percentStr);
  }
}

// XHR "load" event callback
// When the server sends back a response, reload the page
function uploadComplete(evt) {
  location.reload();
}

// XHR "error" event callback
function uploadFailed(evt) {
  alert("There was an error attempting to upload the file.");
}

// XHR "abort" event callback
function uploadCanceled(evt) {
  alert("The upload has been canceled by the user or the browser dropped the connection.");
}


function fileWithinLimit(fileSize) {
	if (fileSize <= 2147483648) {
		return true;
	}
	return false;
}