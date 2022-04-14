/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

swarm.groups = {
    _loading: false,

    init: function () {
        // load initial results
        var search = $('.groups-toolbar input[name=keywords]');
        $('table.groups').data('keywords', search.val());
        swarm.groups.load();

        // wire-up search filter
        var runSearch = function() {
            // if search hasn't changed, return
            if ($('table.groups').data('keywords') === search.val()) {
                return;
            }

            // if currently processing another search, abort it.
            if (swarm.groups._loading) {
                swarm.groups._loading.abort();
                swarm.groups._loading = false;
            }

            $('table.groups').data('keywords', search.val());
            swarm.groups.load(true);
        };

        var handleSearch = function() {
            runSearch();
            // push new url into the browser
            var keywords = '?keywords=' + encodeURIComponent(search.val());
            if (keywords !== location.search) {
                swarm.history.pushState(null, null, keywords);
            }
        };

        $('.groups-toolbar .btn-search').on('click', handleSearch);

        search.on(
            'keypress',
            function(e) {
                // early exit if not enter key
                var code = (e.keyCode || e.which);
                if (e.type === 'keypress' && code !== 13) {
                    return;
                }

                handleSearch();
            }
        );

        // reload the page when user logs in (we need to reload the table and group-add button)
        $(document).on('swarm-login', function () {
            location.reload();
        });

        // continuously add groups when scrolling to bottom
        $(window).scroll(function() {
            if ($.isScrolledToBottom()) {
                swarm.groups.load();
            }
        });

        // handle popstate events
        swarm.history.onPopState(function(event) {
            var params = $.deparam(location.search.replace(/^\?/, ''), false, true);
            search.val(params.q || '');
            runSearch();
        });
    },

    load: function(reset) {
        if (swarm.groups._loading) {
            if (!reset) {
                return;
            }

            swarm.groups._loading.abort();
            swarm.groups._loading = false;
        }

        var table = $('table.groups');
        if (!table.length) {
            return;
        }

        // if reset requested, clear table contents
        if (reset) {
            table.data('last-seen',   null);
            table.data('end-of-data', null);
            table.find('tbody').empty();
        }

        // if there are no more groups, nothing else to do
        if (table.data('end-of-data')) {
            return;
        }

        // add extra row indicating that we are loading data
        table.find('tbody').append(
            '<tr class="loading"><td colspan="5">'
          +  '<span class="loading animate muted">' + swarm.te('Loading...') + '</span>'
          + '</td></tr>'
        );

        swarm.groups._loading = $.ajax({
            url:         location.pathname,
            skipBaseUrl: true,
            data:        {
                format:          'json',
                max:             50,
                after:           table.data('last-seen'),
                keywords:        table.data('keywords'),
                fields:          ['Group', 'name' ,'ownerAvatars', 'memberCount', 'description', 'isMember', 'isInGroup', 'emailFlags'],
                sort:            '-isInGroup,-isEmailEnabled,name',
                excludeProjects: true
            },
            dataType:    'json',
            success:     function (data) {
                table.find('tbody tr.loading').remove();

                // if we received no data or the last data set is same as lastSeen,
                // set 'end-of-data' to indicate there are no more groups to fetch
                var dataLastSeen = data.lastSeen;
                if (dataLastSeen === null || dataLastSeen === table.data('last-seen')) {
                    table.data('end-of-data', true);
                }

                table.data('last-seen', dataLastSeen);

                // render rows from received data and append them to the table
                $.each(data.groups, function(key, group){
                    // show only avatars for the first few owners
                    var deficit    = 3,
                        avatars    = '',
                        moreOwners = [];

                    // sort owner avatars such that avatar for the authenticated user appears first
                    var user = swarm.user.getAuthenticatedUser();
                    if (user) {
                        group.ownerAvatars.sort(function (a, b) {
                            var userA = $(a).find('img').data('user'),
                                userB = $(b).find('img').data('user');

                            return userA === user.id ? -1 : (userB === user.id ? 1 : 0);
                        });
                    }

                    $.each(group.ownerAvatars || [], function (key, avatar) {
                        if (deficit > 0) {
                            deficit--;
                            avatars += avatar;
                        } else {
                            moreOwners.push($(avatar).attr('title') || $(avatar).find('img').data('user'));
                        }
                    });

                    // append the number of owners we didn't render avatars for (if any)
                    if (moreOwners.length) {
                        avatars += '<span class="more-owners" title="' + moreOwners.join(',') + '">'
                                +  '+' + moreOwners.length
                                +  '</span>';
                    }
                    group.ownerAvatars = avatars;

                    // prepare text for enabled notifications
                    var notifications = [];
                    if (group.emailFlags.reviews) {
                        notifications.push(swarm.te('Reviews'));
                    }
                    if (group.emailFlags.commits) {
                        notifications.push(swarm.te('Commits'));
                    }
                    group.notifications = notifications.join(', ');

                    var row = $.templates(
                          '<tr data-id="{{>Group}}" class="{{if isInGroup}}is-in-group{{/if}}{{if isMember}} is-member{{/if}}">'
                        + ' <td class="name"><a class="force-wrap" href="{{url:"/groups"}}/{{url:Group}}">{{>name}}</a>'
                        + '  {{if name != Group}}<p class="muted force-wrap">{{:Group}}</p>{{/if}}'
                        + '</td>'
                        + ' <td class="description">{{:description}}</td>'
                        + ' <td class="owners">{{:ownerAvatars}}</td>'
                        + ' <td class="members">'
                        + '  <span class="badge" title="{{if isMember}}' + swarm.te('You are a member') + '{{else isMember !== null}}' + swarm.te('You are not a member') + '{{/if}}">'
                        + '   {{>memberCount}}'
                        + '  </span>'
                        + ' </td>'
                        + '</tr>'
                    ).render(group);

                    $(row).appendTo(table.find('tbody'));
                });

                // if we have no groups to show and there is no more on the server, let the user know
                if (!table.find('tbody tr').length && !data.length && table.data('end-of-data')) {
                    $('<tr class="groups-info">'
                        + ' <td colspan="' + table.find('thead th').length + '">'
                        + '  <div class="alert border-box pad3">' + swarm.te('No groups.') + '</div>'
                        + ' </td>'
                        + '</tr>'
                    ).appendTo(table.find('tbody'));
                }

                // truncate the description
                table.find('.description').expander({slicePoint: 90});

                // add 'last-is-in-group' class to the last 'is-in-group' row (if there are more rows following)
                // to help with css styling
                var lastIsInGroup = table.find('.is-in-group').last();
                if (lastIsInGroup.next().length) {
                    lastIsInGroup.addClass('last-is-in-group');
                }

                // enforce a minimal delay between requests
                setTimeout(function(){ swarm.groups._loading = false; }, 500);
            }
        });
    }
};

