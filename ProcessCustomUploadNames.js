$(document).ready(function() {
	// Add an "Add rule" button to the Rename Rules container
	$('#RenameRules .InputfieldContent:first').append('<button class="ui-button ui-state-default" id="addRule" style="display: block; clear: left;">Add another rule</button>');

	// Handle what happens on click of our new button
	var addRule = function(e) {
		e.preventDefault();
		$(this).toggleClass('ui-state-active');
		var newRow = $('<li class="Inputfield InputfieldWrapper InputfieldColumnWidthFirst">').load('?addRule=' + ($('#RenameRules ul.Inputfields ul.Inputfields').length));
		$(this).prev('ul:first').append(newRow);
		var options = { sortable: false };
		// ugly hack to wait for new select fields to be ready before converting them to ASM fields. This seems to work most of the time, but still not 100% ?
		setTimeout(function(){
			$(newRow).find(".InputfieldAsmSelect select[multiple=multiple]").asmSelect(options);
		},200);
	};

    if ($.isFunction($(document).on)) {
		$(document).on('click', '#addRule', addRule);
    }
    else {
		$('#addRule').live('click', addRule);
    }


	// Adds the number to each row of rename fields
	$('#RenameRules ul.Inputfields ul.Inputfields').each(function(i) {
		$(this).find('li label').each(function() {
			$(this).html($(this).html() + ' #' + (i+1));
		});
	});


	// Append a delete button to the end of every category row
	var deleteButton = '<li style="float:right"><button class="ui-button ui-state-default deleterow">Delete</button></li>';
	$("#RenameRules ul.Inputfields ul.Inputfields").append(deleteButton);
	// Handle click of the delete button
	var deleteRow = function(e){
		e.preventDefault();
		$(this).toggleClass('ui-state-active');
		$(this).parent().parent().remove();
	}

    if ($.isFunction($(document).on)) {
	    $(document).on('click', '.deleterow', deleteRow);
    } else {
		$('.deleterow').live('click', deleterow);
	}

	// Takes over from normal submit to store our categories in an array and then submit as normal
	$('#Inputfield_submit_save_module, #Inputfield_submit').click(function(e) {
		if ($('#RenameRules').length) {
			// A variable to store the CSV data in
			var data = new Array();
			// Iterate through the rows of rename rules
			$('#RenameRules ul.Inputfields ul.Inputfields').each(function(i) {
				// If the filename format field for the row isn't empty, add the row to our data variable
				if ($(this).find('input[name=filenameFormat]').val() != '') {
					data[i] = {};
					data[i]['enabledFields'] = $(this).find('select[id=Inputfield_enabledFields]').val();
					data[i]['enabledTemplates'] = $(this).find('select[id=Inputfield_enabledTemplates]').val();
					if($(this).find('input[id=enabledPages'+i+']').length !== 0) data[i]['enabledPages'] = $(this).find('input[id=enabledPages'+i+']').val().split(",");
					data[i]['fileExtensions'] = $(this).find('input[name=fileExtensions]').val();
					data[i]['filenameFormat'] = $(this).find('input[name=filenameFormat]').val();
					data[i]['filenameLength'] = $(this).find('input[name=filenameLength]').val();
				}
			});

			if (getObjectSize(data) > 0) {
				$('#Inputfield_ruleData').val(JSON.stringify(data));
			} else {
				$('#Inputfield_ruleData').val('');
			}
		}
	});

});


// Gets the number of elements in an object. This is for older browsers. In newer ones you can just do: Object.keys(obj.Data).length
var getObjectSize = function(obj) {
    var len = 0, key;
    for (key in obj) {
        if (obj.hasOwnProperty(key)) len++;
    }
    return len;
};

