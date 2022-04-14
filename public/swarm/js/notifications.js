/**
 * Perforce Swarm
 *
 * @copyright   2013-2022 Perforce Software. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 * @version     2022.1/2268697
 */

swarm.notifications = {
    user: {
        init: function () {

            swarm.notifications.common.setCheckAll();
            var $notifications = $('#notificationForm');
            // If the page loads and user isn't on their settings buttons are all disabled.
            if ($notifications.hasClass('readOnly')) {
                $notifications.find(':checkbox').attr('disabled', true);
                $notifications.find('.notificationSave').attr('disabled', true);
                $notifications.find('#notificationReset').attr('disabled', true);
                $notifications.find('#notificationCancel').attr('disabled', true);
            }
            swarm.notifications.common.configureReset();
            swarm.notifications.common.enableInputEvents();
            // Set the token for the form.
            $('#token').attr('value', $('body').data('csrf'));
            swarm.notifications.common.preventButtonDoubleClick();
        }
    },

    group: {
        init: function() {
            // Enable the form if this group is using an email address
            swarm.notifications.group.configureSettingsFields();
            // Set up the rest of the form controls
            swarm.notifications.common.configureReset();
            swarm.notifications.common.enableInputEvents();
            swarm.notifications.common.setCheckAll();
            swarm.notifications.common.preventButtonDoubleClick();
        },
        // Make sure that the settings inputs match are appropriate for the email address settings
        configureSettingsFields: function() {
            $('#groupNotificationSettingsPanel .modal-backdrop').show();
            $('#notificationForm table input').prop('disabled',true);
            if ( !$('#emailAddress').prop('disabled') && $('#emailValidIndicator.valid').length) {
                $('#groupNotificationSettingsPanel .modal-backdrop').hide();
                $('#notificationForm table input').not('.notificationDisabled').prop('disabled',false);
                return true;
            }
            return false;
        }
    },
    common: {
        // Wire-up the reset btn to set all value back to default value being set in the input.
        configureReset: function() {
            $('#notificationReset').click(function () {
                $('#notifications tbody input[type=checkbox]').each(function (i, item) {
                    if(!item.disabled) {
                        item.checked = $(this).data('default');
                    }
                });
                swarm.notifications.common.setCheckAll();
            });
        },
        // Enable custom input events
        enableInputEvents: function() {
            // Listen for check all actions.
            $('#checkAllNotifications').change(function () {
                $('#notificationTable input').each(function (item) {
                    if(!$('#notificationTable input')[item].disabled) {
                        $('#notificationTable input')[item].checked = $('#checkAllNotifications')[0].checked;
                    }
                });
            });
            // Listen for individual settings changes
            $("#notificationForm tbody input").change(function(){
                swarm.notifications.common.setCheckAll();
            });
        },
        // Make the checkAll checkbox value match the settings
        setCheckAll: function() {
            // Check whether all enabled settings are checked
            $('#checkAllNotifications')[0].checked =
                $('#notificationForm tbody input:enabled:checked').length === $('#notificationForm tbody input').length - $('#notificationForm tbody td.notificationDisabled').length;
        },
        // Stop the user clicking buttons, mainly submitting the form, twice in quick succession
        preventButtonDoubleClick: function() {
            // Disable the save button once the user has clicked once.
            $('#notificationForm').submit(function (e) {
                $(this).find('button').prop('disabled', true);
            });
        }
    }
};