/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
swarm.testdefinition={

    supportedEncodings : [],
    supportedArguments : [],
    init: function(options) {
        $(document).on('click.disabled','.disabled',function(e) {
            e.preventDefault();
            e.stopPropagation();
        });
        swarm.testdefinition.supportedEncodings = [
            {label:swarm.t('URL Encoded'),value:'url'},
            {label:swarm.t('JSON Encoded'),value:'json'},
            {label:swarm.t('XML Encoded'),value:'xml'}
            ];
        swarm.testdefinition.supportedArguments = [
            {id:'change',description:swarm.t('the change number')},
            {id:'status',description:swarm.t('the status of the shelved change, shelved or committed')},
            {id:'review',description:swarm.t('the review identifier')},
            {id:'version',description:swarm.t('the version of the review')},
            {id:'reviewStatus',description:swarm.t('the Swarm status of the review: needsReview, needsRevision, archived, rejected, approved, approved:commit')},
            {id:'description',description:swarm.t('the change description of the change (request body only)')},
            {id:'test',description:swarm.t('the name of the test')},
            {id:'testRunId',description:swarm.t('the test run id')},
            {id:'projects', description:swarm.t('the project identifiers of projects that are part of the review')},
            {id:'branches', description:swarm.t('combined project and branch identifiers of branches that are part of the review')},
            {id:'branch', description:swarm.t('the branch identifiers of branches that are part of the review')},
            {id:'update', description:swarm.t('the update callback URL')},
            {id:'pass', description:swarm.t('the tests pass callback URL')},
            {id:'fail', description:swarm.t('the tests fail callback URL')}
            ];
        swarm.testdefinition.defineTemplates({});
        swarm.testdefinition.load();
        $(document).on('swarm-login', function (e) {
            swarm.testdefinition.load();
        });
        $('a').tooltip(); // Enables boostrap tooltip features
        $('#add-test-definition-button').on('click',function(e) {
            swarm.testdefinition.showTestDefinitionModal({
                title:swarm.te('Add Test Definition'),
                testdefinition: {encoding:"url", owners: [swarm.user.getAuthenticatedUser().id]},
                mutable:true
            });
        });
        $('#test-definition-modal-content').on('click.save','.btn-save', function(e) {
            e.preventDefault();
            var form = $(this).closest('form'),
                id = form.data('testdefinition-id'),
                url = '/api/v10/testdefinitions'+("" !== id ?("/"+id) :"");
            swarm.form.rest("" !== id ?'PUT' :'POST',url, form,
                // Callback(success handler)
                function(response, form) {
                    if (response.data) {
                        var savedMessage = $.templates(
                            '<div class="swarm-alert alert alert-success" id="inner-message">{{>message}}</div>'
                        ).render({message: swarm.te('Test Definition Saved')});
                        $('.modal-body .messages').append(savedMessage);
                        // Reload tabs
                        swarm.testdefinition.load();
                        // close the form on completion
                        setTimeout(function() {
                            form.find('button.close').click();
                        },500);
                    }
                },
                // Error node
                $('#test-definition-modal-content .modal-body')
            );
        });
        $('.test-definition-cells').on('click.test-definition','.link.test-definition-name a',function(e) {
            var link = $(this),
                cell = $(this).closest('.test-definition-cell');
           e.preventDefault();
           swarm.testdefinition.showTestDefinitionModal($.extend({title:link.text()},cell.data()));
        });
        $('.btn-search').on('click',function() {
            $('div.filter-all').remove();
            $('.test-definition-cell').toggleClass('filtered',true).each(function() {
                var searchTerm = $('.search .input-large').val().toLowerCase(),
                    testdefinition = $(this);
                if (testdefinition.find('a.name').text().toLowerCase().indexOf(searchTerm) !== -1 ||
                    testdefinition.find('.description').text().toLowerCase().indexOf(searchTerm) !== -1) {
                    $(this).toggleClass('filtered',false);
                }
            });
            if ($('.test-definition-cell:visible').length === 0) {
                $('.test-definition-cells').prepend($('<div>').addClass('filter-all').text(swarm.te('No matching test definitions')));
            }
        });
        $('.testdefinitions .search input').on(
            'keyup',
            function(e) {
                // early exit if not enter key
                var code = (e.keyCode || e.which);
                if (e.type === 'keyup' && code !== 13) {
                    return;
                }
                $('.btn-search').trigger('click');
            }
        );
        // Attach a listener to define request header fields dynamically in api format {'header-name':'header-value'}
        $('#test-definition-modal-content').on('keyup.headers','.control-group-request-header input', function(e) {
            var target   = $(e.target),
                value    = target.val().trim(),
                group    = target.closest('.control-group'),
                otherVal = group.find(target.hasClass('header-name')?'input.header-value':'input.header-name').val().trim(),
                header   = group.find('.request-header-pair');
            if (target.hasClass('header-name')) {
                if(!value) {
                    // Remove the real form field when the name becomes empty/blank
                    header.remove();
                } else if (!header.length) {
                    // Add a field to hold the head name/value pair when the first character is entered
                    group.find('.input-group-request-header')
                        .append($('<input>').prop('type','hidden').addClass('request-header-pair')
                        .attr('name','headers['+value+']').val(otherVal));
                } else {
                    // Keep the field name in line with the header name
                    header.attr('name', 'headers[' + value + ']');
                }
                target.prop('required', otherVal!=='');
                group.find('input.header-value').prop('required',value!=='');
                group.find('button.add-request-header').prop('disabled',value==='');
            } else {
                header.val(value);
                target.prop('required', otherVal!=='');
                group.find('input.header-name').prop('required',value!=='');
            }
            // Toggle the add link based on any empty inputs
            $('#test-definition-modal-content .control-group-add-header a')
                .toggleClass(
                    'disabled',
                    0 !== $('#test-definition-modal-content .control-group-request-headers')
                        .find('input.header-name, input.header-value').filter(function(){return !this.value;}).length
                );
        });
        // Attach a listener to the add header link
        $('#test-definition-modal-content').on('click.add-header','.control-group-add-header a', function(e) {
            e.preventDefault();
            var activeLink = $(e.target).not('.disabled');
            if ( activeLink.length ) {
                var controlGroupHeaders = activeLink.closest('.control-group-request-headers');
                // Indicate that there is at least one header
                controlGroupHeaders.find('.request-header-heading').show();
                // Replace the link with input fields and a new disabled link
                controlGroupHeaders.find('.control-group-add-header').replaceWith(
                    $.render.swarmTestDefinitionRequestHeader({key:'',prop:''}) +
                    $.render.swarmTestDefinitionAddHeader({},{disabled:true})
                );
                // Focus the new header name input
                controlGroupHeaders.find('.header-name').last().focus();
            }
        });
        // Attach a listener for the header delete buttons
        $('#test-definition-modal-content').on('click.delete','button.remove-request-header', function(e) {
            $(this).tooltip('hide');
            var controlGroupHeaders = $('#test-definition-modal-content .control-group-request-headers'),
                remainingHeaders = controlGroupHeaders.find('.control-group-request-header').length;
            // Remove this header
            $(this).closest('.control-group-request-header').remove();
            // When the last header is deleted, hide the headings
            if ( 0 === remainingHeaders ) {
                controlGroupHeaders.find('.request-header-heading').hide();
            }
            // Replace the add link based upon what is left
            controlGroupHeaders.find('.control-group-add-header').replaceWith(
                $.render.swarmTestDefinitionAddHeader(
                    {},
                    {
                        disabled:0 !== controlGroupHeaders.find('input.header-name, input.header-value')
                            .filter(function(){return !this.value;}).length,
                        noHeaders:0 === remainingHeaders
                    }
                )
            );
        });
        // Attach a listener for test definition deletion
        $('#test-definition-modal-content').on('click.delete','#btn-delete', function(e) {
            e.preventDefault();
            var button = $(this),
                form = button.closest('form'),
                url = '/api/v10/testdefinitions/' + form.data('testdefinition-id');

            setTimeout(function () {
                var confirm = swarm.tooltip.showConfirm(button, {
                    placement: 'top',
                    content: swarm.te('Delete this test definition?'),
                    classes: 'popover-test-definition',
                    buttons: [
                        '<button type="button" class="btn btn-primary btn-confirm">' + swarm.te('Delete') + '</button>',
                        '<button type="button" class="btn btn-cancel">' + swarm.te('Cancel') + '</button>'
                    ]
                });

                // wire up cancel button
                confirm.tip().on('click', '.btn-cancel', function () {
                    confirm.destroy();
                });

                confirm.tip().on('click', '.btn-confirm', function () {
                    // disable buttons when the delete is in progress
                    swarm.form.disableButton(confirm.tip().find('.btn-confirm'));
                    confirm.tip().find('.buttons .btn').prop('disabled', true);

                    swarm.form.restDelete(url, form,
                        // Callback(success handler)
                        function (response, form) {
                            confirm.destroy();
                            if (!response.error) {
                                var deletedMessage = $.templates(
                                    '<div class="swarm-alert alert alert-success" id="inner-message">{{>message}}</div>'
                                ).render({message: swarm.te('Test definition Deleted')});
                                $('.modal-body .messages').append(deletedMessage);
                                // Reload tabs
                                swarm.testdefinition.load();
                                // close the form on completion
                                setTimeout(function () {
                                    form.find('button.close').click();
                                }, 500);
                            } else {
                                var errorConfirm = swarm.tooltip.showConfirm(button, {
                                    placement: 'top',
                                    content: response.error,
                                    buttons: [
                                        '<button type="button" class="btn btn-primary">' + swarm.te('Ok') + '</button>'
                                    ]
                                });
                                errorConfirm.tip().on('click', '.btn', function () {
                                    errorConfirm.destroy();
                                });
                            }
                        },
                        // Error node
                        $('#test-definition-modal-content .modal-body')
                    );
                });
            }, 5);
        });
    },

    load: function() {
        $('.test-definitions .toolbar button').prop('disabled',true);
        $('.test-definition-cells').html(swarm.t('Loading...'));
        $.ajax({
            url: '/api/v10/testdefinitions',
            dataType: 'json',
            success: function(response) {
                var authenticatedUser = swarm.user.getAuthenticatedUser();
                var mine = $('div#my-test-definitions .test-definition-cells').html(authenticatedUser!==null?'': ('<div class="muted">'
                    + swarm.te("My test definitions will only be populated once you have logged in.")
                    + '<a href="/login/" onclick="swarm.user.login(); return false;"> ' + swarm.te("Log in") + '</a> '
                    + swarm.te("now.")));
                var all = $('div#all-test-definitions .test-definition-cells').html('');
                var ownerIconTitle = swarm.t('You are an owner');
                var testdefinition;
                $.each(response.data.testdefinitions.sort(
                    function(td1, td2) {
                        var td1lower = td1.name.toLowerCase(),
                            td2lower = td2.name.toLowerCase();
                            return td1lower < td2lower ? -1 : td1lower > td2lower ? 1 : 0;
                        }),
                    function() {
                        testdefinition = $($.render.swarmTestDefinitionSummary(
                            {testdefinition: this, ownerIconTitle: ownerIconTitle }));
                        var isSuper = $('body').hasClass('super');
                        $(testdefinition).data({'testdefinition': this, 'mutable': false});
                        if (null !== authenticatedUser &&
                            $([authenticatedUser.id].concat(Object.keys(authenticatedUser.groups || {})))
                                .filter(this.owners.map(function(owner) {
                                    return owner.replace(/^swarm-group-/,'');
                                })).length > 0) {
                            testdefinition.addClass('owner').data('mutable', true).appendTo(all).clone().appendTo(mine).data({
                                'testdefinition': this,
                                'mutable': true
                            });
                        } else if (this.shared || isSuper) {
                            testdefinition.appendTo(all).data({
                                'mutable': isSuper
                            });
                        }
                    }
                );
                $('.test-definition-cells').each(function() {
                    if (!this.hasChildNodes()) {
                        $(this).html(swarm.te('No test definitions to show.'));
                    }
                });
                $('.test-definition-cell .description').expander({slicePoint: 100});
                $('.test-definitions .toolbar button').prop('disabled',false);
            },
            errorHandled: true,
            error: function(response) {
                var content = $('<div>').addClass('messages');
                if ( response.responseJSON && response.responseJSON.messages ) {
                    // There was as Swarm response with more detail
                    $.each(response.responseJSON.messages, function () {
                        content.append($('<p>').addClass('lead muted text-error')
                            .text(this.text));
                    });
                } else {
                    content.append($('<p>').addClass('lead muted text-error')
                        .text(response.statusText));
                }
                $('.test-definitions').html(content);
            }
        });
    },

    /**
     * Display a modal form, prepopulated with any test definition data provided in data.testdefinition.
     * @param data - object
     */
    showTestDefinitionModal: function(data) {
        var content  = $('#test-definition-modal-content'),
            testdefinition = data.testdefinition,
            mutable  = data.mutable||false;
        content.html($.templates(swarm.testdefinition.formTemplate).render({
            title:data.title||swarm.t('Test definition'),
            mutable: mutable,
            testdefinition: testdefinition,
            supportedEncodings: swarm.testdefinition.supportedEncodings,
            supportedArguments: swarm.testdefinition.supportedArguments
        }));
        content.find('#owners').userMultiPicker({
            itemsContainer: content.find('.owners-list'),
            selected:        testdefinition.owners.filter(function(owner) { return -1 === owner.indexOf('swarm-group-');}),
            inputName:       'owners',
            enableGroups:    true,
            required:        true,
            groupInputName:  'owners',
            useGroupKeys:    true,
            excludeProjects: true,
            disabled:        content.find('#owners').hasClass('disabled'),
            selectedGroups:  testdefinition.owners.filter(function(owner) { return -1 !== owner.indexOf('swarm-group-');})
        });

        var testDefinitionId = testdefinition.id;

        if (!testDefinitionId) {
            $("#iterate").prop("checked", true);
        }

        $.ajax({
            url: '/api/v10/workflows?testdefinitions='+testDefinitionId,
            success: function(response) {
                if (response.data.workflows.length > 0) {

                    $('#affected-workflows').empty()
                        .append($('<a href="#workflow-list" data-toggle="collapse" data-target="#workflow-list">')
                            .addClass("workflow-count collapsed")
                            .append($('<i>').addClass("icon icon-chevron-down"))
                            .append($('<span>')
                                .addClass("collapse-title")
                                .text(
                                    swarm.tpe(
                                        '%d workflow uses this test definition',
                                        '%d workflows use this test definition',
                                        response.data.workflows.length
                                    ))))
                            .append($('<div>').attr('id', 'workflow-list').addClass('collapse')
                                .append(
                                    $.render.swarmTestDefinitionAffectedWorkflows({workflows:response.data.workflows})));
                } else {
                    $('#btn-delete').prop('disabled', !mutable || !testDefinitionId);
                    $('.tool-tip').removeAttr('title');
                    $('#affected-workflows p').removeAttr('class').text(
                        swarm.t(
                            'No workflows use this test definition'
                        ));
                }
            },
            errorHandled: true,
            error: function(response) {
                var content = $('<div>').addClass('messages');
                if ( response.responseJSON && response.responseJSON.messages ) {
                    // There was as Swarm response with more detail
                    $.each(response.responseJSON.messages, function () {
                        content.append($('<p>').addClass('lead muted text-error')
                            .text(this.text));
                    });
                } else {
                    content.append($('<p>').addClass('lead muted text-error')
                        .text(response.statusText));
                }
                $('#affected-workflows').html(content);
            }
        });
    },

    testDefinitionDropdown: function(options) {
        function addEvents(localStorageKey, prefix) {
            function clearTestDefinitionListStyling(list) {
                list.find("a.btn-filter").each(function() {
                    this.innerHTML=$(this).data('short-label');
                    $(this).show();
                });
            }

            $('.' + prefix + '-testdefinition-filter').on('click', '.input-filter', function(e) {
                e.stopPropagation();
            });

            $('.' + prefix + '-testdefinition-filter .input-filter').each(function() {
                var $input = $(this),
                    testDefinitionControl = $('#' + prefix + '-testdefinition-control'),
                    list = testDefinitionControl.find('.dropdown-menu li.list-item');

                $input.on('input change', function () {
                    $input.siblings('.clear').hide();

                    if (!$input.val()) {
                        // if value is empty - show all and clear styling
                        clearTestDefinitionListStyling(list, testDefinitionControl);
                    } else {
                        $input.siblings('.clear').show();
                        list.find("a.btn-filter").each(function() {
                            $(this).hide();
                        });
                        list.find("a.btn-filter:dataLabelContains('" + $input.val() + "')").each(function() {
                            var testdefinitionName = $(this).data('short-label');
                            var reg = new RegExp($input.val(), 'gi');
                            this.innerHTML=testdefinitionName.replace(reg, "<b>$&</b>");
                            $(this).show();
                        });
                    }

                }).change();

                $input.siblings('.clear').on('click', function (e) {
                    $input.val('').data('filter-value', '').change();
                    e.stopPropagation();
                    clearTestDefinitionListStyling(list, testDefinitionControl);
                });

                // handling 'enter' and 'esc' keys and arrow-down event on the input box
                $input.on('keydown', function(e) {
                    var listItems = testDefinitionControl.find('.dropdown-menu');
                    if(e.keyCode === 13) {
                        $(document).trigger('click.dropdown.data-api');
                        e.preventDefault();
                    } else if(e.keyCode === 27) {
                        $input.val('');
                        $(document).trigger('click.dropdown.data-api');
                        e.preventDefault();
                    } else if (e.keyCode === 40) {
                        e.stopImmediatePropagation();

                        testDefinitionControl.find('ul.dropdown-menu').children().each(function(){
                            $(this).removeClass('active');
                        });
                        var itemID = listItems.find('li.list-item a:visible').first().closest('li').attr('id');
                        $('#' + itemID).find('a').focus().addClass('active');
                    }

                });

                // wire up navigation - as default navigation doesnt like hidden elements we need to find visible ones ourselves
                testDefinitionControl.find('ul.dropdown-menu').on('keydown', function(e){
                    if (e.keyCode === 40) {
                        e.stopImmediatePropagation();
                        e.preventDefault();
                        // find current visible element and find id of a next visible element
                        var nextElementID = $(document.activeElement).closest('li').nextAll('li').find('a:visible,input').first().closest('li').attr('id');
                        if (nextElementID) {
                            $(document.activeElement).removeClass('active');
                            $('#' + prefix + '-' + nextElementID).find('a,input').focus().addClass('active');
                        }

                    } else if (e.keyCode === 38) {
                        e.stopImmediatePropagation();
                        e.preventDefault();
                        // find current visible element and find id of a previous visible element
                        var previousElementID = $(document.activeElement).closest('li').prevAll('li').children('a:visible,input').first().closest('li').attr('id');
                        if (previousElementID) {
                            $(document.activeElement).removeClass('active');
                            $('#' + prefix + '-' + previousElementID).find('a,input').focus().addClass('active');
                        } else {
                            // if we have not found an element at this level - try to go one higher
                            previousElementID = $(document.activeElement).closest('ul').prevAll('li').children('a:visible,input').first().closest('li').attr('id');
                            $(document.activeElement).removeClass('active');
                            $('#' + prefix + '-' + previousElementID).find('a,input').focus().addClass('active');
                        }
                    }
                });

                testDefinitionControl.find('.dropdown-menu li:has(> a) a').on('click', function(){
                    localStorage.setItem(localStorageKey, $(this).parent().attr('id'));
                    clearTestDefinitionListStyling(list, testDefinitionControl);
                    var idElement = $("#" + localStorage.getItem(localStorageKey) + " a");
                    $input.val(idElement.data('filter-value')).change();
                    $('#' + prefix + '-testdefinition').val(localStorage.getItem(localStorageKey).replace(prefix + '-', ''));
                    // if input is empty set filter value to groups default
                    var testdefinitionValue = !$input.val() ? testDefinitionControl.find('.default') : idElement;
                    if (!$(testdefinitionValue).closest('.dropdown-menu').length) {
                        $(testdefinitionValue).toggleClass('active');
                    } else if ($(testdefinitionValue).is('input')) {
                        $(testdefinitionValue).data('filter-value', $(testdefinitionValue).val());
                        testDefinitionControl.find('.text').text($(testdefinitionValue).val());
                    } else {
                        $(testdefinitionValue).addClass('active');
                        testDefinitionControl.find('.text').text($(testdefinitionValue).data('short-label') || $(testdefinitionValue).html());
                        testDefinitionControl.find('.input-filter').siblings('.clear').trigger('click');
                    }
                });
            });
        }

        function defineTemplates() {
            swarm.testdefinition.dropdown =
                '<div id="{{:prefix}}-testdefinition-control" class="" data-filter-key="testdefinition">\n'+
                '    <button id="{{:prefix}}-btn-testdefinition" type="button" class="btn btn-testdefinition dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" title="">\n'+
                '        <span class="text testdefinition-label"></span>\n' +
                '        <span class="caret"></span>\n'+
                '    </button>\n'+
                '    <ul class="dropdown-menu testdefinition-filter" aria-label="' + swarm.te("Test definitions") +'">\n'+
                '        <li id="{{:prefix}}-no-testdefinition">\n'+
                '            <a href="#" class="btn-filter default" data-filter-value="{{:noneSelected}}">{{:noneSelected}}</a>\n'+
                '        </li>\n'+
                '        <li class="divider"></li>\n'+
                '        <li class="{{:prefix}}-testdefinition-filter">\n'+
                '            <input class="input-filter" data-filter-key="testdefinition" type="text" placeholder="'+swarm.te("Test definition")+'">\n'+
                '            <button type="button" class="clear" style=""><span>x</span></button>\n'+
                '        </li>\n'+
                '        <li class="divider"></li>\n'+
                '    </ul>\n'+
                '</div>';
            swarm.testdefinition.dropdownmenuitem =
                '<li class="list-item" id="{{:prefix}}-{{:testdefinition.id}}">\n'+
                '    <a href="#" class="btn-filter btn-filter-testdefinition" data-filter-key="testdefinition" data-filter-value="{{:prefix}}-{{:testdefinition.id}}" data-short-label="{{:testdefinition.name}}">{{:testdefinition.name}}</a>\n'+
                '</li>';
        }

        function init() {
            var parent = options.parent,
                prefix = options.prefix,
                testdefinitions = options.testdefinitions,
                currentTestDefinitionId = options.currentTestDefinitionId || -1,
                localStorageKey = options.localStorageKey,
                noneSelected = options.noneSelected || swarm.te('No test definition');
            defineTemplates();

            var testDefinitionDropdown       = $($.templates(swarm.testdefinition.dropdown).render({prefix: prefix, noneSelected: noneSelected})),
                authenticatedUser      = swarm.user.getAuthenticatedUser(),
                isSuper                = $('body').hasClass('super'),
                menu                   = testDefinitionDropdown.find('.dropdown-menu'),
                testDefinitionControlClasses = parent.find('#' + prefix + '-testdefinition-control').attr("class");

            parent.find('#' + prefix + '-testdefinition-control').replaceWith(testDefinitionDropdown);
            // preserve classes on the element being replaced
            testDefinitionDropdown.addClass(testDefinitionControlClasses);
            $.each(testdefinitions.sort(
                function(w1, w2) {
                    var w1lower = w1.name.toLowerCase(),
                        w2lower = w2.name.toLowerCase();
                    return w1lower < w2lower ? -1 : w1lower > w2lower ? 1 : 0;
                }),
                function(index, value) {
                    // We want to include: All for super, any shared, any owned and also any that may no longer be
                    // shared or owned by where at the time the choice was made before
                    if (isSuper || value.shared || (parseInt(currentTestDefinitionId, 10) === value.id) ||(null !== authenticatedUser &&
                        $([authenticatedUser.id].concat(Object.keys(authenticatedUser.groups || {})))
                            .filter(this.owners.map(function(owner) {
                                return owner.replace(/^swarm-group-/,'');
                            })).length > 0)) {
                        var item = $($.templates(swarm.testdefinition.dropdownmenuitem).render({prefix: prefix, testdefinition: value}));
                        menu.append(item);
                    }
                });
            var defaultTestDefinitionValue   = $("#" + prefix + "-no-testdefinition a"),
                currentTestDefinition = currentTestDefinitionId !== -1 ? $("#" + prefix + '-' + currentTestDefinitionId + " a") : defaultTestDefinitionValue;
            testDefinitionDropdown.find('.text').text($(currentTestDefinition).data('short-label') || $(currentTestDefinition).html());
            addEvents(localStorageKey, prefix);
            testDefinitionDropdown.find('button').prop('disabled', false);
            return testDefinitionDropdown;
        }
        return init();
    },

    defineTemplates: function(options) {
        $.templates({
            'swarmTestDefinitionSummary':
                '<div class="test-definition-cell pad1 test-definition-cell-{{:testdefinition.id}}">\n' +
                '  <div class="content pad2">\n' +
                '    <span class="test-definition-name link">\n' +
                '      <i class="swarm-icon icon-test-definition-owner mutable-only" title="{{>ownerIconTitle}}"></i>\n' +
                '      <a data-toggle="modal" data-target="#test-definition-modal-content" href="#" class="force-wrap name" data-original-title="" title="">\n' +
                '        {{>testdefinition.name}}' +
                '      </a>\n' +
                '    </span>\n' +
                '    <p class="force-wrap description muted"><small>{{>testdefinition.description}}</small></p>\n' +
                '  </div>\n' +
                '</div>\n',
            'swarmTestDefinitionBodyEncoding':
                '<div class="test-definition-body-encoding">\n' +
                '  <label class="control-label radio span12">\n' +
                '    <div class="controls">\n' +
                '        <input type="radio" name="encoding" value="{{>value}}" {{:value===~current ? "checked=checked" : ""}} tabindex="8"/>\n' +
                '    </div>\n' +
                '    {{>label}}' +
                '  </label>\n' +
                '</div>\n',
            'swarmTestDefinitionRequestHeader':
                '<div class="control-group control-group-request-header">\n' +
                '  <div class="controls controls-row">\n' +
                '    <div class="span11 input-group-request-header">\n' +
                '      <div class="controls controls-row">\n' +
                '        <input class="span6 header-name" type="text" value="{{>key}}" placeholder="'+swarm.te('Name')+'" tabindex="9"/>\n' +
                '        <input class="span6 header-value" type="text" value="{{>prop}}" placeholder="'+swarm.te('Value')+'"  tabindex="9"/>\n' +
                '        {{if key !== "" }}' +
                '        <input class="request-header-pair" name="headers[{{>key}}]" type="hidden" value="{{>prop}}"/>\n' +
                '        {{/if}}' +
                '      </div>\n' +
                '    </div>\n' +
                '    <div class="span1">\n' +
                '      <button class="btn remove-request-header" title="'+swarm.te('Delete')+'" tabindex="10">\n' +
                '        <i class="icon icon-trash" />\n' +
                '      </button>\n' +
                '    </div>\n' +
                '  </div>\n' +
                '</div>\n',
            'swarmTestDefinitionAddHeader':
                '<div class="control-group control-group-add-header">\n' +
                '  <div class="controls controls-row">\n' +
                '    <a class="span3{{if ~disabled}} disabled{{/if}}" href="#">'+swarm.te('+Add header')+'</a>' +
                '    {{if ~noHeaders}}<p class="span9 muted">'+swarm.te('There are no custom headers for this request')+'</p>{{/if}}' +
                '  </div>\n' +
                '</div>\n',
            'swarmTestDefinitionArgument':
                '<div class="test-definition-argument">\n' +
                '  <span class="id">{{>id}}</span>\n' +
                '  <span class="description">{{>description}}</span>\n' +
                '</div>\n',
            'swarmTestDefinitionAffectedWorkflows':
                '<ul>\n' +
                ' {{for workflows itemVar="~workflow"}}' +
                '   <li class="workflow"><p class="workflow-name"><span>{{:name}}</span></p></li>\n' +
                ' {{/for}}' +
                '</ul>\n'
        });
        swarm.testdefinition.formTemplate =
            '<form method="post" class="modal-form" {{if "" !== testdefinition.id}} data-testdefinition-id="{{:testdefinition.id}}"{{/if}}>\n' +
            '  <div class="container-fluid">\n'+
            '    <div class="row-fluid">\n'+
            '      <div class="modal-header">\n' +
            '        <button type="button" class="close" data-dismiss="modal" aria-hidden="true" tabindex="-1"><span>x</span></button>\n' +
            '        <h3 id="testdefinition-content-title" class="force-wrap">{{>title}}</h3>\n' +
            '      </div>\n' +
            '    </div>\n' +
            '    <div class="row-fluid">\n'+
            '      <div class="modal-body">\n' +
            '        <div class="messages"></div>\n' +
            '        <div class="test-definition">\n' +
            '          <div class="column span4">\n'+
            '            <div class="control-group">\n' +
            '              <label class="control-label" for="name">'+swarm.t('Name')+'</label>\n' +
            '              <div class="controls">\n' +
            '                <input class="span12" autocomplete="off" type="text" name="name" id="name" {{:mutable ? "" :"disabled=disabled"}} value="{{>testdefinition.name}}" placeholder="'+swarm.t('Name')+'" required="required" tabindex="1"/>\n' +
            '              </div>\n' +
            '            </div>\n' +
            '            <div class="control-group">\n' +
            '              <label class="control-label" for="description">'+swarm.t('Description')+'</label>\n' +
            '              <div class="controls">\n' +
            '                <textarea class="span12" rows="10" name="description" {{:mutable ? "" :"disabled=disabled"}} id="description" placeholder="'+swarm.t('Description')+'" tabindex="2">{{>testdefinition.description}}</textarea>\n' +
            '              </div>\n' +
            '            </div>\n' +
            '            <div class="control-group control-group-owners">\n' +
            '              <label for="owners" class="control-label">'+swarm.t('Owners')+'</label>\n' +
            '              <div class="controls">\n' +
            '                <div class="body in collapse"">\n' +
            '                  <div class="input-prepend" clear="both">\n' +
            '                    <span class="add-on"><i class="icon-user"></i></span>\n' +
            '                    <input type="text" class="span12 multipicker-input{{:mutable ? "" :" disabled"}}" id="owners" data-items="100" data-selected="[]" placeholder="'+swarm.t('Add an Owner')+'" tabindex="3"/>\n' +
            '                  </div>\n' +
            '                  <div class="owners-list multipicker-items-container clearfix"></div>\n' +
            '                </div>\n' +
            '              </div>\n' +
            '            </div>\n' +
            '            <div class="control-group control-group-shared">\n' +
            '              <div class="controls">\n' +
            '                <div class="body in collapse">\n' +
            '                  <input type="hidden" value="0" name="shared">\n' +
            '                  <label class="control-label{{:mutable ? "" :" disabled"}}">\n' +
            '                    <input {{:mutable ? "" :"disabled=disabled"}} type="checkbox" name="shared" id="shared-test-definition" {{:testdefinition.shared ? "checked=checked" : ""}} value="1"  tabindex="4"/>\n' +
            '                    '+swarm.t('Shared with others')+
            '                  </label>\n' +
            '                </div>\n' +
            '              </div>\n' +
            '            </div>\n' +
            '          </div>\n' +
            '          <div class="column span8">\n' +
            '            <div class="control-group control-group-test-definition-values">\n' +
            '              {{if mutable }}' +
            '              <div class="row-fluid">\n'+
            '                <div class="column span8">\n'+
            '                  <div class="control-group control-group-request-url">\n' +
            '                    <label class="control-label" for="url">'+swarm.te('URL')+'</label>\n' +
            '                    <div class="controls">\n' +
            '                      <input class="span12" type="text" name="url" value="{{>testdefinition.url}}" required="required" placeholder="'+swarm.t('Request URL')+'" tabindex="5"/>\n' +
            '                    </div>\n' +
            '                  </div>\n' +
            '                </div>\n' +
            '                <div class="column span4">\n'+
            '                  <div class="control-group control-group-request-timeout">\n' +
            '                    <label for="timeout">'+swarm.te('Timeout(seconds)')+'</label>\n' +
            '                    <div class="controls">\n' +
            '                      <input class="input-small" type="text" name="timeout" value="{{>testdefinition.timeout}}" placeholder="'+swarm.t("Timeout")+'" tabindex="6"/>\n' +
            '                    </div>\n' +
            '                  </div>\n' +
            '                </div>\n' +
            '              </div>\n' +
            '              <div class="row-fluid">\n'+
            '                <div class="column span8">\n'+
            '                  <div class="control-group control-group-request-body">\n' +
            '                    <label class="control-label" for="body">'+swarm.te('Body')+'</label>\n' +
            '                    <div class="controls">\n' +
            '                      <textarea class="span12" rows="10" name="body" placeholder="'+swarm.t('Request Body')+'" tabindex="7">{{>testdefinition.body}}</textarea>\n' +
            '                    </div>\n' +
            '                  </div>\n' +
            '                </div>\n' +
            '                <div class="column span4">\n'+
            '                  <div class="control-group control-group-body-encoding">\n' +
            '                    <label class="control-group-heading">'+swarm.te('Body encoding')+'</label>\n' +
            '                    {{for supportedEncodings ~current=testdefinition.encoding||"json" tmpl="swarmTestDefinitionBodyEncoding" /}}' +
            '                  </div>\n' +
            '                </div>\n' +
            '              </div>\n' +
            '              <div class="row-fluid">\n'+
            '                <div class="column span8">\n'+
            '                  <div class="control-group control-group-iterate">\n' +
            '                    <div class="controls">\n' +
            '                      <input type="hidden" value="0" name="iterate">\n' +
            '                      <label class="control-label">\n' +
            '                        <input type="checkbox" name="iterate" id="iterate" {{:testdefinition.iterate ? "checked=checked" : ""}} value="1"  tabindex="4"/>\n' +
            '                        '+ swarm.t('Iterate tests for affected projects and branches\n') +
            '                        <a class="help" href="' + swarm.assetUrl('/docs/Content/Swarm/test-add.html#test-add_iterate-tests') + '" target="_swarm_docs"><span>?</span></a>' +
            '                      </label>\n' +
            '                    </div>\n' +
            '                  </div>\n' +
            '                </div>\n' +
            '              </div>\n' +
            '              <div class="row-fluid">\n'+
            '                <div class="column span8">\n'+
            '                  <div class="control-group control-group-request-headers">\n' +
            '                    <div class="span11 request-header-heading{{if !testdefinition.headers}} no-headers{{/if}}">\n' +
            '                      <div class="controls controls-row">\n' +
            '                        <label class="control-label span6">'+swarm.te('Header')+'</label>' +
            '                        <label class="control-label span6">'+swarm.te('Value')+'</label>' +
            '                      </div>\n' +
            '                    </div>\n' +
            '                    {{props testdefinition.headers tmpl="swarmTestDefinitionRequestHeader" /}}' +
            '                    {{include ~noHeaders=!testdefinition.headers tmpl="swarmTestDefinitionAddHeader" /}}' +
            '                  </div>\n' +
            '                </div>\n' +
            '              </div>\n' +
            '              <div class="row-fluid">\n'+
            '                <div class="column span12">\n'+
            '                  <h4>' + swarm.t('Standard arguments (URL and Body fields only)') +
            '                    <a class="help" href="' + swarm.assetUrl('/docs/Content/Swarm/test-add.html#Pass_special_arguments_to_your_test_suite') + '" target="_swarm_docs"><span>?</span></a>' +
            '                  </h4>\n' +
            '                  {{for supportedArguments tmpl="swarmTestDefinitionArgument" /}}' +
            '                </div>\n' +
            '              </div>\n' +
            '            {{else}}' +
            '              <p class="private-message">'+swarm.t('Some details of this test definition are private and cannot be displayed. Only the test definition owners have access to these details.') + '</p>\n' +
            '            {{/if}}' +
            '            </div>\n' +
            '          </div>\n' +
            '        </div>\n' +
            '      </div>\n' +
            '    </div>\n' +
            '    <div class="row-fluid">\n' +
            '      <div id="affected-workflows"><p class="loading animate">'+swarm.t('Loading...')+'</p></div>\n' +
            '    </div>\n' +
            '    <div class="row-fluid">\n'+
            '      <div class="modal-footer">\n' +
            '        <div class="control-group group-buttons text-left">\n' +
            '          <div class="controls">\n' +
            '           {{if mutable }}' +
            '             <button type="submit" class="btn btn-mlarge btn-primary btn-save" tabindex="11">'+swarm.t('Save')+'</button>\n' +
            '             <button type="button" class="btn btn-mlarge" data-dismiss="modal" aria-hidden="true" tabindex="11">'+swarm.t('Cancel')+'</button>\n' +
            '             {{if testdefinition.id }}' +
            '             <div class="tool-tip" data-toggle="tooltip" data-placement="top" title="' + swarm.t('Owners can delete a test definition when not in use') + '">\n'+
            '               <button disabled="disabled" id="btn-delete" type="button" class="btn btn-mlarge btn-danger" aria-hidden="true" tabindex="11">'+swarm.t('Delete')+'</button>\n' +
            '             </div>\n'+
            '             {{/if}}' +
            '           {{else}}' +
            '             <button type="button" class="btn btn-mlarge btn-primary" data-dismiss="modal" aria-hidden="true" tabindex="11">'+swarm.t('Close')+'</button>\n' +
            '           {{/if}}'+
            '          </div>\n' +
            '        </div>\n' +
            '      </div>\n' +
            '    </div>\n' +
            '  </div>\n' +
            '</form>\n';
    }
};