swarm.group = {
    initEdit: function(wrapper, saveUrl, groupId) {
        var membersElement = $(wrapper).find('#members'),
            ownersElement  = $(wrapper).find('#owners');

        // setup userMultiPicker plugin for selecting members/owners
        membersElement.userMultiPicker({
            itemsContainer: $(wrapper).find('.members-list'),
            inputName:      'Users',
            groupInputName: 'Subgroups',
            enableGroups:   true,
            excludeGroups: [groupId]
        });
        ownersElement.userMultiPicker({
            itemsContainer: $(wrapper).find('.owners-list'),
            inputName:      'Owners'
        });

        // check the form state and wire up the submit button
        swarm.form.checkInvalid($(wrapper).find('form'));
        $(wrapper).find('form').submit(function(e) {
            e.preventDefault();
            swarm.form.post(
                saveUrl,
                $(wrapper).find('form'),
                null,
                null,
                function (form) {
                    var formObject = $.deparam($(form).serialize());

                    // ensure we post owners, users and subgroups
                    formObject = $.extend({Owners: null, Users: null, Subgroups: null}, formObject);
                    // Only want to extend useMailingList when we are on notification page.
                    if ($('#notificationForm').length > 0) {
                        formObject = $.extend({useMailingList: false}, formObject);
                    }

                    return formObject;
                }
            );
        });

        // Align email address and mailing list settings
        var validateEmailAddress = function(emailAddressField) {
            // Validate via ajax, don't worry about failures as submit revalidates
            var validIndicator = $('#emailValidIndicator');
            $.post(
                '/validate/emailAddress', {emailAddress: emailAddressField.val()})
                .done(function (data) {
                    validIndicator.removeClass('valid icon-ok icon-remove')
                        .addClass(data.valid ? 'valid icon-ok' : 'icon-remove').show();
                    swarm.notifications.group.configureSettingsFields();
                }
            );
        };
        // Wire up email address input
        $('input#emailAddress').on(
            'input keyup blur',
            function(event){
                // apply delayed validation
                clearTimeout(swarm.group.emailAddressTimeout);
                var emailAddressField = $(this);
                swarm.group.emailAddressTimeout = setTimeout(function(){
                    // Validate via ajax, don't worry about failures as submit revalidates
                    $('#hiddenEmailAddress').val(emailAddressField.val());
                    if($('#useMailingList').prop('checked')){
                        validateEmailAddress(emailAddressField);
                    }
                }, 100);
            }
        );
        var configureEmailAddressField = function(){
            var validIndicator = $('#emailValidIndicator');
            var emailAddressField = $('#emailAddress');
            validIndicator.hide();
            if($('#useMailingList:checked').length === 0){
                // If the mailing list is not being used, disable capture
                emailAddressField.prop('disabled', true);
                $('#groupNotificationHelp .help-block').show();
            } else {
                $('#groupNotificationHelp .help-block').hide();
                emailAddressField.prop('disabled', false);
                validateEmailAddress(emailAddressField);
            }

        };
        configureEmailAddressField();
        $('#useMailingList').on('click', function(e){
            configureEmailAddressField();
            swarm.notifications.group.configureSettingsFields();
        });

        // wire up group delete
        $(wrapper).find('.btn-delete').on('click', function(e){
            e.preventDefault();

            var button  = $(this),
                confirm = swarm.tooltip.showConfirm(button, {
                    placement:  'top',
                    content:    swarm.te('Delete this group?'),
                    buttons:    [
                        '<button type="button" class="btn btn-primary btn-confirm">' + swarm.te('Delete') + '</button>',
                        '<button type="button" class="btn btn-cancel">' + swarm.te('Cancel') + '</button>'
                    ]
                });

            // wire up cancel button
            confirm.tip().on('click', '.btn-cancel', function(){
                confirm.destroy();
            });

            // wire up delete button
            confirm.tip().on('click', '.btn-confirm', function(){
                // disable buttons when the delete is in progress
                swarm.form.disableButton(confirm.tip().find('.btn-confirm'));
                confirm.tip().find('.buttons .btn').prop('disabled', true);

                // attempt to delete the group via ajax request
                $.post('/groups/delete/' + encodeURIComponent(groupId), function(response) {
                    // if there is an error, present it in a new tooltip, otherwise
                    // redirect to the home page
                    if (response.isValid) {
                        window.location.href = swarm.url('/');
                    } else {
                        confirm.destroy();
                        var errorConfirm = swarm.tooltip.showConfirm(button, {
                            placement:  'top',
                            content:    response.error,
                            buttons:    [
                                '<button type="button" class="btn btn-primary">' + swarm.te('Ok') + '</button>'
                            ]
                        });
                        errorConfirm.tip().on('click', '.btn', function(){
                            errorConfirm.destroy();
                        });
                    }
                });
            });
        });

        var showEmailNotificationDetails = function() {
            var hidden = $('input#emailReviews').prop('checked') || $('input#emailCommits').prop('checked');
            $('.email-flags').find('#author-help').toggleClass('hide', hidden);
        };

        showEmailNotificationDetails();
        $('.email-flags').find('input[type="checkbox"]').on('change', showEmailNotificationDetails);
    }
};
