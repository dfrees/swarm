/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

swarm.user = {
    userDetails: '',
    toolbar: '',
    csrf: '',

    settings: {
        init: function() {
            var $settings = $('#settingsForm');
            if ($settings.hasClass('readOnly')) {
                $settings.find(':checkbox').attr('disabled', true);
                $settings.find('.settingsSave').attr('disabled', true);
                $settings.find('#settingsReset').attr('disabled', true);
                $settings.find('#settingsCancel').attr('disabled', true);

                $settings.find('#time-format-display').attr('disabled', true);
            }
            swarm.user.settings.configureReset();
            // Set the token for the form.
            $('#settingsToken').attr('value', $('body').data('csrf'));
            swarm.user.settings.preventButtonDoubleClick();
        },
        configureReset: function() {
            $('#settingsReset').click(function () {
                $('#settings tbody input[type=checkbox]').each(function (i, item) {
                    item.checked = $(this).data('default');
                });
                // Set the selection boxes back to default settings.
                $('#settings tbody select').each(function (s, selection) {
                    var defaultSelected = this.dataset['default']; // Get default first
                    $(selection).find('option').each(function (x, option){ // Then find all the options for this select
                        // If the current option is the same name as the default set the selected to true
                        if (defaultSelected.toLowerCase() === option.id.toLowerCase()) {
                            option.selected = true;
                        }
                    });
                });
            });
        },
        preventButtonDoubleClick: function() {
            // Disable the save button once the user has clicked once.
            $('#settingsForm').submit(function (e) {
                $(this).find('button').prop('disabled', true);
            });
        }
    },

    follow: function(type, id, button) {
        button = $(button);
        swarm.form.disableButton(button);
        button.tooltip('destroy');

        // if we are already following, unfollow
        var unfollow = button.is('.following'),
            action   = unfollow ? 'unfollow' : 'follow';

        $.post(
            '/' + action + '/' + encodeURIComponent(type) + '/' + encodeURIComponent(id),
            function(response) {
                swarm.form.enableButton(button);
                if (response.isValid) {
                    // update button text (e.g. 'Follow' -> 'Unfollow' and vice-versa)
                    button.text(unfollow ? swarm.t('Follow') : swarm.t('Unfollow'));
                    button.toggleClass('following');

                    // indicate success via a temporary tooltip.
                    button.tooltip({
                        title:   unfollow ? swarm.t('No longer following %s', [id]) : swarm.t('Now following %s', [id]),
                        trigger: 'manual'
                    }).tooltip('show');

                    // update the UI immediately
                    swarm.user.updateFollowersSidebar(action);

                    setTimeout(function(){
                        button.tooltip('destroy');
                    }, 3000);
                }
            }
        );

        return false;
    },

    unfollowalldialog: function() {
        $('#unfollow-modal').modal('show');
    },

    unfollowall: function(user, button) {
        button = $(button);
        swarm.form.disableButton(button);
        button.tooltip('destroy');

        $.post(
            '/api/v8/users/' + user + '/unfollowall',
            function(response) {
                swarm.form.enableButton(button);
                if (response.isValid) {
                    location.reload();
                }
            }
        );
        return false;
    },

    updateFollowersSidebar: function(action) {
        var user      = swarm.user.getAuthenticatedUser(),
            counter   = $('.profile-sidebar .metrics .followers .count'),
            followers = $('.profile-sidebar .followers.profile-block'),
            avatars   = followers.find('.avatars');

        // nothing to do if not authenticated
        if (!user) {
            return;
        }

        // change the opacity of the avatar to indicate it's follow/unfollow state
        var avatar = avatars.find('img[data-user="' + user.id + '"]').closest('span');
        avatar.css('opacity', (action === 'follow' ? 1 : 0.2));

        // if we are following, but the avatar wasn't already in the page, we need to add it
        if (action === 'follow' && !avatar.length) {
            // may need to build a new row if one doesn't exist, or is full
            var row = avatars.find('> div').last();
            row     = (row.length && row.children().length < 5 && row) || $('<div />').appendTo(avatars);
            $('<span class="border-box" />').html(user.avatar).appendTo(row);
            followers.removeClass('hidden');
        }

        counter.text(parseInt(counter.text(), 10) + (action === 'follow' ? 1 : -1));
    },

    getAuthenticatedUser: function() {
        return $('body').data('user');
    }
};