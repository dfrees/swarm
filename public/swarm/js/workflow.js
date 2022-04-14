/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */
swarm.workflow={
    supportedEvents: [],
    supportedBlocks: [],
    testDefinitions: [],
    testDefinitionsObject: [],
    testDefinitionsLoaded: false,
    workFlowDisabledDeleteButton: false,

    init: function(options) {
        $(document).on('click.disabled','.disabled',function(e) {
            e.preventDefault();
            e.stopPropagation();
        });
        swarm.workflow.supportedEvents = [
            {value: 'onUpdate', text: swarm.te('On Update')},
            {value: 'onSubmit', text: swarm.te('On Submit')},
            {value: 'onDemand', text: swarm.te('On Demand')}
        ];
        swarm.workflow.supportedBlocks = [
            {value: 'nothing', text: swarm.te('Nothing')},
            {value: 'approved', text: swarm.te('Approve')}
        ];
        swarm.workflow.defineTemplates({});
        swarm.workflow.load();
        $(document).on('swarm-login', function (e) {
            swarm.workflow.load();
        });
        $('a').tooltip(); // Enables boostrap tooltip features
        $('#add-workflow-button').on('click',function(e) {
            swarm.workflow.showWorkflowModal({
                title:swarm.te('Add Workflow'),
                mutable:true
            });
        });
        $('#workflow-modal-content').on('click.save','.btn-save', function(e) {
            e.preventDefault();
            var form = $(this).closest('form'),
                id = form.data('workflow-id'),
                url = '/api/v10/workflows'+("" !== id ?("/"+id) :"");
            swarm.form.rest("" !== id ?'PUT' :'POST',url, form,
                // Callback(success handler)
                function(response, form) {
                    if (response.data) {
                        var savedMessage = $.templates(
                            '<div class="swarm-alert alert alert-success" id="inner-message">{{>message}}</div>'
                        ).render({message: swarm.te('Workflow Saved')});
                        $('.modal-body .messages').append(savedMessage);
                        // Reload tabs
                        swarm.workflow.load();
                        // close the form on completion
                        setTimeout(function() {
                            form.find('button.close').click();
                        },500);
                    }
                },
                // Error node
                $('#workflow-modal-content .modal-body')
            );
        });
        $('#workflow-modal-content').on('click.rule','.control-group-workflow-rules .btn-group li a', function(e) {
            e.preventDefault();
            var choice = $(this),
                group  = choice.closest('.btn-group');
                group.find('span.current').text(choice.text());
                group.find('input').val(choice.data('value'));
        });
        // Add the dropdown selector event.
        $('#workflow-modal-content').on('click.tests','.control-group-workflow-tests .btn-group li a', function(e) {
            e.preventDefault();
            var choice = $(this),
                group  = choice.closest('.btn-group');
            group.find('span.current').text(choice.text());
            group.find('input').val(choice.data('value'));
        });
        $('.workflow-cells').on('click.workflow','.link.workflow-name a',function(e) {
            var link = $(this),
                cell = $(this).closest('.workflow-cell');
           e.preventDefault();
           swarm.workflow.showWorkflowModal($.extend({title:link.text()},cell.data()));
        });
        $('.btn-search').on('click',function() {
            $('div.filter-all').remove();
            $('.workflow-cell').toggleClass('filtered',true).each(function() {
                var searchTerm = $('.search .input-large').val().toLowerCase(),
                    workflow = $(this);
                if (workflow.find('a.name').text().toLowerCase().indexOf(searchTerm) !== -1 ||
                    workflow.find('.description').text().toLowerCase().indexOf(searchTerm) !== -1) {
                    $(this).toggleClass('filtered',false);
                }
            });
            if ($('.workflow-cell:visible').length === 0) {
                $('.workflow-cells').prepend($('<div>').addClass('filter-all').text(swarm.te('No matching workflows')));
            }
        });
        $('.workflows .search input').on(
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
        $('#workflow-modal-content').on('click.delete','#btn-delete', function(e) {
            e.preventDefault();
            var button = $(this),
                form = button.closest('form'),
                url = '/api/v10/workflows/' + form.data('workflow-id');

            setTimeout(function () {
                var confirm = swarm.tooltip.showConfirm(button, {
                    placement: 'top',
                    content: swarm.te('Delete this workflow?'),
                    classes: 'popover-workflow',
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
                                ).render({message: swarm.te('Workflow Deleted')});
                                $('.modal-body .messages').append(deletedMessage);
                                // Reload tabs
                                swarm.workflow.load();
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
                        $('#workflow-modal-content .modal-body')
                    );
                });
            }, 5);
        });
        // Fetch the testDefinitions that this user can use.
        $('.workflows').addClass('loading-test-definitions').find('input, button').prop('disabled',true);

        $.ajax({
            url: '/api/v10/testdefinitions',
            errorHandled: true,
            errorNode: $('.messages'),
            success: function(response) {
                if (response.data.testdefinitions.length > 0) {
                    var sorted = [];
                    swarm.workflow.testDefinitionsObject = response.data.testdefinitions;
                    $.each(response.data.testdefinitions, function (index, test) {
                        sorted['test-' + test.id.toString()] = test;
                    });
                    swarm.workflow.testDefinitions = sorted;
                }
                $('.workflows').removeClass('loading-test-definitions').not('.loading-workflows').find('input, button').prop('disabled',false);
            },
            error: function(response) {
                swarm.workflow.showErrors(
                    (response.responseJSON && response.responseJSON.messages)||
                    [{text:swarm.te('Failed to load test definitions. Please contact your Swarm administrator.')}]
                );
            }
        });
        // One place to toggle the buttons and input for test definitions.
        var toggleTestDefinition = function(test, panel){
            test.toggleClass('test-edit');
            test.find('.btn-group').toggleClass('view-mode');
            $.merge(
                test.find('.test-definition, .event-view, .blocks-view'),
                panel.find('button.edit, button.delete')
            ).toggleClass('hidden');
            $('.control-group-workflow-tests .controls-row.footer').removeClass('hidden');
        };

        // Add the edit test button event.
        $('#workflow-modal-content').on('click.editTest','.edit', function(e) {
            var panel = $('.control-group-workflow-tests .panel');
            var test = swarm.workflow.getTestRow($(this).data('row-id'), this);
            toggleTestDefinition(test, panel);
            $('#add-tests').prop('disabled', true);
            test.find('.test-definition-selection input').first().addClass('invalid');
            var testDefinitionText = test.find('.test-definition-selection div').first().text();
            test.find('.test-definition-selection span').first().text(testDefinitionText);
            $('.control-group-workflow-tests .controls-row.footer').addClass('hidden');
            var testDefinitionPrefix = 'workflow-'+$(this).data('row-id');
            swarm.testdefinition.testDefinitionDropdown({
                parent: $('.test-definition-selection'),
                prefix: testDefinitionPrefix,
                testdefinitions:  swarm.workflow.testDefinitionsObject ,
                currentTestDefinitionId: $(this).data('test-id'),
                noneSelected: false,
                localStorageKey: 'swarm.workflow.testdefinition.'+$(this).data('row-id')
            });
            $('.test-definition-dropdown ul li.divider').first().hide();
            $('[id*=-no-testdefinition]').hide();
            $('#workflow-modal-content .btn-save').prop('disabled', true);
            $('#btn-delete').prop('disabled', true);
        });
        // add the delete button event.
        $('#workflow-modal-content').on('click.deleteTest','.delete', function(e) {
            swarm.workflow.getTestRow($(this).data('row-id'), this).remove();
            $('body .tooltip').remove();
        });
        // add the accept button event.
        $('#workflow-modal-content').on('click.acceptTest','.accept', function(e) {
            var panel = $('.control-group-workflow-tests .panel');
            // If we have a control row with test-add we know this is a new test that needs the test id added to
            // the class.
            var testAdd = $('.control-group-workflow-tests .controls-row.test-add');
            testAdd.removeClass().addClass('controls-row controls test-'+$(this).data('row-id')+' test-edit');
            var test = swarm.workflow.getTestRow($(this).data('row-id'), this);
            toggleTestDefinition(test, panel);
            $('#add-tests').prop('disabled', '');
            test.find('.test-definition-selection input').first().removeClass('invalid');
            // Now set the value.
            var testDefinitionId = test.find('.test-definition-selection input').first().val();
            var testDefinitionText = test.find('.test-definition-dropdown .text').text();
            test.find('.test-definition').text(testDefinitionText).attr('data-test-definition', testDefinitionId);
            var testEventId = test.find('.test-event-dropdown input').data('value');
            var testEventText = test.find('.test-event-dropdown .current').text();
            test.find('.event-view').text(testEventText).attr('data-event', testEventId);
            var testBlocksId = test.find('.test-blocks-dropdown input').data('value');
            var testBlocksText = test.find('.test-blocks-dropdown .current').text();
            test.find('.blocks-view').text(testBlocksText).attr('data-blocks', testBlocksId);
            $('#workflow-modal-content .btn-save').prop('disabled', '');
            if (swarm.workflow.workFlowDisabledDeleteButton) {
                $('#btn-delete').prop('disabled', true);
            } else {
                $('#btn-delete').removeAttr('disabled');
            }
        });
        // add the cancel button event.
        $('#workflow-modal-content').on('click.cancel','.cancel', function(e) {
            var panel = $('.control-group-workflow-tests .panel');
            var test = '';
            if (panel.find('.test-add').length){
                var model = $('.modal-form');
                model.data('workflowTests', model.data('workflowTests')-1);
                test = panel.find('.test-add');
                test.remove();
                $('body .tooltip').remove();
            } else {
                test = $(swarm.workflow.getTestRow($(this).data('row-id'), this));
                // Now set the value back.
                var testDefinitionText = test.find('.test-definition').text();
                var testDefinitionId = test.find('.test-definition').data('test-definition');
                test.find('.test-definition-selection input').attr('value', testDefinitionId);
                test.find('.test-definition-dropdown .text').text(testDefinitionText);
                var testEventId = test.find('.event-view').data('event');
                var testEventText = test.find('.event-view').text();
                test.find('.test-event-dropdown .current').text(testEventText);
                test.find('.test-event-dropdown input').data('value', testEventId);
                var testBlocksId = test.find('.blocks-view').data('blocks');
                var testBlocksText = test.find('.blocks-view').text();
                test.find('.test-blocks-dropdown .current').text(testBlocksText);
                test.find('.test-blocks-dropdown input').data('value', testBlocksId);
            }
            toggleTestDefinition(test, panel);
            // Remove the invalid case and undisable the add and save button.
            test.find('.test-definition-selection input').first().removeClass('invalid');
            $('#add-tests').prop('disabled', '');
            $('#workflow-modal-content .btn-save').prop('disabled', '');
            if (swarm.workflow.workFlowDisabledDeleteButton) {
                $('#btn-delete').prop('disabled', true);
            } else {
                $('#btn-delete').removeAttr('disabled');
            }
        });
        // Add test button
        $('#workflow-modal-content').on('click.addTests','#add-tests', function(e) {
            e.preventDefault();
            var panel = $('.control-group-workflow-tests .panel');
            panel.find('button.edit').addClass('hidden');
            panel.find('button.delete').addClass('hidden');
            // Disable the add test button.
            $(this).prop('disabled', true);
            $('#workflow-modal-content .btn-save').prop('disabled', true);
            $('.control-group-workflow-tests .controls-row.footer').toggleClass('hidden');
            // check if there is a .no-test class and remove if present.
            $('.control-group-workflow-tests').find('.no-tests').remove();
            var authenticatedUser = swarm.user.getAuthenticatedUser();
            var isSuper = $('body').hasClass('super');
            var model = $('.modal-form');
            var nextIndex = model.data('workflowTests');
            model.data('workflowTests', nextIndex+1);
            // create a new test row using template.
            var newTest =  $($.render.swarmWorkflowTestsAdd({
                tests: Object.keys(swarm.workflow.testDefinitions).filter(
                    function(testId){
                        var test = swarm.workflow.testDefinitions[testId];
                        return swarm.workflow.canSee(test.shared, test.owners, isSuper, authenticatedUser, true);
                    }).map(function(testId) {
                    var test = swarm.workflow.testDefinitions[testId];
                    return {id: test.id, name: test.name};
                }),
                name: swarm.t('Please choose an option'),
                event: swarm.workflow.supportedEvents[0].value,
                blocks: swarm.workflow.supportedBlocks[0].value,
                eventName: swarm.workflow.supportedEvents[0].text,
                blocksName: swarm.workflow.supportedBlocks[0].text,
                nextIndex: nextIndex,
                supportedEvents: swarm.workflow.supportedEvents,
                supportedBlocks: swarm.workflow.supportedBlocks,
                add: true
            }));
            panel.append(newTest);
            var testDefinitionPrefix = 'workflow-'+nextIndex;
            swarm.testdefinition.testDefinitionDropdown({
                parent: $('.test-definition-selection'),
                prefix: testDefinitionPrefix,
                testdefinitions:  swarm.workflow.testDefinitionsObject ,
                currentTestDefinitionId: null,
                noneSelected: false,
                localStorageKey: 'swarm.workflow.testdefinition.'+nextIndex
            });
            $('[id*=-no-testdefinition]').hide();
            $('[id*=-no-testdefinition]').next().hide();
            newTest.find('.swarm-icon.icon-task-addressed').tooltip('toggleEnabled');
            $('#btn-delete').prop('disabled', true);
        });
        // Add the test definition dropdown selector for test option.
        $('#workflow-modal-content').on('click.test-definition-dropdown','.test-definition-dropdown ul li', function(e) {
            $('button.accept').prop('disabled', '').removeClass('disabled');
            var test = $('.control-group-workflow-tests .controls-row.test-add');
            var testDefinitionId = test.find('input').first().val();
            var testDefinitionText = test.find('.text').text();
            test.find('.test-definition').text(testDefinitionText).attr('data-test-definition', testDefinitionId);
            test.find('.swarm-icon.icon-task-addressed').tooltip('toggleEnabled');
            $('.test-definition-dropdown input').removeClass('invalid');
        });
        // Add the test definition dropdown selector for its event.
        $('#workflow-modal-content').on('click.test-event-dropdown','.test-event-dropdown ul li   ', function(e) {
            var testEventText = $(this).find('a').text();
            var testEventId = $(this).find('a').data('value');
            $('.control-group-workflow-tests .controls-row.test-add .event-view')
                .text(testEventText).attr('data-event', testEventId);
        });
        // Add the test definition dropdown selector for its blocks.
        $('#workflow-modal-content').on('click.test-blocks-dropdown','.test-blocks-dropdown ul li   ', function(e) {
            var testBlocksText = $(this).find('a').text();
            var testBlocksId = $(this).find('a').data('value');
            $('.control-group-workflow-tests .controls-row.test-add .blocks-view')
                .text(testBlocksText).attr('data-blocks', testBlocksId);
        });
    },
    showErrors: function(messages) {
        var content = $('.messages').addClass('alert-error').toggleClass('hidden',false);
        $.each(messages, function () {
            content.append($('<div>').text(this.text));
        });
    },
    load: function() {
        var workflows = $('.workflows').addClass('loading-workflows');
        workflows.find('input, button').not('.close').prop('disabled',true);
        workflows.find('.workflow-cells').html(swarm.t('Loading...'));
        $.ajax({
            url: '/api/v10/workflows',
            dataType: 'json',
            errorHandled: true,
            success: function(response) {
                var authenticatedUser = swarm.user.getAuthenticatedUser();
                var mine = $('div#my-workflows .workflow-cells').html(authenticatedUser!==null?'': ('<div class="muted">'
                    + swarm.te("My workflows will only be populated once you have logged in.")
                    + '<a href="/login/" onclick="swarm.user.login(); return false;"> ' + swarm.te("Log in") + '</a> '
                    + swarm.te("now.")));
                var all = $('div#all-workflows .workflow-cells').html('');
                var ownerIconTitle = swarm.t('You are an owner');
                var workflow;
                $.each(response.data.workflows.sort(
                    function(w1, w2) {
                        var w1lower = (w1.id === swarm.workflow.defaults.id ? 0 : 1)+w1.name.toLowerCase(),
                            w2lower = (w2.id === swarm.workflow.defaults.id ? 0 : 1)+w2.name.toLowerCase();
                        return w1lower < w2lower ? -1 : w1lower > w2lower ? 1 : 0;
                    }),
                    function() {
                        workflow = $($.templates(swarm.workflow.summary).render(
                            {workflow: this, ownerIconTitle: ownerIconTitle, isGlobal: swarm.workflow.defaults.id === this.id }));
                        var isSuper = $('body').hasClass('super');
                        $(workflow).data({'workflow': this, 'mutable': false});
                        if (null !== authenticatedUser && swarm.workflow.isOwnerByGroup(this.owners, authenticatedUser)) {
                            workflow.addClass('owner').data('mutable', true).appendTo(all).clone().appendTo(mine).data({
                                'workflow': this,
                                'mutable': true
                            });
                        } else if (this.shared || isSuper || swarm.workflow.defaults.id === this.id) {
                            workflow.appendTo(all).data({
                                'mutable': isSuper
                            });
                        }
                        if (this.id === swarm.workflow.defaults.id) {
                            // Update the defaults, in case it has been updated via the ui
                            swarm.workflow.defaults = this;
                        }
                    }
                );
                $('.workflow-cells').each(function() {
                    if (!this.hasChildNodes()) {
                        $(this).html(swarm.te('No Workflows to show.'));
                    }
                });
                $('.workflows').removeClass('loading-workflows').not('.loading-test-definitions')
                    .find('input, button').prop('disabled',false);
                $('.workflow-cell .description').expander({slicePoint: 100});
            },
            error: function(response) {
                $('.workflow-cells').empty();
                var messages = $('.messages').addClass('alert-error').toggleClass('hidden',false);
                if ( response.responseJSON && response.responseJSON.messages ) {
                    // There was as Swarm response with more detail
                    $.each(response.responseJSON.messages, function () {
                        messages.append($('<div>').text(this.text));
                    });
                } else {
                    messages.append($('<div>').text(swarm.te('Failed to load workflow definitions. Please contact your Swarm administrator.')));
                }
            }
        });
    },

    /**
     * Display a modal form, prepopulated with any workflow data provided in data.workflow.
     * @param data - object
     */
    showWorkflowModal: function(data) {
        var content  = $('#workflow-modal-content'),
            workflow = data.workflow || {owners: [swarm.user.getAuthenticatedUser().id]},
            mutable  = data.mutable||false,
            onSubmit = workflow.on_submit || null,
            endRules = workflow.end_rules || null,
            defaults = swarm.workflow.defaults,
            rules    = [
            {
                description: swarm.te('On commit without a review'),
                target: 'submit_without_review_action',
                name: 'on_submit[without_review]',
                rule: (onSubmit && onSubmit.without_review) ? onSubmit.without_review.rule : defaults.on_submit.without_review.rule,
                mode: (onSubmit && onSubmit.without_review) ? onSubmit.without_review.mode : 'inherit',
                defaultErr: defaults.on_submit.without_review.rule,
                options: [
                    {value: 'no_checking', text: swarm.te('Allow')},
                    {value: 'auto_create', text: swarm.te('Create a review')},
                    {value: 'reject',      text: swarm.te('Reject')}
                ]
            },
            {
                description: swarm.te('On commit with a review'),
                target: 'submit_with_review_action',
                name: 'on_submit[with_review]',
                rule: (onSubmit && onSubmit.with_review) ? onSubmit.with_review.rule : defaults.on_submit.with_review.rule,
                mode: (onSubmit && onSubmit.with_review) ? onSubmit.with_review.mode : 'inherit',
                defaultErr: defaults.on_submit.with_review.rule,
                options: [
                    {value: 'no_checking', text: swarm.te('Allow')},
                    {value: 'approved',    text: swarm.te('Reject unless approved')}
                ]
            },
            {
                description: swarm.te('On update of a review in an end state'),
                target: 'submit_end_rule_action',
                name: 'end_rules[update]',
                rule: (endRules && endRules.update) ? endRules.update.rule : defaults.end_rules.update.rule,
                mode: (endRules && endRules.update) ? endRules.update.mode : 'inherit',
                defaultErr: defaults.end_rules.update.rule,
                options: [
                    {value: 'no_checking', text: swarm.te('Allow')},
                    {value: 'no_revision', text: swarm.te('Reject')}
                ]
            },
            {
                description: swarm.te('Count votes up from'),
                target: 'counted_votes_action',
                name: 'counted_votes',
                rule: workflow.counted_votes ? workflow.counted_votes.rule : defaults.counted_votes.rule,
                mode: workflow.counted_votes ? workflow.counted_votes.mode : 'inherit',
                defaultErr: defaults.counted_votes.rule,
                options: [
                    {value: 'anyone',  text: swarm.te('Anyone')},
                    {value: 'members', text: swarm.te('Members')}
                ]
            },
            {
                description: swarm.te('Automatically approve reviews'),
                target: 'submit_auto_approve_action',
                name: 'auto_approve',
                rule: workflow.auto_approve ? workflow.auto_approve.rule : defaults.auto_approve.rule,
                mode: workflow.auto_approve ? workflow.auto_approve.mode : 'inherit',
                defaultErr: defaults.auto_approve.rule,
                options: [
                    {value: 'never', text: swarm.te('Never')},
                    {value: 'votes', text: swarm.te('Based on vote count')}
                ]
            }
        ];

        var isSuper = $('body').hasClass('super');
        var authenticatedUser = swarm.user.getAuthenticatedUser();
        var tests = Object.keys(swarm.workflow.testDefinitions).filter(
            function(testId){
                var test = swarm.workflow.testDefinitions[testId];
                return swarm.workflow.canSee(test.shared, test.owners, isSuper, authenticatedUser, true);
            });
        // Ensure the test definitions have the name for their given id.
        if (workflow.tests) {
            $.each(Object.keys(workflow.tests), function (index, testKey) {
                var workflowTest = workflow.tests[testKey];
                workflowTest.name = swarm.workflow.testDefinitions['test-' + workflowTest.id].name;
                tests['test-' + workflowTest.id] = swarm.workflow.testDefinitions['test-' + workflowTest.id];
            });
        }
        content.html($.templates(swarm.workflow.formTemplate).render({
            title:data.title||swarm.t('Workflow'),
            isGlobal: swarm.workflow.defaults.id === workflow.id,
            mutable: mutable,
            workflow: workflow,
            rules: rules,
            supportedEvents: swarm.workflow.supportedEvents,
            supportedBlocks: swarm.workflow.supportedBlocks,
            haveTestDefinitions: tests.length,
            tests: tests.map(function(testId) {
                var test = swarm.workflow.testDefinitions[testId];
                return {id: test.id, name: test.name};
            }),
            testsCount: workflow.tests ? workflow.tests.length : 0
        }));
        content.find('#owners').userMultiPicker({
            itemsContainer: content.find('.owners-list'),
            selected:        workflow.owners.filter(function(owner) { return -1 === owner.indexOf('swarm-group-');}),
            inputName:       'owners',
            enableGroups:    true,
            required:        true,
            groupInputName:  'owners',
            useGroupKeys:    true,
            excludeProjects: true,
            disabled:        content.find('#owners').hasClass('disabled'),
            selectedGroups:  workflow.owners.filter(function(owner) { return -1 !== owner.indexOf('swarm-group-');})
        });
        // Set the current value of the rule dropdowns
        $.each(content.find('.control-group-workflow-rules .rule .btn-group'),function(){
            var rule = $(this);
            rule.find('span.current').text(rule.find('li a[data-value="'+rule.find('input').val()+'"]').text()||swarm.t('Please choose an option'));
        });
        // Set the current value of the test event dropdown.
        $.each(content.find('.control-group-workflow-tests .btn-group'),function(){
            var test = $(this);
            test.find('span.current').text(test.find('li a[data-value="'+test.find('input').val()+'"]').text()||swarm.t('Please choose an option'));
        });
        // Set the current value of the test event on page.
        $.each(content.find('.control-group-workflow-tests .event-view'),function(){
            var event = $(this);
            event.text(swarm.workflow.getTestEvent(event.data('event')).text);
        });
        // Set the current value of the test block on page.
        $.each(content.find('.control-group-workflow-tests .blocks-view'),function(){
            var blocks = $(this);
            blocks.text(swarm.workflow.getTestBlock(blocks.data('blocks')).text);
        });
        // Wire up any mode switches, this will currently only find anything for the global workflow
        content.find('.control-group-workflow-rules .mode .rounded-switch').not('.disabled').on('click', function() {
           var modeInput = $(this).toggleClass('policy').find('input');
           modeInput.val(modeInput.val()==='policy'?'default':'policy');
        });
        // Configure the Rule exclusion multipicker, this will currently only find anything for the global workflow
        content.find('#workflow-exclusions').userMultiPicker({
            itemsContainer: content.find('.exclusions-list'),
            selected:        (workflow.user_exclusions||{rule:[]}).rule,
            inputName:       'user_exclusions[rule]',
            enableGroups:    true,
            required:        false,
            groupInputName:  'group_exclusions[rule]',
            useGroupKeys:    true,
            excludeProjects: true,
            disabled:        content.find('#workflow-exclusions').hasClass('disabled'),
            selectedGroups:  (workflow.group_exclusions||{rule:[]}).rule
        });

        var workflowId = workflow.id;
        // Fetch the projects for this workflow.
        $.ajax({
            url: '/api/v9/projects?workflow='+workflowId,
            errorHandled: true,
            success: function(data) {
                if (data.projects.length > 0) {
                    swarm.workflow.workFlowDisabledDeleteButton = true;
                    $('#affected-projects').empty()
                        .append(
                            $('<a href="#project-list" onclick="event.preventDefault();" data-toggle="collapse" data-target="#project-list">')
                                .addClass("project-count collapsed")
                                .append($('<i>').addClass("icon icon-chevron-down"))
                                .append($('<span>')
                                    .addClass('collapse-title')
                                    .text(
                                        swarm.tpe(
                                            '%d project uses this workflow',
                                            '%d projects use this workflow',
                                            data.totalCount
                                        ))))
                        .append(
                            $('<div>').attr('id', 'project-list').addClass('collapse')
                                .append(
                                    $.templates(swarm.workflow.affectedProjectsTemplate)
                                        .render({projects:data.projects, workflowId: workflowId})));
                } else if (data.totalCount) {
                    $('#affected-projects p').removeAttr('class').text(
                        swarm.tpe(
                            '%d project uses this workflow',
                            '%d projects use this workflow',
                            data.totalCount
                        ));
                } else {
                    $('#btn-delete').prop('disabled', !mutable);
                    $('.tool-tip').removeAttr('title');
                    $('#affected-projects p').removeAttr('class').text(
                        swarm.t(
                            'No projects use this workflow'
                        ));
                }
            },
            error: function(response) {
                $('#affected-projects').html($('<p>').addClass('messages')
                    .append($('<span>').addClass("text-error")
                        .text(swarm.te('Failed to check the project associations for this workflow, please contact your Swarm administrator.'))
                    )
                );
            }
        });
    },

    workflowDropdown: function(options) {
        function addEvents(localStorageKey, prefix) {
            function clearWorkflowListStyling(list) {
                list.find("a.btn-filter").each(function() {
                    this.innerHTML=$(this).data('short-label');
                    $(this).show();
                });
            }

            $('.' + prefix + '-workflow-filter').on('click', '.input-filter', function(e) {
                e.stopPropagation();
            });

            $('.' + prefix + '-workflow-filter .input-filter').each(function() {
                var $input = $(this),
                    workflowControl = $('#' + prefix + '-workflow-control'),
                    list = workflowControl.find('.dropdown-menu li.list-item');

                $input.on('input change', function () {
                    $input.siblings('.clear').hide();

                    if (!$input.val()) {
                        // if value is empty - show all and clear styling
                        clearWorkflowListStyling(list, workflowControl);
                    } else {
                        $input.siblings('.clear').show();
                        list.find("a.btn-filter").each(function() {
                            $(this).hide();
                        });
                        list.find("a.btn-filter:dataLabelContains('" + $input.val() + "')").each(function() {
                            var workflowName = $(this).data('short-label');
                            var reg = new RegExp($input.val(), 'gi');
                            this.innerHTML=workflowName.replace(reg, "<b>$&</b>");
                            $(this).show();
                        });
                    }

                }).change();

                $input.siblings('.clear').on('click', function (e) {
                    $input.val('').data('filter-value', '').change();
                    e.stopPropagation();
                    clearWorkflowListStyling(list, workflowControl);
                });

                // handling 'enter' and 'esc' keys and arrow-down event on the input box
                $input.on('keydown', function(e) {
                    var listItems = workflowControl.find('.dropdown-menu');
                    if(e.keyCode === 13) {
                        $(document).trigger('click.dropdown.data-api');
                        e.preventDefault();
                    } else if(e.keyCode === 27) {
                        $input.val('');
                        $(document).trigger('click.dropdown.data-api');
                        e.preventDefault();
                    } else if (e.keyCode === 40) {
                        e.stopImmediatePropagation();

                        workflowControl.find('ul.dropdown-menu').children().each(function(){
                            $(this).removeClass('active');
                        });
                        var itemID = listItems.find('li.list-item a:visible').first().closest('li').attr('id');
                        $('#' + itemID).find('a').focus().addClass('active');
                    }

                });

                // wire up navigation - as default navigation doesnt like hidden elements we need to find visible ones ourselves
                workflowControl.find('ul.dropdown-menu').on('keydown', function(e){
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

                workflowControl.find('.dropdown-menu li:has(> a) a').on('click', function(){
                    localStorage.setItem(localStorageKey, $(this).parent().attr('id'));

                    clearWorkflowListStyling(list, workflowControl);
                    var idElement = $("#" + localStorage.getItem(localStorageKey) + " a");
                    $input.val(idElement.data('filter-value')).change();
                    $('#' + prefix + '-workflow').val(localStorage.getItem(localStorageKey).replace(prefix + '-', ''));
                    // if input is empty set filter value to groups default
                    var workflowValue = !$input.val() ? workflowControl.find('.default') : idElement;
                    if (!$(workflowValue).closest('.dropdown-menu').length) {
                        $(workflowValue).toggleClass('active');
                    } else if ($(workflowValue).is('input')) {
                        $(workflowValue).data('filter-value', $(workflowValue).val());
                        workflowControl.find('.text').text($(workflowValue).val());
                    } else {
                        $(workflowValue).addClass('active');
                        workflowControl.find('.text').text($(workflowValue).data('short-label') || $(workflowValue).html());
                        workflowControl.find('.input-filter').siblings('.clear').trigger('click');
                    }
                });
            });
        }

        function defineTemplates() {
            swarm.workflow.dropdown =
                '<div id="{{:prefix}}-workflow-control" class="" data-filter-key="workflow">\n'+
                '    <button id="{{:prefix}}-btn-project-workflow" type="button" class="btn btn-workflow dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" title="">\n'+
                '        <span class="text workflow-label"></span>\n' +
                '        <span class="caret"></span>\n'+
                '    </button>\n'+
                '    <ul class="dropdown-menu workflow-filter" aria-label="' + swarm.te("Workflows") +'">\n'+
                '        <li id="{{:prefix}}-no-workflow">\n'+
                '            <a href="javascript:void(0);" class="btn-filter default" data-filter-value="{{:noneSelected}}">{{:noneSelected}}</a>\n'+
                '        </li>\n'+
                '        <li class="divider"></li>\n'+
                '        <li class="{{:prefix}}-workflow-filter">\n'+
                '            <input class="input-filter" data-filter-key="workflow" type="text" placeholder="'+swarm.te("Workflows")+'">\n'+
                '            <button type="button" class="clear" style=""><span>x</span></button>\n'+
                '        </li>\n'+
                '        <li class="divider"></li>\n'+
                '    </ul>\n'+
                '</div>';
            swarm.workflow.dropdownmenuitem =
                '<li class="list-item" id="{{:prefix}}-{{:workflow.id}}">\n'+
                '    <a href="javascript:void(0);" class="btn-filter btn-filter-workflow" data-filter-key="workflow" data-filter-value="{{:prefix}}-{{:workflow.id}}" data-short-label="{{:workflow.name}}">{{:workflow.name}}</a>\n'+
                '</li>';
        }

        function init() {
            var parent = options.parent,
                prefix = options.prefix,
                workflows = options.workflows,
                currentWorkflowId = options.currentWorkflowId || -1,
                localStorageKey = options.localStorageKey,
                noneSelected = options.noneSelected || swarm.te('No workflow');
            defineTemplates();

            var workflowDropdown       = $($.templates(swarm.workflow.dropdown).render({prefix: prefix, noneSelected: noneSelected})),
                authenticatedUser      = swarm.user.getAuthenticatedUser(),
                isSuper                = $('body').hasClass('super'),
                menu                   = workflowDropdown.find('.dropdown-menu'),
                workflowControlClasses = parent.find('#' + prefix + '-workflow-control').attr("class");

            parent.find('#' + prefix + '-workflow-control').replaceWith(workflowDropdown);
            // preserve classes on the element being replaced
            workflowDropdown.addClass(workflowControlClasses);
            $.each(workflows.sort(
                function(w1, w2) {
                    var w1lower = w1.name.toLowerCase(),
                        w2lower = w2.name.toLowerCase();
                    return w1lower < w2lower ? -1 : w1lower > w2lower ? 1 : 0;
                }),
                function(index, value) {
                    // We want to include: All for super, any shared, any owned and also any that may no longer be
                    // shared or owned by where at the time the choice was made before
                    if (value.id !== swarm.workflow.defaults.id && (isSuper || value.shared || (parseInt(currentWorkflowId, 10) === value.id) ||(null !== authenticatedUser &&
                        $([authenticatedUser.id].concat(Object.keys(authenticatedUser.groups || {})))
                            .filter(this.owners.map(function(owner) {
                                return owner.replace(/^swarm-group-/,'');
                            })).length > 0))) {
                        var item = $($.templates(swarm.workflow.dropdownmenuitem).render({prefix: prefix, workflow: value}));
                        menu.append(item);
                    }
                });
            var defaultWorkflowValue   = $("#" + prefix + "-no-workflow a"),
                currentWorkflow = currentWorkflowId !== -1 ? $("#" + prefix + '-' + currentWorkflowId + " a") : defaultWorkflowValue;
            workflowDropdown.find('.text').text($(currentWorkflow).data('short-label') || $(currentWorkflow).html());
            addEvents(localStorageKey, prefix);
            workflowDropdown.find('button').prop('disabled', false);
            return workflowDropdown;
        }
        return init();
    },

    getTestRow: function(id, element) {
        return $(element).closest('.controls-row.test-' + id);
    },

    getTestEvent: function(event) {
        switch (event) {
            case "onUpdate":
                return swarm.workflow.supportedEvents[0];
            case "onSubmit":
                return swarm.workflow.supportedEvents[1];
            case "onDemand":
                return swarm.workflow.supportedEvents[2];
            default:
                return swarm.workflow.supportedEvents[1];
        }
    },

    getTestBlock: function(block) {
        switch (block) {
            case "nothing":
                return swarm.workflow.supportedBlocks[0];
            case "approved":
                return swarm.workflow.supportedBlocks[1];
            default:
                return swarm.workflow.supportedBlocks[1];
        }
    },

    isOwnerByGroup: function(owners, authenticatedUser) {
        return authenticatedUser !== null && $([authenticatedUser.id].concat(Object.keys(authenticatedUser.groups || {})))
            .filter(owners.map(function(owner) {
                return owner.replace(/^swarm-group-/,'');
            })).length > 0;
    },

    /**
     * This is a function to allow us to test if the user logged in can see the given item based on below.
     * If you are super you can see it.
     * If it is shared you can see it.
     * If it is not shared but you are the owner you can see it.
     * If you apart of a group that is an owner.
     * @param shared
     * @param owners
     * @param isSuper
     * @param authenticatedUser
     * @param includeGroups
     * @returns {boolean}
     */
    canSee: function (shared, owners, isSuper, authenticatedUser, includeGroups) {
        var valid = false;
        if (includeGroups && swarm.workflow.isOwnerByGroup(owners, authenticatedUser)) {
            valid = true;
        } else if (isSuper || shared === true) {
            valid = true;
        }
        return valid;
    },

    defineTemplates: function(options) {
        $.templates({
            'swarm-workflow-summary':
                '<div class="workflow-cell pad1 workflow-cell-{{:workflow.id}}{{:isGlobal?" workflow-cell-global":""}}">\n' +
                '  <div class="content pad2"><span class="workflow-name link"><i class="swarm-icon icon-workflow-owner mutable-only" title="{{>ownerIconTitle}}"></i><a data-toggle="modal" data-target="#workflow-modal-content" href="#" class="force-wrap name" data-original-title="" title="">{{>workflow.name}}</a></span>\n' +
                '    <p class="force-wrap description muted"><small>{{>workflow.description}}</small></p>\n' +
                '  </div>\n' +
                '</div>\n',
            'swarm-workflow-rules':
                '<h4>'+swarm.t('Rules')+
                '  <a class="help" href="' + swarm.assetUrl('/docs/Content/Swarm/workflow.overview.html#Workflow_rules') + '" target="_swarm_docs"><span>?</span></a>' +
                '</h4>\n' +
                '<div class="panel">\n' +
                '  {{for rules ~mutable=mutable tmpl="swarm-workflow-rule" /}}' +
                '</div>\n',
            'swarm-workflow-rule':
                '<div class="control-group">\n' +
                '  {{include tmpl="swarm-workflow-rule-input"/}}\n' +
                '  <div class="controls mode">\n' +
                '    <input name="{{:name}}[mode]" type="hidden" value="inherit" />\n' +
                '  </div>\n' +
                '</div>\n',
            'swarm-workflow-rule-input':
                '<div class="controls rule">\n' +
                '  <label for="{{:name}}[rule]" class="rule-description">{{:description}}</label>\n' +
                '  <div class="btn-group">\n' +
                '    <button class="btn dropdown-toggle dropdown-value{{:~mutable ?"" : " disabled"}}" data-toggle="dropdown" data-target="{{:target}}">' +
                '      <span class="current"></span>' +
                '      <span class="vertical-bar"></span>' +
                '      <span class="caret"></span>' +
                '    </button>\n' +
                '    <input name="{{:name}}[rule]" type="hidden" value={{:rule}} onError={{:defaultErr}} />\n' +
                '    <ul class="dropdown-menu" role="menu" aria-label="{{:target}}">\n' +
                '    {{for options}}' +
                '      <li><a href="#" data-value="{{:value}}">{{:text}}</a></li>\n' +
                '    {{/for}}' +
                '    </ul>\n' +
                '  </div>\n' +
                '</div>',
            'swarm-global-rules' :
                '<h4><div class="rule-description-title">' + swarm.t('Global Rules')+
                '      <a class="help" href="' + swarm.assetUrl('/docs/Content/Swarm/admin.workflow_configurables.html') + '" target="_swarm_docs"><span>?</span></a>\n' +
                '    </div>\n' +
                '    <div class="mode-setting-title">' + swarm.t('Enforce') +
                '      <a class="help" href="' + swarm.assetUrl('/docs/Content/Swarm/admin.workflow_configurables.html#Global_workflow') + '" target="_swarm_docs"><span>?</span></a>\n' +
                '    </div>\n' +
                '</h4>\n' +
                '<div class="panel">\n' +
                '  {{for rules ~mutable=mutable tmpl="swarm-global-rule" /}}' +
                '</div>\n' +
                '<div class="control-group control-group-exclusions">\n' +
                '  <label for="workflow-exclusions" class="control-label">'+
                      swarm.t('Members or groups who can ignore ALL workflow rules') +
                '  </label>\n' +
                '  <div class="controls exclusions">\n' +
                '    <div class="body in collapse"">\n' +
                '      <div class="input-prepend" clear="both">\n' +
                '        <span class="add-on"><i class="icon-user"></i></span>\n' +
                '        <input type="text" class="input-xlarge multipicker-input{{:mutable ? "" :" disabled"}}" id="workflow-exclusions" data-items="100" data-selected="[]" placeholder="'+swarm.t('Member or group')+'">\n' +
                '      </div>\n' +
                '      <div class="exclusions-list multipicker-items-container clearfix"></div>\n' +
                '    </div>\n' +
                '    <input name="group_exclusions[mode]" type="hidden" value="policy" />\n' +
                '    <input name="user_exclusions[mode]" type="hidden" value="policy" />\n' +
                '  </div>\n' +
                '</div>\n',
            'swarm-global-rule':
                '<div class="control-group">\n' +
                '  {{include tmpl="swarm-workflow-rule-input"/}}\n' +
                '  <div class="controls mode">\n' +
                '    <div class="rounded-switch {{:mode}}{{:~mutable ?"" : " disabled"}}">\n' +
                '      <div class="switch-button"></div>\n' +
                '      <input name="{{:name}}[mode]" type="hidden" value="{{:mode}}" />\n' +
                '    </div>\n' +
                '  </div>\n' +
                '</div>\n',
            'swarmWorkflowTests':
                '<div class="controls-row tests-title">' +
                '  {{include tmpl="swarmWorkflowTestsTitle"/}}\n' +
                '</div>' +
                '<div class="panel">\n' +
                '  {{for workflow.tests ~tests=tests ~mutable=mutable ~supportedEvents=supportedEvents ~supportedBlocks=supportedBlocks tmpl="swarmWorkflowTestsTest"/}}' +
                '</div>\n' +
                '<div class="controls-row footer">\n' +
                '     <div class="add-test-btn">' +
                '       {{if mutable }}' +
                '       <button id="add-tests" type="button"  {{:haveTestDefinitions ? "" : " disabled=disabled"}} aria-hidden="true">' +
                '        <span{{:haveTestDefinitions ? "" : " title=\'' + swarm.t('There are no test definitions available for you to add to workflows') + '\'"}}>' + swarm.t('+Add Test') + '</span>'+
                '       </button>' +
                '        {{/if}}' +
                '     </div>' +
                '     {{if workflow.tests && workflow.tests.length === 0 || !workflow.tests}}' +
                '     <span class="no-tests muted">' +
                        swarm.t('There are no tests associated with this workflow') +
                '     </span>' +
                '  {{/if}}' +
                '</div>\n',
            'swarmWorkflowTestsAdd':
                '<div class="controls-row controls test-edit test-add">\n' +
                '  <div class="test-definition-selection">\n' +
                '    {{include ~add=add ~tests=tests tmpl="swarmWorkflowTestsDefinitionDropdown" /}}\n' +
                '  </div>\n' +
                '  <div class="test-definition-event-selection">\n' +
                '    {{include ~add=add ~eventName=eventName ~supportedEvents=supportedEvents tmpl="swarmWorkflowTestsEvents" /}}\n' +
                '  </div>' +
                '  <div class="test-definition-blocks-selection">\n' +
                '    {{include ~add=add ~blocksName=blocksName ~supportedBlocks=supportedBlocks tmpl="swarmWorkflowTestsBlocks" /}}\n' +
                '  </div>' +
                '  <div class="test-definition-options">\n' +
                '   <span class="test-options link">' +
                '    <button type="button" class="btn edit hidden" aria-hidden="true">' +
                '      <i class="swarm-icon icon-edit-pencil" title="' + swarm.t("Edit") + '"></i>' +
                '    </button>' +
                '    <button type="button" class="btn delete hidden" aria-hidden="true">' +
                '      <i class="icon-trash" title="' + swarm.t("Delete") + '"></i>' +
                '    </button>' +
                '    <button type="button" class="accept disabled" aria-hidden="true" disabled>' +
                '      <i class="swarm-icon icon-task-addressed" title="' + swarm.t("Accept") + '"></i>' +
                '    </button>' +
                '    <button type="button" class="cancel" aria-hidden="true">' +
                '      <i class="swarm-icon icon-cross" title="' + swarm.t("Cancel") + '"></i>' +
                '    </button>' +
                '   </span>' +
                '  </div>\n' +
                '</div>\n',
            'swarmWorkflowTestsTitle':
                '<div class="test-definition-title">\n' +
                '  <h4>'+swarm.t('Tests')+
                '    <a class="help" href="' + swarm.assetUrl('/docs/Content/Swarm/workflow.add.html') + '" target="_swarm_docs"><span>?</span></a>' +
                '  </h4>\n' +
                '</div>\n' +
                '<div class="test-definition-event-title">\n' +
                '<h4><div>' + swarm.t('When') + '</div></h4>' +
                '</div>\n' +
                '<div class="test-definition-blocks">\n' +
                '<h4><div>' + swarm.t('Blocks') + '</div></h4>' +
                '</div>\n',
            'swarmWorkflowTestsTest':
                '<div class="controls-row controls test-'+'{{if #getIndex() >= 0}}{{:#getIndex()}}{{else}}{{:nextIndex}}{{/if}}'+'">\n' +
                '  <div class="test-definition-selection">\n' +
                '   {{include tmpl="swarmWorkflowTestsDefinitionDropdown"/}}\n' +
                '  </div>\n' +
                '  <div class="test-definition-event-selection">\n' +
                '    {{include tmpl="swarmWorkflowTestsEvents" /}}\n' +
                '  </div>' +
                '  <div class="test-definition-blocks-selection">\n' +
                '    {{include tmpl="swarmWorkflowTestsBlocks" /}}\n' +
                '  </div>' +
                '  <div class="test-definition-options">\n' +
                '   <span class="test-options link">' +
                '   {{if ~mutable }}' +
                '    <button type="button" class="btn edit" data-row-id="{{if #getIndex() >= 0}}{{:#getIndex()}}{{else}}{{:nextIndex}}{{/if}}" data-test-id="{{:id}}" aria-hidden="true">' +
                '      <i class="swarm-icon icon-edit-pencil" title="' + swarm.t("Edit") + '"></i>' +
                '    </button>' +
                '    <button type="button" class="btn delete" data-row-id="{{if #getIndex() >= 0}}{{:#getIndex()}}{{else}}{{:nextIndex}}{{/if}}" data-test-id="{{:id}}" aria-hidden="true">' +
                '      <i class="icon-trash" title="' + swarm.t("Delete") + '"></i>' +
                '    </button>' +
                '    <button type="button" class="accept hidden" data-row-id="{{if #getIndex() >= 0}}{{:#getIndex()}}{{else}}{{:nextIndex}}{{/if}}" data-test-id="{{:id}}" aria-hidden="true">' +
                '      <i class="swarm-icon icon-task-addressed" title="' + swarm.t("Accept") + '"></i>' +
                '    </button>' +
                '    <button type="button" class="cancel hidden" data-row-id="{{if #getIndex() >= 0}}{{:#getIndex()}}{{else}}{{:nextIndex}}{{/if}}" data-test-id="{{:id}}" aria-hidden="true">' +
                '      <i class="swarm-icon icon-cross" title="' + swarm.t("Cancel") + '"></i>' +
                '    </button>' +
                '    {{/if}}' +
                '   </span>' +
                '  </div>\n' +
                '</div>\n',
            'swarmWorkflowTestsEvents':
                '  <div class="event-view{{:~add ? " hidden" : ""}}" data-event="{{:event}}">' +
                '{{if ~add }}{{:~eventName}}{{/if}}' +
                '  </div>\n' +
                '  <div class="btn-group test-event-dropdown{{:~add ? "" : " view-mode"}}">\n' +
                '    <button class="btn dropdown-toggle dropdown-value"' +
                ' data-toggle="dropdown"' +
                ' data-target="tests['+ '{{if #getIndex() >= 0}}{{:#getIndex()}}{{else}}{{:nextIndex}}{{/if}}'+'][event]">' +
                '      <span class="current">{{if ~add }}{{:~eventName}}{{/if}}</span>' +
                '      <span class="vertical-bar"></span>' +
                '      <span class="caret"></span>' +
                '    </button>\n' +
                '    <input name="tests['+ '{{if #getIndex() >= 0}}{{:#getIndex()}}{{else}}{{:nextIndex}}{{/if}}'+'][event]" type="hidden" value={{:event}} />\n' +
                '    <ul class="dropdown-menu" role="menu" aria-label="tests['+ '{{if #getIndex() >= 0}}{{:#getIndex()}}{{else}}{{:nextIndex}}{{/if}}'+'][event]">\n' +
                '    {{for ~supportedEvents }}' +
                '      <li><a href="#" data-value="{{:value}}">{{:text}}</a></li>\n' +
                '    {{/for}}' +
                '    </ul>\n' +
                '  </div>\n',
            'swarmWorkflowTestsBlocks':
                '  <div class="blocks-view{{:~add ? " hidden" : ""}}" data-blocks="{{:blocks}}">' +
                '{{if ~add }}{{:~blocksName}}{{/if}}' +
                '  </div>\n' +
                '  <div class="btn-group test-blocks-dropdown{{:~add ? "" : " view-mode"}}">\n' +
                '    <button class="btn dropdown-toggle dropdown-value"' +
                ' data-toggle="dropdown"' +
                ' data-target="tests['+ '{{if #getIndex() >= 0}}{{:#getIndex()}}{{else}}{{:nextIndex}}{{/if}}'+'][blocks]">' +
                '      <span class="current">{{if ~add }}{{:~blocksName}}{{/if}}</span>' +
                '      <span class="vertical-bar"></span>' +
                '      <span class="caret"></span>' +
                '    </button>\n' +
                '    <input name="tests['+ '{{if #getIndex() >= 0}}{{:#getIndex()}}{{else}}{{:nextIndex}}{{/if}}'+'][blocks]" type="hidden" value={{:blocks}} />\n' +
                '    <ul class="dropdown-menu" role="menu" aria-label="tests['+ '{{if #getIndex() >= 0}}{{:#getIndex()}}{{else}}{{:nextIndex}}{{/if}}'+'][blocks]">\n' +
                '    {{for ~supportedBlocks }}' +
                '      <li><a href="#" data-value="{{:value}}">{{:text}}</a></li>\n' +
                '    {{/for}}' +
                '    </ul>\n' +
                '  </div>\n',
            'swarmWorkflowTestsDefinitionDropdown':
                '<div class="test-definition{{:~add ? " hidden" : ""}}" data-test-definition="{{:id}}">{{:name}}</div>\n' +
                '<input id = "workflow-'+'{{if #getIndex() >= 0}}{{:#getIndex()}}{{else}}{{:nextIndex}}{{/if}}'+'-testdefinition"  name="tests['+ '{{if #getIndex() >= 0}}{{:#getIndex()}}{{else}}{{:nextIndex}}{{/if}}'+'][id]" type="hidden" class="{{:~add ? "invalid" : ""}}" value="{{:id}}"/>\n' +
                '<div id="workflow-'+'{{if #getIndex() >= 0}}{{:#getIndex()}}{{else}}{{:nextIndex}}{{/if}}'+'-testdefinition-control" class="btn-group test-definition-dropdown{{:~add ? "" : " view-mode"}}" data-filter-key="testdefinition">\n'+
                '    <button id="btn-testdefinition-'+'{{if #getIndex() >= 0}}{{:#getIndex()}}{{else}}{{:nextIndex}}{{/if}}" type="button" class="btn btn-testdefinition dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" title="">\n'+
                '        <span class="text testdefinition-label">{{:name}}</span>\n' +
                '        <span class="caret"></span>\n'+
                '    </button>\n'+
                '    <ul class="dropdown-menu testdefinition-filter" aria-label="' + swarm.te("Test definitions") +'">\n'+
                '        <li id="{{if #getIndex() >= 0}}{{:#getIndex()}}{{else}}{{:nextIndex}}{{/if}}'+'-no-testdefinition">\n'+
                '            <a href="#" class="btn-filter default" data-filter-value="{{:noneSelected}}">{{:noneSelected}}</a>\n'+
                '        </li>\n'+
                '        <li class="divider"></li>\n'+
                '        <li class="{{if #getIndex() >= 0}}{{:#getIndex()}}{{else}}{{:nextIndex}}{{/if}}'+'-testdefinition-filter">\n'+
                '            <input class="input-filter" data-filter-key="testdefinition" type="text" placeholder="'+swarm.te("Test Definition")+'">\n'+
                '            <button type="button" class="clear" style=""><span>x</span></button>\n'+
                '        </li>\n'+
                '        <li class="divider"></li>\n'+
                '        {{for ~tests }}' +
                '           <li class="list-item" id="testdefinition-{{:id}}">\n'+
                '             <a href="#" class="btn-filter btn-filter-workflow" data-filter-key="workflow" data-filter-value="testdefinition-{{:id}}" data-short-label="{{:name}}">{{:name}}</a>\n'+
                '           </li>'+
                '       {{/for}}'+
                '    </ul>\n'+
                '</div>'
        });
        swarm.workflow.summary =
            '<div class="workflow-cell pad1 workflow-cell-{{:workflow.id}}{{:isGlobal?" workflow-cell-global":""}}">\n' +
            '  <div class="content pad2"><span class="workflow-name link"><i class="swarm-icon icon-workflow-owner mutable-only" title="{{>ownerIconTitle}}"></i><a data-toggle="modal" data-target="#workflow-modal-content" href="#" class="force-wrap name" data-original-title="" title="">{{>workflow.name}}</a></span>\n' +
            '    <p class="force-wrap description muted"><small>{{>workflow.description}}</small></p>\n' +
            '  </div>\n' +
            '</div>\n';
        swarm.workflow.formTemplate =
            '<form method="post" class="modal-form{{:isGlobal?" global-workflow":""}}"{{if "" !== workflow.id}} data-workflow-id="{{:workflow.id}}" data-workflow-tests="{{:testsCount}}"{{/if}}>\n' +
            '  <div class="container-fluid">\n'+
            '    <div class="row-fluid">\n'+
            '      <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-hidden="true"><span>x</span></button><h3 id="workflow-content-title" class="force-wrap">{{>title}}</h3></div>\n' +
            '    </div>\n' +
            '    <div class="row-fluid">\n'+
            '      <div class="modal-body">\n' +
            '        <div class="messages"></div>\n' +
            '        <div class="workflow">\n' +
            '          <div class="column span4">'+
            '            <div class="control-group">\n' +
            '              <label class="control-label" for="name">'+swarm.t('Name')+'</label>\n' +
            '              <div class="controls">\n' +
            '                <input class="span12" autocomplete="off" type="text" name="name" id="name" {{:mutable ? "" :"disabled=disabled"}} value="{{>workflow.name}}" placeholder="'+swarm.t('Name')+'" required="required">\n' +
            '              </div>\n' +
            '            </div>\n' +
            '            <div class="control-group">\n' +
            '              <label class="control-label" for="description">'+swarm.t('Description')+'</label>\n' +
            '              <div class="controls">\n' +
            '                <textarea class="span12" rows="10" name="description" {{:mutable ? "" :"disabled=disabled"}} id="description" placeholder="'+swarm.t('Description')+'">{{>workflow.description}}</textarea>\n' +
            '              </div>\n' +
            '            </div>\n' +
            '            <div class="control-group control-group-owners">\n' +
            '              <label for="owners" class="control-label">'+swarm.t('Owners')+'</label>\n' +
            '              <div class="controls">\n' +
            '                <div class="body in collapse"">\n' +
            '                  <div class="input-prepend" clear="both">\n' +
            '                    <span class="add-on"><i class="icon-user"></i></span>\n' +
            '                    <input type="text" class="span12 multipicker-input{{:mutable ? "" :" disabled"}}" id="owners" data-items="100" data-selected="[]" placeholder="'+swarm.t('Add an Owner')+'">\n' +
            '                  </div>\n' +
            '                  <div class="owners-list multipicker-items-container clearfix"></div>\n' +
            '                </div>\n' +
            '              </div>\n' +
            '            </div>\n' +
            '            {{if !isGlobal }}' +
            '              <div class="control-group control-group-shared">\n' +
            '                <div class="controls">\n' +
            '                  <div class="body in collapse">\n' +
            '                    <input type="hidden" value="0" name="shared">\n' +
            '                    <label class="control-label{{:mutable ? "" :" disabled"}}"><input {{:mutable ? "" :"disabled=disabled"}} type="checkbox" name="shared" id="shared-workflow" {{:workflow.shared ? "checked=checked" : ""}} value="1" />'+swarm.t('Shared with others')+'</label>' +
            '                  </div>\n' +
            '                </div>\n' +
            '              </div>\n' +
            '            {{/if}}' +
            '          </div>\n' +
            '          <div class="column span8">\n' +
            '            <div class="control-group control-group-workflow-rules">\n' +
            '              {{include tmpl=isGlobal ? "swarm-global-rules" : "swarm-workflow-rules" /}}' +
            '            </div>\n' +
            '            <div class="control-group control-group-workflow-tests">\n' +
            '              {{include tmpl="swarmWorkflowTests" /}}' +
            '            </div>\n' +
            '          </div>\n' +
            '        </div>\n' +
            '      </div>\n' +
            '    </div>\n' +
            '    {{if !isGlobal }}' +
            '      <div class="row-fluid">' +
            '        <div id="affected-projects"><p class="loading animate">'+swarm.t('Loading...')+'</p></div>' +
            '      </div>\n' +
            '    {{/if}}' +
            '    <div class="row-fluid">\n'+
            '      <div class="modal-footer">\n' +
            '        <div class="control-group group-buttons text-left">\n' +
            '          <div class="controls">\n' +
            '           {{if mutable }}' +
            '             <button type="submit" class="btn btn-mlarge btn-primary btn-save">'+swarm.t('Save')+'</button>\n' +
            '             <button type="button" class="btn btn-mlarge btn-cancel" data-dismiss="modal" aria-hidden="true">'+swarm.t('Cancel')+'</button>\n' +
            '             {{if !isGlobal && workflow.id }}' +
            '               <div class="tool-tip" data-toggle="tooltip" data-placement="top" title="' + swarm.t('Owners can delete a workflow when not in use') + '">'+
            '                 <button disabled="disabled" id="btn-delete" type="button" class="btn btn-mlarge btn-danger" aria-hidden="true">'+swarm.t('Delete')+'</button>\n' +
            '               </div>'+
            '             {{/if}}' +
            '           {{else}}' +
            '             <button type="button" class="btn btn-mlarge btn-cancel btn-primary" data-dismiss="modal" aria-hidden="true">'+swarm.t('Close')+'</button>\n' +
            '           {{/if}}'+
            '          </div>\n' +
            '        </div>\n' +
            '      </div>\n' +
            '    </div>\n' +
            '  </div>\n' +
            '</form>\n';
        swarm.workflow.affectedProjectsTemplate =
            '<ul>' +
            '  {{for projects itemVar="~project"}}' +
            '  <li class="project">' +
            '    <p class="project-name">' +
            '      <a class="collapsed" onclick="event.preventDefault();" href="#open-project-{{:#index}}" data-toggle="collapse" data-target="#collapse-project-{{:#index}}">' +
            '        <i class="icon icon-chevron-down"></i><span>{{:name}}</span>' +
            '      </a>' +
            '    </p>' +
            '    <div id="collapse-project-{{:#index}}" class="collapse">' +
            '      <p class="branch-heading">' + swarm.t('Branches') + '</p>' +
            '      <ol class="branch">' +
            '        {{for branches}}' +
            '          {{if (~root.workflowId == workflow || (!workflow && ~root.workflowId == ~project.workflow))}}' +
            '        <li class="branch"><span>{{:name}}</span></li>' +
            '          {{/if}}' +
            '        {{/for}}' +
            '      </ol>' +
            '    </div>' +
            '  </li>' +
            '  {{/for}}' +
            '</ul>\n';
    }
};
