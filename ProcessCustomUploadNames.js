$(document).ready(function() {

    $('#RenameRules .Inputfields').not('.ui-helper-clearfix').sortable({ axis: "y" });

    $('#RenameRules .InputfieldWrapper').each(function() {
        $(this).prepend('<label style="cursor:move;" class="cun-header InputfieldHeader" for="">&nbsp;<span class="ui-icon ui-icon-trash InputfieldRepeaterTrash deleterow" style="display: block;float:right;cursor:pointer;">Delete</span><span class="ui-icon ui-icon-arrowthick-2-n-s InputfieldRepeaterDrag"></span></label>');
        $(this).css("margin-top", "20px");
        // this was breaking theming etc in Tracy ACE editor and it also doesn't seem like it is needed anymore?
        //$('script:not([src^=http])').remove(); // removes inline script that was causing duplication of Add button for the Enabled Pages setting when drag/drop ordering.
    });


    // Add an "Add another rule" button to the Rename Rules container
    $('#RenameRules').after('<br /><button class="ui-button ui-widget ui-corner-all ui-state-default" id="addRule" style="display: block; margin:20px 0; clear: left;"><span class="ui-button-text">Add another rule</span></button><br />');

    // Handle what happens on click of our new button
    var addRule = function(e) {
        e.preventDefault();
        $(this).toggleClass('ui-state-active');
        var options = { sortable: false };
        var newRow = $('<li class="Inputfield InputfieldWrapper InputfieldColumnWidthFirst" style="margin-top:20px;">').load('?addRule=' + ($('#RenameRules ul.Inputfields ul.Inputfields').length), function() {
            $(newRow).prepend('<label style="cursor:move;" class="cun-header InputfieldHeader" for="">&nbsp;<span class="ui-icon ui-icon-trash InputfieldRepeaterTrash deleterow" style="display: block;float:right;cursor:pointer;">Delete</span><span class="ui-icon ui-icon-arrowthick-2-n-s InputfieldRepeaterDrag"></span></label>');
            $(newRow).find(".InputfieldAsmSelect select[multiple=multiple]").asmSelect(options);
            $(".InputfieldPageListSelectMultipleData").each(function() {
                InputfieldPageListSelectMultiple.init($(this));
            });
        });
        $('.Inputfields').not('.ui-helper-clearfix').append(newRow);

    };

    if($.isFunction($(document).on)) {
        $('#addRule').on('click', addRule);
    }
    else {
        $('#addRule').live('click', addRule);
    }


    // Handle click of the delete button
    var deleteRow = function(e){
        e.stopPropagation();
        e.preventDefault();
        $(this).toggleClass('ui-state-active');
        $(this).parent().parent().remove();
    }

    if($.isFunction($(document).on)) {
        $('.deleterow').on('click', deleteRow);
    } else {
        $('.deleterow').live('click', deleterow);
    }

    // Takes over from normal submit to store our categories in an array and then submit as normal
    $('#Inputfield_submit_save_module, #Inputfield_submit').click(function(e) {
        if($('#RenameRules').length) {
            // A variable to store the CSV data in
            var data = new Array();
            // Iterate through the rows of rename rules
            $('#RenameRules ul.Inputfields ul.Inputfields').each(function(i) {
                data[i] = {};
                data[i]['tempDisabled'] = $(this).find('input[name=tempDisabled]').is(':checked') ? 1 : 0;
                data[i]['enabledFields'] = $(this).find('select[id=Inputfield_enabledFields]').val();
                data[i]['enabledTemplates'] = $(this).find('select[id=Inputfield_enabledTemplates]').val();
                data[i]['enabledPages'] = $(this).find('input[id^=enabledPages]').val().split(",");
                data[i]['fileExtensions'] = $(this).find('input[name=fileExtensions]').val();
                data[i]['filenameFormat'] = $(this).find('input[name=filenameFormat]').val();
                data[i]['filenameLength'] = $(this).find('input[name=filenameLength]').val();
                data[i]['renameOnSave'] = $(this).find('input[name=renameOnSave]').is(':checked') ? 1 : 0;
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

