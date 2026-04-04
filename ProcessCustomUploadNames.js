$(document).ready(function() {

    $('#RenameRules .Inputfields').not('.ui-helper-clearfix').sortable({ axis: "y", handle: ".cun-header" });

    var ruleHeaderHtml = function(num) {
        return '<li class="Inputfield cun-header-wrap" style="width:100%">' +
            '<div class="cun-header">' +
                '<i class="fa fa-arrows InputfieldRepeaterDrag" title="Drag to reorder"></i>' +
                '<span class="cun-rule-num">Rule ' + num + '</span>' +
                '<span class="cun-header-actions">' +
                    '<i class="fa fa-trash-o InputfieldRepeaterTrash deleterow" title="Delete rule"></i>' +
                '</span>' +
            '</div>' +
        '</li>';
    };

    $('#RenameRules .InputfieldWrapper').each(function(i) {
        $(this).find('> .Inputfields').prepend(ruleHeaderHtml(i + 1));
    });

    // Update rule header labels with number and filename format
    var updateRuleLabels = function() {
        $('#RenameRules .InputfieldWrapper').each(function(i) {
            var format = $(this).find('input[name=filenameFormat]').val();
            var label = 'Rule ' + (i + 1);
            if(format) label += ': ' + format;
            $(this).find('.cun-rule-num').text(label);
        });
    };

    updateRuleLabels();
    $('#RenameRules .Inputfields').not('.ui-helper-clearfix').on('sortstop', updateRuleLabels);
    $(document).on('change keyup', 'input[name=filenameFormat]', updateRuleLabels);


    // Add an "Add another rule" button to the Rename Rules container
    $('#RenameRules').after('<button class="ui-button ui-widget ui-corner-all ui-state-default" id="addRule"><span class="ui-button-text">Add another rule</span></button>');

    // Handle what happens on click of our new button
    var addRule = function(e) {
        e.preventDefault();
        $(this).toggleClass('ui-state-active');
        var options = { sortable: false };
        var ruleCount = $('#RenameRules ul.Inputfields ul.Inputfields').length;
        var newRow = $('<li class="Inputfield InputfieldWrapper InputfieldColumnWidthFirst">').load('?addRule=' + ruleCount, function() {
            $(newRow).find('> .Inputfields').prepend(ruleHeaderHtml(ruleCount + 1));
            $(newRow).find(".InputfieldAsmSelect select[multiple=multiple]").asmSelect(options);
            $(".InputfieldPageListSelectMultipleData").each(function() {
                InputfieldPageListSelectMultiple.init($(this));
            });
        });
        $('.Inputfields').not('.ui-helper-clearfix').append(newRow);

    };

    $(document).on('click', '#addRule', addRule);


    // Handle click of the delete button
    $(document).on('click', '.deleterow', function(e) {
        e.stopPropagation();
        e.preventDefault();
        var $rule = $(this).closest('.InputfieldWrapper');
        $rule.slideUp(200, function() {
            $(this).remove();
            updateRuleLabels();
        });
    });

    // Takes over from normal submit to store our categories in an array and then submit as normal
    $('#Inputfield_submit_save_module, #Inputfield_submit').click(function(e) {
        if($('#RenameRules').length) {
            // A variable to store the CSV data in
            var data = [];
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

            if (data.length > 0) {
                $('#Inputfield_ruleData').val(JSON.stringify(data));
            } else {
                $('#Inputfield_ruleData').val('');
            }
        }
    });

});
